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
		$this->addOption( 'from', null, InputOption::VALUE_REQUIRED, 'Source environment (resolves the environments.{from} entry of config.json)', 'prod', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'to', null, InputOption::VALUE_REQUIRED, 'Target environment (resolves the environments.{to} entry of config.json). Omit to use the active configuration.', '', envCommand::suggestEnvironments( ... ) );
		$this->addOption( 'db', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Restrict to the named database(s). Repeatable. Default: every database in the source config.' );
		$this->addOption( 'dump-dir', null, InputOption::VALUE_REQUIRED, 'Directory to write the mongodump output to. Default: srv/tmp/mongodump-{timestamp}.' );
		$this->addOption( 'keep-dump', null, InputOption::VALUE_NONE, 'Keep the dump directory after a successful restore' );
		$this->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt' );
		$this->addOption( 'allow-prod', null, InputOption::VALUE_NONE, 'Allow restoring INTO an environment whose type is "prod" (refused otherwise)' );
		$this->setHelp( 'Cross-platform replacement for the per-app restore-live-to-local.ps1: connection strings come from config.json — the active mongoDatabases for the local side, and the environments.{name} entries (environment-prefixed variables like PROD_MONGO_URI, defined in .env) for foreign environments. Validate an environment first with `gf env <name>`. Requires the MongoDB Database Tools (mongodump/mongorestore) on PATH.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$fromVariant = (string)$input->getOption( 'from' );
		$toVariant   = (string)$input->getOption( 'to' );
		if( $fromVariant==='' ) {
			throw new cliException( '--from requires an environment variant name (e.g. --from=prod)' );
		}

		$sourceEnvironment = $context->loadVariantEnvironment( $fromVariant );
		$sourceDatabases   = $sourceEnvironment->mongoDatabases;

		if( $toVariant==='' ) {
			$activeConfig    = $context->loadConfig();
			$targetType      = $activeConfig->type;
			$targetDatabases = $activeConfig->mongoDatabases;
		}
		else {
			$targetEnvironment = $context->loadVariantEnvironment( $toVariant );
			$targetType        = $targetEnvironment->type;
			$targetDatabases   = $targetEnvironment->mongoDatabases;
		}

		// Guard by environment NAME as well as by type: type comes from a committed
		// literal in environments.{name}, but an entry could omit it.
		if( $toVariant==='prod' && !$input->getOption( 'allow-prod' ) ) {
			throw new cliException( 'Refusing to restore into the environment named "prod". Pass --allow-prod if you really mean it.' );
		}
		if( $targetType==='prod' && !$input->getOption( 'allow-prod' ) ) {
			throw new cliException( 'Refusing to restore into an environment with type "prod" (' . $context->describeConfigSource( $toVariant ) . '). Pass --allow-prod if you really mean it.' );
		}

		$pairs = self::pairDatabases( $sourceDatabases, $targetDatabases, $input->getOption( 'db' ) );
		if( count( $pairs[ 'matched' ] )===0 ) {
			throw new cliException( 'No database pairs to restore. Source environment databases: ' . implode( ', ', array_map( fn( mongoDatabase $db ) => $db->database, $sourceDatabases ) ) );
		}

		$identicalPairs = self::findIdenticalPairs( $pairs[ 'matched' ] );
		if( count( $identicalPairs )>0 ) {
			[ $sourceDb ] = $identicalPairs[ 0 ];
			throw new cliException( 'Source and target resolve to the same database (' . $sourceDb->database . ' @ ' . mongoTools::redactUri( $sourceDb->uri ) . '). Check that environments.' . $fromVariant . ' in config.json references its own variables (e.g. ' . strtoupper( $fromVariant ) . '_MONGO_URI) with the right values in .env. Validate with `gf env ' . $fromVariant . '`.' );
		}
		foreach( $pairs[ 'unmatched' ] as $unmatchedName ) {
			$io->warning( 'Source database "' . $unmatchedName . '" has no matching database in the target config — skipped.' );
		}

		// resolve the tools before doing anything
		$mongodumpBinary    = mongoTools::findBinary( 'mongodump' );
		$mongorestoreBinary = mongoTools::findBinary( 'mongorestore' );

		$io->section( 'Restore plan (' . $fromVariant . ' -> ' . ( $toVariant===''?'active configuration':$toVariant ) . ')' );
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
	 * Pairs whose source and target are the SAME database (same normalized uri AND same
	 * database name) — dumping and restoring onto itself is never useful and usually means
	 * an incomplete {variant}.env overlay fell back to the local environment's values.
	 * Same-cluster clones under a different database name stay legal.
	 *
	 * @param array<int, array{0: mongoDatabase, 1: mongoDatabase}> $matchedPairs
	 *
	 * @return array<int, array{0: mongoDatabase, 1: mongoDatabase}>
	 */
	public static function findIdenticalPairs( array $matchedPairs ): array {
		return array_values( array_filter( $matchedPairs, function( array $pair ): bool {
			[ $sourceDb, $targetDb ] = $pair;

			return rtrim( $sourceDb->uri, '/' )===rtrim( $targetDb->uri, '/' ) && $sourceDb->database===$targetDb->database;
		} ) );
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
