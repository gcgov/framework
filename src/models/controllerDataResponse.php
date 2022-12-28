<?php

namespace gcgov\framework\models;

use gcgov\framework\exceptions\modelException;
use gcgov\framework\interfaces\_controllerDataResponse;

class controllerDataResponse extends controllerResponse implements _controllerDataResponse {

	public const SupportedContentTypes = [
		'application/json',
		'text/plain',
	];

	private string $contentType = 'application/json';
	private mixed  $data        = null;


	/**
	 * @param mixed                                              $data Data to be json encoded and output
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 */
	public function __construct( mixed $data = null, array $headers = [] ) {
		$this->setData( $data );
		parent::__construct( $headers );
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
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}


	/**
	 * @param string $contentType
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function setContentType( string $contentType ): void {
		if( in_array( $contentType, self::SupportedContentTypes ) ) {
			$this->contentType = $contentType;
		}
		else {
			throw new \gcgov\framework\exceptions\controllerException( 'Content-Type: ' . $contentType . ' has not been implemented in \gcgov\framework\renderer. Supported types: ' . json_encode( self::SupportedContentTypes ) );
		}
	}

}