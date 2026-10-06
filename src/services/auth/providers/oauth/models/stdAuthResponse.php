<?php

namespace gcgov\framework\services\auth\providers\oauth\models;

/**
 * @OA\Schema()
 */
class stdAuthResponse extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/** @OA\Property() */
	public string $token_type = 'Bearer';

	/** @OA\Property() */
	public int $expires_in = 0;

	/** @OA\Property() */
	public string $access_token = '';

	/** @OA\Property() */
	public string $refresh_token = '';


	public function __construct( ?\Lcobucci\JWT\Token\Plain $accessToken = null, ?\Lcobucci\JWT\Token\Plain $refreshToken = null, string $tokenType = 'Bearer' ) {
		$now = new \DateTimeImmutable();

		if( $accessToken!==null ) {
			$this->token_type   = $tokenType;
			$this->expires_in   = $accessToken->claims()->get( 'exp' )->getTimestamp() - $now->getTimestamp();
			$this->access_token = $accessToken->toString();
		}

		if( $refreshToken!==null ) {
			$this->refresh_token = $refreshToken->toString();
		}
	}

}
