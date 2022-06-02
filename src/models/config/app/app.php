<?php


namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class app  extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $title    = '';

	public string $guid   = '';

	public function __construct() {
	}



}