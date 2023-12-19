<?php

namespace gcgov\framework\interfaces;

interface _controllerFileResponse extends _controllerResponse {

	public function getFilePathname(): string;


	public function setFilePathname( string $filePathname ): void;


	public function getContentType(): string;

}
