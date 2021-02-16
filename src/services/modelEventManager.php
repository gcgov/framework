<?php

namespace gcgov\framework\services;


use gcgov\framework\exceptions\eventException;
use gcgov\framework\config;
use JetBrains\PhpStorm\Pure;


class modelEventManager {

	private int $chain = 0;

	/** @var \gcgov\framework\services\modelEventManager\event[] */
	private array $eventQueue = [];

	/** @var \MongoDB\UpdateResult[] */
	private array $responses = [];

	/** @var \gcgov\framework\services\modelEventManager\classInformation[] Associative array where key=classFQN and value is \gcgov\framework\services\events\classInformation */
	private array $appClassesInformation = [];


	public function __construct() {
		\gcgov\framework\services\log::debug( 'Create event queue');
		$this->setAppClassesInformation();
	}


	/**
	 * @param  \gcgov\framework\services\modelEventManager\event  $event
	 */
	public function queue( \gcgov\framework\services\modelEventManager\event $event ) : void {
		//LOGIC ERROR CAPTURE AT RUNTIME -- sucks
		//verify that this method exists on this interface
		try {
			$eventInterface = new \ReflectionClass( $event->interfaceFqn );
			if( !$eventInterface->isInterface() || !$eventInterface->implementsInterface( 'gcgov\framework\interfaces\event\definitions' ) ) {
				throw new \gcgov\framework\exceptions\eventException( $event->interfaceFqn . ' either is not an interface or does not implement gcgov\framework\interfaces\event\definitions', 500 );
			}
			$method = $eventInterface->getMethod( $event->methodName );
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\exceptions\eventException( 'Method ' . $event->methodName . ' does not exist on ' . $event->interfaceFqn, 500, $e );
		}

		//verify that the provided interface exists
		if( !isset( $this->appClassesInformation[ $event->interfaceFqn ] ) ) {
			throw new \gcgov\framework\exceptions\eventException( $event->interfaceFqn . ' either does not implement interface \gcgov\framework\services\events\events or it is outside of the app directory scope', 500 );
		}

		\gcgov\framework\services\log::debug( $this->chain.' Add event to queue: ' . $event->interfaceFqn . '\\' . $event->methodName . '()' );

		$this->eventQueue[ $event->_id ] = $event;
	}


	/**
	 * @return \MongoDB\UpdateResult[]
	 */
	public function execute() : array {
		foreach( $this->eventQueue as $eventId => $event ) {
			$this->dispatch( $event );
			unset($this->eventQueue[ $event->_id ]);
		}

		if(count($this->eventQueue)>0) {
			$this->chain++;
			\gcgov\framework\services\log::debug( $this->chain.' Execute chained events');
			$this->execute();
		}

		return $this->responses;
	}


	/**
	 * @param  \gcgov\framework\services\modelEventManager\event  $event
	 */
	private function dispatch( \gcgov\framework\services\modelEventManager\event $event ) : void  {
		\gcgov\framework\services\log::debug( $this->chain.' Dispatch event: ' . $event->interfaceFqn . '\\' . $event->methodName . '()' );

		//find classes that implement this event interface
		foreach( $this->appClassesInformation as $appReflection ) {
			//this class implements this event interface
			if( in_array( $event->interfaceFqn, $appReflection->getInterfaceNames() ) ) {
				try {
					\gcgov\framework\services\log::debug( $this->chain.' Call event listener: ' . $appReflection->getClass()
					                                                               ->getName() . '\\' . $event->methodName . '()' );
					$arguments = [ $this ];
					foreach($event->methodArguments as $argument) {
						$arguments[] = $argument;
					}
					$eventResponse = $appReflection->callMethod( $event->methodName, $arguments );
					$this->addResponse( $eventResponse );
				}
				catch( \ReflectionException $e ) {
					throw new eventException( 'Method ' . $event->methodName . ' could not be called with the provided parameters', 500, $e );
				}
			}
		}

	}


	/**
	 * @param  \MongoDB\UpdateResult|\MongoDB\UpdateResult[]  $eventResponse
	 *
	 * @return \MongoDB\UpdateResult[] All manager responses included the newly added
	 */
	private function addResponse( \MongoDB\UpdateResult|array $eventResponse ) : array {
		if(  $eventResponse instanceof \MongoDB\UpdateResult ) {
			$this->responses [] = $eventResponse;
		}
		else {
			foreach($eventResponse as $response) {
				$this->responses[] = $response;
			}
		}

		return $this->responses;
	}


	/**
	 * @return \gcgov\framework\services\modelEventManager\classInformation[]
	 */
	private function setAppClassesInformation() : array {
		$appDir = config::getAppDir();

		//get app files
		$dir      = new \RecursiveDirectoryIterator( $appDir, \RecursiveDirectoryIterator::SKIP_DOTS );
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

		/** @var \SplFileInfo $file */
		foreach( $fileList as $file ) {
			$fqn = $this->convertFileToClassFqn( $file );

			//create event reflection
			$classInformation = new \gcgov\framework\services\modelEventManager\classInformation( $fqn );
			if( in_array( 'gcgov\framework\interfaces\event\definitions', $classInformation->getInterfaceNames() ) || in_array( 'gcgov\framework\interfaces\event\listeners', $classInformation->getInterfaceNames() ) ) {
				$this->appClassesInformation[ $fqn ] = $classInformation;
			}
		}

		return $this->appClassesInformation;
	}


	private function convertFileToClassFqn( \SplFileInfo $file ) : string {
		$namespace = trim( substr( $file->getPath(), strlen( config::getRootDir() ) ), '/\\' );
		$className = $file->getBasename( '.' . $file->getExtension() );

		return $namespace . '\\' . $className;
	}

}