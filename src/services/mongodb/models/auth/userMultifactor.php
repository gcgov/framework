<?php

namespace gcgov\framework\services\mongodb\models\auth;

use OpenApi\Attributes as OA;

#[OA\Schema]
class userMultifactor extends \gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'userMultifactor';

	const _HUMAN = 'user multifactor';

	const _HUMAN_PLURAL = 'user multifactors';

	#[OA\Property( type: 'string' )]
	public \MongoDB\BSON\ObjectId $_id;

	#[OA\Property( type: 'string' )]
	public \MongoDB\BSON\ObjectId $userId;

	#[OA\Property()]
	public string $secret = '';

	#[OA\Property()]
	public bool $verified = false;

	#[OA\Property()]
	public ?int $timeslice = null;

	#[OA\Property()]
	public \DateTimeImmutable $createdAt;

	#[OA\Property()]
	public ?\DateTimeImmutable $verifiedAt = null;


	public function __construct( \MongoDB\BSON\ObjectId $userId ) {
		parent::__construct();
		$this->_id = new \MongoDB\BSON\ObjectId();
		$this->createdAt = new \DateTimeImmutable();
		$this->userId = $userId;
	}

}
