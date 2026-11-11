<?php

namespace gcgov\framework\services\auth\providers\oauth\models;

/**
 * @OA\Schema()
 */
class verifyMfaSecretRequest extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/** @OA\Property() */
	public string $code = '';

	/** @OA\Property() */
	public ?\MongoDB\BSON\ObjectId $userMultifactorId = null;


	public function __construct( string $code = '', ?\MongoDB\BSON\ObjectId $userMultifactorId = null ) {
		$this->code              = $code;
		$this->userMultifactorId = $userMultifactorId;
	}

}
