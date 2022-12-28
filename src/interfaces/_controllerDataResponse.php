<?php

namespace gcgov\framework\interfaces;

interface _controllerDataResponse extends _controllerResponse {

	public function getData(): mixed;


	public function setData( mixed $data ): void;


	public function getContentType(): string;


	public function setContentType( string $contentType ): void;

}