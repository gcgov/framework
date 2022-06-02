<?php


namespace gcgov\framework\models\config\environment;


use gcgov\framework\exceptions\configException;
use gcgov\framework\interfaces\jsonDeserialize;


class sqlDatabaseUser extends \andrewsauder\jsonDeserialize\jsonDeserialize  {

	public string $username = '';

	public string $password = '';


	public function __construct() {
	}


}