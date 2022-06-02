<?php

namespace gcgov\framework\models\config\environment;


class sqlDatabase extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool            $default = false;

	public string          $name    = '';

	public string          $dsn     = '';

	public sqlDatabaseUser $readAccount;

	public sqlDatabaseUser $writeAccount;


	public function __construct() {
	}

}