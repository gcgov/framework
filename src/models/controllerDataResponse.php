<?php

namespace gcgov\framework\models;

use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\_controllerResponse;

class controllerDataResponse implements _controllerResponse {

	public const SupportedContentTypes = [
		'application/json',
		'text/plain',
	];

	private string $contentType = 'application/json';
	private mixed  $data        = null;
	/** @var \gcgov\framework\models\controllerResponseHeader[] */
	private array $headers    = [];
	private int   $httpStatus = 200;


	/**
	 * @param mixed                                              $data Data to be json encoded and output
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 */
	public function __construct( mixed $data = null, array $headers = [] ) {
		$this->setData( $data );
		if(count($headers)>0) {
			$this->setHeaders( $headers );
		}
	}


	/**
	 * @return mixed
	 */
	public function getData(): mixed {

		return $this->data;
	}


	/**
	 * @param mixed $data
	 */
	public function setData( mixed $data ): void {

		$this->data = $data;
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
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}


	/**
	 * @param string $contentType
	 *
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function setContentType( string $contentType ): void {
		if( in_array( $contentType, self::SupportedContentTypes ) ) {
			$this->contentType = $contentType;
		}
		else {
			throw new modelException( 'Content-Type: ' . $contentType . ' has not been implemented in \gcgov\framework\renderer. Supported types: ' . json_encode( self::SupportedContentTypes ) );
		}
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
		$this->headers[] =  new \gcgov\framework\models\controllerResponseHeader( $name, $value );
	}

}