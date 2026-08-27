<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The deterministic half of a v6 → v7 migration.
 *
 * What it does is a pure function of two JSON files, which is why it lives in code
 * rather than in a runbook executed thirty times by hand. The parts that need
 * judgement — which Zone an application belongs in, which of its values are really
 * secrets, what its container needs — are not here.
 *
 * The conversion, in one sentence: everything that varies between deployments leaves
 * the committed file and becomes a required `%env(...)` reference, with the v6 values
 * written to a gitignored .env so the developer's machine keeps working.
 */
#[AsCommand( name: 'migrate', description: 'Convert a v6 application to v7: merge app.json + environment.json into config.json and extract their values into .env' )]
final class migrateCommand extends Command {

	/**
	 * v6 environment.json keys that no longer exist in v7, and why.
	 *
	 * @var array<string, string>
	 */
	public const array REMOVED_KEYS = [
		'serverName' => 'nothing read it',
		'cookieUrl'  => 'nothing read it',
		'phpPath'    => 'a property of a developer machine, not of the application — use GF_PHP',
		'baseUrl'    => 'deprecated in v6; derived from rootUrl + basePath',
	];

	/**
	 * Scalar config paths that become environment references, and the variable each uses.
	 * `secret` entries resolve through `%env(secret:NAME)%`, so production can supply them
	 * as provisioned files instead.
	 *
	 * @var array<string, array{var: string, secret: bool}>
	 */
	public const array EXTRACTED = [
		'type'                          => [ 'var' => 'APP_TYPE', 'secret' => false ],
		'rootUrl'                       => [ 'var' => 'APP_ROOT_URL', 'secret' => false ],
		'basePath'                      => [ 'var' => 'APP_BASE_PATH', 'secret' => false ],
		'jwtAuth.redirectAfterLoginUrl'  => [ 'var' => 'APP_REDIRECT_AFTER_LOGIN', 'secret' => false ],
		'jwtAuth.redirectAfterLogoutUrl' => [ 'var' => 'APP_REDIRECT_AFTER_LOGOUT', 'secret' => false ],
		'microsoft.clientId'            => [ 'var' => 'MICROSOFT_CLIENT_ID', 'secret' => false ],
		'microsoft.clientSecret'        => [ 'var' => 'MICROSOFT_CLIENT_SECRET', 'secret' => true ],
		'microsoft.tenant'              => [ 'var' => 'MICROSOFT_TENANT', 'secret' => false ],
		'microsoft.driveId'             => [ 'var' => 'MICROSOFT_DRIVE_ID', 'secret' => false ],
		'payjunction.username'          => [ 'var' => 'PAYJUNCTION_USERNAME', 'secret' => false ],
		'payjunction.password'          => [ 'var' => 'PAYJUNCTION_PASSWORD', 'secret' => true ],
		'payjunction.apiKey'            => [ 'var' => 'PAYJUNCTION_API_KEY', 'secret' => true ],
		'email.SMTPUsername'            => [ 'var' => 'SMTP_USERNAME', 'secret' => false ],
		'email.SMTPPassword'            => [ 'var' => 'SMTP_PASSWORD', 'secret' => true ],
	];

	/** Files that only made sense under IIS or the v6 config layout. */
	public const array DEAD_PATHS = [
		'app/config/app.json',
		'app/config/environment.json',
		'app/cli/local.bat',
		'app/cli/local-debug.bat',
		'app/cli/prod.bat',
		'update-production.ps1',
		'scripts/setup.ps1',
		'scripts/create-jwt-keys.ps1',
		'www/web-local.config',
		'www/web-prod.config',
		'composer-local.json',
		'composer-prod.json',
	];


	protected function configure(): void {
		$this->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Show what would change without writing anything' );
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'Proceed even though config.json already exists (it will be overwritten)' );
		$this->addOption( 'keep-dead-files', null, InputOption::VALUE_NONE, 'Leave the v6 IIS/batch/config files in place' );
		$this->setHelp( <<<'HELP'
			Converts the configuration half of a v6 application to v7. Run it on a clean working
			tree so the result is reviewable as a diff, and read that diff before committing.

			  gf migrate --dry-run     see the plan
			  gf migrate               apply it

			It writes {root}/config.json, writes {root}/.env with the values it extracted, and
			deletes the v6 IIS and batch files. It does NOT write a Dockerfile, choose a Zone,
			or decide what belongs in your secret store — those need judgement, and the
			companion skill covers them.

			Anything it cannot convert safely is reported rather than guessed at.
			HELP );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::locateScaffold();
		if( $context===null ) {
			throw new cliException( 'gf migrate must be run from inside an application (a directory containing composer.json and an app/ directory).' );
		}

		$io     = new SymfonyStyle( $input, $output );
		$dryRun = (bool)$input->getOption( 'dry-run' );

		$appJsonPath         = $context->rootDir . '/app/config/app.json';
		$environmentJsonPath = $context->rootDir . '/app/config/environment.json';
		if( !file_exists( $appJsonPath ) && !file_exists( $environmentJsonPath ) ) {
			throw new cliException( 'Neither app/config/app.json nor app/config/environment.json exists — this does not look like a v6 application. Nothing to migrate.' );
		}

