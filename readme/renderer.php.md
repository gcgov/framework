# /app/renderer.php

```php
namespace app;

use gcgov\framework\models\controllerDataResponse;

class renderer implements \gcgov\framework\interfaces\render {

	/**
	 * Controllers are expected to capture model exceptions and convert them into controller exceptions
	 *      but this renderer is provided in the event that a model exception bubbles all the way up to
	 *      the framework renderer
	 *
	 * @param  \gcgov\framework\exceptions\modelException  $e
	 *
	 * @return controllerDataResponse
	 */
	public static function processModelException( \gcgov\framework\exceptions\modelException $e ) : controllerDataResponse {
		$data = [
			'error' => true,
			'message'=> $e->getMessage(),
			'status'=> $e->getCode()
		];

		$response = new controllerDataResponse();
		$response->setHttpStatus( $e->getCode() );
		$response->setData( $data );

		return $response;
	}


	public static function processControllerException( \gcgov\framework\exceptions\controllerException $e ) : controllerDataResponse {
		$data = [
			'error' => true,
			'message'=> $e->getMessage(),
			'status'=> $e->getCode()
		];

		$response = new controllerDataResponse();
		$response->setHttpStatus( $e->getCode() );
		$response->setData( $data );

		return $response;
	}


	public static function processRouteException( \gcgov\framework\exceptions\routeException $e ) : controllerDataResponse {
		$data = [
			'error' => true,
			'message'=> $e->getMessage(),
			'status'=> $e->getCode()
		];

		$response = new controllerDataResponse();
		$response->setHttpStatus( $e->getCode() );
		$response->setData( $data );

		return $response;
	}


	public static function processSystemErrorException( \Exception|\ErrorException|\Error $e ) : controllerDataResponse {
		$data = [
			'error' => true,
			'message'=> $e->getMessage(),
			'status'=> $e->getCode()
		];

		$response = new controllerDataResponse();
		$response->setHttpStatus( 500 );
		$response->setData( $data );

		return $response;
	}


	/**
	 * Processed prior to __constructor() being called
	 */
	public static function _before() : void {
	}


	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after() : void {
	}

}
```
