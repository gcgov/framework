<?php

namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;
use gcgov\framework\services\mongodb\tools\reflectionCache\reflectionCacheClass;
use gcgov\framework\services\mongodb\typeHelpers;

/**
 * Multi-tier reflection metadata cache:
 * - in-memory (static)
 * - APCu (if enabled)
 * - disk (serialized snapshot, invalidated by class filemtime)
 */
final class reflectionCache {

	/** @var array<string, reflectionCacheClass> */
	private static array $memory = [];

	private const APC_PREFIX = 'gcgov:rc:v2:';

	/**
	 * @throws \ReflectionException
	 */
	public static function getReflectionClass( string $className ): reflectionCacheClass {
		$fqn = typeHelpers::classNameToFqn($className);

		// Fast path: in-memory
		if (isset(self::$memory[$fqn])) {
			return self::$memory[$fqn];
		}

		$rClass = new \ReflectionClass($fqn);
		$file   = $rClass->getFileName() ?: '';
		$mtime  = $file !== '' ? @filemtime($file) ?: 0 : 0;

		// APCu
		$snapshot = null;
		$apcKey   = self::APC_PREFIX.$fqn;

		if (\function_exists('apcu_fetch')) {
			$cached = apcu_fetch($apcKey);
			if (is_array($cached) && ($cached['filemtime'] ?? -1) === $mtime) {
				$snapshot = $cached;
			}
		}

		// Disk
		if ($snapshot === null) {
			$diskPath = self::diskPathFor($fqn);
			if (is_file($diskPath)) {
				$raw = @file_get_contents($diskPath);
				if ($raw !== false) {
					$decoded = @unserialize($raw);
					if (is_array($decoded) && ($decoded['filemtime'] ?? -1) === $mtime) {
						$snapshot = $decoded;
						// warm APCu
						if (\function_exists('apcu_store')) {
							apcu_store($apcKey, $snapshot);
						}
					}
				}
			}
		}

		// Build fresh
		if ($snapshot === null) {
			$snapshot = reflectionCacheClass::buildSnapshotFromReflection($rClass);

			// store APCu
			if (\function_exists('apcu_store')) {
				apcu_store($apcKey, $snapshot);
			}

			// store disk
			@file_put_contents(self::diskPathFor($fqn), serialize($snapshot), \LOCK_EX);
		}

		$meta = reflectionCacheClass::fromSnapshot($snapshot, $rClass);
		self::$memory[$fqn] = $meta;
		return $meta;
	}


	private static function diskPathFor(string $fqn): string {
		if(!is_dir(config::getTempDir() . '/gcgov-reflection-cache/')) {
			@mkdir(config::getTempDir() . '/gcgov-reflection-cache/', 0777, true);
		}
		return config::getTempDir() . '/gcgov-reflection-cache/' . \sha1($fqn) . '.cache.ser';
	}
}
