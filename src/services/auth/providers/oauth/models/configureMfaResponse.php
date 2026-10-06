<?php

namespace gcgov\framework\services\auth\providers\oauth\models;

use gcgov\framework\services\mongodb\models\auth\userMultifactor;

/**
 * @OA\Schema()
 */
class configureMfaResponse extends stdAuthResponse {

	/** @OA\Property() */
	public bool $mfaRequired = false;

	/** @OA\Property() */
	public bool $mfaConfigured = false;

	/** @OA\Property() */
	public string $qrCodeDataUri = '';

	/** @OA\Property() */
	public string $secret = '';

	/** @OA\Property() */
	public ?\MongoDB\BSON\ObjectId $userId = null;

	/** @OA\Property() */
	public ?\MongoDB\BSON\ObjectId $userMultifactorId = null;


	public function __construct( ?\Lcobucci\JWT\Token\Plain $accessToken, userMultifactor $userMultifactor, string $qrCodeDataUri = '' ) {
		parent::__construct( $accessToken );
		$this->qrCodeDataUri     = $qrCodeDataUri;
		$this->secret            = $userMultifactor->secret;
		$this->userId            = $userMultifactor->userId;
		$this->userMultifactorId = $userMultifactor->_id;
		$this->mfaRequired       = true;
		$this->mfaConfigured     = false;
	}

}
