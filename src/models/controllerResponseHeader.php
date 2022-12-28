<?php

namespace gcgov\framework\models;

class controllerResponseHeader {

	private string $name;
	private string $value;

	public function __construct( string $name, string $value ) {
		$this->name = $name;
		$this->value = $value;
	}

	public function output(): void {
		header( $this->name.': ' . $this->value );
	}

}