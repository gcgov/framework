<?php

namespace gcgov\framework\services\modelEventManager;


use gcgov\framework\exceptions\eventException;


class classInformation {

	private \ReflectionClass $class;

	/** @var \ReflectionClass[] A numerical array with interface names of the class as the values */
	//private array $interfaces = [];

	/** @var string[] A numerical array with interface names of the class as the values */
	private array $interfaceNames = [];

	/** @var \ReflectionMethod[] An associative array where the key=method name and value is \ReflectionMethod of the method */
	private array $methods = [];


	/**
	 * classInformation constructor.
	 *
	 * @param  string  $classFqn
	 *
	 * @throws \gcgov\framework\exceptions\eventException
	 */
	public function __construct( string $classFqn ) {
		try {
			$this->class = new \ReflectionClass( $classFqn );
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\exceptions\eventException( 'Reflection failed on class ' . $classFqn, 500 );
		}

		try {
			$this->interfaceNames = $this->class->getInterfaceNames();
		}
		catch( \ReflectionException $e ) {
			throw new \gcgov\framework\exceptions\eventException( 'Getting interface names from ' . $classFqn . ' failed.', 500, $e );
		}
	}


	/**
	 * @return string[]
	 */
	public function getInterfaceNames() : array {
		return $this->interfaceNames;
	}


	/**
	 * @param  string  $methodName
	 * @param  array   $methodParams
	 *
	 * @return mixed
	 * @throws \ReflectionException
	 */
	public function callMethod( string $methodName, array $methodParams ) : mixed {
		if( !isset( $this->methods[ $methodName ] ) ) {
			try {
				$this->methods[ $methodName ] = $this->getClass()->getMethod( $methodName );
			}
			catch( \ReflectionException $e ) {
				throw new eventException( 'Method ' . $methodName . ' does not exist on ' . $this->getClass()->name, 500, $e );
			}
		}

		$instance = $this->createInstance( $this->methods[ $methodName ]->isStatic() );

		return $this->methods[ $methodName ]->invokeArgs( $instance, $methodParams );
	}


	/**
	 * @return \ReflectionClass
	 */
	public function getClass() : \ReflectionClass {
		return $this->class;
	}


	/**
	 * @param  bool  $static
	 *
	 * @return object
	 * @throws \ReflectionException
	 */
	private function createInstance( bool $static ) : object {
		if( $static ) {
			$instance = $this->getClass()->newInstanceWithoutConstructor();
		}
		else {
			$instance = $this->getClass()->newInstance();
		}

		return $instance;
	}

}