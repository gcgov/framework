<?php
namespace gcgov\framework\services\jwtAuth\models;

use gcgov\framework\services\mongodb\attributes\label;

/**
 * @OA\Schema()
 */
final class userAuthorizationCode
	extends
	\gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'userAuthorizationCode';

	const _HUMAN = 'user authorization code';

	const _HUMAN_PLURAL = 'user authorization codes';

	#[label( 'Id' )]
	/** @OA\Property(type="string") */
	public \MongoDB\BSON\ObjectId $_id;

	#[label( 'User Id' )]
	/** @OA\Property(type="string") */
	public \MongoDB\BSON\ObjectId $userId;

	#[label( 'Expiration' )]
	/** @OA\Property() */
	public \DateTimeImmutable     $expiration;

	#[label( 'Creation' )]
	/** @OA\Property() */
	public \DateTimeImmutable     $creation;


	public function __construct(\MongoDB\BSON\ObjectId $userId, \DateInterval $duration) {
		parent::__construct();
		$this->_id = new \MongoDB\BSON\ObjectId();
		$this->userId                 = $userId;
		$this->creation               = new \DateTimeImmutable();
		$this->expiration             = $this->creation->add( $duration );
	}

}
