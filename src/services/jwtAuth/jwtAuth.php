<?php

namespace gcgov\framework\services\jwtAuth;

use gcgov\framework\config;
use gcgov\framework\exceptions\configException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\exceptions\serviceException;

class jwtAuth {

	/** @var string[] */
	public array $guids = [];

	//configuration tools
	private \Lcobucci\JWT\Configuration $configuration;

	private string $guid = '';

	private \Lcobucci\JWT\Signer\Rsa\Sha512 $signer;

	//token settings
	private string $issuedBy = '';

	private string $permittedFor = '';

	private string $keyPath = '';


	public function __construct( string $guid=null ) {

		//standard config
		if( file_exists( config::getSrvDir().'/jwtCertificates/' ) ) {
			$this->keyPath = config::getSrvDir().'/jwtCertificates/';
		}
		else {
			$this->keyPath = dirname( __FILE__ ) . '/jwtCertificates/';
		}

		//env config
		$envConfig = config::getEnvironmentConfig();
		if( !isset( $envConfig->jwtAuth ) || empty( $envConfig->jwtAuth->tokenIssuedBy ) || empty( $envConfig->jwtAuth->tokenPermittedFor ) ) {
			throw new configException( 'Missing "auth" section of /app/config/environment.json' );
		}
		$this->issuedBy     = $envConfig->jwtAuth->tokenIssuedBy;
		$this->permittedFor = $envConfig->jwtAuth->tokenPermittedFor;

		//guid config
		if( !file_exists( $this->keyPath . 'guids.json' ) ) {
			throw new configException( 'Missing ' . $this->keyPath . 'guids.json. Have you run vendor/gcgov/framework/scrips/create-jwt-keys.ps1?' );
		}
		$this->guids = json_decode( file_get_contents( $this->keyPath . 'guids.json' ) );

		//guid specific init
		if( !isset( $guid ) ) {
			try {
				$guidIndex = random_int( 0, 4 );
			}
			catch( \Exception $e ) {
				$guidIndex = rand( 0, 4 );
			}
		}
		else {
			if( file_exists( $this->keyPath . 'private-'.$guid.'.pem' ) && file_exists( $this->keyPath . 'public-'.$guid.'.pem' ) ) {
				if( in_array( $guid, $this->guids )) {
					$guidIndex = array_search( $guid, $this->guids );
				}
				else {
					$this->guids[] = $guid;
					$guidIndex = count($this->guids)-1;
				}
			}
			else {
				throw new configException( 'Missing private or public key for guid '.$guid );
			}
		}

		$this->init( $this->guids[ $guidIndex ] );


	}


	private function init( string $guid ): void {

		$this->signer = new \Lcobucci\JWT\Signer\Rsa\Sha512();

		$this->guid = $guid;

		if( !file_exists( $this->getPrivateKeyPath() ) || !file_exists( $this->getPublicKeyPath() ) ) {
			throw new serviceException( 'GUID/kid of token is not valid' );
		}

		$privateKey = \Lcobucci\JWT\Signer\Key\InMemory::file( $this->getPrivateKeyPath() );
		$publicKey  = \Lcobucci\JWT\Signer\Key\InMemory::file( $this->getPublicKeyPath() );

		$this->configuration = \Lcobucci\JWT\Configuration::forAsymmetricSigner( $this->signer, $privateKey, $publicKey );
	}


	private function getPrivateKeyPath(): string {
		return $this->keyPath . '/private-' . $this->guid . '.pem';
	}


	private function getPublicKeyPath(): string {
		return $this->keyPath . '/public-' . $this->guid . '.pem';
	}


