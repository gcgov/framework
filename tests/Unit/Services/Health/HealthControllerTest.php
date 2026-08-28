<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\Health;

use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\models\unifiedConfig;
use gcgov\framework\services\health\controllers\health;
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
