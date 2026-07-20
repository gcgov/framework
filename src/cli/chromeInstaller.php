<?php

namespace gcgov\framework\cli;

use gcgov\framework\exceptions\serviceException;
use gcgov\framework\services\chrome\chromeInstallation;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads and installs chrome-headless-shell (Chrome for Testing, Stable channel)
 * into {srvDir}/chrome. Orchestration only — all path/manifest/platform logic lives in
 * \gcgov\framework\services\chrome\chromeInstallation.
 *
 * Install is atomic: the zip downloads to a pid-suffixed temp file, extracts to a
 * pid-suffixed temp directory, and the version directory appears via a single rename().
 * The manifest is written last (the commit point) — an interrupted install leaves only
 * tmp-* artifacts, which the next install/update sweeps.
 */
final class chromeInstaller {

	private readonly string $srvDir;
	private readonly ClientInterface $httpClient;


	public function __construct( string $srvDir, ?ClientInterface $httpClient = null ) {
		$this->srvDir     = $srvDir;
		$this->httpClient = $httpClient ?? new Client();
	}


	/**
	 * Install (or update) chrome-headless-shell.
	 *
	 * @param bool $force Reinstall even when the current stable version is already installed
	 * @param bool $prune Remove other installed versions (and stale temp artifacts) afterwards
	 *
	 * @return string The version that is now current
	 * @throws \gcgov\framework\cli\cliException
	 */
	public function install( SymfonyStyle $io, bool $force = false, bool $prune = false ): string {
		if( !extension_loaded( 'zip' ) ) {
			throw new cliException( 'The PHP zip extension is required to extract the chrome-headless-shell download but is not loaded. Enable extension=zip in php.ini (Windows) or install php-zip (Linux) and retry.' );
		}

		try {
			$platform = chromeInstallation::detectCurrentPlatform();
		}
		catch( serviceException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}

		$chromeDir = chromeInstallation::chromeDir( $this->srvDir );

		// short-circuit without touching the network: plain `chrome:install` on a working installation
		if( !$force && !$prune ) {
			$existingManifest   = chromeInstallation::readManifest( $this->srvDir );
			$existingExecutable = chromeInstallation::getExecutablePath( $this->srvDir );
			if( $existingManifest!==null && $existingExecutable!==null ) {
				$io->text( 'chrome-headless-shell ' . $existingManifest[ 'version' ] . ' is already installed: ' . $existingExecutable );
				$io->text( 'Run `gf chrome:update` to check for a newer version.' );

				return $existingManifest[ 'version' ];
			}
		}

		$io->text( 'Checking the current Chrome for Testing stable version (' . $platform . ')...' );
		try {
			$versionsJson = (string)$this->httpClient->request( 'GET', chromeInstallation::VERSIONS_URL, [
				'connect_timeout' => 15,
				'timeout'         => 30,
			] )->getBody();
		}
		catch( \Throwable $e ) {
			throw new cliException( 'Could not fetch the Chrome for Testing version feed (' . chromeInstallation::VERSIONS_URL . '): ' . $e->getMessage(), 0, $e );
		}

		try {
			$download = chromeInstallation::selectDownload( $versionsJson, $platform );
		}
		catch( serviceException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}

		$version           = $download[ 'version' ];
		$versionDir        = $chromeDir . '/' . $version;
		$expectedRelative  = chromeInstallation::executableRelativePath( $version, $platform );
		$expectedExecutable = $chromeDir . '/' . $expectedRelative;

		if( !$force && file_exists( $expectedExecutable ) ) {
			$io->text( 'chrome-headless-shell ' . $version . ' (current stable) is already installed.' );
			chromeInstallation::writeManifest( $this->srvDir, $version, $platform );
		}
		else {
			$this->downloadAndExtract( $io, $download[ 'url' ], $version, $platform, $force );
		}

		if( $prune ) {
			$removedVersions = chromeInstallation::pruneOldVersions( $this->srvDir, $version );
			if( count( $removedVersions )>0 ) {
				$io->text( 'Removed old version(s): ' . implode( ', ', $removedVersions ) );
			}
		}

		$io->success( 'chrome-headless-shell ' . $version . ' ready: ' . $expectedExecutable );

		return $version;
	}


