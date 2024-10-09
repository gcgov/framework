<?php

namespace gcgov\framework\models\config\environment\mongoDatabase;

use gcgov\framework\models\config\environment\mongoDatabase\kmsProviders\gcp;

class kmsProviders extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public ?gcp $gcp = null;

}
