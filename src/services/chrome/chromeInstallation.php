<?php

namespace gcgov\framework\services\chrome;

use gcgov\framework\exceptions\serviceException;

/**
 * Shared chrome-headless-shell installation logic: platform detection, Chrome for
 * Testing version/download selection, on-disk layout, and the installation manifest.
 *
 * Used by both the gf CLI (which passes appContext->getSrvDir()) and the runtime
 * chrome service (which passes config::getSrvDir()) — every method takes an explicit
 * $srvDir so this class never resolves paths itself. Nothing here touches the network;
 * downloading is the CLI installer's job (\gcgov\framework\cli\chromeInstaller).
 *
 * Layout: {srvDir}/chrome/{version}/chrome-headless-shell-{platform}/chrome-headless-shell(.exe)
 * Manifest: {srvDir}/chrome/installation.json
 */
final class chromeInstallation {

	public const string VERSIONS_URL = 'https://googlechromelabs.github.io/chrome-for-testing/last-known-good-versions-with-downloads.json';

	public const string MANIFEST_FILE = 'installation.json';

	public const string GITIGNORE_CONTENT = "*\n!.gitignore\n";


	/**
	 * Map the runtime environment to a Chrome for Testing platform key.
	 *
	 * @param string $osFamily PHP_OS_FAMILY: 'Windows' | 'Darwin' | 'Linux' | ...
	 * @param string $machine  php_uname('m'): 'x86_64' | 'arm64' | 'aarch64' | ...
	 * @param int    $intSize  PHP_INT_SIZE: 8 on 64-bit builds, 4 on 32-bit
	 *
	 * @throws \gcgov\framework\exceptions\serviceException When Chrome for Testing has no build for the platform
	 */
	public static function detectPlatform( string $osFamily, string $machine, int $intSize ): string {
		$machineLower = strtolower( $machine );
		$isArm        = in_array( $machineLower, [ 'arm64', 'aarch64' ], true );

		if( $osFamily==='Darwin' ) {
			return $isArm ? 'mac-arm64' : 'mac-x64';
		}

		if( $osFamily==='Windows' ) {
			return $intSize===8 ? 'win64' : 'win32';
		}

		if( $osFamily==='Linux' ) {
			if( $isArm ) {
				throw new serviceException( 'Chrome for Testing does not publish chrome-headless-shell builds for Linux arm64 (' . $machine . ').' );
			}

			return 'linux64';
		}

		throw new serviceException( 'Chrome for Testing does not publish chrome-headless-shell builds for this platform (' . $osFamily . '/' . $machine . ').' );
	}


	/**
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function detectCurrentPlatform(): string {
		return self::detectPlatform( PHP_OS_FAMILY, php_uname( 'm' ), PHP_INT_SIZE );
	}


	/**
	 * Pick the chrome-headless-shell download for $platform from the Chrome for Testing
	 * "last known good versions with downloads" JSON payload.
	 *
	 * @return array{version: string, url: string}
	 * @throws \gcgov\framework\exceptions\serviceException On malformed payload, unknown channel, or missing platform
	 */
	public static function selectDownload( string $versionsJson, string $platform, string $channel = 'Stable' ): array {
		$data = json_decode( $versionsJson, true );
		if( !is_array( $data ) || !isset( $data[ 'channels' ] ) || !is_array( $data[ 'channels' ] ) ) {
			throw new serviceException( 'Unexpected response from ' . self::VERSIONS_URL . ' — no channels found.' );
		}

		if( !isset( $data[ 'channels' ][ $channel ][ 'version' ], $data[ 'channels' ][ $channel ][ 'downloads' ] ) ) {
			throw new serviceException( 'Channel "' . $channel . '" not found in the Chrome for Testing version feed.' );
		}

		$channelData = $data[ 'channels' ][ $channel ];
		$downloads   = $channelData[ 'downloads' ][ 'chrome-headless-shell' ] ?? [];

		foreach( $downloads as $download ) {
			if( isset( $download[ 'platform' ], $download[ 'url' ] ) && $download[ 'platform' ]===$platform ) {
				return [ 'version' => (string)$channelData[ 'version' ], 'url' => (string)$download[ 'url' ] ];
			}
		}

		throw new serviceException( 'No chrome-headless-shell download for platform "' . $platform . '" in the ' . $channel . ' channel of the Chrome for Testing version feed.' );
	}


	/**
	 * Path of the executable relative to the chrome directory.
	 * The Chrome for Testing zip extracts to chrome-headless-shell-{platform}/.
	 */
	public static function executableRelativePath( string $version, string $platform ): string {
		$binaryName = str_starts_with( $platform, 'win' ) ? 'chrome-headless-shell.exe' : 'chrome-headless-shell';

		return $version . '/chrome-headless-shell-' . $platform . '/' . $binaryName;
	}


	public static function chromeDir( string $srvDir ): string {
		return rtrim( str_replace( '\\', '/', $srvDir ), '/' ) . '/chrome';
	}


	public static function manifestPath( string $srvDir ): string {
		return self::chromeDir( $srvDir ) . '/' . self::MANIFEST_FILE;
	}


