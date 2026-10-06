<?php
namespace gcgov\framework\services;

use Monolog\Logger;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;

final class log {

	public static function debug( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->debug( $message, $context );
	}


	public static function info( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->info( $message, $context );
	}


	public static function notice( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->notice( $message, $context );
	}


	public static function warning( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->warning( $message, $context );
	}


	public static function error( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->error( $message, $context );
	}


	public static function critical( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->critical( $message, $context );
	}


	public static function alert( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->alert( $message, $context );
	}


	public static function emergency( string $channel, string $message, array $context = [] ): void {
		$logger = self::getLogger( $channel );
		$logger->emergency( $message, $context );
	}


	/** @var Logger[] */
	private static array $loggers = [];


	private static function getLogger( string $channel = '' ): Logger {
		if( isset( self::$loggers[ $channel ] ) ) {
			return self::$loggers[ $channel ];
		}

		if( empty( $channel ) ) {
			try {
				$channel = \gcgov\framework\config::getApp()->title;
				if( $channel === '' ) {
					$channel = 'app';
				}
			}
			catch( \gcgov\framework\exceptions\configException $e ) {
				$channel = 'app';
			}
		}

		self::$loggers[ $channel ] = new Logger( $channel, self::buildHandlers( $channel ) );

		return self::$loggers[ $channel ];
	}


	/**
	 * Handlers for the configured destination.
	 *
	 * stderr is the default and what a container needs: its filesystem does not survive
	 * a deploy, so file logs would be per-replica and destroyed on every release. Records
	 * go out as JSON lines so a collector can query them. Applications still hosted on
	 * IIS set logging.destination to "file".
	 *
	 * Configuration may itself be unreadable when something fails early in the lifecycle,
	 * and a logger that throws while reporting an error is worse than a misplaced log —
	 * so an unreadable config falls back to stderr.
	 *
	 * @return \Monolog\Handler\HandlerInterface[]
	 */
	private static function buildHandlers( string $channel ): array {
		try {
			$logging = \gcgov\framework\config::getLogging();
		}
		catch( \gcgov\framework\exceptions\configException ) {
			return [ self::stderrHandler() ];
		}

		$handlers = [];
		if( $logging->writesToStderr() ) {
			$handlers[] = self::stderrHandler();
		}
		if( $logging->writesToFile() ) {
			$handlers[] = new StreamHandler( \gcgov\framework\config::getRootDir() . '/logs/' . $channel . '.log' );
		}

		// An unrecognised destination still has to log somewhere.
		return count( $handlers )>0 ? $handlers : [ self::stderrHandler() ];
	}


	private static function stderrHandler(): StreamHandler {
		$handler = new StreamHandler( 'php://stderr' );
		$handler->setFormatter( new JsonFormatter() );

		return $handler;
	}

}