<?php

namespace gcgov\framework\models\config\environment;

use gcgov\framework\exceptions\configException;

class jwtAuth extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $tokenIssuedBy = "";

	public string $tokenPermittedFor = "";

	public string $redirectAfterLoginUrl = "";

	public string $redirectAfterLogoutUrl = "";

	public function __construct() {
	}

}
