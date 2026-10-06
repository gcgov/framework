<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Support;

use gcgov\framework\services\log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\After;

/**
 * Capture what a test causes the framework to log, instead of letting it reach the console.
 *
 * A test that exercises an error path makes the framework log about it, and log's default
 * destination is stderr — so a green run prints JSON warnings and stack traces that read
 * like failures and bury the ones that are. Swapping in a Monolog TestHandler stops that,
 * and turns the record into something the test can assert: "this path warns, and says
 * which dependency failed" is usually the behaviour worth pinning anyway.
 *
 * Restoration runs through #[After] rather than tearDown() so this composes with
 * seedsFrameworkConfig, which already defines one.
 */
trait capturesFrameworkLog {

	/** @var array<string, \Monolog\Logger|null> channel => the logger that was cached before */
	private array $capturedFrameworkLogChannels = [];


	/**
	 * Route one channel's records into a TestHandler for the rest of this test.
	 *
	 * The channel is the first argument to log::warning() and friends at the call site
	 * under test — 'health', 'Framework Lifecycle', and so on.
	 */
	protected function captureLog( string $channel ): TestHandler {
		$loggers = new \ReflectionProperty( log::class, 'loggers' );
		/** @var array<string, \Monolog\Logger> $current */
		$current = $loggers->getValue();

		if( !array_key_exists( $channel, $this->capturedFrameworkLogChannels ) ) {
			$this->capturedFrameworkLogChannels[ $channel ] = $current[ $channel ] ?? null;
		}

		$handler            = new TestHandler();
		$current[ $channel ] = new Logger( $channel, [ $handler ] );
		$loggers->setValue( null, $current );

		return $handler;
	}


	#[After]
	protected function restoreCapturedFrameworkLog(): void {
		if( count( $this->capturedFrameworkLogChannels )===0 ) {
			return;
		}

		$loggers = new \ReflectionProperty( log::class, 'loggers' );
		/** @var array<string, \Monolog\Logger> $current */
		$current = $loggers->getValue();

		foreach( $this->capturedFrameworkLogChannels as $channel => $previous ) {
			if( $previous===null ) {
				unset( $current[ $channel ] );
			}
			else {
				$current[ $channel ] = $previous;
			}
		}
		$loggers->setValue( null, $current );

		$this->capturedFrameworkLogChannels = [];
	}

}
