<?php

namespace gcgov\framework\models\config\environment;

class logging extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public bool $lifecycle     = false;
	public bool $renderer     = false;

	public function __construct() {
	}
}
