<?php

namespace gcgov\framework\models;

use gcgov\framework\interfaces\_controllerResponse;

class controllerResponse implements _controllerResponse {

	/** @var \gcgov\framework\models\controllerResponseHeader[] */
	private array $headers    = [];
	private int   $httpStatus = 200;


	public function __construct( array $headers = [] ) {
		if( count( $headers )>0 ) {
			$this->setHeaders( $headers );
		}
	}


	/**
	 * @return int
	 */
	public function getHttpStatus(): int {

		return $this->httpStatus;
	}


	/**
	 * @param int $httpStatus
	 */
	public function setHttpStatus( int $httpStatus ): void {

		$this->httpStatus = $httpStatus;
	}


	/**
	 * @return array
	 */
	public function getHeaders(): array {
		return $this->headers;
	}


	/**
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 */
	public function setHeaders( array $headers ): void {
		$this->headers = $headers;
	}


	/**
	 * @param \gcgov\framework\models\controllerResponseHeader[] $additionalHeaders
	 */
	public function addHeaders( array $additionalHeaders ): void {
		foreach( $additionalHeaders as $additionalHeader ) {
			$this->headers[] = $additionalHeader;
		}
	}


	/**
	 * @param string $name
	 * @param string $value
	 */
	public function addHeader( string $name, string $value ): void {
		$this->headers[] = new \gcgov\framework\models\controllerResponseHeader( $name, $value );
	}

}