<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\environmentFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand( name: 'deploy', description: 'Deploy the application: pull, check out a release tag, activate the environment config, write version.json, composer update (replaces update-production.ps1)' )]
final class deployCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'env', null, InputOption::VALUE_REQUIRED, 'Environment whose config variants to activate after checkout', 'prod', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'tag', null, InputOption::VALUE_REQUIRED, 'Tag to deploy. Omit to pick interactively from the most recent tags.' );
		$this->addOption( 'tags', null, InputOption::VALUE_REQUIRED, 'How many recent tags to offer in the interactive picker', '15' );
		$this->addOption( 'no-composer', null, InputOption::VALUE_NONE, 'Skip the composer update step' );
		$this->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompts' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$gitBinary = ( new ExecutableFinder() )->find( 'git' );
		if( $gitBinary===null ) {
			throw new cliException( 'git was not found on PATH.' );
		}

		$isWorkTree = $this->capture( [ $gitBinary, 'rev-parse', '--is-inside-work-tree' ], $context->rootDir );
		if( trim( $isWorkTree )!=='true' ) {
			throw new cliException( $context->rootDir . ' is not a git working tree.' );
		}

		$composerBinary = null;
		if( !$input->getOption( 'no-composer' ) ) {
			$composerBinary = ( new ExecutableFinder() )->find( 'composer' ) ?? ( new ExecutableFinder() )->find( 'composer.phar' );
			if( $composerBinary===null ) {
				throw new cliException( 'composer was not found on PATH. Install it or pass --no-composer to skip dependency updates.' );
			}
		}

		$environment = (string)$input->getOption( 'env' );

		$io->section( 'Fetching' );
		$this->runStep( [ $gitBinary, 'fetch', '--all', '--tags', '--prune' ], $context->rootDir, $output );
		$this->runStep( [ $gitBinary, 'pull' ], $context->rootDir, $output );

		$tag = (string)( $input->getOption( 'tag' ) ?? '' );
		if( $tag==='' ) {
			$tagList = array_values( array_filter( explode( "\n", $this->capture( [ $gitBinary, 'tag', '--sort=-creatordate' ], $context->rootDir ) ) ) );
			if( count( $tagList )===0 ) {
				throw new cliException( 'No git tags found — create a release tag before deploying, or pass --tag.' );
			}
			$tagList = array_slice( $tagList, 0, max( 1, (int)$input->getOption( 'tags' ) ) );
			$tag     = (string)$io->choice( 'Select the tag to deploy', $tagList, $tagList[ 0 ] );
		}

		$dirtyFiles = trim( $this->capture( [ $gitBinary, 'status', '--porcelain' ], $context->rootDir ) );
		if( $dirtyFiles!=='' ) {
			$io->warning( "The working tree has uncommitted changes:\n" . $dirtyFiles );
		}

		if( !$input->getOption( 'yes' ) && !$io->confirm( 'Deploy tag ' . $tag . ' with environment "' . $environment . '"?', false ) ) {
			$io->text( 'Aborted. No changes made.' );

			return Command::FAILURE;
		}

		$io->section( 'Checking out ' . $tag );
		$this->runStep( [ $gitBinary, 'checkout', 'tags/' . $tag ], $context->rootDir, $output );
		$io->text( '(A detached HEAD at the tag is expected.)' );

		$io->section( 'Submodules' );
		$this->runStep( [ $gitBinary, 'submodule', 'sync', '--recursive' ], $context->rootDir, $output );
		$this->runStep( [ $gitBinary, 'submodule', 'update', '--init', '--recursive' ], $context->rootDir, $output );

		$io->section( 'Activating environment "' . $environment . '"' );
		foreach( environmentFiles::apply( $context->rootDir, $environment ) as $result ) {
			$io->text( $result[ 'status' ] . ( str_starts_with( $result[ 'status' ], 'skipped' ) ? '' : ': ' . $result[ 'source' ] . ' -> ' . $result[ 'target' ] ) );
		}

		file_put_contents( $context->rootDir . '/version.json', json_encode( [ 'version' => $tag, 'inherit' => true ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$io->text( 'Wrote version.json (version ' . $tag . ')' );

		if( $composerBinary!==null ) {
			$io->section( 'composer update' );
			$this->runStep( [ $composerBinary, 'update', '--no-interaction' ], $context->rootDir, $output );
		}

		$io->success( 'Deployed ' . $tag . ' (' . $environment . ').' );

		return Command::SUCCESS;
	}


	/**
	 * Run a step, streaming output; abort the deploy on a non-zero exit.
	 *
	 * @param string[] $commandLine
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function runStep( array $commandLine, string $workingDirectory, OutputInterface $output ): void {
		$process = new Process( $commandLine, $workingDirectory, null, null, null );
		$process->run( function( string $type, string $buffer ) use ( $output ): void {
			$output->write( $buffer );
		} );

		if( !$process->isSuccessful() ) {
			throw new cliException( implode( ' ', $commandLine ) . ' exited with code ' . (string)$process->getExitCode() . ' — deploy aborted.' );
		}
	}


	/**
	 * @param string[] $commandLine
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function capture( array $commandLine, string $workingDirectory ): string {
		$process = new Process( $commandLine, $workingDirectory );
		$process->run();
		if( !$process->isSuccessful() ) {
			throw new cliException( implode( ' ', $commandLine ) . ' failed: ' . trim( $process->getErrorOutput() ) );
		}

		return $process->getOutput();
	}

}
