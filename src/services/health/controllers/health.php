<?php

namespace gcgov\framework\services\health\controllers;

use gcgov\framework\config;
use gcgov\framework\models\controllerDataResponse;

/**
 * Liveness and readiness, deliberately kept apart.
 *
 * Liveness answers "is this process able to serve?" and does no I/O. Readiness answers
 * "should traffic be sent here right now?" and checks the dependencies. Collapsing them
 * is the standard mistake: a container orchestrator restarts on a failing liveness probe,
 * so a brief database outage would turn into a crash loop across every replica at once.
 */
final class health implements \gcgov\framework\interfaces\controller {

	public static function _before(): void {
	}


	public static function _after(): void {
	}


	/**
	 * Liveness. No I/O, no dependencies — if configuration resolved and PHP is running,
	 * this process is alive. Backs the container HEALTHCHECK.
	 */
	public function live(): controllerDataResponse {
		return new controllerDataResponse( [
			                                   'status'  => 'ok',
			                                   'version' => self::version(),
		                                   ] );
	}


	/**
	 * Readiness. Pings every configured Mongo database. Backs the deploy gate and the
	 * reverse proxy's load-balancing decision. Returns 503 when a dependency is down, so
	 * a deploy that cannot reach its database fails loudly instead of reporting success.
	 */
	public function ready(): controllerDataResponse {
		$checks  = [];
		$healthy = true;

		try {
			foreach( config::getMongoDatabases() as $mongoDatabase ) {
				$checks[ 'mongo:' . $mongoDatabase->database ] = self::pingMongo( $mongoDatabase->database );
				if( $checks[ 'mongo:' . $mongoDatabase->database ]!=='ok' ) {
					$healthy = false;
				}
			}
		}
		catch( \Throwable $e ) {
			$checks[ 'config' ] = 'failed: ' . $e->getMessage();
			$healthy            = false;
		}

		$response = new controllerDataResponse( [
			                                        'status'  => $healthy ? 'ok' : 'unavailable',
			                                        'version' => self::version(),
			                                        'checks'  => $checks,
		                                        ] );
		if( !$healthy ) {
			$response->setHttpStatus( 503 );
		}

		return $response;
	}


	/**
	 * The deployed release, for confirming that a deploy actually landed — half the
	 * reason this endpoint exists. Written into the image at build time.
	 */
	private static function version(): string {
		$version = getenv( 'APP_VERSION' );

		return is_string( $version ) && $version!=='' ? $version : 'unknown';
	}


	/** @return string 'ok', or a description of why not — never a thrown exception. */
	private static function pingMongo( string $databaseName ): string {
		try {
			( new \gcgov\framework\services\mongodb\tools\mdb( database: $databaseName ) )->db->command( [ 'ping' => 1 ] );

			return 'ok';
		}
		catch( \Throwable $e ) {
			return 'failed: ' . $e->getMessage();
		}
	}

}
