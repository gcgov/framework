<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\mongoTools;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand( name: 'db:run', description: 'Run a mongosh script against an environment\'s mongo database (connection info from config, not hardcoded)' )]
final class dbRunCommand extends Command {

	protected function configure(): void {
		$this->addArgument( 'script', InputArgument::REQUIRED, 'Path to the .js script to execute with mongosh' );
		$this->addArgument( 'mongoshArgs', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Extra arguments passed through to mongosh (prefix with --)' );
		$this->addOption( 'env', null, InputOption::VALUE_REQUIRED, 'Environment variant to read the connection from (reads app/config/environment-{env}.json). Omit to use the active environment.json.', '', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'db', null, InputOption::VALUE_REQUIRED, 'Database name from the mongoDatabases config to run against. Default: the entry marked default (or the only entry).' );
		$this->setHelp( 'Replaces per-script mongosh invocations with hardcoded connection strings, e.g.: gf db:run db/create-admin.js --env=local. Everything after -- is forwarded to mongosh.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();

		$scriptPath = (string)$input->getArgument( 'script' );
		if( !file_exists( $scriptPath ) ) {
			throw new cliException( 'Script not found: ' . $scriptPath );
		}

		$environmentConfig = $context->loadEnvironmentConfig( (string)$input->getOption( 'env' ) );

		$databaseName = (string)( $input->getOption( 'db' ) ?? '' );
		$mongoDatabase = null;
		foreach( $environmentConfig->mongoDatabases as $candidate ) {
			if( $databaseName!=='' ? $candidate->database===$databaseName : ( $candidate->default || count( $environmentConfig->mongoDatabases )===1 ) ) {
				$mongoDatabase = $candidate;
				break;
			}
		}
		if( $mongoDatabase===null ) {
			$available = implode( ', ', array_map( fn( $db ) => $db->database, $environmentConfig->mongoDatabases ) );
			throw new cliException( $databaseName==='' ? 'No default mongo database found in the environment config. Available: ' . $available . '. Choose one with --db.' : 'No mongo database named "' . $databaseName . '" in the environment config. Available: ' . $available );
		}

		$mongoshBinary = mongoTools::findBinary( 'mongosh' );

		$commandLine   = [ $mongoshBinary, mongoTools::uriWithDatabase( $mongoDatabase->uri, $mongoDatabase->database ), '--file', $scriptPath ];
		$commandLine   = array_merge( $commandLine, $input->getArgument( 'mongoshArgs' ) );

		if( $output->isVerbose() ) {
			$output->writeln( '<comment>Running mongosh against ' . mongoTools::redactUri( $mongoDatabase->uri ) . ' (database ' . $mongoDatabase->database . ')</comment>' );
		}

		$process = new Process( $commandLine, $context->rootDir, null, null, null );
		$process->run( function( string $type, string $buffer ) use ( $output ): void {
			$output->write( $buffer );
		} );

		return $process->getExitCode() ?? Command::FAILURE;
	}

}
