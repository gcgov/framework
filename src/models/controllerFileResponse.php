<?php

namespace gcgov\framework\models;

use gcgov\framework\exceptions\controllerException;
use gcgov\framework\interfaces\_controllerFileResponse;

class controllerFileResponse extends controllerResponse implements _controllerFileResponse {

	protected string $contentType = '';
	protected string  $filePathname        = '';


	/**
	 * @param string                                             $filePathname
	 * @param \gcgov\framework\models\controllerResponseHeader[] $headers
	 *
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public function __construct( string $filePathname, array $headers = [] ) {
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
		$this->contentType  = mime_content_type( $filePathname );
	}


	/**
	 * @return string
	 */
	public function getContentType(): string {
		return $this->contentType;
	}


}