		if( file_exists( $context->getConfigPath() ) && !$input->getOption( 'force' ) && !$dryRun ) {
			throw new cliException( $context->getConfigPath() . ' already exists — this application appears to be migrated. Pass --force to overwrite it.' );
		}

		$plan = self::plan(
			self::readJson( $appJsonPath ),
			self::readJson( $environmentJsonPath )
		);

		$io->title( 'v6 → v7 migration' . ( $dryRun ? ' (dry run)' : '' ) );

		$io->section( 'config.json' );
		$io->text( 'Extracted ' . count( $plan[ 'env' ] ) . ' value(s) into environment references.' );

		$io->section( '.env' );
		foreach( $plan[ 'env' ] as $name => $value ) {
			$io->text( '  ' . $name . '=' . ( $plan[ 'secrets' ][ $name ] ?? false ? '••••••••  (secret — provision as a file in production)' : $value ) );
		}

		if( count( $plan[ 'warnings' ] )>0 ) {
			$io->section( 'Review these yourself' );
			foreach( $plan[ 'warnings' ] as $warning ) {
				$io->text( '  · ' . $warning );
			}
		}

		$deadFiles = self::deadFilesPresent( $context->rootDir );
		if( count( $deadFiles )>0 && !$input->getOption( 'keep-dead-files' ) ) {
			$io->section( 'Deleting' );
			foreach( $deadFiles as $deadFile ) {
				$io->text( '  ' . $deadFile );
			}
		}

		if( $dryRun ) {
			$io->note( 'Dry run — nothing was written.' );

			return Command::SUCCESS;
		}

