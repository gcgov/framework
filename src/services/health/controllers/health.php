<?php

namespace gcgov\framework\services\health\controllers;

use gcgov\framework\config;
use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\services\log;

/**
 * Liveness and readiness, deliberately kept apart.
 *
 * Liveness answers "is this process able to serve?" and does no I/O. Readiness answers
 * "should traffic be sent here right now?" and checks the dependencies. Collapsing them
 * is the standard mistake: a container orchestrator restarts on a failing liveness probe,
 * so a brief database outage would turn into a crash loop across every replica at once.
 */
final class health implements \gcgov\framework\interfaces\controller {

	/**
	 * How long a dependency gets to answer a readiness probe.
	 *
	 * Sized to the probe interval, not to a user request. The driver's default
	 * serverSelectionTimeoutMS is 30 seconds, so an unreachable database parked one worker
	 * per probe for 30 seconds each — with an orchestrator probing every few seconds and a
	 * fresh client per configured database, every worker ends up sitting in a hung probe
	 * and real traffic 502s. That is precisely the crash loop the liveness/readiness split
	 * exists to prevent, arriving through readiness instead.
	 */
	private const PROBE_TIMEOUT_MS = 2000;


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
				$checks[ 'mongo:' . $mongoDatabase->database ] = self::pingMongo( $mongoDatabase );
				if( $checks[ 'mongo:' . $mongoDatabase->database ]!=='ok' ) {
					$healthy = false;
				}
			}
		}
		catch( \Throwable $e ) {
			// Logged, not returned — see pingMongo(). A configException message carries the
			// config file path and the name of the unresolved environment variable.
			log::error( 'health', 'Readiness could not read configuration', [ 'exception' => $e ] );
			$checks[ 'config' ] = 'failed';
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


	/**
	 * @return string 'ok' or 'failed' — never a thrown exception, and never the reason.
	 *
	 * The reason goes to the log. These routes are registered unauthenticated on every
	 * application, and a driver failure message names internal hostnames, ports and
	 * replica-set topology — a probe of a public /health/ready would otherwise map the
	 * inside of a Zone that is not reachable from outside it.
	 *
	 * Built as its own short-timeout client rather than through mdb, which takes its
	 * timeouts from the application's own clientParams. A configured clientParams still
	 * wins, so a database that genuinely needs longer can say so.
	 */
	private static function pingMongo( mongoDatabase $mongoDatabase ): string {
		try {
			$client = new \MongoDB\Client( $mongoDatabase->uri, array_merge( [
				'serverSelectionTimeoutMS' => self::PROBE_TIMEOUT_MS,
				'connectTimeoutMS'         => self::PROBE_TIMEOUT_MS,
				'socketTimeoutMS'          => self::PROBE_TIMEOUT_MS,
			], $mongoDatabase->clientParams ) );
			$client->{$mongoDatabase->database}->command( [ 'ping' => 1 ] );

			return 'ok';
		}
		catch( \Throwable $e ) {
			log::warning( 'health', 'Readiness ping failed for database "' . $mongoDatabase->database . '"', [ 'exception' => $e ] );

			return 'failed';
		}
	}

}
