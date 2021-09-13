<?php

namespace gcgov\framework\services\microsoft\components;

class upload {

	/**
	 * @OA\Property()
	 * @var \Microsoft\Graph\Model\DriveItem[]
	 */
	public array                          $files        = [];

	/**
	 * @OA\Property()
	 * @var \gcgov\framework\services\microsoft\components\envelope[]
	 */
	public array                          $errors     = [];


	public function __construct() {

	}

	public function merge( upload $upload ) {
		$this->files = array_merge( $this->files, $upload->files );
		$this->errors = array_merge( $this->errors, $upload->errors );
	}

}
