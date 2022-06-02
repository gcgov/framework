<?php

namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;


class jwtAuth extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $tokenIssuedBy = "";

	public string $tokenPermittedFor = "";

	public function __construct() {
	}

}