		$encoded = json_encode( $plan[ 'config' ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if( $encoded===false || file_put_contents( $context->getConfigPath(), $encoded . "\n" )===false ) {
			throw new cliException( 'Failed writing ' . $context->getConfigPath() );
		}

		$this->writeEnvFile( $context->getEnvFilePath(), $plan[ 'env' ], $plan[ 'secrets' ] );

		if( !$input->getOption( 'keep-dead-files' ) ) {
			foreach( $deadFiles as $deadFile ) {
				@unlink( $context->rootDir . '/' . $deadFile );
			}
		}

		$io->success( 'Migrated. Review the diff, then run `gf env` to confirm the configuration resolves.' );
		$io->text( 'Still to do by hand: the Dockerfile and compose entry, the Zone this application belongs in, and moving its secrets into the ops repository.' );

		return Command::SUCCESS;
	}


	/**
	 * The whole conversion, as a pure function of the two v6 documents — which is what
	 * makes it testable against all thirty applications without touching a filesystem.
	 *
	 * @param  array<string, mixed>  $appJson          Decoded app/config/app.json
	 * @param  array<string, mixed>  $environmentJson  Decoded app/config/environment.json
	 *
	 * @return array{config: array<string, mixed>, env: array<string, string>, secrets: array<string, bool>, warnings: string[]}
	 */
	public static function plan( array $appJson, array $environmentJson ): array {
		$config   = $environmentJson;
		$env      = [];
		$secrets  = [];
		$warnings = [];

		// app.json's three sections move in wholesale — they never varied by environment.
		foreach( [ 'app', 'email', 'settings' ] as $section ) {
			if( isset( $appJson[ $section ] ) && is_array( $appJson[ $section ] ) ) {
				$config[ $section ] = array_merge( $config[ $section ] ?? [], $appJson[ $section ] );
			}
		}

		foreach( self::REMOVED_KEYS as $key => $reason ) {
			if( array_key_exists( $key, $config ) ) {
				unset( $config[ $key ] );
				$warnings[] = 'Dropped "' . $key . '" (' . $reason . '). Grep the application for it before committing.';
			}
		}

		foreach( self::EXTRACTED as $path => $extraction ) {
			$value = self::readPath( $config, $path );
			if( $value===null || !is_scalar( $value ) || (string)$value==='' ) {
				continue;
			}
			$env[ $extraction[ 'var' ] ]     = (string)$value;
			$secrets[ $extraction[ 'var' ] ] = $extraction[ 'secret' ];
			self::writePath( $config, $path, self::reference( $extraction[ 'var' ], $extraction[ 'secret' ] ) );
		}

		// Mongo connections: one variable pair per database, suffixed past the first so
		// an application with several keeps them distinct.
		$databases = $config[ 'mongoDatabases' ] ?? [];
		if( is_array( $databases ) ) {
			foreach( array_values( $databases ) as $index => $database ) {
				if( !is_array( $database ) ) {
					continue;
				}
				$suffix = $index===0 ? '' : '_' . ( $index + 1 );
				foreach( [ 'uri' => [ 'MONGO_URI' . $suffix, true ], 'database' => [ 'MONGO_DATABASE' . $suffix, false ] ] as $key => [ $varName, $isSecret ] ) {
					if( !isset( $database[ $key ] ) || !is_scalar( $database[ $key ] ) || (string)$database[ $key ]==='' ) {
						continue;
					}
					$env[ $varName ]                             = (string)$database[ $key ];
					$secrets[ $varName ]                         = $isSecret;
					$config[ 'mongoDatabases' ][ $index ][ $key ] = self::reference( $varName, $isSecret );
				}
			}
		}

		if( isset( $config[ 'sqlDatabases' ] ) && is_array( $config[ 'sqlDatabases' ] ) && count( $config[ 'sqlDatabases' ] )>0 ) {
			$warnings[] = 'sqlDatabases was left as-is: its read/write accounts hold credentials that must become %env(secret:...) references and move to the ops repository. Convert them by hand.';
		}

		// Logging: v6 had no destination and always wrote files. Say so explicitly rather
		// than letting an IIS application silently change behaviour on upgrade.
		$config[ 'logging' ]                  = is_array( $config[ 'logging' ] ?? null ) ? $config[ 'logging' ] : [];
		$config[ 'logging' ][ 'destination' ] = 'file';
		$warnings[]                           = 'logging.destination was set to "file" to preserve v6 behaviour. Change it to "stderr" when this application moves into a container — a container filesystem does not survive a deploy.';

		if( ( $config[ 'app' ][ 'guid' ] ?? '' )==='' ) {
			$warnings[] = 'app.guid is empty. The oauth server uses it as the OAuth client_id, so set it before deploying.';
		}

		ksort( $env );

		return [ 'config' => $config, 'env' => $env, 'secrets' => $secrets, 'warnings' => $warnings ];
	}


	public static function reference( string $varName, bool $isSecret ): string {
		return '%env(' . ( $isSecret ? 'secret:' : '' ) . $varName . ')%';
	}


	/**
	 * @return string[]  Dead v6 files that actually exist, relative to the root
	 */
	public static function deadFilesPresent( string $rootDir ): array {
		$present = [];
		foreach( self::DEAD_PATHS as $path ) {
			if( file_exists( $rootDir . '/' . $path ) ) {
				$present[] = $path;
			}
		}
		// Every environment-{variant}.json, whatever the variant is called.
		foreach( glob( $rootDir . '/app/config/environment-*.json' ) ?: [] as $variantFile ) {
			$present[] = 'app/config/' . basename( $variantFile );
		}

		return $present;
	}


	/**
	 * @param  array<string, mixed>  $data
	 *
	 * @return mixed
	 */
	private static function readPath( array $data, string $path ): mixed {
		$cursor = $data;
		foreach( explode( '.', $path ) as $segment ) {
			if( !is_array( $cursor ) || !array_key_exists( $segment, $cursor ) ) {
				return null;
			}
			$cursor = $cursor[ $segment ];
		}

		return $cursor;
	}


	/**
	 * @param  array<string, mixed>  $data
	 */
	private static function writePath( array &$data, string $path, string $value ): void {
		$segments = explode( '.', $path );
		$cursor   = &$data;
		foreach( $segments as $index => $segment ) {
			if( $index===count( $segments ) - 1 ) {
				$cursor[ $segment ] = $value;
				break;
			}
			if( !isset( $cursor[ $segment ] ) || !is_array( $cursor[ $segment ] ) ) {
				return;
			}
			$cursor = &$cursor[ $segment ];
		}
	}


	/**
	 * @return array<string, mixed>
	 * @throws \gcgov\framework\cli\cliException
	 */
	private static function readJson( string $path ): array {
		if( !file_exists( $path ) ) {
			return [];
		}
		$decoded = json_decode( (string)file_get_contents( $path ), true );
		if( !is_array( $decoded ) ) {
			throw new cliException( 'Failed to parse ' . $path . ': the file is not a valid JSON object.' );
		}

		return $decoded;
	}


	/**
	 * @param  array<string, string>  $env
	 * @param  array<string, bool>    $secrets
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function writeEnvFile( string $path, array $env, array $secrets ): void {
		$lines = [
			'# Written by `gf migrate` from the v6 configuration. Never commit this file.',
			'#',
			'# Values marked as secrets below are provisioned as files in production and read',
			'# through the companion {NAME}_FILE variable — see the ops repository.',
			'',
		];
		foreach( $env as $name => $value ) {
			if( $secrets[ $name ] ?? false ) {
				$lines[] = '# secret';
			}
			$lines[] = $name . '=' . $value;
		}

		if( file_exists( $path ) ) {
			$lines[] = '';
			$lines[] = '# --- appended by gf migrate; the pre-existing contents are above ---';
			$existing = (string)file_get_contents( $path );
			if( file_put_contents( $path, $existing . "\n" . implode( "\n", $lines ) . "\n" )===false ) {
				throw new cliException( 'Failed appending to ' . $path );
			}

			return;
		}

		if( file_put_contents( $path, implode( "\n", $lines ) . "\n" )===false ) {
			throw new cliException( 'Failed writing ' . $path );
		}
	}

}
