<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\services\chrome\chromeInstallation;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'chrome:status', description: 'Show whether chrome-headless-shell is installed, its version, and the executable path' )]
final class chromeStatusCommand extends Command {

	private readonly ClientInterface $httpClient;


	public function __construct( ?ClientInterface $httpClient = null ) {
		parent::__construct();
		$this->httpClient = $httpClient ?? new Client();
	}


	protected function configure(): void {
		$this->addOption( 'check-latest', null, InputOption::VALUE_NONE, 'Also fetch the Chrome for Testing feed and report whether a newer Stable version is available' );
		$this->setHelp( 'Exit code 0 when a working installation exists, 1 otherwise — usable as a scripted check (`gf chrome:status && ...`). An outdated-but-working installation still exits 0; pair with `gf chrome:update` to refresh. Without --check-latest this command never touches the network.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		$srvDir         = $context->getSrvDir();
		$manifest       = chromeInstallation::readManifest( $srvDir );
		$executablePath = chromeInstallation::getExecutablePath( $srvDir );

		if( $executablePath===null ) {
			if( $manifest!==null ) {
				$io->error( 'chrome-headless-shell installation is broken: the manifest lists version ' . $manifest[ 'version' ] . ' but its executable is missing. Run `gf chrome:install --force` to reinstall.' );
			}
			else {
				$io->warning( 'chrome-headless-shell is not installed. Run `gf chrome:install` to download it.' );
			}

			return Command::FAILURE;
		}

		// derive the active version: prefer the manifest; fall back to the version segment
		// of the scanned executable path (first path segment below srv/chrome/)
		$chromeDir     = chromeInstallation::chromeDir( $srvDir );
		$relativePath  = ltrim( substr( str_replace( '\\', '/', $executablePath ), strlen( $chromeDir ) ), '/' );
		$activeVersion = explode( '/', $relativePath )[ 0 ];

		$io->text( 'chrome-headless-shell is installed' );
		$rows = [
			[ 'Version', $activeVersion ],
			[ 'Platform', $manifest[ 'platform' ] ?? chromeInstallation::detectCurrentPlatform() ],
			[ 'Executable', $executablePath ],
		];
		if( $manifest!==null && $manifest[ 'installedAt' ]!=='' ) {
			$rows[] = [ 'Installed at', $manifest[ 'installedAt' ] ];
		}
		$io->definitionList( ...array_map( fn( array $row ) => [ $row[ 0 ] => $row[ 1 ] ], $rows ) );

		if( $manifest===null ) {
			$io->note( 'installation.json manifest is missing (resolved by directory scan). Running `gf chrome:install` will rewrite it.' );
		}

		$otherVersions = array_values( array_diff( chromeInstallation::installedVersions( $srvDir ), [ $activeVersion ] ) );
		if( count( $otherVersions )>0 ) {
			$io->text( 'Other installed version(s): ' . implode( ', ', $otherVersions ) . ' — run `gf chrome:update` to remove old versions.' );
		}

		if( $input->getOption( 'check-latest' ) ) {
			$this->reportLatest( $io, $activeVersion );
		}

		return Command::SUCCESS;
	}


	private function reportLatest( SymfonyStyle $io, string $activeVersion ): void {
		try {
			$versionsJson = (string)$this->httpClient->request( 'GET', chromeInstallation::VERSIONS_URL, [
				'connect_timeout' => 15,
				'timeout'         => 30,
			] )->getBody();

			$download = chromeInstallation::selectDownload( $versionsJson, chromeInstallation::detectCurrentPlatform() );

			if( version_compare( $activeVersion, $download[ 'version' ], '>=' ) ) {
				$io->text( 'Latest Stable: ' . $download[ 'version' ] . ' — up to date.' );
			}
			else {
				$io->text( 'Latest Stable: ' . $download[ 'version' ] . ' — <comment>update available</comment>, run `gf chrome:update`.' );
			}
		}
		catch( \Throwable $e ) {
			$io->warning( 'Could not check the latest version: ' . $e->getMessage() );
		}
	}

}
