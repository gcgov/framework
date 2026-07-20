<?php

namespace gcgov\framework\cli;

use gcgov\framework\models\environmentConfig;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * PHP interpreter resolution and Xdebug flag construction for child processes
 * spawned by `gf cli`.
 */
final class phpProcess {

	/**
	 * Resolve the PHP binary to run app code with. Priority:
	 *  1. --php option
	 *  2. GF_PHP environment variable
	 *  3. environmentConfig->phpPath (a directory per the setup convention — php/php.exe appended;
	 *     a full binary path is also accepted)
	 *  4. Symfony PhpExecutableFinder / PHP_BINARY (the interpreter running gf)
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function findPhpBinary( ?string $optionValue = null, ?environmentConfig $environmentConfig = null ): string {
		$candidates = [];

		if( $optionValue!==null && $optionValue!=='' ) {
			$candidates[ $optionValue ] = '--php option';
		}

		$envValue = getenv( 'GF_PHP' );
		if( $envValue!==false && $envValue!=='' ) {
			$candidates[ $envValue ] = 'GF_PHP environment variable';
		}

		if( $environmentConfig!==null && $environmentConfig->phpPath!=='' ) {
			$candidates[ $environmentConfig->phpPath ] = 'environment.json phpPath';
		}

		foreach( $candidates as $candidate => $sourceDescription ) {
			$resolved = self::resolveBinary( (string)$candidate );
			if( $resolved!==null ) {
				return $resolved;
			}
			throw new cliException( 'PHP binary from ' . $sourceDescription . ' not found or not executable: ' . $candidate );
		}

		$found = ( new PhpExecutableFinder() )->find( false );
		if( $found!==false ) {
			return $found;
		}

		return PHP_BINARY;
	}


	/**
	 * Accepts a php binary path or a directory containing php/php.exe.
	 */
	private static function resolveBinary( string $path ): ?string {
		if( is_file( $path ) ) {
			return $path;
		}
		if( is_dir( $path ) ) {
			foreach( [ '/php.exe', '/php' ] as $binaryName ) {
				$binary = rtrim( $path, '/\\' ) . $binaryName;
				if( is_file( $binary ) ) {
					return $binary;
				}
			}
		}

		return null;
	}


	/**
	 * `-d` ini overrides that enable step debugging in the child process.
	 * Replaces the per-app local-debug.bat.
	 *
	 * @return string[]
	 */
	public static function xdebugFlags( string $clientHost = '127.0.0.1', int $clientPort = 9003 ): array {
		return [
			'-dxdebug.mode=debug',
			'-dxdebug.start_with_request=yes',
			'-dxdebug.client_host=' . $clientHost,
			'-dxdebug.client_port=' . $clientPort,
		];
	}

}
