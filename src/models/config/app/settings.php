<?php


namespace gcgov\framework\models\config\app;


class settings extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool $forceMfaForPasswordUsers = false;

	public function __construct() {
	}


}
