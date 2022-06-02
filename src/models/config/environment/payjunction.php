<?php

namespace gcgov\framework\models\config\environment;



class payjunction extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $username   = "";

	public string $password   = "";

	public string $apiKey     = "";

	public string $terminalId = "";

	public string $merchantId = "";


	public function __construct() {
	}

}