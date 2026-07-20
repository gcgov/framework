<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\mongoTools;
use gcgov\framework\models\config\environment\mongoDatabase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

#[AsCommand( name: 'db:restore', description: 'Dump the mongo databases of a source environment and restore them (--drop) into a target environment' )]
final class dbRestoreCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'from', null, InputOption::VALUE_REQUIRED, 'Source environment variant (reads app/config/environment-{from}.json)', 'prod', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'to', null, InputOption::VALUE_REQUIRED, 'Target environment variant. Omit to use the active app/config/environment.json.', '', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'db', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to the named database(s). Repeatable. Default: every database in the source config.' );
		$this->addOption( 'dump-dir', null, InputOption::VALUE_REQUIRED, 'Directory to write the mongodump output to. Default: srv/tmp/mongodump-{timestamp}.' );
		$this->addOption( 'keep-dump', null, InputOption::VALUE_NONE, 'Keep the dump directory after a successful restore' );
		$this->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt' );
		$this->addOption( 'allow-prod', null, InputOption::VALUE_NONE, 'Allow restoring INTO an environment whose type is "prod" (refused otherwise)' );
		$this->setHelp( 'Cross-platform replacement for the per-app restore-live-to-local.ps1: connection strings come from the environment variant config files instead of being hardcoded. Requires the MongoDB Database Tools (mongodump/mongorestore) on PATH.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$fromVariant = (string)$input->getOption( 'from' );
		$toVariant   = (string)$input->getOption( 'to' );
		if( $fromVariant==='' ) {
			throw new cliException( '--from requires an environment variant name (e.g. --from=prod)' );
		}

		$sourceConfig = $context->loadEnvironmentConfig( $fromVariant );
		$targetConfig = $context->loadEnvironmentConfig( $toVariant );

		if( $targetConfig->type==='prod' && !$input->getOption( 'allow-prod' ) ) {
			throw new cliException( 'Refusing to restore into an environment with type "prod" (' . $context->getEnvironmentConfigPath( $toVariant ) . '). Pass --allow-prod if you really mean it.' );
		}

		$pairs = self::pairDatabases( $sourceConfig->mongoDatabases, $targetConfig->mongoDatabases, $input->getOption( 'db' ) );
		if( count( $pairs[ 'matched' ] )===0 ) {
			throw new cliException( 'No database pairs to restore. Source config databases: ' . implode( ', ', array_map( fn( mongoDatabase $db ) => $db->database, $sourceConfig->mongoDatabases ) ) );
		}
		foreach( $pairs[ 'unmatched' ] as $unmatchedName ) {
			$io->warning( 'Source database "' . $unmatchedName . '" has no matching database in the target config — skipped.' );
		}

		// resolve the tools before doing anything
		$mongodumpBinary    = mongoTools::findBinary( 'mongodump' );
		$mongorestoreBinary = mongoTools::findBinary( 'mongorestore' );

		$io->section( 'Restore plan (' . $fromVariant . ' -> ' . ( $toVariant===''?'active environment.json':$toVariant ) . ')' );
		foreach( $pairs[ 'matched' ] as [ $sourceDb, $targetDb ] ) {
			$io->text( '  ' . $sourceDb->database . ' @ ' . mongoTools::redactUri( $sourceDb->uri ) . '  ->  ' . $targetDb->database . ' @ ' . mongoTools::redactUri( $targetDb->uri ) . '  (--drop)' );
		}

		if( !$input->getOption( 'yes' ) && !$io->confirm( 'The target database(s) will be DROPPED and replaced. Continue?', false ) ) {
			$io->text( 'Aborted. No changes made.' );

			return Command::FAILURE;
		}

		$dumpDir = (string)( $input->getOption( 'dump-dir' ) ?? '' );
		if( $dumpDir==='' ) {
			$dumpDir = $context->getSrvDir() . '/tmp/mongodump-' . date( 'Ymd-His' );
		}
		if( !is_dir( $dumpDir ) && !mkdir( $dumpDir, 0775, true ) ) {
			throw new cliException( 'Failed to create dump directory ' . $dumpDir );
		}

		foreach( $pairs[ 'matched' ] as [ $sourceDb, $targetDb ] ) {
			$io->section( 'Dumping ' . $sourceDb->database );
			$exitCode = $this->stream( new Process( self::buildDumpCommand( $mongodumpBinary, $sourceDb, $dumpDir ), $context->rootDir, null, null, null ), $output );
			if( $exitCode!==0 ) {
				throw new cliException( 'mongodump exited with code ' . $exitCode . ' — aborting before restore. Dump directory: ' . $dumpDir );
			}

			$io->section( 'Restoring into ' . $targetDb->database );
			$exitCode = $this->stream( new Process( self::buildRestoreCommand( $mongorestoreBinary, $sourceDb, $targetDb, $dumpDir ), $context->rootDir, null, null, null ), $output );
			if( $exitCode!==0 ) {
				throw new cliException( 'mongorestore exited with code ' . $exitCode . '. Dump directory kept for inspection: ' . $dumpDir );
			}
		}

		if( $input->getOption( 'keep-dump' ) ) {
			$io->text( 'Dump kept at ' . $dumpDir );
		}
		else {
			self::deleteDirectory( $dumpDir );
		}

		$io->success( 'Restore complete.' );

		return Command::SUCCESS;
	}


	/**
	 * Pair source databases with target databases by database name; when the source or
	 * target has exactly one default database and no name match exists, fall back to
	 * pairing the two default databases.
	 *
	 * @param mongoDatabase[] $sourceDatabases
	 * @param mongoDatabase[] $targetDatabases
	 * @param string[]        $onlyDatabases
	 *
	 * @return array{matched: array<int, array{0: mongoDatabase, 1: mongoDatabase}>, unmatched: string[]}
	 */
	public static function pairDatabases( array $sourceDatabases, array $targetDatabases, array $onlyDatabases = [] ): array {
		$matched   = [];
		$unmatched = [];

		$targetsByName = [];
		foreach( $targetDatabases as $targetDb ) {
			$targetsByName[ $targetDb->database ] = $targetDb;
		}
		$defaultTarget = null;
		foreach( $targetDatabases as $targetDb ) {
			if( $targetDb->default ) {
				$defaultTarget = $targetDb;
				break;
			}
		}

		foreach( $sourceDatabases as $sourceDb ) {
			if( count( $onlyDatabases )>0 && !in_array( $sourceDb->database, $onlyDatabases, true ) ) {
				continue;
			}

			if( isset( $targetsByName[ $sourceDb->database ] ) ) {
				$matched[] = [ $sourceDb, $targetsByName[ $sourceDb->database ] ];
			}
			elseif( $sourceDb->default && $defaultTarget!==null ) {
				$matched[] = [ $sourceDb, $defaultTarget ];
			}
			else {
				$unmatched[] = $sourceDb->database;
			}
		}

		return [ 'matched' => $matched, 'unmatched' => $unmatched ];
	}


	/**
	 * @return string[]
	 */
	public static function buildDumpCommand( string $mongodumpBinary, mongoDatabase $sourceDb, string $dumpDir ): array {
		$command = [ $mongodumpBinary, '--uri=' . $sourceDb->uri ];
		if( $sourceDb->database!=='' ) {
			$command[] = '--db=' . $sourceDb->database;
		}
		$command[] = '--out=' . $dumpDir;

		return $command;
	}


	/**
	 * @return string[]
	 */
	public static function buildRestoreCommand( string $mongorestoreBinary, mongoDatabase $sourceDb, mongoDatabase $targetDb, string $dumpDir ): array {
		$command = [ $mongorestoreBinary, '--uri=' . $targetDb->uri, '--drop' ];
		if( $sourceDb->database!=='' && $targetDb->database!=='' && $sourceDb->database!==$targetDb->database ) {
			$command[] = '--nsFrom=' . $sourceDb->database . '.*';
			$command[] = '--nsTo=' . $targetDb->database . '.*';
		}
		$command[] = $dumpDir . '/' . $sourceDb->database;

		return $command;
	}


	private function stream( Process $process, OutputInterface $output ): int {
		$process->setTimeout( null );
		$process->run( function( string $type, string $buffer ) use ( $output ): void {
			$output->write( $buffer );
		} );

		return $process->getExitCode() ?? 1;
	}


	private static function deleteDirectory( string $directory ): void {
		if( !is_dir( $directory ) ) {
			return;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $directory, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $directory );
	}

}
