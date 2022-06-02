<?php

namespace gcgov\framework\models\config\app;


use gcgov\framework\interfaces\jsonDeserialize;
use gcgov\framework\exceptions\configException;


class email extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $fromAddress    = '';

	public string $fromName       = '';

	public string $replyToAddress = '';

	public string $replyToName    = '';


	public function __construct() {
	}



}