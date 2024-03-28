<?php

namespace gcgov\framework\models;

use gcgov\framework\interfaces\_controllerFileResponse;

class controllerFileBase64EncodedContentResponse extends controllerResponse implements _controllerFileResponse {

	protected string $contentType          = '';
	protected string $filePathname         = '';
	protected string $base64EncodedContent = '';


	/**
	 * @param string                                             $contentType
	 * @param string                                             $base64EncodedContent
	 * @param string                                             $filename
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function __construct( string $contentType, string $base64EncodedContent, string $filename, array $headers = [] ) {
		parent::__construct( $headers );
		$this->setFilePathname( $filename );
		$this->contentType          = $contentType;
		$this->base64EncodedContent = $base64EncodedContent;
	}


	/**
	 * @return string
	 */
	public function getFilePathname(): string {

		return $this->filePathname;
	}


	/**
	 * @param string $filePathname
	 *
	 */
	public function setFilePathname( string $filePathname ): void {
		$this->filePathname = $filePathname;
	}


	/**
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}


	public function getBase64EncodedContent(): string {
		return $this->base64EncodedContent;
	}

}
