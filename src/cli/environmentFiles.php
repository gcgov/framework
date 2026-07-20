<?php

namespace gcgov\framework\cli;

/**
 * Copies environment-variant config files to their canonical (active) names.
 * Shared by `gf env` and `gf deploy`.
 */
final class environmentFiles {

	/**
	 * The variant => canonical file pairs, relative to the application root.
	 *
	 * @return array<string, string>
	 */
	public static function filePairs( string $environment ): array {
		return [
			'app/config/environment-' . $environment . '.json' => 'app/config/environment.json',
			'composer-' . $environment . '.json'                => 'composer.json',
			'www/web-' . $environment . '.config'               => 'www/web.config',
		];
	}


	/**
	 * Copy every existing variant file for $environment to its canonical name.
	 * Missing variant files are skipped (not every app has every pair).
	 *
	 * @return array<int, array{source: string, target: string, status: string}>
	 * @throws \gcgov\framework\cli\cliException When no variant file exists at all or a copy fails
	 */
	public static function apply( string $rootDir, string $environment, bool $dryRun = false ): array {
		$rootDir = rtrim( str_replace( '\\', '/', $rootDir ), '/' );
		$results = [];
		$copied  = 0;

		foreach( self::filePairs( $environment ) as $source => $target ) {
			$sourcePath = $rootDir . '/' . $source;
			$targetPath = $rootDir . '/' . $target;

			if( !file_exists( $sourcePath ) ) {
				$results[] = [ 'source' => $source, 'target' => $target, 'status' => 'skipped (no ' . $source . ')' ];
				continue;
			}

			if( !$dryRun ) {
				if( !copy( $sourcePath, $targetPath ) ) {
					throw new cliException( 'Failed to copy ' . $sourcePath . ' to ' . $targetPath );
				}
			}

			$results[] = [ 'source' => $source, 'target' => $target, 'status' => $dryRun ? 'would copy' : 'copied' ];
			$copied++;
		}

		if( $copied===0 ) {
			throw new cliException( 'No environment variant files found for environment "' . $environment . '" in ' . $rootDir . '. Expected at least one of: ' . implode( ', ', array_keys( self::filePairs( $environment ) ) ) );
		}

		return $results;
	}

}
