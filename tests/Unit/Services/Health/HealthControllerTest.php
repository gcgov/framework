<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Health;

use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\services\health\controllers\health;
use gcgov\framework\tests\Support\capturesFrameworkLog;
use gcgov\framework\tests\Support\seedsFrameworkConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Readiness backs the deploy gate, so its status code is the thing a release depends on:
 * a regression that returns 200 for an unreachable database makes every deploy report
 * success against a database it cannot reach.
 */
#[CoversClass(health::class)]
final class HealthControllerTest extends TestCase {

	use seedsFrameworkConfig;
	use capturesFrameworkLog;

	protected function setUp(): void {
		putenv( 'APP_VERSION' );
	}


	public function testLivenessDoesNoIoAndReportsOk(): void {
		$this->seedConfig();

		$response = ( new health() )->live();
		$data     = $response->getData();

		$this->assertSame( 200, $response->getHttpStatus() );
		$this->assertSame( 'ok', $data[ 'status' ] );
		$this->assertArrayNotHasKey( 'checks', $data, 'liveness must not touch dependencies' );
	}


	public function testVersionFallsBackWhenAppVersionIsUnset(): void {
		$this->seedConfig();

		$this->assertSame( 'unknown', ( new health() )->live()->getData()[ 'version' ] );
	}


	public function testVersionReportsTheDeployedRelease(): void {
		$this->seedConfig();
		putenv( 'APP_VERSION=1.4.2' );

		$this->assertSame( '1.4.2', ( new health() )->live()->getData()[ 'version' ] );
	}


	public function testReadinessIsOkWithNoConfiguredDatabases(): void {
		$this->seedConfig();

		$response = ( new health() )->ready();

		$this->assertSame( 200, $response->getHttpStatus() );
		$this->assertSame( 'ok', $response->getData()[ 'status' ] );
		$this->assertSame( [], $response->getData()[ 'checks' ] );
	}


	/**
	 * An unreachable database must produce 503 — and must not put the driver's message,
	 * which names internal hostnames, ports and replica-set topology, into the body of an
	 * unauthenticated endpoint.
	 */
	public function testUnreachableDatabaseIs503AndDisclosesNothing(): void {
		$log = $this->captureLog( 'health' );
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$database           = new mongoDatabase();
			$database->default  = true;
			$database->database = 'appdb';
			// Unroutable by RFC 5737, so this fails without depending on a live server.
			$database->uri          = 'mongodb://192.0.2.1:27017';
			$database->clientParams = [ 'serverSelectionTimeoutMS' => 50, 'connectTimeoutMS' => 50 ];
			$c->mongoDatabases      = [ $database ];
		} );

		$response = ( new health() )->ready();
		$data     = $response->getData();

		$this->assertSame( 503, $response->getHttpStatus() );
		$this->assertSame( 'unavailable', $data[ 'status' ] );
		$this->assertSame( 'failed', $data[ 'checks' ][ 'mongo:appdb' ] );

		$serialized = json_encode( $data );
		$this->assertIsString( $serialized );
		$this->assertStringNotContainsString( '192.0.2.1', $serialized, 'the probe must not publish the database host' );
		$this->assertStringNotContainsString( '27017', $serialized, 'the probe must not publish the database port' );

		// The detail the response withholds has to go somewhere, or the operator has a 503
		// and nothing to act on. It belongs in the log, which is not public.
		$this->assertTrue( $log->hasWarningThatContains( 'appdb' ), 'the failing database must be named in the log' );
	}


	/**
	 * When the auth service is enabled, usable signing keys are a readiness dependency:
	 * a missing or empty key mount used to pass every health gate — deploy green, proxy
	 * green — and surface only as a configException on the first production sign-in.
	 */
	public function testMissingJwtKeysFailReadinessWhenAuthIsEnabled(): void {
		$log = $this->captureLog( 'health' );
		$this->seedConfig( static function( unifiedConfig $c ): void {
			$c->services->auth   = new \gcgov\framework\models\config\services\auth();
			$c->jwtAuth->keyPath = sys_get_temp_dir() . '/gcgov-health-missing-keys-' . uniqid();
		} );

		$response = ( new health() )->ready();

		$this->assertSame( 503, $response->getHttpStatus() );
		$this->assertSame( 'failed', $response->getData()[ 'checks' ][ 'jwtKeys' ] );
		$this->assertTrue( $log->hasWarningThatContains( 'cert:generate-auth' ), 'the log must name the command that fixes it' );
	}


	public function testProvisionedJwtKeysPassReadinessWhenAuthIsEnabled(): void {
		$keyDir = sys_get_temp_dir() . '/gcgov-health-keys-' . uniqid();
		mkdir( $keyDir, 0777, true );
		file_put_contents( $keyDir . '/guids.json', json_encode( [ 'abc' ] ) );
		file_put_contents( $keyDir . '/private-abc.pem', 'pem' );
		file_put_contents( $keyDir . '/public-abc.pem', 'pem' );

		try {
			$this->seedConfig( static function( unifiedConfig $c ) use ( $keyDir ): void {
				$c->services->auth   = new \gcgov\framework\models\config\services\auth();
				$c->jwtAuth->keyPath = $keyDir;
			} );

			$response = ( new health() )->ready();

			$this->assertSame( 200, $response->getHttpStatus() );
			$this->assertSame( 'ok', $response->getData()[ 'checks' ][ 'jwtKeys' ] );
		}
		finally {
			unlink( $keyDir . '/guids.json' );
			unlink( $keyDir . '/private-abc.pem' );
			unlink( $keyDir . '/public-abc.pem' );
			rmdir( $keyDir );
		}
	}


	/** Readiness without the auth service must not demand keys nothing will read. */
	public function testJwtKeysAreNotCheckedWhenAuthIsDisabled(): void {
		$this->seedConfig();

		$response = ( new health() )->ready();

		$this->assertSame( 200, $response->getHttpStatus() );
		$this->assertArrayNotHasKey( 'jwtKeys', $response->getData()[ 'checks' ] );
	}


	/**
	 * The probe carries its own short timeouts so an unreachable database cannot park a
	 * worker for the driver's 30s default — with a probe every few seconds that exhausts
	 * the pool and 502s real traffic.
	 */
	public function testProbeTimeoutIsSizedToAProbeInterval(): void {
		$timeout = ( new \ReflectionClassConstant( health::class, 'PROBE_TIMEOUT_MS' ) )->getValue();

		$this->assertLessThanOrEqual( 5000, $timeout, 'a readiness probe must fail fast' );
		$this->assertGreaterThan( 0, $timeout );
	}

}
