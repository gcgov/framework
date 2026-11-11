<?php
namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;
use Monolog\Logger;


/**
 * The database layer's log: one record per database operation, and its own warnings and
 * errors. It writes only while the first database in mongoDatabases sets `logging` to
 * true, because the volume is a line per operation.
 *
 * Where it writes is logging.destination, the same as services\log: stderr by default, as
 * JSON lines, or {root}/logs/{channel}.log for "file". In a container the host keeps the
 * container's output across a deploy, and a file inside the container goes with it: a record
 * written only to a file reached no collector and never showed in `docker logs`.
 */
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
			$mongoDatabases = config::getMongoDatabases();
		}
		catch( \gcgov\framework\exceptions\configException $e ) {
			return false;
		}
		return isset( $mongoDatabases[ 0 ] ) && $mongoDatabases[ 0 ]->logging;
	}


	/** @var Logger[] */
	private static array $loggers = [];

	private static function getLogger( string $channel = '' ): Logger {
		if( isset( self::$loggers[ $channel ] ) ) {
			return self::$loggers[ $channel ];
		}

		if( $channel === '' ) {
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

		self::$loggers[ $channel ] = new Logger( $channel, \gcgov\framework\services\log::buildHandlers( $channel ) );

		return self::$loggers[ $channel ];
	}

}
