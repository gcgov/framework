<?php


namespace gcgov\framework\models\config\app;


class settings extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool $useSession = false;

	public bool $forceMfaForPasswordUsers = false;

	public function __construct() {
	}


}
