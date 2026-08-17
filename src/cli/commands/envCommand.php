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

#[AsCommand( name: 'env', description: 'List environment variants and validate that a variant\'s app/config/{name}.env overlay fully resolves app/config/environment.json' )]
final class envCommand extends Command {

	protected function configure(): void {
		$this->addArgument( 'environment', InputArgument::OPTIONAL, 'Variant to validate (app/config/{name}.env). Omit to list variants and check the active environment.', null, self::suggestEnvironments( ... ) );
		$this->setHelp( 'Environment selection is environment-variable driven: app/config/environment.json references variables with %env(...), and the process environment / {root}/.env supplies the values. This command validates that resolution. `gf env <name>` resolves environment.json with the app/config/{name}.env overlay applied — use it to prove an overlay (e.g. prod.env, used by db:restore/db:run) defines every variable it needs before relying on it.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$environment = (string)( $input->getArgument( 'environment' ) ?? '' );

		if( $environment==='' ) {
			$variants = $context->getEnvironmentVariants();
			$io->text( count( $variants )===0
				? 'No variant overlay files found in app/config (create app/config/{name}.env — see prod.env.example in the app template).'
				: 'Variant overlay files in app/config: ' . implode( ', ', array_map( fn( string $v ) => $v . '.env', $variants ) ) );

			$io->section( 'Active environment (app/config/environment.json + ambient environment)' );

			return $this->validate( $context, '', $io );
		}

		$io->section( 'Variant "' . $environment . '" (' . $context->describeEnvironmentConfigSource( $environment ) . ')' );

		return $this->validate( $context, $environment, $io );
	}


	private function validate( appContext $context, string $variant, SymfonyStyle $io ): int {
		try {
			$environmentConfig = $context->loadEnvironmentConfig( $variant );
		}
		catch( cliException $e ) {
			$io->error( $e->getMessage() );

			return Command::FAILURE;
		}

		$io->text( 'type: ' . $environmentConfig->type );
		if( $environmentConfig->serverName!=='' ) {
			$io->text( 'serverName: ' . $environmentConfig->serverName );
		}
		if( $environmentConfig->rootUrl!=='' ) {
			$io->text( 'rootUrl: ' . $environmentConfig->rootUrl . '  basePath: ' . $environmentConfig->getBasePath() );
		}
		foreach( $environmentConfig->mongoDatabases as $mongoDatabase ) {
			$io->text( 'mongo: ' . $mongoDatabase->database . ' @ ' . mongoTools::redactUri( $mongoDatabase->uri ) . ( $mongoDatabase->default ? ' (default)' : '' ) );
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
