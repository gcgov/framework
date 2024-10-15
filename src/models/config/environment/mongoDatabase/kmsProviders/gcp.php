<?php

namespace gcgov\framework\models\config\environment\mongoDatabase\kmsProviders;

class gcp extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $email                         = '';
	public string $privateKeyFilePathName        = '';
	public string $masterKeyLocationFilePathName = '';

	public string $credentialsFilePathName = '';

}
