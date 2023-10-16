<?php

namespace gcgov\framework\interfaces;

interface _controllerPdfResponse extends _controllerResponse {

	public function getFilePathname(): string;


	public function setFilePathname( string $filePathname ): void;


	public function getContentType(): string;


	public function setContentType( string $contentType ): void;

}
