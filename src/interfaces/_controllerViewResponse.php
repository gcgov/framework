<?php

namespace gcgov\framework\interfaces;

interface _controllerViewResponse extends _controllerResponse {

	public function getView(): string;


	public function setView( string $view ): void;


	public function getVars(): array;


	public function setVars( array $vars ): void;

}