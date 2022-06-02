<?php


namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class settings extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool $useSession = false;


	public function __construct() {
	}


}