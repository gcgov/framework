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
	 * @param string[]  $parentContexts
	 *
	 * @return \gcgov\framework\services\mongodb\typeMap
	 */
	public static function get( string $className, array $parentContexts=[] ): \gcgov\framework\services\mongodb\typeMap {
		$calledClassFqn = typeHelpers::classNameToFqn( $className );

		//cache key allows a typemap to be cached for an embeddable property for each location in a call tree it exists in
		// this allows us to respect the #[excludeFromTypemapWhenThisClassNotRoot] attribute to limit the potential for
		// an infinite loop from circular references while generating typemaps
		$cacheKey = $calledClassFqn;
		if(count($parentContexts)>0) {
			$cacheKey = implode('.',$parentContexts).'.'.$calledClassFqn;
		}

		//generate typemap if it does not exist
		if( !isset( self::$typeMaps[ $cacheKey ] ) ) {
			$typeMap =  new \gcgov\framework\services\mongodb\typeMap( $calledClassFqn, [], $parentContexts );
			//store typemap
			self::$typeMaps[ $cacheKey ] = $typeMap;
			//store root model typemaps in model typemaps
			if( $typeMap->model && count($parentContexts)==0 ) {
				self::$modelTypeMaps[ $cacheKey ] = $typeMap;
			}
		}

		//return typemap from mem cache
		return self::$typeMaps[ $cacheKey ];
	}


//	private static function set( string $className, \gcgov\framework\services\mongodb\typeMap $typeMap ): void {
//		$calledClassFqn = typeHelpers::classNameToFqn( $className );
//		self::$typeMaps[ $calledClassFqn ] = $typeMap;
//		if( $typeMap->model ) {
//			self::$modelTypeMaps[ $calledClassFqn ] = $typeMap;
//		}
//	}


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
