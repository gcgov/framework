<?php

namespace gcgov\framework\interfaces;

interface _controllerResponse {

	public function getHttpStatus(): int;


	public function setHttpStatus( int $httpStatus ): void;


	public function getHeaders(): array;


	public function setHeaders( array $headers ): void;


	public function addHeaders( array $additionalHeaders ): void;


	public function addHeader( string $name, string $value ): void;

}