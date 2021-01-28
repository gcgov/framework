<?php


namespace gcgov\framework;


use gcgov\framework\exceptions\controllerException;
use gcgov\framework\models\routeHandler;
use gcgov\framework\interfaces\controller;


final class renderer {

	public function __construct() {

	}


	/**
	 * @param  \gcgov\framework\models\routeHandler  $routeHandler
	 *
	 * @return string
	 */
	public function render( routeHandler $routeHandler ) : string {

		$controllerContent = $this->getContentFromController( $routeHandler );

		//process controller output
		return $this->processOutput( $controllerContent );

	}

	private function getContentFromController( routeHandler $routeHandler) : array {
		//exceptions raised here are all logic exceptions: a route is defined that points to a controllerClass or method that does not exist
		try {

			$controllerClass = new \ReflectionClass( $routeHandler->class );

			$method = $controllerClass->getMethod( $routeHandler->method );

			//lifecycle before call
			$this->lifecycleControllerBefore( $controllerClass );

			//run controller method
			try {
				if( $method->isStatic() ) {
					$instance = $controllerClass->newInstanceWithoutConstructor();
				}
				else {
					$instance = $controllerClass->newInstance();
				}

				$controllerResult = $method->invokeArgs( $instance, $routeHandler->arguments );

				//lifecycle after call
				$this->lifecycleControllerAfter( $instance );

				return $controllerResult;
			}
			catch( controllerException $e ) {
				\error_log( $e );
				return \app\renderer::processControllerException( $e );
			}
			catch( \Exception | \Error | \ErrorException $e ) {
				\error_log( $e );
				return \app\renderer::processSystemErrorException( $e );
			}


		}
		catch( \ReflectionException $e ) {
			error_log( $e );
			return \app\renderer::processSystemErrorException( $e );
		}

	}

	private function processOutput( array $controllerContent ) : string {
		if( isset( $controllerContent[ 'data' ] ) ) {
			header( 'Content-Type:application/json' );
			$encodedResponse = json_encode( $controllerContent[ 'data' ] );
			if( $encodedResponse === false ) {
				return \app\renderer::processSystemErrorException( new \Error( 'JSON encoding of data failed', 0 ));
			}

			return $encodedResponse;
		}

		//LOGIC ERROR: view and vars for templated views does not exist
		throw new \Error( 'View and vars not implemented' );
	}


	/**
	 * @param  \ReflectionClass  $controllerClass
	 *
	 * @throws \ReflectionException
	 */
	private function lifecycleControllerBefore( \ReflectionClass $controllerClass ) {

		$controllerInstance = $controllerClass->newInstanceWithoutConstructor();

		$_beforeMethod = $controllerClass->getMethod( '_before' );

		$_beforeMethod->invoke( $controllerInstance );

	}


	/**
	 * @param  controller  $controllerInstance
	 *
	 */
	private function lifecycleControllerAfter( controller $controllerInstance ) {

		$controllerInstance::_after();

	}

}