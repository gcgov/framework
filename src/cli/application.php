<?php

namespace gcgov\framework\cli;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The gf console application. Entry point: bin/gf (installed as vendor/bin/gf).
 *
 * Command discovery never boots the application request lifecycle: `gf list`
 * works in any directory, including outside an application entirely.
 */
final class application extends \Symfony\Component\Console\Application {

	private ?\Throwable $commandDiscoveryError = null;


	public function __construct( ?string $composerAutoloadPath = null ) {
		parent::__construct( 'gf — gcgov/framework CLI', self::resolveVersion() );

		if( $composerAutoloadPath!==null ) {
			appContext::$composerAutoloadPath = $composerAutoloadPath;
		}

		$this->addCommands( [
			                    new commands\cliCommand(),
			                    new commands\cliListCommand(),
			                    new commands\certGenerateAuthCommand(),
			                    new commands\chromeInstallCommand(),
			                    new commands\chromeUpdateCommand(),
			                    new commands\chromeStatusCommand(),
			                    new commands\dbRestoreCommand(),
			                    new commands\dbRunCommand(),
			                    new commands\envCommand(),
			                    new commands\setupCommand(),
			                    new commands\deployCommand(),
			                    new commands\completionPowershellCommand(),
		                    ] );

		$this->discoverProviderCommands();
	}


	/**
	 * Allow space-separated command names: `gf db restore` resolves to `db:restore`
	 * when that joined name exists. The colon form is canonical.
	 */
	public function run( ?InputInterface $input = null, ?OutputInterface $output = null ): int {
		if( $input===null && isset( $_SERVER[ 'argv' ] ) && is_array( $_SERVER[ 'argv' ] ) ) {
			$input = new ArgvInput( $this->normalizeArgv( $_SERVER[ 'argv' ] ) );
		}

		return parent::run( $input, $output );
	}


	/**
	 * @param string[] $argv
	 *
	 * @return string[]
	 */
	public function normalizeArgv( array $argv ): array {
		if( count( $argv )>=3 && str_starts_with( $argv[ 1 ], '-' )===false && str_starts_with( $argv[ 2 ], '-' )===false ) {
			$joined = $argv[ 1 ] . ':' . $argv[ 2 ];
			if( $this->has( $joined ) ) {
				array_splice( $argv, 1, 2, [ $joined ] );
			}
		}

		return $argv;
	}


	public function doRun( InputInterface $input, OutputInterface $output ): int {
		if( $this->commandDiscoveryError!==null && $output->isVerbose() ) {
			$output->writeln( '<comment>gf: app/plugin command discovery skipped: ' . $this->commandDiscoveryError->getMessage() . '</comment>' );
		}

		return parent::doRun( $input, $output );
	}


	/**
	 * Register commands contributed by the app (\app\cli\commandProvider) and by each
	 * framework-service plugin ({serviceNamespace}\cli\commandProvider). Failures never
	 * break gf itself — built-in commands always remain available.
	 */
	private function discoverProviderCommands(): void {
		try {
			$context = appContext::locate();
			if( $context===null || !class_exists( '\app\app' ) ) {
				return;
			}

			$namespaces   = $context->getServiceNamespaces();
			$namespaces[] = '\app';

			foreach( $namespaces as $namespace ) {
				$providerClass = '\\' . trim( $namespace, '\\' ) . '\cli\commandProvider';
				if( class_exists( $providerClass ) && is_a( $providerClass, commandProvider::class, true ) ) {
					$this->addCommands( $providerClass::getCommands() );
				}
			}
		}
		catch( \Throwable $e ) {
			$this->commandDiscoveryError = $e;
		}
	}


	private static function resolveVersion(): string {
		if( class_exists( \Composer\InstalledVersions::class ) && \Composer\InstalledVersions::isInstalled( 'gcgov/framework' ) ) {
			return \Composer\InstalledVersions::getPrettyVersion( 'gcgov/framework' ) ?? 'dev';
		}

		return 'dev';
	}

}
