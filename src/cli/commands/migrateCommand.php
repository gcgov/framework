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

	/**
	 * v6 Framework Service namespaces, and the config.json `services` key each becomes.
	 *
	 * @var array<string, string>
	 */
	public const array SERVICE_NAMESPACES = [
		'gcgov\\framework\\services\\documentation' => 'documentation',
		'gcgov\\framework\\services\\usercrud'      => 'userCrud',
		'gcgov\\framework\\services\\authoauth'     => 'auth:oauth',
		'gcgov\\framework\\services\\authmsfront'   => 'auth:msFront',
		'gcgov\\framework\\services\\cronMonitor'   => 'cronMonitor',
	];

	/**
	 * Service configuration that used to be applied by calling a singleton in
	 * \app\app::_before(). The values are arbitrary PHP expressions, so these are
	 * reported for the developer to transcribe rather than guessed at.
	 *
	 * @var array<string, string>
	 */
	public const array SINGLETON_CALLS = [
		'setBlockNewUsers'          => 'services.auth.blockNewUsers / services.auth.defaultNewUserRoles',
		'setAuthorizeUrlParameters' => 'services.auth.oauth.authorizeUrlParameters',
	];

	/** Framework Service packages that are now part of the framework itself. */
	public const array SERVICE_PACKAGES = [
		'gcgov/framework-service-auth-oauth-server',
		'gcgov/framework-service-auth-ms-front',
		'gcgov/framework-service-user-crud',
		'gcgov/framework-service-documentation',
		'gcgov/framework-service-gcgov-cron-monitor',
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

		$appPhpPath = $context->rootDir . '/app/app.php';
		$detected   = file_exists( $appPhpPath )
			? self::detectServices( (string)file_get_contents( $appPhpPath ) )
			: [ 'services' => [], 'singletons' => [] ];

		$plan = self::plan(
			self::readJson( $appJsonPath ),
			self::readJson( $environmentJsonPath ),
			$detected
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

		$composerPath    = $context->rootDir . '/composer.json';
		$composerRemoved = [];
		$composerJson    = null;
		if( file_exists( $composerPath ) ) {
			$decoded = json_decode( (string)file_get_contents( $composerPath ), true );
			if( is_array( $decoded ) ) {
				[ 'json' => $composerJson, 'removed' => $composerRemoved ] = self::removeServiceRequires( $decoded );
			}
		}
		if( count( $composerRemoved )>0 ) {
			$io->section( 'composer.json' );
			$io->text( 'These are part of the framework now, and conflict with it:' );
			foreach( $composerRemoved as $package ) {
				$io->text( '  - ' . $package );
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

		if( count( $composerRemoved )>0 && is_array( $composerJson ) ) {
			$encodedComposer = json_encode( $composerJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if( $encodedComposer===false || file_put_contents( $composerPath, $encodedComposer . "\n" )===false ) {
				throw new cliException( 'Failed writing ' . $composerPath );
			}
		}

		if( !$input->getOption( 'keep-dead-files' ) ) {
			foreach( $deadFiles as $deadFile ) {
				@unlink( $context->rootDir . '/' . $deadFile );
			}
		}

		$io->success( 'Migrated. Review the diff, then run `gf env` to confirm the configuration resolves.' );
		if( count( $composerRemoved )>0 ) {
			$io->text( 'Run `composer update` — composer.json changed.' );
		}
		$io->text( 'Still to do by hand: the Dockerfile and compose entry, the Zone this application belongs in, and moving its secrets into the ops repository.' );

		return Command::SUCCESS;
	}


	/**
	 * The whole conversion, as a pure function of the two v6 documents — which is what
	 * makes it testable against all thirty applications without touching a filesystem.
	 *
	 * @param  array<string, mixed>  $appJson          Decoded app/config/app.json
	 * @param  array<string, mixed>  $environmentJson  Decoded app/config/environment.json
	 * @param  array{services: string[], singletons: string[]}  $detected  Result of detectServices()
	 *
	 * @return array{config: array<string, mixed>, env: array<string, string>, secrets: array<string, bool>, warnings: string[]}
	 */
	public static function plan( array $appJson, array $environmentJson, array $detected = [ 'services' => [], 'singletons' => [] ] ): array {
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

		// Framework Services: from namespaces returned by \app\app to a config section.
		$services = [];
		foreach( $detected[ 'services' ] ?? [] as $service ) {
			if( $service==='cronMonitor' ) {
				continue;
			}
			if( str_starts_with( $service, 'auth:' ) ) {
				$provider = substr( $service, 5 );
				if( isset( $services[ 'auth' ] ) ) {
					$warnings[] = 'Both authentication services were registered. Only one provider can be active, so "' . $services[ 'auth' ][ 'provider' ] . '" was kept — change services.auth.provider if that is the wrong one.';
					continue;
				}
				$services[ 'auth' ] = [ 'provider' => $provider ];
				continue;
			}
			$services[ $service ] = new \stdClass();
		}
		if( count( $services )>0 ) {
			$config[ 'services' ] = $services;
		}

		// cronMonitor is no longer a Framework Service, so its url gets a typed home of
		// its own rather than living in the untyped appDictionary.
		$cronMonitorUrl = $config[ 'appDictionary' ][ 'cronMonitorUrl' ] ?? null;
		if( is_string( $cronMonitorUrl ) && $cronMonitorUrl!=='' ) {
			$config[ 'cronMonitor' ] = [ 'url' => $cronMonitorUrl ];
			unset( $config[ 'appDictionary' ][ 'cronMonitorUrl' ] );
			$warnings[] = 'appDictionary.cronMonitorUrl moved to cronMonitor.url. Update any application code still reading it from appDictionary.';
		}

		foreach( $detected[ 'singletons' ] ?? [] as $call ) {
			$warnings[] = $call . '() is called in app/app.php. That configuration moved to ' . ( self::SINGLETON_CALLS[ $call ] ?? 'config.json' ) . ' — copy the values across by hand, then delete the call.';
		}

		ksort( $env );

		return [ 'config' => $config, 'env' => $env, 'secrets' => $secrets, 'warnings' => $warnings ];
	}


	public static function reference( string $varName, bool $isSecret ): string {
		return '%env(' . ( $isSecret ? 'secret:' : '' ) . $varName . ')%';
	}


	/**
	 * Find the Framework Services an application registers, and the service-configuration
	 * singletons it calls, by reading app/app.php.
	 *
	 * Comments are stripped with the tokenizer rather than by matching text, because the
	 * scaffolded app.php ships the alternatives commented out directly above the live
	 * array — a plain search would report services the application does not run.
	 *
	 * Impure input, pure function: execute() reads the file, this interprets it.
	 *
	 * @return array{services: string[], singletons: string[]}
	 */
	public static function detectServices( string $appSource ): array {
		$code = '';
		foreach( @token_get_all( $appSource ) as $token ) {
			if( is_array( $token ) ) {
				if( $token[ 0 ]===T_COMMENT || $token[ 0 ]===T_DOC_COMMENT ) {
					continue;
				}
				$code .= $token[ 1 ];
				continue;
			}
			$code .= $token;
		}

		$services = [];
		foreach( self::SERVICE_NAMESPACES as $namespace => $service ) {
			if( str_contains( $code, $namespace ) ) {
				$services[] = $service;
			}
		}

		$singletons = [];
		foreach( array_keys( self::SINGLETON_CALLS ) as $call ) {
			if( str_contains( $code, $call ) ) {
				$singletons[] = $call;
			}
		}

		return [ 'services' => $services, 'singletons' => $singletons ];
	}


	/**
	 * Drop the Framework Service packages from an application's composer.json.
	 *
	 * The framework declares a `conflict` against them, so leaving them in place makes the
	 * application unresolvable rather than merely untidy.
	 *
	 * @param  array<string, mixed>  $composerJson
	 *
	 * @return array{json: array<string, mixed>, removed: string[]}
	 */
	public static function removeServiceRequires( array $composerJson ): array {
		$removed = [];
		foreach( [ 'require', 'require-dev' ] as $section ) {
			if( !isset( $composerJson[ $section ] ) || !is_array( $composerJson[ $section ] ) ) {
				continue;
			}
			foreach( self::SERVICE_PACKAGES as $package ) {
				if( array_key_exists( $package, $composerJson[ $section ] ) ) {
					unset( $composerJson[ $section ][ $package ] );
					$removed[] = $package;
				}
			}
		}

		return [ 'json' => $composerJson, 'removed' => array_values( array_unique( $removed ) ) ];
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
