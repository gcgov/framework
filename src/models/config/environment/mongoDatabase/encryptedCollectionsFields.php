<?php

namespace gcgov\framework\models\config\environment\mongoDatabase;

class encryptedCollectionsFields extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $collection = '';

	/** @var \gcgov\framework\models\config\environment\mongoDatabase\encryptedFieldMap[] $encryptedFieldMap */
	public array $encryptedFieldMap = [];

	public function __construct() {
	}

}
