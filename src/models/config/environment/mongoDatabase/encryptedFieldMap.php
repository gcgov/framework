<?php

namespace gcgov\framework\models\config\environment\mongoDatabase;

class encryptedFieldMap extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $keyAltName = '';
	public string $keyId = '';

	public string $path = '';

	public string $bsonType = '';

	public array $queries = [];


	public function __construct() {
	}

	public function toDriverArray(): array {
		if(count($this->queries)>0) {
			return [
				'keyId'=>$this->keyId,
				'path'=>$this->path,
				'bsonType'=>$this->bsonType,
				'queries'=>$this->queries,
			];
		}
		return [
			'keyId'=>$this->keyId,
			'path'=>$this->path,
			'bsonType'=>$this->bsonType
		];
	}

}
