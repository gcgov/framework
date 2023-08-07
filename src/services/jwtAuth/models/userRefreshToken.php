<?php
namespace gcgov\framework\services\jwtAuth\models;

use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\updateDeleteResult;


/**
 * @OA\Schema()
 */
final class userRefreshToken
	extends
	\gcgov\framework\services\mongodb\model {

	const _COLLECTION = 'userRefreshToken';

	const _HUMAN = 'user refresh token';

	const _HUMAN_PLURAL = 'user refresh tokens';

	#[label( 'Id' )]
	/** @OA\Property(type="string") */
	public \MongoDB\BSON\ObjectId $_id;

	#[label( 'User Id' )]
	/** @OA\Property(type="string") */
	public \MongoDB\BSON\ObjectId $userId;

	#[label( 'Scope' )]
	/** @OA\Property() */
	public string                 $scope                  = 'refresh';

	#[label( 'Expiration' )]
	/** @OA\Property() */
	public \DateTimeImmutable     $expiration;

	#[label( 'Creation' )]
	/** @OA\Property() */
	public \DateTimeImmutable     $creation;

	#[label( 'Token' )]
	/** @OA\Property() */
	public string                 $token                  = '';

	#[label( 'Creator User Agent Header' )]
	/** @OA\Property() */
	public string                 $creatorUserAgentHeader = '';

	#[label( 'Creator IP' )]
	/** @OA\Property() */
	public string                 $creatorIP              = '';


	public function __construct( \MongoDB\BSON\ObjectId|string $userId, \DateInterval $duration, string $token ) {
		parent::__construct();
		$this->_id                    = new \MongoDB\BSON\ObjectId();
		$this->userId                 = $userId instanceof \MongoDB\BSON\ObjectId ? $userId : new \MongoDB\BSON\ObjectId($userId);
		$this->token                  = password_hash( $token, PASSWORD_DEFAULT );
		$this->creation               = new \DateTimeImmutable();
		$this->expiration             = $this->creation->add( $duration );
		$this->creatorUserAgentHeader = $_SERVER[ 'HTTP_USER_AGENT' ] ?? '';
		$this->creatorIP              = $_SERVER[ 'REMOTE_ADDR' ] ?? '';
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function removeOutdatedRefreshTokens() : updateDeleteResult {
		$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: self::_getCollectionName() );

		$filter = [
			'expiration' => [
				'$lt' => new \MongoDB\BSON\UTCDateTime()
			]
		];

		$options = [
			'writeConcern' => new \MongoDB\Driver\WriteConcern( 'majority' )
		];

		try {
			$deleteResult = $mdb->collection->deleteMany( $filter, $options );
			return new updateDeleteResult( $deleteResult );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

	}
}
