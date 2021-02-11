<?php
namespace gcgov\framework\services;


use Monolog\Logger;
use Monolog\Handler\StreamHandler;


final class log {

	public static function debug( $message, $context = [] ) {
		$logger = self::getLogger();
		$logger->debug( $message, $context );
	}


	private static function getLogger() : Logger {
		$channel = \gcgov\framework\config::getAppConfig()?->app?->title ?? 'app';

		// Create the logger
		$logger = new Logger( $channel );

		// Now add some handlers
		$logger->pushHandler( new StreamHandler( \gcgov\framework\config::getRootDir() . '/logs/' . $channel . '.log', Logger::DEBUG ) );

		return $logger;
	}


	public static function info( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->info( $message, (array) $context );
	}


	public static function notice( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->notice( $message, (array) $context );
	}


	public static function warning( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->warning( $message, (array) $context );
	}


	public static function error( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->error( $message, (array) $context );
	}


	public static function critical( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->critical( $message, (array) $context );
	}


	public static function alert( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->alert( $message, (array) $context );
	}


	public static function emergency( $message, $context = null ) {
		$logger = self::getLogger();
		$logger->emergency( $message, (array) $context );
	}

}