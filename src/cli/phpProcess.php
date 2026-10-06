<?php

namespace gcgov\framework\cli;

use Symfony\Component\Process\PhpExecutableFinder;

/**
 * PHP interpreter resolution and Xdebug flag construction for child processes
 * spawned by `gf cli`.
 */
final class phpProcess {

	/**
	 * Binary names that are not the PHP CLI interpreter (or, in php-win's case, a CLI build
	 * without console streams). App code cannot be run with them: $argv/$argc and
	 * STDIN/STDOUT/STDERR are unavailable, so the child dies before the route starts.
	 */
	private const NON_CLI_BINARY_NAMES = [ 'php-cgi', 'php-fpm', 'php-win' ];


	/**
	 * Resolve the PHP command to run app code with, returned as a command array
	 * `[ binary, ...arguments ]` ready to prepend to a Symfony Process command line.
	 * Priority:
	 *  1. --php option
	 *  2. GF_PHP environment variable
	 *  3. Symfony PhpExecutableFinder / PHP_BINARY (the interpreter running gf)
	 *
	 * The interpreter is a property of the machine, not of the application, so it is
	 * deliberately not configurable in the committed config.json.
	 *
	 * Sources 1-3 may include trailing arguments after the binary, e.g.
	 * `C:\path\php.exe -c C:\path\php.ini` — the binary and each argument become separate
	 * command-array elements so Symfony Process escapes them individually.
	 *
	 * A non-CLI binary (php-cgi/php-fpm/php-win — the path IIS FastCGI is configured with)
	 * is swapped for the CLI binary sitting beside it, or reported as an error when there
	 * isn't one.
	 *
	 * @return string[] Command array — first element is the binary, remaining elements are arguments.
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function findPhpBinary( ?string $optionValue = null ): array {
		$candidates = [];

		if( $optionValue!==null && $optionValue!=='' ) {
			$candidates[ $optionValue ] = '--php option';
		}

		$envValue = getenv( 'GF_PHP' );
		if( $envValue!==false && $envValue!=='' ) {
			$candidates[ $envValue ] = 'GF_PHP environment variable';
		}

		foreach( $candidates as $candidate => $sourceDescription ) {
			$resolved = self::resolveBinary( (string)$candidate );
			if( $resolved!==null ) {
				return self::requireCliBinary( $resolved, $sourceDescription );
			}
			throw new cliException( 'PHP binary from ' . $sourceDescription . ' not found or not executable: ' . $candidate );
		}

		$found = ( new PhpExecutableFinder() )->find( false );
		if( $found!==false ) {
			return self::requireCliBinary( [ $found ], 'the PHP interpreter running gf' );
		}

		return self::requireCliBinary( [ PHP_BINARY ], 'the PHP interpreter running gf' );
	}


	/**
	 * `-d` ini overrides every gf-spawned PHP child needs, regardless of the php.ini the
	 * resolved interpreter loads. php.ini-production ships `register_argc_argv = Off`, which
	 * leaves $argv/$argc undefined in the child — the route runner can't read its arguments.
	 *
	 * @return string[]
	 */
	public static function requiredIniFlags(): array {
		return [ '-dregister_argc_argv=1' ];
	}


	/**
	 * Ensure the resolved command runs the CLI interpreter. php-cgi/php-fpm/php-win are
	 * silently swapped for the php/php.exe beside them (a path copied from an IIS FastCGI
	 * handler mapping is the common case); when no CLI binary is there, fail with an
	 * actionable message instead of letting the child process die on undefined $argv/STDERR.
	 *
	 * @param  string[]  $command
	 *
	 * @return string[]
	 * @throws \gcgov\framework\cli\cliException
	 */
	private static function requireCliBinary( array $command, string $sourceDescription ): array {
		$binary = $command[ 0 ] ?? '';
		if( !in_array( strtolower( pathinfo( $binary, PATHINFO_FILENAME ) ), self::NON_CLI_BINARY_NAMES, true ) ) {
			return $command;
		}

		$cliBinary = self::resolveBinaryFile( dirname( $binary ) );
		if( $cliBinary!==null ) {
			$command[ 0 ] = $cliBinary;

			return $command;
		}

		throw new cliException( 'PHP binary from ' . $sourceDescription . ' is not the CLI interpreter: ' . $binary . '. gf runs application code through the PHP CLI binary (php/php.exe) — php-cgi, php-fpm, and php-win cannot run CLI routes ($argv and STDERR are unavailable there). No CLI binary was found beside it, so point --php or GF_PHP at php.exe or the directory containing it.' );
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
