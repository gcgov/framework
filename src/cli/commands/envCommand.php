<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\mongoTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'env', description: 'Validate that config.json resolves; list the variables it needs, or write a .env skeleton' )]
final class envCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'list', null, InputOption::VALUE_NONE, 'Print the variables config.json references, marking which are secrets, and exit' );
		$this->addOption( 'init', null, InputOption::VALUE_NONE, 'Write a .env skeleton containing every variable config.json references' );
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'With --init, overwrite an existing .env' );
		$this->setHelp( <<<'HELP'
			Configuration is one committed config.json whose environment-varying values are
			%env(...) references. Every reference is required — there are no defaults — so this
			command is how you find out what an environment is missing before the application does.

			  gf env            validate: resolve config.json against the current environment
			  gf env --list     the variables config.json references, and which are secrets
			  gf env --init     write a .env skeleton from that same list

			The manifest is derived from config.json rather than hand-maintained, so it cannot
			drift. Note that .env also carries variables config.json knows nothing about (docker
			compose ports, CORS origins); --init leaves anything already in the file alone.

			A secret reference (%env(secret:NAME)%) is satisfied either by NAME or by NAME_FILE
			pointing at a provisioned file — which is how one config.json serves both a developer
			machine and production.
			HELP );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		if( $input->getOption( 'list' ) ) {
			return $this->listReferences( $context, $io );
		}

		if( $input->getOption( 'init' ) ) {
			return $this->writeEnvFile( $context, $io, (bool)$input->getOption( 'force' ) );
		}

		return $this->validate( $context, $io );
	}


	private function validate( appContext $context, SymfonyStyle $io ): int {
		$io->section( 'config.json + the current environment' );

		try {
			$config = $context->loadConfig();
		}
		catch( cliException $e ) {
			$io->error( $e->getMessage() );
			$io->text( 'Run `gf env --list` to see every variable config.json needs, or `gf env --init` to write a .env skeleton.' );

			return Command::FAILURE;
		}

		$io->text( 'type: ' . $config->type );
		if( $config->rootUrl!=='' ) {
			$io->text( 'rootUrl: ' . $config->rootUrl . '  basePath: ' . $config->getBasePath() );
		}
		$io->text( 'logging: ' . $config->logging->destination );
		foreach( $config->mongoDatabases as $mongoDatabase ) {
			$io->text( 'mongo: ' . $mongoDatabase->database . ' @ ' . mongoTools::redactUri( $mongoDatabase->uri ) . ( $mongoDatabase->default ? ' (default)' : '' ) );
		}

		$io->success( 'Resolved successfully — every %env(...) reference has a value.' );

		return Command::SUCCESS;
	}


	private function listReferences( appContext $context, SymfonyStyle $io ): int {
		$references = $context->configReferences();

		if( count( $references )===0 ) {
			$io->text( 'config.json references no environment variables.' );

			return Command::SUCCESS;
		}

		$rows = [];
		foreach( $references as $name => $isSecret ) {
			$rows[] = [ $name, $isSecret ? 'secret' : '', $this->isSet( $name ) ? 'set' : 'MISSING' ];
		}
		$io->table( [ 'Variable', 'Kind', 'Current environment' ], $rows );

		return Command::SUCCESS;
	}


	private function writeEnvFile( appContext $context, SymfonyStyle $io, bool $force ): int {
		$envPath = $context->getEnvFilePath();

		if( file_exists( $envPath ) && !$force ) {
			throw new cliException( $envPath . ' already exists. Pass --force to overwrite it, or `gf env --list` to see what it should contain. (A .env usually holds values this command cannot know — overwriting is deliberately opt-in.)' );
		}

		$references = $context->configReferences();
		$contents   = $this->renderEnvFile( $references );

		if( file_put_contents( $envPath, $contents )===false ) {
			throw new cliException( 'Failed writing ' . $envPath );
		}

		$io->success( 'Wrote ' . $envPath . ' with ' . count( $references ) . ' variable(s). Fill in the values — the application will not start until every one has one.' );

		return Command::SUCCESS;
	}


	/**
	 * @param  array<string, bool>  $references  variable name => is a secret
	 */
	public function renderEnvFile( array $references ): string {
		$lines = [
			'# Generated by `gf env --init` from config.json. Never commit this file.',
			'#',
			'# Every variable below is REQUIRED: config.json has no defaults, and a variable',
			'# set to the empty string counts as unset. Re-run `gf env` to check.',
			'',
		];

		$secrets = array_keys( array_filter( $references ) );
		$plain   = array_keys( array_filter( $references, static fn( bool $isSecret ): bool => !$isSecret ) );

		foreach( $plain as $name ) {
			$lines[] = $name . '=';
		}

		if( count( $secrets )>0 ) {
			$lines[] = '';
			$lines[] = '# Secrets. In production these are provisioned as files and read through';
			$lines[] = '# the companion {NAME}_FILE variable instead — set one or the other, never both.';
			foreach( $secrets as $name ) {
				$lines[] = $name . '=';
				$lines[] = '# ' . $name . '_FILE=/run/secrets/' . strtolower( $name );
			}
		}

		return implode( "\n", $lines ) . "\n";
	}


	/** Whether a variable currently has a value, by either the plain or the _FILE name. */
	private function isSet( string $name ): bool {
		foreach( [ $name, $name . \gcgov\framework\services\environment\envVarResolver::SECRET_FILE_SUFFIX ] as $candidate ) {
			if( ( $_ENV[ $candidate ] ?? $_SERVER[ $candidate ] ?? getenv( $candidate ) ?: '' )!=='' ) {
				return true;
			}
		}

		return false;
	}

}
