<?php

namespace gcgov\framework\interfaces;

interface _controllerDataResponse {

	public function getData(): mixed;


	public function setData( mixed $data ): void;


	public function getHttpStatus(): int;


	public function setHttpStatus( int $httpStatus ): void;


	public function getContentType(): string;


	public function setContentType( string $contentType ): void;


	public function getHeaders(): array;


	public function setHeaders( array $headers ): void;


	public function addHeaders( array $additionalHeaders ): void;


	public function addHeader( string $name, string $value ): void;

}