	/**
	 * @param \gcgov\framework\models\authUser $authUser
	 * @param \DateInterval|null $duration Defaults to 1 hour. Pass a date interval to create a different length token. BE RESPONSIBLE!
	 *
	 * @return \Lcobucci\JWT\Token\Plain
	 */
	public function createAccessToken( \gcgov\framework\models\authUser $authUser, \DateInterval $duration = null ): \Lcobucci\JWT\Token\Plain {
		if( !( $duration instanceof \DateInterval ) ) {
			$duration = new \DateInterval( 'PT1H' );
		}

		$now = new \DateTimeImmutable();

		//create token
		$token = $this->configuration->builder()
			// Configures the issuer (iss claim)
			->issuedBy( $this->issuedBy )
			// Configures the audience (aud claim)
			->permittedFor( $this->permittedFor )
			//configures sub
			->relatedTo( (string) $authUser->userId )
			//configures jti claim for id -- will use for refresh token instead so that we can revoke a refresh token
			//->identifiedBy('asdf)
			// Configures the time that the token was issue (iat claim)
			->issuedAt( $now )
			// Configures the time that the token can be used (nbf claim)
			//->canOnlyBeUsedAfter($now->modify('+1 second'))
			// Configures the expiration time of the token (exp claim)
			->expiresAt( $now->add( $duration ) )
			// Configures a new claim, called "scopes" with array of user roles
			->withClaim( 'scope', $authUser->roles )
			// Configures a new header, called kid that identifies the guid of the keys used for encrypting
			->withHeader( 'kid', $this->guid )
			//add data to claims with the user info
			->withClaim( 'data', $authUser->toJwtData() )
			// Builds a new token
			->getToken( $this->configuration->signer(), $this->configuration->signingKey() );

		return $token;
	}


	/**
	 * @param \gcgov\framework\models\authUser $authUser
	 * @param \DateInterval|null $duration Defaults to 1 month. Pass a date interval to create a different length token. BE RESPONSIBLE!
	 *
	 * @return \Lcobucci\JWT\Token\Plain
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function createRefreshToken( \gcgov\framework\models\authUser $authUser, \DateInterval $duration = null ): \Lcobucci\JWT\Token\Plain {
		if( !( $duration instanceof \DateInterval ) ) {
			$duration = new \DateInterval( 'P1M' );
		}

		$identifier       = new \MongoDB\BSON\ObjectId();
		$userRefreshToken = new \gcgov\framework\services\jwtAuth\models\userRefreshToken( $authUser->userId, $duration, (string)$identifier );


		//create token
		$token = $this->configuration->builder()
			// Configures the issuer (iss claim)
			->issuedBy( $this->issuedBy )
			// Configures the audience (aud claim)
			->permittedFor( $this->permittedFor )
			//configures sub
			->relatedTo( (string)$userRefreshToken->_id )
			//configures jti claim for id -- will use for refresh token instead so that we can revoke a refresh token
			->identifiedBy( (string)$identifier )
			// Configures the time that the token was issue (iat claim)
			->issuedAt( $userRefreshToken->creation )
			// Configures the time that the token can be used (nbf claim)
			//->canOnlyBeUsedAfter($now->modify('+1 second'))
			// Configures the expiration time of the token (exp claim)
			->expiresAt( $userRefreshToken->expiration )
			// Configures a new claim, called "scopes" with array of user roles
			->withClaim( 'scope', $userRefreshToken->scope )
			// Configures a new header, called kid that identifies the guid of the keys used for encrypting
			->withHeader( 'kid', $this->guid )
			//add data to claims with the user info
			//->withClaim( 'data', [] )
			// Builds a new token
			->getToken( $this->configuration->signer(), $this->configuration->signingKey() );

		\gcgov\framework\services\jwtAuth\models\userRefreshToken::save( $userRefreshToken );

		return $token;
	}


	/**
	 * @param string $token Encoded token
	 *
	 * @return \Lcobucci\JWT\Token
	 * @throws \Lcobucci\JWT\Validation\RequiredConstraintsViolated
	 * @throws \Lcobucci\JWT\Encoding\CannotDecodeContent
	 * @throws \Lcobucci\JWT\Token\UnsupportedHeaderFound
	 * @throws \Lcobucci\JWT\Token\InvalidTokenStructure
	 */
	public function validateAccessToken( string $token ): \Lcobucci\JWT\Token {
		//if token is passed straight from the header, strip "Bearer " from the string before we try to parse it
		$token = str_replace( 'Bearer ', '', $token );

		//decode
		$parsedToken = $this->configuration->parser()->parse( $token );

		//get guid from the token header "kid" field
		$guid = $parsedToken->headers()->get( 'kid' );

		//reinitialize the configuration with the proper guid
		$this->init( $guid );

		//validate
		$constraints = [
			new \Lcobucci\JWT\Validation\Constraint\SignedWith( $this->signer, \Lcobucci\JWT\Signer\Key\InMemory::file( $this->getPublicKeyPath() ) ),
			new \Lcobucci\JWT\Validation\Constraint\ValidAt( \Lcobucci\Clock\SystemClock::fromSystemTimezone() ),
			new \Lcobucci\JWT\Validation\Constraint\IssuedBy( $this->issuedBy ),
			new \Lcobucci\JWT\Validation\Constraint\PermittedFor( $this->permittedFor ),
			//new \Lcobucci\JWT\Validation\Constraint\RelatedTo('zz'),
			//new \Lcobucci\JWT\Validation\Constraint\IdentifiedBy('asdfasdf'),
		];

		$this->configuration->validator()->assert( $parsedToken, ...$constraints );

		return $parsedToken;
	}


