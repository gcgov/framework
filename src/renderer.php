<?php

namespace gcgov\framework;

use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\exceptions\routeException;
use gcgov\framework\interfaces\controller;
use gcgov\framework\models\controllerDataResponse;
use gcgov\framework\models\controllerFileBase64EncodedContentResponse;
use gcgov\framework\models\controllerFileResponse;
use gcgov\framework\models\controllerResponse;
use gcgov\framework\models\controllerViewResponse;
use gcgov\framework\models\routeHandler;

final class renderer {

	public function __construct() {
	}


	/**
	 * @param \gcgov\framework\models\routeHandler|\gcgov\framework\exceptions\routeException $routeHandlerOrException
	 *
	 * @return string
	 */
	public function render( routeHandler|routeException $routeHandlerOrException ): string {
		if( $routeHandlerOrException instanceof routeHandler ) {
			$controllerResponse = $this->getContentFromController( $routeHandlerOrException );
		}
		else {
			$controllerResponse = \app\renderer::processRouteException( $routeHandlerOrException );
		}

		//process controller output
		if( $controllerResponse instanceof controllerDataResponse ) {
			return $this->processControllerDataResponse( $controllerResponse );
		}
		elseif( $controllerResponse instanceof controllerViewResponse ) {
			return $this->processControllerViewResponse( $controllerResponse );
		}
		elseif( $controllerResponse instanceof controllerFileBase64EncodedContentResponse ) {
			return $this->processControllerFileBase64EncodedContentResponse( $controllerResponse );
		}
		elseif( $controllerResponse instanceof controllerFileResponse ) {
			return $this->processControllerFileResponse( $controllerResponse );
		}

		return '';
	}


