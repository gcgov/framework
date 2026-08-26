<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\mongoTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'env', description: 'List config.json environments and validate that %env(...) references resolve (active config, or an environments.{name} entry)' )]
final class envCommand extends Command {

	protected function configure(): void {
		$this->addArgument( 'environment', InputArgument::OPTIONAL, 'environments.{name} entry of config.json to validate. Omit to list environments and check the active configuration.', null, self::suggestEnvironments( ... ) );
		$this->setHelp( 'Environment selection is environment-variable driven: the root config.json references variables with %env(...), and the process environment / {root}/.env supplies the values. This command validates that resolution. `gf env <name>` resolves the environments.{name} entry of config.json — the per-environment connection info used by db:restore/db:run, referencing environment-prefixed variables (e.g. PROD_MONGO_URI in .env) — and fails naming the first unresolvable variable.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$environment = (string)( $input->getArgument( 'environment' ) ?? '' );

		if( $environment==='' ) {
			$variants = $context->getEnvironmentVariants();
			$io->text( count( $variants )===0
				? 'No environments section in config.json (define environments.{name} with type + mongoDatabases to enable gf db:restore/db:run against other environments).'
				: 'Environments defined in config.json: ' . implode( ', ', $variants ) );

			$io->section( 'Active configuration (config.json + ambient environment)' );

			return $this->validateActive( $context, $io );
		}

		$io->section( 'Environment "' . $environment . '" (' . $context->describeConfigSource( $environment ) . ')' );

		return $this->validateVariant( $context, $environment, $io );
	}


	private function validateActive( appContext $context, SymfonyStyle $io ): int {
		try {
			$unifiedConfig = $context->loadConfig();
		}
		catch( cliException $e ) {
			$io->error( $e->getMessage() );

			return Command::FAILURE;
		}

		$io->text( 'type: ' . $unifiedConfig->type );
		if( $unifiedConfig->serverName!=='' ) {
			$io->text( 'serverName: ' . $unifiedConfig->serverName );
		}
		if( $unifiedConfig->rootUrl!=='' ) {
			$io->text( 'rootUrl: ' . $unifiedConfig->rootUrl . '  basePath: ' . $unifiedConfig->getBasePath() );
		}
		foreach( $unifiedConfig->mongoDatabases as $mongoDatabase ) {
			$io->text( 'mongo: ' . $mongoDatabase->database . ' @ ' . mongoTools::redactUri( $mongoDatabase->uri ) . ( $mongoDatabase->default ? ' (default)' : '' ) );
		}

		$io->success( 'Resolved successfully — every %env(...) reference has a value.' );

		return Command::SUCCESS;
	}


	private function validateVariant( appContext $context, string $environment, SymfonyStyle $io ): int {
		try {
			$variantEnvironment = $context->loadVariantEnvironment( $environment );
		}
		catch( cliException $e ) {
			$io->error( $e->getMessage() );

			return Command::FAILURE;
		}

		$io->text( 'type: ' . $variantEnvironment->type );
		foreach( $variantEnvironment->mongoDatabases as $mongoDatabase ) {
			$io->text( 'mongo: ' . $mongoDatabase->database . ' @ ' . mongoTools::redactUri( $mongoDatabase->uri ) . ( $mongoDatabase->default ? ' (default)' : '' ) );
		}
		if( $variantEnvironment->type==='' ) {
			$io->warning( 'environments.' . $environment . ' has no "type" — set it to a committed literal (e.g. "prod"); the db:restore prod guard relies on it.' );
		}

		$io->success( 'Resolved successfully — every %env(...) reference has a value.' );

		return Command::SUCCESS;
	}


	/**
	 * @return string[]
	 */
	public static function suggestEnvironments( CompletionInput $completionInput ): array {
		try {
			$context = appContext::locate();

			return $context===null ? [] : $context->getEnvironmentVariants();
		}
		catch( \Throwable ) {
			return [];
		}
	}

}
