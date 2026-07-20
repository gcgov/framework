<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Chrome;

use gcgov\framework\exceptions\serviceException;
use gcgov\framework\services\chrome\chromeInstallation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(chromeInstallation::class)]
final class ChromeInstallationTest extends TestCase {

	/** Trimmed copy of the real last-known-good-versions-with-downloads.json payload
	 *  (Stable + Beta channels; includes the sibling chrome/chromedriver arrays that
	 *  selectDownload must ignore). */
	public const string FIXTURE_JSON = <<<'JSON'
	{
		"timestamp": "2026-07-20T09:13:03.009Z",
		"channels": {
			"Stable": {
				"channel": "Stable",
				"version": "151.0.7922.34",
				"revision": "1654411",
				"downloads": {
					"chrome": [
						{"platform": "linux64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/linux64/chrome-linux64.zip"}
					],
					"chromedriver": [
						{"platform": "linux64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/linux64/chromedriver-linux64.zip"}
					],
					"chrome-headless-shell": [
						{"platform": "linux64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/linux64/chrome-headless-shell-linux64.zip"},
						{"platform": "mac-arm64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/mac-arm64/chrome-headless-shell-mac-arm64.zip"},
						{"platform": "mac-x64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/mac-x64/chrome-headless-shell-mac-x64.zip"},
						{"platform": "win32", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/win32/chrome-headless-shell-win32.zip"},
						{"platform": "win64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/win64/chrome-headless-shell-win64.zip"}
					]
				}
			},
			"Beta": {
				"channel": "Beta",
				"version": "151.0.7922.34",
				"revision": "1654411",
				"downloads": {
					"chrome-headless-shell": [
						{"platform": "linux64", "url": "https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/linux64/chrome-headless-shell-linux64.zip"}
					]
				}
			}
		}
	}
	JSON;

	private string $tempSrvDir = '';

	protected function setUp(): void {
		$this->tempSrvDir = sys_get_temp_dir() . '/gcgov-chrome-test-' . uniqid() . '/srv';
		mkdir( $this->tempSrvDir, 0777, true );
	}

	protected function tearDown(): void {
		chromeInstallation::deleteRecursively( dirname( $this->tempSrvDir ) );
	}


	// ---- platform detection ----

	/**
	 * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
	 */
	public static function platformProvider(): array {
		return [
			'mac arm64'      => [ 'Darwin', 'arm64', 8, 'mac-arm64' ],
			'mac aarch64'    => [ 'Darwin', 'aarch64', 8, 'mac-arm64' ],
			'mac intel'      => [ 'Darwin', 'x86_64', 8, 'mac-x64' ],
			'windows 64-bit' => [ 'Windows', 'AMD64', 8, 'win64' ],
			'windows 32-bit' => [ 'Windows', 'x86', 4, 'win32' ],
			'linux x86_64'   => [ 'Linux', 'x86_64', 8, 'linux64' ],
			'linux amd64'    => [ 'Linux', 'amd64', 8, 'linux64' ],
		];
	}

	#[DataProvider('platformProvider')]
	public function testDetectPlatform( string $osFamily, string $machine, int $intSize, string $expected ): void {
		$this->assertSame( $expected, chromeInstallation::detectPlatform( $osFamily, $machine, $intSize ) );
	}

	public function testDetectPlatformThrowsForLinuxArm(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::detectPlatform( 'Linux', 'aarch64', 8 );
	}

	public function testDetectPlatformThrowsForUnknownOs(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::detectPlatform( 'BSD', 'x86_64', 8 );
	}

	public function testDetectCurrentPlatformReturnsAKnownPlatform(): void {
		$this->assertContains( chromeInstallation::detectCurrentPlatform(), [ 'linux64', 'mac-arm64', 'mac-x64', 'win32', 'win64' ] );
	}


	// ---- download selection ----

	public function testSelectDownloadPerPlatform(): void {
		foreach( [ 'linux64', 'mac-arm64', 'mac-x64', 'win32', 'win64' ] as $platform ) {
			$download = chromeInstallation::selectDownload( self::FIXTURE_JSON, $platform );
			$this->assertSame( '151.0.7922.34', $download[ 'version' ] );
			$this->assertSame( 'https://storage.googleapis.com/chrome-for-testing-public/151.0.7922.34/' . $platform . '/chrome-headless-shell-' . $platform . '.zip', $download[ 'url' ] );
		}
	}

	public function testSelectDownloadFromNonStableChannel(): void {
		$download = chromeInstallation::selectDownload( self::FIXTURE_JSON, 'linux64', 'Beta' );
		$this->assertSame( '151.0.7922.34', $download[ 'version' ] );
	}

	public function testSelectDownloadThrowsOnUnknownPlatform(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::selectDownload( self::FIXTURE_JSON, 'linux-arm64' );
	}

	public function testSelectDownloadThrowsOnUnknownChannel(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::selectDownload( self::FIXTURE_JSON, 'linux64', 'Nightly' );
	}

	public function testSelectDownloadThrowsOnMalformedJson(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::selectDownload( 'not json at all', 'linux64' );
	}

	public function testSelectDownloadThrowsOnEmptyObject(): void {
		$this->expectException( serviceException::class );
		chromeInstallation::selectDownload( '{}', 'linux64' );
	}


	// ---- paths ----

	public function testExecutableRelativePathAddsExeOnWindowsOnly(): void {
		$this->assertSame( '151.0.7922.34/chrome-headless-shell-win64/chrome-headless-shell.exe', chromeInstallation::executableRelativePath( '151.0.7922.34', 'win64' ) );
		$this->assertSame( '151.0.7922.34/chrome-headless-shell-win32/chrome-headless-shell.exe', chromeInstallation::executableRelativePath( '151.0.7922.34', 'win32' ) );
		$this->assertSame( '151.0.7922.34/chrome-headless-shell-linux64/chrome-headless-shell', chromeInstallation::executableRelativePath( '151.0.7922.34', 'linux64' ) );
		$this->assertSame( '151.0.7922.34/chrome-headless-shell-mac-arm64/chrome-headless-shell', chromeInstallation::executableRelativePath( '151.0.7922.34', 'mac-arm64' ) );
	}

	public function testChromeDirNormalizesTrailingSlash(): void {
		// config::getSrvDir() has a trailing slash, appContext->getSrvDir() does not
		$this->assertSame( '/root/srv/chrome', chromeInstallation::chromeDir( '/root/srv/' ) );
		$this->assertSame( '/root/srv/chrome', chromeInstallation::chromeDir( '/root/srv' ) );
		$this->assertSame( 'C:/root/srv/chrome', chromeInstallation::chromeDir( 'C:\\root\\srv\\' ) );
	}


	// ---- chrome dir + manifest ----

	public function testEnsureChromeDirCreatesDirAndGitignore(): void {
		$chromeDir = chromeInstallation::ensureChromeDir( $this->tempSrvDir );

		$this->assertDirectoryExists( $chromeDir );
		$this->assertSame( chromeInstallation::GITIGNORE_CONTENT, file_get_contents( $chromeDir . '/.gitignore' ) );

		// does not overwrite an existing .gitignore
		file_put_contents( $chromeDir . '/.gitignore', 'custom' );
		chromeInstallation::ensureChromeDir( $this->tempSrvDir );
		$this->assertSame( 'custom', file_get_contents( $chromeDir . '/.gitignore' ) );
	}

	public function testManifestRoundtrip(): void {
		chromeInstallation::writeManifest( $this->tempSrvDir, '151.0.7922.34', 'linux64' );

		$manifest = chromeInstallation::readManifest( $this->tempSrvDir );
		$this->assertNotNull( $manifest );
		$this->assertSame( '151.0.7922.34', $manifest[ 'version' ] );
		$this->assertSame( 'linux64', $manifest[ 'platform' ] );
		$this->assertSame( '151.0.7922.34/chrome-headless-shell-linux64/chrome-headless-shell', $manifest[ 'executable' ] );
		$this->assertNotFalse( \DateTimeImmutable::createFromFormat( DATE_RFC3339, $manifest[ 'installedAt' ] ) );

		// atomic write leaves no temp files
		$this->assertSame( [], glob( chromeInstallation::chromeDir( $this->tempSrvDir ) . '/*.tmp-*' ) ?: [] );
	}

	public function testReadManifestReturnsNullWhenMissingOrCorrupt(): void {
		$this->assertNull( chromeInstallation::readManifest( $this->tempSrvDir ) );

		chromeInstallation::ensureChromeDir( $this->tempSrvDir );
		file_put_contents( chromeInstallation::manifestPath( $this->tempSrvDir ), 'not json' );
		$this->assertNull( chromeInstallation::readManifest( $this->tempSrvDir ) );
	}


	// ---- executable resolution ----

	private function seedInstalledVersion( string $version, string $platform ): string {
		$binaryDir = chromeInstallation::chromeDir( $this->tempSrvDir ) . '/' . $version . '/chrome-headless-shell-' . $platform;
		mkdir( $binaryDir, 0777, true );
		$binaryName = str_starts_with( $platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';
		file_put_contents( $binaryDir . '/' . $binaryName, 'binary' );

		return $binaryDir . '/' . $binaryName;
	}

	public function testGetExecutablePathViaManifest(): void {
		$platform   = chromeInstallation::detectCurrentPlatform();
		$binaryPath = $this->seedInstalledVersion( '151.0.7922.34', $platform );
		chromeInstallation::writeManifest( $this->tempSrvDir, '151.0.7922.34', $platform );

		$this->assertSame( $binaryPath, chromeInstallation::getExecutablePath( $this->tempSrvDir ) );
		// trailing-slash srvDir (config::getSrvDir() style) resolves identically
		$this->assertSame( $binaryPath, chromeInstallation::getExecutablePath( $this->tempSrvDir . '/' ) );
	}

	public function testGetExecutablePathFallsBackToScanWhenManifestStale(): void {
		$platform = chromeInstallation::detectCurrentPlatform();
		chromeInstallation::writeManifest( $this->tempSrvDir, '150.0.0.0', $platform );  // points at nothing on disk
		$binaryPath = $this->seedInstalledVersion( '151.0.7922.34', $platform );

		$this->assertSame( $binaryPath, chromeInstallation::getExecutablePath( $this->tempSrvDir ) );
	}

	public function testGetExecutablePathScanPicksHighestVersion(): void {
		$platform = chromeInstallation::detectCurrentPlatform();
		$this->seedInstalledVersion( '99.0.1000.5', $platform );
		$newestBinaryPath = $this->seedInstalledVersion( '151.0.7922.34', $platform );
		$this->seedInstalledVersion( '150.0.9999.99', $platform );

		$this->assertSame( $newestBinaryPath, chromeInstallation::getExecutablePath( $this->tempSrvDir ) );
	}

	public function testGetExecutablePathNullWhenNothingInstalled(): void {
		$this->assertNull( chromeInstallation::getExecutablePath( $this->tempSrvDir ) );
	}


	// ---- prune ----

	public function testPruneOldVersionsKeepsCurrentAndSupportFiles(): void {
		$platform = chromeInstallation::detectCurrentPlatform();
		$this->seedInstalledVersion( '149.0.0.1', $platform );
		$this->seedInstalledVersion( '150.0.0.1', $platform );
		$keptBinaryPath = $this->seedInstalledVersion( '151.0.7922.34', $platform );
		chromeInstallation::writeManifest( $this->tempSrvDir, '151.0.7922.34', $platform );
		$chromeDir = chromeInstallation::ensureChromeDir( $this->tempSrvDir );
		mkdir( $chromeDir . '/tmp-extract-12345' );
		touch( $chromeDir . '/tmp-download-12345.zip' );

		$removed = chromeInstallation::pruneOldVersions( $this->tempSrvDir, '151.0.7922.34' );

		sort( $removed );
		$this->assertSame( [ '149.0.0.1', '150.0.0.1' ], $removed );
		$this->assertFileExists( $keptBinaryPath );
		$this->assertFileExists( $chromeDir . '/.gitignore' );
		$this->assertFileExists( chromeInstallation::manifestPath( $this->tempSrvDir ) );
		$this->assertDirectoryDoesNotExist( $chromeDir . '/149.0.0.1' );
		$this->assertDirectoryDoesNotExist( $chromeDir . '/150.0.0.1' );
		$this->assertDirectoryDoesNotExist( $chromeDir . '/tmp-extract-12345' );
		$this->assertFileDoesNotExist( $chromeDir . '/tmp-download-12345.zip' );
	}

}
