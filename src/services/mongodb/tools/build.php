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
					/** @var \gcgov\framework\services\mongodb\getResult $pagedResponse */
					$pagedResponse = $classFqn::getPagedResponse( 10, 1, [ '$or'=>$queryOrs ]);
					$totalDocumentCount = $pagedResponse->getTotalDocumentCount();
					$totalPageCount = $pagedResponse->getTotalPageCount();
					error_log('--'.$totalDocumentCount.' documents');
					error_log('--'.$totalPageCount.' pages');

					$currentDocumentCount = 1;
					for($page=1;$page<=$totalPageCount;$page++) {
						error_log('-- Page '.$page);
						if($page>1) {
							$pagedResponse = $classFqn::getPagedResponse( 10, $page, [ '$or'=>$queryOrs ]);
						}
						$dbObjects = $pagedResponse->getData();

						$maxRetryAttempts = 3;

						foreach($dbObjects as $dbObjectIndex=>&$dbObject) {
							//if a save fails, retry saving it up to $maxRetryAttempts
							for($currentRetryAttempt = 0; $currentRetryAttempt<=$maxRetryAttempts; $currentRetryAttempt++) {
								try {
									$startRead = microtime(true);
									$item = $classFqn::getOne( $dbObject->_id );
									$endRead = microtime(true);
									$startSave = microtime(true);
									$updates[] = $classFqn::save( $item );
									$endSave = microtime(true);
									error_log('-- '.$classFqn.'::save ('.$currentDocumentCount.'/'.$totalDocumentCount . ' - '.round(($currentDocumentCount / $totalDocumentCount)*100, 2 ).'%) - Read: '.round($endRead-$startRead, 2).' seconds - Write: '.round($endSave-$startSave, 2).' seconds');
									break;
								}
								catch( \Exception $e ) {
									error_log($e);
									error_log('-- Failed to save '.$classFqn.' ('.$dbObject->_id.'). Attempt '.($currentRetryAttempt+1).'/'.($maxRetryAttempts+1));
								}
							}

							$currentDocumentCount++;

							/*if($currentDocumentCount>2) {
								break 3;
							}*/
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