	/**
	 * @throws \gcgov\framework\cli\cliException
	 */
	private function downloadAndExtract( SymfonyStyle $io, string $url, string $version, string $platform, bool $force ): void {
		try {
			chromeInstallation::ensureChromeDir( $this->srvDir );
		}
		catch( serviceException $e ) {
			throw new cliException( $e->getMessage(), 0, $e );
		}

		$chromeDir      = chromeInstallation::chromeDir( $this->srvDir );
		$versionDir     = $chromeDir . '/' . $version;
		$tempZipPath    = $chromeDir . '/tmp-download-' . getmypid() . '.zip';
		$tempExtractDir = $chromeDir . '/tmp-extract-' . getmypid();

		try {
			$io->text( 'Downloading ' . $url );
			$progressBar = null;
			try {
				$this->httpClient->request( 'GET', $url, [
					'sink'            => $tempZipPath,
					'connect_timeout' => 15,
					'timeout'         => 900,
					'progress'        => function( $totalBytes, $downloadedBytes ) use ( $io, &$progressBar ): void {
						if( $totalBytes>0 && $progressBar===null ) {
							$progressBar = $io->createProgressBar( $totalBytes );
						}
						$progressBar?->setProgress( $downloadedBytes );
					},
				] );
			}
			catch( \Throwable $e ) {
				throw new cliException( 'Download failed: ' . $e->getMessage(), 0, $e );
			}
			finally {
				$progressBar?->finish();
				$io->newLine();
			}

			if( !file_exists( $tempZipPath ) || (int)filesize( $tempZipPath )===0 ) {
				throw new cliException( 'Download produced an empty file — aborting.' );
			}

			$io->text( 'Extracting...' );
			$zipArchive = new \ZipArchive();
			if( $zipArchive->open( $tempZipPath )!==true ) {
				throw new cliException( 'The downloaded file is not a valid zip archive (' . $tempZipPath . ').' );
			}
			if( !$zipArchive->extractTo( $tempExtractDir ) ) {
				$zipArchive->close();
				throw new cliException( 'Failed to extract the chrome-headless-shell archive to ' . $tempExtractDir );
			}
			$zipArchive->close();

			// the zip contains a single top-level dir: chrome-headless-shell-{platform}/
			$binaryName         = str_starts_with( $platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
			$extractedBinaryPath = $tempExtractDir . '/chrome-headless-shell-' . $platform . '/' . $binaryName;
			if( !file_exists( $extractedBinaryPath ) ) {
				throw new cliException( 'The downloaded archive did not contain the expected executable (chrome-headless-shell-' . $platform . '/' . $binaryName . ') — aborting.' );
			}

			if( PHP_OS_FAMILY!=='Windows' ) {
				chmod( $extractedBinaryPath, 0755 );
			}

			// atomic move into place
			if( is_dir( $versionDir ) ) {
				chromeInstallation::deleteRecursively( $versionDir );
			}
			if( !rename( $tempExtractDir, $versionDir ) ) {
				// a concurrent install may have won the race — that's fine if its result is valid
				if( !file_exists( $chromeDir . '/' . chromeInstallation::executableRelativePath( $version, $platform ) ) ) {
					throw new cliException( 'Failed to move the extracted files into ' . $versionDir );
				}
			}

			try {
				chromeInstallation::writeManifest( $this->srvDir, $version, $platform );
			}
			catch( serviceException $e ) {
				throw new cliException( $e->getMessage(), 0, $e );
			}
		}
		finally {
			if( file_exists( $tempZipPath ) ) {
				@unlink( $tempZipPath );
			}
			if( is_dir( $tempExtractDir ) ) {
				chromeInstallation::deleteRecursively( $tempExtractDir );
			}
		}
	}

}