	private function getContentFromController( routeHandler $routeHandler ): controllerResponse {
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
			catch( modelException $e ) {
				\error_log( $e );
				\gcgov\framework\services\log::debug( 'Renderer', $e->getMessage(), $e->getTrace() );

				return \app\renderer::processModelException( $e );
			}
			catch( controllerException $e ) {
				\error_log( $e );
				\gcgov\framework\services\log::debug( 'Renderer', $e->getMessage(), $e->getTrace() );

				return \app\renderer::processControllerException( $e );
			}
			catch( \Exception|\Error|\ErrorException $e ) {
				\error_log( $e );
				\gcgov\framework\services\log::error( 'Renderer', $e->getMessage(), [ $e ] );

				return \app\renderer::processSystemErrorException( $e );
			}
		}
		catch( \ReflectionException $e ) {
			error_log( $e );
			\gcgov\framework\services\log::error( 'Renderer', $e->getMessage(), [ $e ] );

			return \app\renderer::processSystemErrorException( $e );
		}
	}


	/**
	 * @param \gcgov\framework\interfaces\_controllerDataResponse $controllerDataResponse
	 *
	 * @return string
	 */
	private function processControllerDataResponse( \gcgov\framework\interfaces\_controllerDataResponse $controllerDataResponse ): string {
		if( !headers_sent( $filename, $lineNumber ) ) {
			foreach( $controllerDataResponse->getHeaders() as $header ) {
				$header->output();
			}
		}
		else {
			\gcgov\framework\services\log::warning( 'Renderer', 'Cannot set content-type header or additional headers. Headers already sent in ' . $filename . ' on line ' . $lineNumber );
		}

		if( $controllerDataResponse->getHttpStatus()!=200 ) {
			http_response_code( $controllerDataResponse->getHttpStatus() );
			if($controllerDataResponse->getHttpStatus()==204) {
				header( 'Content-Length: 0' );
				return '';
			}
			if( $controllerDataResponse->getData()===null ) {
				return '';
			}
		}

		header( 'Content-Type:' . $controllerDataResponse->getContentType() );


		if( $controllerDataResponse->getContentType()==='application/json' ) {
			$encodedResponse = json_encode( $controllerDataResponse->getData() );
			if( $encodedResponse===false ) {
				return \app\renderer::processSystemErrorException( new \LogicException( 'JSON encoding of controller->data failed', 0 ) );
			}
		}
		elseif( $controllerDataResponse->getContentType()==='text/plain' ) {
			$encodedResponse = (string)$controllerDataResponse->getData();
		}
		else {
			return \app\renderer::processSystemErrorException( new \LogicException( 'Unsupported content-type provided in controller response', 500 ) );
		}

		return $encodedResponse;
	}


	/**
	 * @param \gcgov\framework\interfaces\_controllerFileResponse $controllerFileResponse
	 *
	 * @return string
	 */
	private function processControllerFileResponse( \gcgov\framework\interfaces\_controllerFileResponse $controllerFileResponse ): string {
		if( !headers_sent( $fileBasename, $lineNumber ) ) {
			foreach( $controllerFileResponse->getHeaders() as $header ) {
				$header->output();
			}
		}
		else {
			\gcgov\framework\services\log::warning( 'Renderer', 'Cannot set content-type header or additional headers. Headers already sent in ' . $fileBasename . ' on line ' . $lineNumber );
		}

		if( $controllerFileResponse->getHttpStatus()!=200 ) {
			http_response_code( $controllerFileResponse->getHttpStatus() );
			if($controllerFileResponse->getHttpStatus()==204) {
				header( 'Content-Length: 0' );
				return '';
			}
			if( $controllerFileResponse->getFilePathname()==='' ) {
				return '';
			}
		}

		header( 'Content-Type:' . $controllerFileResponse->getContentType() );

		$fileBasename = basename($controllerFileResponse->getFilePathname());
		header( 'x-filename: ' . $fileBasename );
		header( 'Content-Description: File Transfer' );
		if( isset( $_GET[ 'download' ] ) ) {
			header( 'Content-Disposition: attachment; filename=' . $fileBasename );
		}
		else {
			header( 'Content-Disposition: inline; filename=' . $fileBasename );
		}
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );

		$encodedResponse = file_get_contents( $controllerFileResponse->getFilePathname() );

		return $encodedResponse;
	}


	/**
	 * @param \gcgov\framework\models\controllerFileBase64EncodedContentResponse $controllerResponse
	 *
	 * @return string
	 */
	private function processControllerFileBase64EncodedContentResponse( \gcgov\framework\models\controllerFileBase64EncodedContentResponse $controllerResponse ): string {
		if( !headers_sent( $fileBasename, $lineNumber ) ) {
			foreach( $controllerResponse->getHeaders() as $header ) {
				$header->output();
			}
		}
		else {
			\gcgov\framework\services\log::warning( 'Renderer', 'Cannot set content-type header or additional headers. Headers already sent in ' . $fileBasename . ' on line ' . $lineNumber );
		}

		if( $controllerResponse->getHttpStatus()!=200 ) {
			http_response_code( $controllerResponse->getHttpStatus() );
			if($controllerResponse->getHttpStatus()==204) {
				header( 'Content-Length: 0' );
				return '';
			}
			if( $controllerResponse->getFilePathname()==='' ) {
				return '';
			}
		}

		header( 'Content-Type:' . $controllerResponse->getContentType() );

		$fileName = $controllerResponse->getFilePathname();
		header( 'x-filename: ' . $fileName );
		header( 'Content-Description: File Transfer' );
		if( isset( $_GET[ 'download' ] ) ) {
			header( 'Content-Disposition: attachment; filename=' . $fileName );
		}
		else {
			header( 'Content-Disposition: inline; filename=' . $fileName );
		}
		header( 'Content-Transfer-Encoding: binary' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );
		header( 'Pragma: public' );

		$encodedResponse = base64_decode( $controllerResponse->getBase64EncodedContent() );

		return $encodedResponse;
	}


	private function processControllerViewResponse( controllerViewResponse $controllerViewResponse ): string {
		$viewFile      = $controllerViewResponse->getView();
		$varsToHydrate = $controllerViewResponse->getVars();
		try {
			ob_start();
			foreach( $varsToHydrate as $key => $value ) {
				${$key} = $value;
			}
			include_once( $viewFile );
			$content = ob_get_contents();
			ob_end_clean();
		}
		catch( \Throwable $e ) {
			return \app\renderer::processSystemErrorException( $e );
		}
		return $content;
	}


	/**
	 * @param \ReflectionClass $controllerClass
	 *
	 * @throws \ReflectionException
	 */
	private function lifecycleControllerBefore( \ReflectionClass $controllerClass ): void {
		$controllerInstance = $controllerClass->newInstanceWithoutConstructor();

		$_beforeMethod = $controllerClass->getMethod( '_before' );

		$_beforeMethod->invoke( $controllerInstance );
	}


	/**
	 * @param controller $controllerInstance
	 *
	 */
	private function lifecycleControllerAfter( controller $controllerInstance ): void {
		$controllerInstance::_after();
	}

}
