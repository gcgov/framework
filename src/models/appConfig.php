<?php


namespace gcgov\framework\models;


use gcgov\framework\exceptions\configException;
use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;


class appConfig extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public app      $app;

	public email    $email;

	public settings $settings;


	public function __construct() {

	}
}