	/**
	 * Create {srvDir}/chrome (and a .gitignore inside it) if missing.
	 *
	 * @return string The chrome directory path
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function ensureChromeDir( string $srvDir ): string {
		$chromeDir = self::chromeDir( $srvDir );

		if( !is_dir( $chromeDir ) && !mkdir( $chromeDir, 0775, true ) && !is_dir( $chromeDir ) ) {
			throw new serviceException( 'Failed to create directory ' . $chromeDir );
		}

		if( !file_exists( $chromeDir . '/.gitignore' ) ) {
			file_put_contents( $chromeDir . '/.gitignore', self::GITIGNORE_CONTENT );
		}

		return $chromeDir;
	}


	/**
	 * @return array{version: string, platform: string, executable: string, installedAt: string}|null Null when absent or unparseable
	 */
	public static function readManifest( string $srvDir ): ?array {
		$manifestPath = self::manifestPath( $srvDir );
		if( !file_exists( $manifestPath ) ) {
			return null;
		}

		$data = json_decode( (string)file_get_contents( $manifestPath ), true );
		if( !is_array( $data ) || !isset( $data[ 'version' ], $data[ 'platform' ], $data[ 'executable' ] ) ) {
			return null;
		}

		return [
			'version'     => (string)$data[ 'version' ],
			'platform'    => (string)$data[ 'platform' ],
			'executable'  => (string)$data[ 'executable' ],
			'installedAt' => (string)( $data[ 'installedAt' ] ?? '' ),
		];
	}


	/**
	 * Write the manifest atomically (temp file + rename).
	 *
	 * @throws \gcgov\framework\exceptions\serviceException
	 */
	public static function writeManifest( string $srvDir, string $version, string $platform ): void {
		self::ensureChromeDir( $srvDir );

		$manifest = [
			'version'     => $version,
			'platform'    => $platform,
			'executable'  => self::executableRelativePath( $version, $platform ),
			'installedAt' => date( DATE_RFC3339 ),
		];

		$manifestPath = self::manifestPath( $srvDir );
		$tempPath     = $manifestPath . '.tmp-' . getmypid();

		if( file_put_contents( $tempPath, json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) )===false || !rename( $tempPath, $manifestPath ) ) {
			@unlink( $tempPath );
			throw new serviceException( 'Failed to write ' . $manifestPath );
		}
	}


	/**
	 * Absolute path to the current chrome-headless-shell executable, or null when not installed.
	 * Prefers the manifest; falls back to scanning installed version directories (highest version wins).
	 */
	public static function getExecutablePath( string $srvDir ): ?string {
		$chromeDir = self::chromeDir( $srvDir );

		$manifest = self::readManifest( $srvDir );
		if( $manifest!==null ) {
			$executablePath = $chromeDir . '/' . ltrim( $manifest[ 'executable' ], '/' );
			if( file_exists( $executablePath ) ) {
				return $executablePath;
			}
		}

		// fallback: scan for installed versions (manifest missing or stale)
		$versions = self::installedVersions( $srvDir );
		if( count( $versions )===0 ) {
			return null;
		}
		usort( $versions, 'version_compare' );
		$newestVersion = end( $versions );

		try {
			$platform = self::detectCurrentPlatform();
		}
		catch( serviceException ) {
			return null;
		}

		$executablePath = $chromeDir . '/' . self::executableRelativePath( $newestVersion, $platform );

		return file_exists( $executablePath ) ? $executablePath : null;
	}


	/**
	 * Installed version directory names (unsorted).
	 *
	 * @return string[]
	 */
	public static function installedVersions( string $srvDir ): array {
		$chromeDir = self::chromeDir( $srvDir );
		if( !is_dir( $chromeDir ) ) {
			return [];
		}

		$versions = [];
		foreach( scandir( $chromeDir ) ?: [] as $entry ) {
			if( preg_match( '/^\d+(\.\d+){3}$/', $entry )===1 && is_dir( $chromeDir . '/' . $entry ) ) {
				$versions[] = $entry;
			}
		}

		return $versions;
	}


	/**
	 * Delete every installed version except $keepVersion, plus any stale tmp-* artifacts
	 * left behind by interrupted installs.
	 *
	 * @return string[] The removed version directory names
	 */
	public static function pruneOldVersions( string $srvDir, string $keepVersion ): array {
		$chromeDir = self::chromeDir( $srvDir );
		$removed   = [];

		foreach( self::installedVersions( $srvDir ) as $version ) {
			if( $version!==$keepVersion ) {
				self::deleteRecursively( $chromeDir . '/' . $version );
				$removed[] = $version;
			}
		}

		// stale temp downloads/extracts from interrupted runs
		foreach( glob( $chromeDir . '/tmp-*' ) ?: [] as $stale ) {
			self::deleteRecursively( $stale );
		}

		return $removed;
	}


	public static function deleteRecursively( string $path ): void {
		if( is_file( $path ) || is_link( $path ) ) {
			@unlink( $path );

			return;
		}
		if( !is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			$file->isDir() ? @rmdir( $file->getPathname() ) : @unlink( $file->getPathname() );
		}
		@rmdir( $path );
	}

}
