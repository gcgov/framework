<?php
namespace gcgov\framework\services;


use Monolog\Logger;
use Monolog\Handler\StreamHandler;


final class log {

	public static function debug( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->debug( $message, $context );
	}


	public static function info( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->info( $message, $context );
	}


	public static function notice( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->notice( $message, $context );
	}


	public static function warning( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->warning( $message, $context );
	}


	public static function error( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->error( $message, $context );
	}


	public static function critical( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->critical( $message, $context );
	}


	public static function alert( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->alert( $message, $context );
	}


	public static function emergency( string $channel, string $message, array $context = [] ) {
		$logger = self::getLogger( $channel );
		$logger->emergency( $message, $context );
	}


	private static function getLogger( string $channel = '' ) : Logger {
		if( empty( $channel ) ) {
			$channel = \gcgov\framework\config::getAppConfig()?->app?->title ?? 'app';
		}

		// Create the logger
		$logger = new Logger( $channel );

		// Now add some handlers
		$logger->pushHandler( new StreamHandler( \gcgov\framework\config::getRootDir() . '/logs/' . $channel . '.log', Logger::DEBUG ) );

		return $logger;
	}

}