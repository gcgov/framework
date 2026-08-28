<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\services\guid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'init', description: 'Bootstrap a freshly scaffolded application: name it, mint its guid, write .env, generate JWT keys, install chrome' )]
final class initCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'title', null, InputOption::VALUE_REQUIRED, 'Human readable application title (e.g. "Timesheet API")' );
		$this->addOption( 'guid', null, InputOption::VALUE_REQUIRED, 'Application guid. Omit to mint one. This is the OAuth client_id, so an application being re-initialised must keep its existing value.' );
		$this->addOption( 'skip-env', null, InputOption::VALUE_NONE, 'Do not write .env' );
		$this->addOption( 'skip-keys', null, InputOption::VALUE_NONE, 'Do not generate JWT signing keys' );
		$this->addOption( 'skip-chrome', null, InputOption::VALUE_NONE, 'Do not download chrome-headless-shell' );
		$this->setHelp( <<<'HELP'
			Run once after scaffolding a project from gcgov/framework-app-template.

			Deliberately non-interactive, so it can run from a scaffolding script, a devcontainer
			postCreateCommand, or CI — which is where project bootstrap belongs. It replaces the
			v6 `gf setup` wizard, whose prompts filled {placeholder} tokens in php.ini and
			web.config files that no longer exist.

			  gf init --title="Timesheet API"

			It writes the title and guid into config.json, writes a .env skeleton from the
			variables config.json references, generates JWT signing keypairs, and installs
			chrome-headless-shell. Everything else about an application's configuration is either
			a committed literal or an environment variable you supply.
			HELP );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::locateScaffold();
		if( $context===null ) {
			throw new cliException( 'gf init must be run from inside a scaffolded application (a directory containing composer.json and an app/ directory).' );
		}

		$io = new SymfonyStyle( $input, $output );
		$io->title( 'gcgov/framework application setup' );
		$io->text( 'Application root: ' . $context->rootDir );

		$this->writeIdentity( $context, $io, (string)( $input->getOption( 'title' ) ?? '' ), (string)( $input->getOption( 'guid' ) ?? '' ) );

		if( !$input->getOption( 'skip-env' ) ) {
			$io->section( '.env' );
			if( file_exists( $context->getEnvFilePath() ) ) {
				$io->text( 'Kept the existing .env. Run `gf env --list` to check it against config.json.' );
			}
			else {
				$contents = ( new envCommand() )->renderEnvFile( $context->configReferences() );
				if( file_put_contents( $context->getEnvFilePath(), $contents )===false ) {
					throw new cliException( 'Failed writing ' . $context->getEnvFilePath() );
				}
				$io->text( 'Wrote ' . $context->getEnvFilePath() . ' — fill in the values.' );
			}
		}

		if( !$input->getOption( 'skip-keys' ) ) {
			$io->section( 'JWT signing keys' );
			$this->runSubCommand( 'cert:generate-auth', [ '--yes' => true ], $output, $io );
		}

		if( !$input->getOption( 'skip-chrome' ) ) {
			$io->section( 'chrome-headless-shell' );
			try {
				( new \gcgov\framework\cli\chromeInstaller( $context->getSrvDir() ) )->install( $io );
			}
			catch( \Throwable $e ) {
				$io->warning( 'chrome-headless-shell was not installed: ' . $e->getMessage() . ' Install it later with `vendor/bin/gf chrome:install`.' );
			}
		}

		$io->success( 'Initialised. Next: fill in .env, then `gf env` to check it resolves.' );

		return Command::SUCCESS;
	}


	/**
	 * Write app.title and app.guid into config.json, touching nothing else.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function writeIdentity( appContext $context, SymfonyStyle $io, string $title, string $guid ): void {
		$configPath = $context->getConfigPath();
		if( !file_exists( $configPath ) ) {
			throw new cliException( 'Missing ' . $configPath . '. Scaffold from gcgov/framework-app-template, which ships one.' );
		}

		$identity = self::applyIdentity( (string)file_get_contents( $configPath ), $title, $guid, $configPath );

		if( file_put_contents( $configPath, $identity[ 'json' ] )===false ) {
			throw new cliException( 'Failed writing ' . $configPath );
		}

		$io->section( 'Identity' );
		$io->text( 'title: ' . $identity[ 'title' ] );
		$io->text( 'guid:  ' . $identity[ 'guid' ] . ( $identity[ 'guidKept' ] ? ' (kept)' : '' ) );
	}


	/**
	 * Stamp the title and guid into a config.json document, returning the rewritten JSON.
	 *
	 * Pure, so the rewrite can be tested directly rather than through the filesystem.
	 *
	 * Decoded as objects rather than associative arrays: json_decode( $raw, true ) maps an
	 * empty JSON object to [], which json_encode writes back as []. In config.json `{}`
	 * carries meaning — `"services": { "userCrud": {} }` is how a Framework Service is
	 * enabled, since presence is what activates it — so the assoc round-trip rewrote every
	 * service block the template declared into an array that no longer hydrates the nullable
	 * service properties, silently disabling userCrud and documentation on the very first
	 * command a new project runs.
	 *
	 * @return array{json: string, title: string, guid: string, guidKept: bool}
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function applyIdentity( string $rawConfigJson, string $title, string $guid, string $sourceDescription = 'config.json' ): array {
		$decoded = json_decode( $rawConfigJson, false );
		if( !$decoded instanceof \stdClass ) {
			throw new cliException( 'Failed to parse ' . $sourceDescription . ': the file is not a valid JSON object.' );
		}

		if( !isset( $decoded->app ) || !$decoded->app instanceof \stdClass ) {
			$decoded->app = new \stdClass();
		}

		$existingGuid = isset( $decoded->app->guid ) ? (string)$decoded->app->guid : '';
		// The guid is the OAuth client_id: minting a new one for an application that already
		// has one invalidates every registered client.
		$decoded->app->guid = $guid!=='' ? $guid : ( $existingGuid!=='' ? $existingGuid : guid::create() );
		if( $title!=='' ) {
			$decoded->app->title = $title;
		}

		$encoded = json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if( $encoded===false ) {
			throw new cliException( 'Failed encoding ' . $sourceDescription );
		}

		return [
			'json'     => $encoded . "\n",
			'title'    => isset( $decoded->app->title ) ? (string)$decoded->app->title : '',
			'guid'     => (string)$decoded->app->guid,
			'guidKept' => $existingGuid!=='' && $existingGuid===$decoded->app->guid,
		];
	}


	/**
	 * @param  array<string, mixed>  $arguments
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function runSubCommand( string $name, array $arguments, OutputInterface $output, SymfonyStyle $io ): void {
		$application = $this->getApplication();
		if( $application===null ) {
			return;
		}

		try {
			$exitCode = $application->find( $name )->run( new \Symfony\Component\Console\Input\ArrayInput( $arguments ), $output );
		}
		catch( \Throwable $e ) {
			$io->warning( $name . ' did not complete: ' . $e->getMessage() . ' Run `vendor/bin/gf ' . $name . '` yourself.' );

			return;
		}

		if( $exitCode!==Command::SUCCESS ) {
			$io->warning( $name . ' exited with code ' . $exitCode . '. Run `vendor/bin/gf ' . $name . '` yourself.' );
		}
	}

}
