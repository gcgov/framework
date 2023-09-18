<?php

namespace gcgov\framework\services\mongodb\tools;


use gcgov\framework\config;


class build {

	/**
	 * @return \gcgov\framework\services\mongodb\updateDeleteResult[]
	 */
	public function standardizeModels() : array {
		$appDir = config::getAppDir();

		//get app files
		$dir      = new \RecursiveDirectoryIterator( $appDir . '/models', \FilesystemIterator::SKIP_DOTS );
		$filter   = new \RecursiveCallbackFilterIterator( $dir, function( $current, $key, $iterator ) {
			if( $iterator->hasChildren() ) {
				return true;
			}
			elseif( $current->isFile() && 'php' === $current->getExtension() ) {
				return true;
			}

			return false;
		} );
		$fileList = new \RecursiveIteratorIterator( $filter );

		$updates = [];

		$classIndex = 0;
		$classCount = iterator_count($fileList);

		/** @var \SplFileInfo $file */
		foreach( $fileList as $file ) {
			//convert file name to be the class name
			$namespace = trim( substr( $file->getPath(), strlen( config::getRootDir() ) ), '/\\' );
			$className = $file->getBasename( '.' . $file->getExtension() );
			$classFqn  = str_replace( '/', '\\', '\\' . $namespace . '\\' . $className );

			try {
				//load the class via reflection
				$classReflection = new \ReflectionClass( $classFqn );

				//check if this class is an instance of our model \gcgov\framework\services\mongodb\model
				if( $classReflection->isSubclassOf( \gcgov\framework\services\mongodb\model::class ) ) {
					error_log('Standardize '.$classFqn.' ('.round((($classIndex+1) / $classCount)*100, 2 ).'%)');
					$queryOrs = $classFqn::mongoFieldsExistsQuery();
					$dbObjects = $classFqn::getAll( [ '$or'=>$queryOrs ]);
					error_log('--'.count($dbObjects));
					foreach($dbObjects as $dbObjectIndex=>&$dbObject) {
						try {
							$item = $classFqn::getOne( $dbObject->_id );
							$updates[] = $classFqn::save( $item );
							error_log('-- '.$classFqn.'::save ('.($dbObjectIndex+1).'/ '.count($dbObjects) . ' - '.round((($dbObjectIndex+1) / count($dbObjects))*100, 2 ).'%)');
						}
						catch( \Exception $e ) {
							error_log($e);
							error_log('-- Failed to save '.$classFqn.' ('.$dbObject->_id.')');
						}
					}
				}
			}
			catch( \ReflectionException $e ) {
				throw new \gcgov\framework\services\mongodb\exceptions\dispatchException( 'Reflection failed on class ' . $classFqn, 500, $e );
			}

			$classIndex++;
		}

		return $updates;

	}

}
