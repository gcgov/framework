<?php
namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;


final class log {

	/** @param array<string, mixed> $context */
	public static function debug( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->debug( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function info( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->info( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function notice( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->notice( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function warning( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->warning( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function error( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->error( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function critical( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->critical( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function alert( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->alert( $message, $context );
	}


	/** @param array<string, mixed> $context */
	public static function emergency( string $channel, string $message, array $context = [] ): void {
		if( !self::isMongoLoggingEnabled() ) {
			return;
		}
		self::getLogger( $channel )->emergency( $message, $context );
	}


	private static function isMongoLoggingEnabled(): bool {
		try {
			$envConfig = config::getEnvironmentConfig();
		}
		catch( \gcgov\framework\exceptions\configException $e ) {
			return false;
		}
		return isset( $envConfig->mongoDatabases[ 0 ] ) && $envConfig->mongoDatabases[ 0 ]->logging;
	}


	/** @var Logger[] */
	private static array $loggers = [];

	private static function getLogger( string $channel = '' ): Logger {
		if( isset( self::$loggers[ $channel ] ) ) {
			return self::$loggers[ $channel ];
		}

		if( $channel === '' ) {
			try {
				$channel = \gcgov\framework\config::getAppConfig()->app->title;
				if( $channel === '' ) {
					$channel = 'app';
				}
			}
			catch( \gcgov\framework\exceptions\configException $e ) {
				$channel = 'app';
			}
		}

		$handlers = [
			new StreamHandler( \gcgov\framework\config::getRootDir() . '/logs/' . $channel . '.log' )
		];

		self::$loggers[ $channel ] = new Logger( $channel, $handlers );

		return self::$loggers[ $channel ];
	}

}