	/**
	 * @param string $token Encoded token
	 *
	 * @return \Lcobucci\JWT\Token
	 * @throws \Lcobucci\JWT\Validation\RequiredConstraintsViolated
	 * @throws \Lcobucci\JWT\Encoding\CannotDecodeContent
	 * @throws \Lcobucci\JWT\Token\UnsupportedHeaderFound
	 * @throws \Lcobucci\JWT\Token\InvalidTokenStructure
	 * @throws \Exception
	 */
	public function validateRefreshToken( string $token ): \MongoDB\BSON\ObjectId {
		//if token is passed straight from the header, strip "Bearer " from the string before we try to parse it
		$token = str_replace( 'Bearer ', '', $token );

		//decode
		$parsedToken = $this->configuration->parser()->parse( $token );

		//get guid from the token header "kid" field
		$guid = $parsedToken->headers()->get( 'kid' );

		//reinitialize the configuration with the proper guid
		$this->init( $guid );

		//validate
		$constraints = [
			new \Lcobucci\JWT\Validation\Constraint\SignedWith( $this->signer, \Lcobucci\JWT\Signer\Key\InMemory::file( $this->getPublicKeyPath() ) ),
			new \Lcobucci\JWT\Validation\Constraint\ValidAt( \Lcobucci\Clock\SystemClock::fromSystemTimezone() ),
			new \Lcobucci\JWT\Validation\Constraint\IssuedBy( $this->issuedBy ),
			new \Lcobucci\JWT\Validation\Constraint\PermittedFor( $this->permittedFor ),
			//new \Lcobucci\JWT\Validation\Constraint\RelatedTo('zz'),
			//new \Lcobucci\JWT\Validation\Constraint\IdentifiedBy('asdfasdf'),
		];

		$this->configuration->validator()->assert( $parsedToken, ...$constraints );

		try {
			$identityValid = $this->validateRefreshTokenIdentity( $parsedToken->claims()->get( 'sub' ), $parsedToken->claims()->get( 'jti' ) );
		}
		catch( modelException $e ) {
			throw new \Exception( 'Refresh token invalid', 401 );
		}

		return $identityValid;
	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	public function deleteRefreshToken( string $unparsedToken ) {
		//if token is passed straight from the header, strip "Bearer " from the string before we try to parse it
		$token = str_replace( 'Bearer ', '', $unparsedToken );

		//decode
		$parsedToken = $this->configuration->parser()->parse( $token );

		\gcgov\framework\services\jwtAuth\models\userRefreshToken::delete( $parsedToken->claims()->get( 'sub' ) );

	}


	/**
	 * @throws \gcgov\framework\exceptions\modelException
	 */
	private function validateRefreshTokenIdentity( string $tokenSelector, string $token ): \MongoDB\BSON\ObjectId {
		/** @var \gcgov\framework\services\jwtAuth\models\userRefreshToken $refreshToken */
		$refreshToken = \gcgov\framework\services\jwtAuth\models\userRefreshToken::getOne( $tokenSelector );

		if( !empty( $refreshToken->userId ) && !empty( $refreshToken->token ) && password_verify( $token, $refreshToken->token ) ) {
			return $refreshToken->userId;
		}

		throw new \gcgov\framework\exceptions\modelException( 'Refresh token invalid', 401 );
	}

	public function getJwksKeys() {

		$jwksKeys = [];

		foreach( $this->guids as $guid ) {
			$pub_key    = openssl_pkey_get_public( file_get_contents( $this->keyPath . '/public-' . $guid . '.pem' ) );
			$keyData    = openssl_pkey_get_details( $pub_key );
			$jwksKeys[] = [
				'alg' => 'RS512',
				'kty' => 'RSA',
				'use' => 'sig',
				'n'   => rtrim( str_replace( [ '+', '/' ], [ '-', '_' ], base64_encode( $keyData[ 'rsa' ][ 'n' ] ) ), '=' ),
				'e'   => rtrim( str_replace( [ '+', '/' ], [ '-', '_' ], base64_encode( $keyData[ 'rsa' ][ 'e' ] ) ), '=' ),
				'kid' => $guid
			];
		}

		return $jwksKeys;

	}

}
