<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Services\CronMonitor;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Promise\PromiseInterface;
use gcgov\framework\services\cronMonitor\cronMonitor;

#[CoversClass(cronMonitor::class)]
final class CronMonitorTest extends TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $requestHistory;

	protected function setUp(): void {
		$this->requestHistory = [];
		$this->primeFrameworkConfig();
	}


	/** The monitor url moved out of the untyped appDictionary into its own section. */
	private function primeFrameworkConfig(): void {
		$config = ( new \ReflectionProperty( \gcgov\framework\config::class, 'unifiedConfig' ) )->getValue();
		$config->cronMonitor->url = 'http://monitor.test/';
	}

	public function testClassExposesEndMethodReturningVoid(): void {
		$reflection = new \ReflectionClass( cronMonitor::class );
		$this->assertTrue( $reflection->hasMethod( 'end' ) );
		$this->assertSame( 'void', (string) $reflection->getMethod( 'end' )->getReturnType() );
	}

	public function testConstructorStoresJobIdAndFiresStartRequest(): void {
		$monitor = $this->buildMonitorWithMockedTransport( 'job-001', [
			new Response( 200, [], json_encode( [ 'data' => 'run-xyz' ] ) ),
			new Response( 200, [], '' ),
		] );

		$this->assertSame( 'job-001', $this->reflectProperty( $monitor, 'jobId' ) );
		$this->assertInstanceOf( PromiseInterface::class, $this->reflectProperty( $monitor, 'jobPromise' ) );

		$monitor->end();
		$this->assertCount( 2, $this->requestHistory );
		$this->assertSame( 'http://monitor.test/jobHistory/start/job-001', (string) $this->requestHistory[0][ 'request' ]->getUri() );
		$this->assertSame( 'http://monitor.test/jobHistory/end/job-001/run-xyz', (string) $this->requestHistory[1][ 'request' ]->getUri() );
	}

	public function testEndDefaultsRunIdToEmptyWhenStartResponseIsInvalidJson(): void {
		$monitor = $this->buildMonitorWithMockedTransport( 'job-002', [
			new Response( 200, [], 'not-json' ),
			new Response( 200, [], '' ),
		] );
		$monitor->end();

		$this->assertCount( 2, $this->requestHistory );
		$this->assertSame( 'http://monitor.test/jobHistory/end/job-002/', (string) $this->requestHistory[1][ 'request' ]->getUri() );
	}

	public function testEndSwallowsExceptionsFromFinalRequest(): void {
		$monitor = $this->buildMonitorWithMockedTransport( 'job-004', [
			new Response( 200, [], json_encode( [ 'data' => 'run-abc' ] ) ),
			new \RuntimeException( 'network is down' ),
		] );

		$monitor->end();
		$this->assertCount( 2, $this->requestHistory );
	}

	public function testEndSwallowsStartPromiseExceptionAndStillFiresEndRequest(): void {
		// The start-promise wait() is now wrapped in the same try/catch as
		// the JSON-decoding step, so an exception from the start request
		// is swallowed and the end request still fires with an empty runId.
		$monitor = $this->buildMonitorWithMockedTransport( 'job-005', [
			new \RuntimeException( 'start failed' ),
			new Response( 200, [], '' ),
		] );

		$monitor->end();
		$this->assertCount( 2, $this->requestHistory );
		$this->assertSame( 'http://monitor.test/jobHistory/end/job-005/', (string) $this->requestHistory[1][ 'request' ]->getUri() );
	}

	/**
	 * @param  list<\Throwable|Response>  $queue
	 */
	private function buildMonitorWithMockedTransport( string $jobId, array $queue ): cronMonitor {
		$monitor = ( new \ReflectionClass( cronMonitor::class ) )->newInstanceWithoutConstructor();

		$mock = new MockHandler( $queue );
		$stack = HandlerStack::create( $mock );
		$stack->push( Middleware::history( $this->requestHistory ) );
		$client = new Client( [ 'base_uri' => 'http://monitor.test/', 'handler' => $stack ] );

		$jobIdProp = new \ReflectionProperty( cronMonitor::class, 'jobId' );
		$jobIdProp->setValue( $monitor, $jobId );
		$clientProp = new \ReflectionProperty( cronMonitor::class, 'client' );
		$clientProp->setValue( $monitor, $client );
		$promiseProp = new \ReflectionProperty( cronMonitor::class, 'jobPromise' );
		$promiseProp->setValue( $monitor, $client->requestAsync( 'GET', 'jobHistory/start/' . $jobId ) );

		return $monitor;
	}

	private function reflectProperty( cronMonitor $monitor, string $name ): mixed {
		$prop = new \ReflectionProperty( cronMonitor::class, $name );
		return $prop->getValue( $monitor );
	}


}
