<?php

namespace gcgov\framework\services\microsoft\components;

use JetBrains\PhpStorm\Deprecated;
use OpenApi\Attributes as OA;

#[Deprecated( 'Use \andrewsauder\microsoftServices instead' )]
#[OA\Schema]
class upload {

	#[OA\Property]
	/** @var \Microsoft\Graph\Model\DriveItem[] $files */
	public array $files = [];

	#[OA\Property]
	/** @var \gcgov\framework\services\microsoft\components\envelope[] $errors */
	public array $errors = [];


	public function __construct() {

	}


	public function merge( upload $upload ) {
		$this->files  = array_merge( $this->files, $upload->files );
		$this->errors = array_merge( $this->errors, $upload->errors );
	}

}
