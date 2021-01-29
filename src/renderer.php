<?php


namespace gcgov\framework;


use gcgov\framework\exceptions\controllerException;
use gcgov\framework\models\routeHandler;
use gcgov\framework\interfaces\controller;
use gcgov\framework\interfaces\_controllerResponse;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerViewResponse;


final class renderer {

	public function __construct() {

	}


	/**
	 * @param  \gcgov\framework\models\routeHandler  $routeHandler
	 *
	 * @return string
	 */
	public function render( routeHandler $routeHandler ) : string {

		$controllerResponse = $this->getContentFromController( $routeHandler );

		//process controller output
		if($controllerResponse instanceof controllerDataResponse) {
			return $this->processControllerDataResponse( $controllerResponse );
		}
		elseif($controllerResponse instanceof controllerViewResponse) {
			return $this->processControllerViewResponse( $controllerResponse );
		}

	}

	private function getContentFromController( routeHandler $routeHandler) : controllerDataResponse|controllerViewResponse {
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


	/**
	 * @param  \gcgov\framework\models\controllerDataResponse  $controllerDataResponse
	 *
	 * @return string
	 */
	private function processControllerDataResponse( controllerDataResponse $controllerDataResponse ) : string {

		header( 'Content-Type:application/json' );

		$encodedResponse = json_encode( $controllerDataResponse->getData() );
		if( $encodedResponse === false ) {
			return \app\renderer::processSystemErrorException( new \LogicException( 'JSON encoding of controller->data failed', 0 ));
		}

		return $encodedResponse;

	}

	private function processControllerViewResponse(controllerViewResponse $controllerViewResponse) : string {

		//LOGIC ERROR: view and vars for templated views does not exist
		throw new \LogicException( 'View and vars not implemented' );
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