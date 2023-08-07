<?php

namespace gcgov\framework\services\mongodb\models\auth;

use andrewsauder\jsonDeserialize\attributes\excludeJsonSerialize;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\mongodb\attributes\label;
use gcgov\framework\services\mongodb\typeMapType;

/**
 * Class user
 * @OA\Schema()
 */
class user
	extends \gcgov\framework\services\mongodb\model
	implements \gcgov\framework\interfaces\auth\user {

	const _COLLECTION = 'user';

	const _HUMAN = 'user';

	const _HUMAN_PLURAL = 'users';

	#[label( 'Id' )]
	/** @OA\Property(type="string") */
	public \MongoDB\BSON\ObjectId $_id;

	/** all std user fields */
	use \gcgov\framework\traits\userTrait;

	public function __construct() {
		parent::__construct();
		$this->_id = new \MongoDB\BSON\ObjectId();
	}


	public static function getOne( \MongoDB\BSON\ObjectId|string|int $_id ): self {
		if( is_int( $_id ) ) {
			throw new modelException( 'Integer ids are not supported in Mongo' );
		}
		return parent::getOne( $_id );
	}


	protected function _beforeBsonSerialize(): void {
		if( empty( $this->oauthId ) ) {
			//clear oauth provider
			if( !empty( $this->password ) ) {
				$this->oauthProvider = '';
			}

			//hash password or remove it from the object if it's not being updated
			if( empty( $this->password ) ) {
				unset( $this->password );
			}
			else {
				$this->password = password_hash( $this->password, PASSWORD_DEFAULT );
			}
		}
		else {
			//clear the password to limit sign in to oauth
			$this->password = '';
		}
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getFromOauth( string $email, string $externalId, string $externalProvider, ?string $firstName = '', ?string $lastName = '', bool $addIfNotExisting = false ): \gcgov\framework\interfaces\auth\user {
		try {
			$filter = [
				'$or' => [
					[
						'oauthId'       => $externalId,
						'oauthProvider' => $externalProvider
					],
					[ 'email' => $email ]
				]
			];
			$user   = self::getOneBy( $filter );
		}
		catch( modelException $e ) {
			if( !$addIfNotExisting ) {
				throw new \gcgov\framework\exceptions\modelException( $email . ' is not set up as a user. Please contact your supervisor to have this account enabled.', 401 );
			}
			$user = new user();
		}

		$user->oauthId       = $externalId;
		$user->oauthProvider = $externalProvider;
		$user->email         = $email;
		$user->name          = trim( ( $firstName ?? '' ) . ' ' . ( $lastName ?? '' ) );

		self::save( $user );

		return $user;
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function verifyUsernamePassword( string $username, string $password ): \gcgov\framework\interfaces\auth\user {
		$user = self::getOneBy( [ '$or' => [ [ 'username' => $username ], [ 'email' => $username ] ] ] );

		//verify user password
		if( !empty( $user->password ) ) {
			$passwordValid = password_verify( $password, $user->password );
			if( $passwordValid ) {
				return $user;
			}
		}

		throw new \gcgov\framework\exceptions\modelException( 'Incorrect username or password', 401 );

	}


	/**
	 * @param string $externalId
	 *
	 * @return self
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOneByExternalId( string $externalId ): self {
		$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: self::_getCollectionName() );

		$filter = [
			'externalId' => $externalId
		];

		$options = [
			'typeMap' => self::getBsonOptionsTypeMap( typeMapType::unserialize ),
		];

		try {
			$cursor = $mdb->collection->findOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		if( $cursor===null ) {
			throw new \gcgov\framework\exceptions\modelException( self::_getHumanName( capitalize: true ) . ' not found', 404 );
		}

		return $cursor;
	}


	/**
	 * @param string $email
	 *
	 * @return self
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public static function getOneByEmail( string $email ): self {
		$mdb = new \gcgov\framework\services\mongodb\tools\mdb( collection: self::_getCollectionName() );

		$filter = [
			'email' => $email
		];

		$options = [
			'typeMap' => self::getBsonOptionsTypeMap( typeMapType::unserialize ),
		];

		try {
			$cursor = $mdb->collection->findOne( $filter, $options );
		}
		catch( \MongoDB\Driver\Exception\RuntimeException $e ) {
			throw new \gcgov\framework\exceptions\modelException( 'Database error', 500, $e );
		}

		if( $cursor===null ) {
			throw new \gcgov\framework\exceptions\modelException( self::_getHumanName( capitalize: true ) . ' not found', 404 );
		}

		return $cursor;
	}


	public function getId(): \MongoDB\BSON\ObjectId {
		return $this->_id;
	}

}
