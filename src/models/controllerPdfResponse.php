<?php

namespace gcgov\framework\models;

use gcgov\framework\exceptions\controllerException;
use gcgov\framework\interfaces\_controllerPdfResponse;

class controllerPdfResponse extends controllerResponse implements _controllerPdfResponse {

	public const SupportedContentTypes = [
		'application/pdf'
	];

	private string $contentType = 'application/pdf';
	private string  $filePathname        = '';


	/**
	 * @param string                                             $filePathname
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function __construct( string $filePathname = '', array $headers = [] ) {
		parent::__construct( $headers );
		$this->setFilePathname( $filePathname );
	}


	/**
	 * @return mixed
	 */
	public function getFilePathname(): string {

		return $this->filePathname;
	}


	/**
	 * @param string $filePathname
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function setFilePathname( string $filePathname ): void {
		if(!file_exists($filePathname)) {
			throw new controllerException($filePathname.' not found', 400);
		}
		$this->filePathname = $filePathname;
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
