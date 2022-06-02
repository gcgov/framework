<?php

namespace gcgov\framework\models\config\environment;

class microsoft extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $clientId     = "";

	public string $clientSecret = "";

	public string $tenant       = "";

	public string $driveId      = "";

	public string $fromAddress  = "";


	public function __construct() {
	}
}