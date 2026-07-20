<?php

namespace gcgov\framework\cli;

/**
 * The find/replace engine behind `gf setup`: replaces {placeholder} tokens in a
 * freshly scaffolded project tree (cross-platform port of scripts/setup.ps1).
 */
final class tokenReplacer {

	/** File extensions eligible for token replacement */
	public const array EXTENSIONS = [ 'ini', 'json', 'php', 'config', 'bat', 'ps1' ];

	/** Directory names never descended into. Note srv/ is deliberately INCLUDED in replacement:
	 *  the scaffold's per-environment php.ini files (srv/app.{env}[-cli]/php.ini) carry tokens. */
	public const array EXCLUDED_DIRECTORIES = [ 'vendor', '.git', 'node_modules' ];


	/**
	 * Replace tokens in every eligible file under $rootDir.
	 * Tokens with an empty value are skipped (matching setup.ps1: pressing enter skips a value,
	 * leaving the {token} in place for a later re-run).
	 * Values written into .json files get backslashes escaped.
	 *
	 * @param array<string, string> $replacements token => value, e.g. '{app_title}' => 'Timesheet API'
	 *
	 * @return string[] Paths of files that were modified
	 */
	public static function replaceInTree( string $rootDir, array $replacements ): array {
		$replacements = array_filter( $replacements, fn( string $value ) => $value!=='' );
		if( count( $replacements )===0 ) {
			return [];
		}

		$modifiedFiles = [];

		foreach( self::findEligibleFiles( $rootDir ) as $filePath ) {
			$contents = file_get_contents( $filePath );
			if( $contents===false ) {
				continue;
			}

			$isJson      = strtolower( pathinfo( $filePath, PATHINFO_EXTENSION ) )==='json';
			$newContents = $contents;

			foreach( $replacements as $token => $value ) {
				$replacementValue = $isJson ? str_replace( '\\', '\\\\', $value ) : $value;
				$newContents      = str_replace( $token, $replacementValue, $newContents );
			}

			if( $newContents!==$contents ) {
				file_put_contents( $filePath, $newContents );
				$modifiedFiles[] = $filePath;
			}
		}

		return $modifiedFiles;
	}


	/**
	 * @return string[]
	 */
	public static function findEligibleFiles( string $rootDir ): array {
		$rootDir = rtrim( str_replace( '\\', '/', $rootDir ), '/' );
		if( !is_dir( $rootDir ) ) {
			return [];
		}

		$directoryIterator = new \RecursiveDirectoryIterator( $rootDir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::UNIX_PATHS );
		$filterIterator    = new \RecursiveCallbackFilterIterator( $directoryIterator, function( \SplFileInfo $file ): bool {
			if( $file->isDir() ) {
				return !in_array( $file->getFilename(), self::EXCLUDED_DIRECTORIES, true );
			}

			return in_array( strtolower( $file->getExtension() ), self::EXTENSIONS, true );
		} );

		$files = [];
		foreach( new \RecursiveIteratorIterator( $filterIterator ) as $file ) {
			/** @var \SplFileInfo $file */
			$files[] = $file->getPathname();
		}
		sort( $files );

		return $files;
	}


	/**
	 * Normalize a url path segment: '/api/' style with configurable leading/trailing slashes.
	 * Port of setup.ps1's FormatRelativeUrl.
	 */
	public static function formatRelativeUrl( string $path, bool $trailingSlash = true, bool $leadingSlash = true ): string {
		$path = trim( $path );
		$path = str_replace( '\\', '/', $path );
		$path = trim( $path, '/' );

		if( $path==='' ) {
			return $leadingSlash || $trailingSlash ? '/' : '';
		}

		if( $trailingSlash ) {
			$path .= '/';
		}
		if( $leadingSlash ) {
			$path = '/' . $path;
		}

		return (string)preg_replace( '#//+#', '/', $path );
	}

}
