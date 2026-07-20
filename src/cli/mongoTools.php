<?php

namespace gcgov\framework\cli;

use Symfony\Component\Process\ExecutableFinder;

/**
 * Shared helpers for the gf db:* commands. These commands read connection info
 * from app/config/environment{-variant}.json and shell out to the MongoDB
 * command line tools — no ext-mongodb required.
 */
final class mongoTools {

	/**
	 * Locate a MongoDB command line tool on PATH.
	 *
	 * @throws \gcgov\framework\cli\cliException
	 */
	public static function findBinary( string $binaryName ): string {
		$found = ( new ExecutableFinder() )->find( $binaryName );
		if( $found===null ) {
			$downloadUrl = $binaryName==='mongosh' ? 'https://www.mongodb.com/try/download/shell' : 'https://www.mongodb.com/try/download/database-tools';
			throw new cliException( $binaryName . ' was not found on PATH. Install it from ' . $downloadUrl . ' and ensure it is on your PATH.' );
		}

		return $found;
	}


	/**
	 * Redact the password portion of a mongodb:// / mongodb+srv:// uri for display.
	 */
	public static function redactUri( string $uri ): string {
		return (string)preg_replace( '#^(mongodb(?:\+srv)?://[^:/@]+):[^@]+@#i', '$1:***@', $uri );
	}


	/**
	 * Ensure the connection string selects $database so tools like mongosh operate on
	 * the right database. If the uri already names a database, it is left untouched.
	 */
	public static function uriWithDatabase( string $uri, string $database ): string {
		if( $database==='' ) {
			return $uri;
		}

		// split off querystring
		$queryString = '';
		$base        = $uri;
		$queryPos    = strpos( $uri, '?' );
		if( $queryPos!==false ) {
			$base        = substr( $uri, 0, $queryPos );
			$queryString = substr( $uri, $queryPos );
		}

		// mongodb://user:pass@host:port[/database]
		$schemeEnd = strpos( $base, '://' );
		if( $schemeEnd===false ) {
			return $uri;
		}
		$afterScheme = substr( $base, $schemeEnd + 3 );

		$slashPos = strpos( $afterScheme, '/' );
		if( $slashPos===false ) {
			return rtrim( $base, '/' ) . '/' . $database . $queryString;
		}

		$existingDatabase = substr( $afterScheme, $slashPos + 1 );
		if( $existingDatabase==='' ) {
			return $base . $database . $queryString;
		}

		return $uri;
	}

}
