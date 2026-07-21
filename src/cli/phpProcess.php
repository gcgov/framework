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
	 * Resolve the PHP command to run app code with, returned as a command array
	 * `[ binary, ...arguments ]` ready to prepend to a Symfony Process command line.
	 * Priority:
	 *  1. --php option
	 *  2. GF_PHP environment variable
	 *  3. environmentConfig->phpPath (a directory per the setup convention — php/php.exe appended;
	 *     a full binary path, optionally followed by CLI arguments, is also accepted)
	 *  4. Symfony PhpExecutableFinder / PHP_BINARY (the interpreter running gf)
	 *
	 * Sources 1-3 may include trailing arguments after the binary, e.g.
	 * `C:\path\php.exe -c C:\path\php.ini` — the binary and each argument become separate
	 * command-array elements so Symfony Process escapes them individually.
	 *
	 * @return string[] Command array — first element is the binary, remaining elements are arguments.
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function findPhpBinary( ?string $optionValue = null, ?environmentConfig $environmentConfig = null ): array {
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
			return [ $found ];
		}

		return [ PHP_BINARY ];
	}


	/**
	 * Resolve a PHP command string into a `[ binary, ...arguments ]` array. Accepts:
	 *  - a php binary path
	 *  - a directory containing php/php.exe
	 *  - a binary path followed by CLI arguments, e.g. "C:\path\php.exe -c C:\path\php.ini"
	 *    (unquoted paths containing spaces are supported by greedily accumulating leading
	 *    tokens until they resolve to a real file; quote paths/arguments containing spaces)
	 *
	 * @return string[]|null Command array, or null when the binary can't be found.
	 */
	private static function resolveBinary( string $path ): ?array {
		$path = trim( $path );
		if( $path==='' ) {
			return null;
		}

		// Fast path: the whole string is a binary or a directory containing one, with no arguments.
		$binary = self::resolveBinaryFile( $path );
		if( $binary!==null ) {
			return [ $binary ];
		}

		// Split a leading binary from trailing CLI arguments.
		$tokens          = self::tokenize( $path );
		$binaryCandidate = '';
		foreach( $tokens as $index => $token ) {
			// An option flag (e.g. -c) after a non-empty candidate marks the start of the arguments.
			if( $binaryCandidate!=='' && str_starts_with( $token, '-' ) ) {
				break;
			}
			$binaryCandidate = $binaryCandidate==='' ? $token : $binaryCandidate . ' ' . $token;
			$binary          = self::resolveBinaryFile( $binaryCandidate );
			if( $binary!==null ) {
				return array_merge( [ $binary ], array_slice( $tokens, $index + 1 ) );
			}
		}

		return null;
	}


	/**
	 * Resolve a single path to a php binary: the path itself if it's a file, or php/php.exe
	 * inside it if it's a directory.
	 */
	private static function resolveBinaryFile( string $path ): ?string {
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
	 * Split a command string into tokens on whitespace, keeping single- or double-quoted
	 * spans (e.g. paths containing spaces) intact and stripping their surrounding quotes.
	 *
	 * @return string[]
	 */
	private static function tokenize( string $path ): array {
		preg_match_all( '/"[^"]*"|\'[^\']*\'|\S+/', $path, $matches );

		return array_map( static fn( string $token ): string => trim( $token, '"\'' ), $matches[ 0 ] );
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
