<?php

namespace gcgov\framework\services\modelEventManager;


class event {

	public string $_id;

	public string $interfaceFqn    = '';

	public string $methodName      = '';

	public array  $methodArguments = [];


	public function __construct( string $eventInterfaceFqn, string $eventMethodName, array $eventMethodArguments = [] ) {
		$this->_id             = (string) new \MongoDB\BSON\ObjectId();
		$this->interfaceFqn    = $eventInterfaceFqn;
		$this->methodName      = $eventMethodName;
		$this->methodArguments = $eventMethodArguments;
	}

}