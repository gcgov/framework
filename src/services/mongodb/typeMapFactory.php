<?php

namespace gcgov\framework\services\mongodb;


use gcgov\framework\config;


/**
 * Class typeMapFactory
 * @see     https://www.php.net/manual/en/mongodb.persistence.deserialization.php
 * @package gcgov\framework\services\mongodb
 */
class typeMapFactory {

	private static bool $allModelTypeMapsFetched = false;

	/** @var \gcgov\framework\services\mongodb\typeMap[] */
	private static array $typeMaps = [];

	/** @var \gcgov\framework\services\mongodb\typeMap[] */
	private static array $modelTypeMaps = [];


	/**
	 * @param string $className
	 *
	 * @return \gcgov\framework\services\mongodb\typeMap
	 */
	public static function get( string $className ): \gcgov\framework\services\mongodb\typeMap {
		$calledClassFqn = typeHelpers::classNameToFqn( $className );
		if( !isset( self::$typeMaps[ $calledClassFqn ] ) ) {
			$typeMap =  new \gcgov\framework\services\mongodb\typeMap( $calledClassFqn );
			self::set( $calledClassFqn, $typeMap );
		}
		return self::$typeMaps[ $calledClassFqn ];
	}


	private static function set( string $className, \gcgov\framework\services\mongodb\typeMap $typeMap ): void {
		$calledClassFqn = typeHelpers::classNameToFqn( $className );
		self::$typeMaps[ $calledClassFqn ] = $typeMap;
		if( $typeMap->model ) {
			self::$modelTypeMaps[ $calledClassFqn ] = $typeMap;
		}
	}


	public static function getAllModelTypeMaps(): array {
		if( !self::$allModelTypeMapsFetched ) {
			$appDir = config::getAppDir();

			//get app files
			$dir      = new \RecursiveDirectoryIterator( $appDir . '/models', \FilesystemIterator::SKIP_DOTS );
			$filter   = new \RecursiveCallbackFilterIterator( $dir, function( $current, $key, $iterator ) {
				if( $iterator->hasChildren() ) {
					return true;
				}
				elseif( $current->isFile() && 'php'===$current->getExtension() ) {
					return true;
				}

				return false;
			} );
			$fileList = new \RecursiveIteratorIterator( $filter );

			/** @var \SplFileInfo $file */
			foreach( $fileList as $file ) {
				//convert file name to be the class name
				$namespace = trim( substr( $file->getPath(), strlen( config::getRootDir() ) ), '/\\' );
				$className = $file->getBasename( '.' . $file->getExtension() );
				$classFqn  = typeHelpers::classNameToFqn( str_replace( '/', '\\', '\\' . $namespace . '\\' . $className ) );

				self::get( $classFqn );
			}

			self::$allModelTypeMapsFetched = true;
		}

		return self::$modelTypeMaps;
	}


}