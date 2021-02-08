<?php
namespace gcgov\framework\services;


use gcgov\framework\config;
use gcgov\framework\exceptions\eventException;


final class events {

	private static \RecursiveIteratorIterator $appFileList;

	/** @var \gcgov\framework\services\events\classInformation[] Associative array where key=classFQN and value is \gcgov\framework\services\events\classInformation */
	private static array $appClassesInformation = [];


	/**
	 * Trigger an event to launch all listeners
	 *
	 * @param  string  $eventInterfaceFqn
	 * @param  string  $eventMethodName
	 * @param  array   $eventMethodArguments
	 *
	 * @return array
	 * @throws \gcgov\framework\exceptions\eventException
	 */
	public static function trigger( string $eventInterfaceFqn, string $eventMethodName, array $eventMethodArguments = [] ) : array {

		//get all available event interfaces and listeners as array of classInformation
		$appClassesInformation = self::getAppClassesInformation();

		//verify that the provided interface exists
		if( !isset( $appClassesInformation[ $eventInterfaceFqn ] ) ) {
			throw new \gcgov\framework\exceptions\eventException( $eventInterfaceFqn . ' either does not implement interface \gcgov\framework\services\events\events or it is outside of the app directory scope', 500 );
		}

		$results = [];

		//find classes that implement this event interface
		foreach( $appClassesInformation as $appReflection ) {

			//this class implements this event interface
			if( in_array( $eventInterfaceFqn, $appReflection->getInterfaceNames() ) ) {
				try {
					$results[] = $appReflection->callMethod( $eventMethodName, $eventMethodArguments );
				}
				catch( \ReflectionException $e ) {
					throw new eventException( 'Method ' . $eventMethodName . ' could not be called with the provided parameters', 500, $e );
				}

			}

		}

		return $results;

	}


	/**
	 * @return \gcgov\framework\services\events\classInformation[]
	 */
	private static function getAppClassesInformation() : array {

		if( count( self::$appClassesInformation ) == 0 ) {
			self::setAppClassesInformation();
		}

		return self::$appClassesInformation;
	}


	/**
	 * @return \gcgov\framework\services\events\classInformation[]
	 */
	private static function setAppClassesInformation() : array {

		$fileList = self::getAppFileList();

		/** @var \SplFileInfo $file */
		foreach( $fileList as $file ) {
			$fqn = self::convertFileToClassFqn( $file );

			//create event reflection
			$classInformation = new events\classInformation( $fqn );
			if( in_array( 'gcgov\framework\interfaces\event\definitions', $classInformation->getInterfaceNames() ) || in_array( 'gcgov\framework\interfaces\event\listeners', $classInformation->getInterfaceNames() ) ) {
				self::$appClassesInformation[ $fqn ] = $classInformation;
			}

		}

		return self::$appClassesInformation;

	}


	/**
	 * @return \RecursiveIteratorIterator
	 */
	private static function getAppFileList() : \RecursiveIteratorIterator {

		if( !isset( self::$appFileList ) || !( self::$appFileList instanceof \RecursiveIteratorIterator ) ) {
			self::setAppFileList();
		}

		return self::$appFileList;
	}


	/**
	 * @return \RecursiveIteratorIterator
	 */
	private static function setAppFileList() : \RecursiveIteratorIterator {

		$appDir = config::getAppDir();

		$dir    = new \RecursiveDirectoryIterator( $appDir, \RecursiveDirectoryIterator::SKIP_DOTS );
		$filter = new \RecursiveCallbackFilterIterator( $dir, function( $current, $key, $iterator ) {

			if( $iterator->hasChildren() ) {
				return true;
			}
			elseif( $current->isFile() && 'php' === $current->getExtension() ) {
				return true;
			}

			return false;
		} );

		self::$appFileList = new \RecursiveIteratorIterator( $filter );

		return self::$appFileList;
	}


	private static function convertFileToClassFqn( \SplFileInfo $file ) : string {

		$namespace = trim( substr( $file->getPath(), strlen( config::getRootDir() ) ), '/\\' );
		$className = $file->getBasename( '.' . $file->getExtension() );

		return $namespace . '\\' . $className;
	}


}