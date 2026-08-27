<?php

namespace gcgov\framework\services\auth\providers\oauth\services;

use gcgov\framework\config;
use gcgov\framework\exceptions\controllerException;
use gcgov\framework\exceptions\modelDocumentNotFoundException;
use gcgov\framework\exceptions\modelException;
use gcgov\framework\services\auth\providers\oauth\models\configureMfaResponse;
use gcgov\framework\services\auth\providers\oauth\models\requireMfaResponse;
use gcgov\framework\services\mongodb\models\auth\userMultifactor;
use RobThree\Auth\TwoFactorAuthException;

class multifactor {

	/**
	 * Render enrollment QR codes as SVG rather than PNG.
	 *
	 * BaconQrCodeProvider defaults to the Imagick backend, which would make ext-imagick —
	 * a compiled ImageMagick binding — a hard requirement of the framework for every
	 * application, in order to draw a square. The SVG backend needs no extension, and the
	 * result is still a data URI an <img> renders.
	 */
	private static function qrProvider(): \RobThree\Auth\Providers\Qr\BaconQrCodeProvider {
		return new \RobThree\Auth\Providers\Qr\BaconQrCodeProvider( format: 'svg' );
	}


	public static function requireMfaResponse( ?\Lcobucci\JWT\Token\Plain $accessToken, \gcgov\framework\interfaces\auth\user $user ): requireMfaResponse {
		return new requireMfaResponse( $accessToken, $user );
	}


	/**
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public static function configureMfaResponse( \MongoDB\BSON\ObjectId $userId, ?\Lcobucci\JWT\Token\Plain $accessToken = null ): configureMfaResponse {
		try {
			$tfa    = new \RobThree\Auth\TwoFactorAuth( self::qrProvider() );
			$secret = $tfa->createSecret();
		}
		catch( TwoFactorAuthException $e ) {
			throw new controllerException( 'Failed to generate MFA secret', 500 );
		}

		try {
			$qrCodeDataUri = $tfa->getQRCodeImageAsDataUri( config::getApp()->title, $secret, 500 );
		}
		catch( TwoFactorAuthException $e ) {
			throw new controllerException( 'Failed to generate QR code for MFA secret', 500 );
		}

		try {
			$oldMultifactorConfigurations = userMultifactor::getAll( [ 'userId' => $userId ] );
			userMultifactor::deleteMany( $oldMultifactorConfigurations );

			//create new multifactor attempt
			$userMultifactor         = new userMultifactor( $userId );
			$userMultifactor->secret = $secret;
			userMultifactor::save( $userMultifactor );
		}
		catch( modelException $e ) {
			error_log( $e );
			throw new controllerException( 'Failed to save MFA secret', 500 );
		}

		return new configureMfaResponse( $accessToken, $userMultifactor, $qrCodeDataUri );
	}


	/**
	 * @param \MongoDB\BSON\ObjectId $userId
	 * @param \MongoDB\BSON\ObjectId $userMultifactorId
	 * @param string                 $code
	 *
	 * @return \gcgov\framework\interfaces\auth\user
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public static function verifyMfaSecret( \MongoDB\BSON\ObjectId $userId, \MongoDB\BSON\ObjectId $userMultifactorId, string $code ): \gcgov\framework\interfaces\auth\user {

		try {
			$userClassName = \gcgov\framework\services\request::getUserClassFqdn();
			$user          = $userClassName::getOne( $userId );
			error_log( $user->password );
			$userMultifactor = userMultifactor::getOneBy( [ '_id' => $userMultifactorId, 'userId' => $user->_id ] );
		}
		catch( modelDocumentNotFoundException|modelException $e ) {
			throw new controllerException( 'Identifier not found for user', 404 );
		}

		try {
			$tfa       = new \RobThree\Auth\TwoFactorAuth( self::qrProvider() );
			$timeslice = $userMultifactor->timeslice;
			$result    = $tfa->verifyCode( $userMultifactor->secret, $code, 1, null, $timeslice );

		}
		catch( TwoFactorAuthException $e ) {
			throw new controllerException( 'Not able to check MFA code', 500, $e );
		}

		if( !$result ) {
			throw new controllerException( 'Incorrect code provided', 400 );
		}

		try {
			$userMultifactor->timeslice  = $timeslice;
			$userMultifactor->verified   = true;
			$userMultifactor->verifiedAt = new \DateTimeImmutable();
			userMultifactor::save( $userMultifactor );

			$user->password      = '';
			$user->mfaConfigured = true;
			$userClassName       = \gcgov\framework\services\request::getUserClassFqdn();
			$userClassName::save( $user );

			return $user;
		}
		catch( \Exception $e ) {
			throw new controllerException( 'Failed to save MFA configuration', 500, $e );
		}

	}


	/**
	 * @param \MongoDB\BSON\ObjectId $userId
	 * @param string                 $code
	 *
	 * @return bool
	 * @throws \gcgov\framework\exceptions\controllerException
	 */
	public static function isMfaCodeCorrect( \MongoDB\BSON\ObjectId $userId, string $code ): bool {

		try {
			$userMultifactor = userMultifactor::getOneBy( [ 'userId' => new \MongoDB\BSON\ObjectId( $userId ) ] );
		}
		catch( modelDocumentNotFoundException|modelException $e ) {
			throw new controllerException( 'Identifier not found for user', 404 );
		}

		try {
			$tfa       = new \RobThree\Auth\TwoFactorAuth( self::qrProvider() );
			$timeslice = null;
			$result    = $tfa->verifyCode( $userMultifactor->secret, $code, 1, null, $timeslice );
		}
		catch( TwoFactorAuthException $e ) {
			throw new controllerException( 'Not able to check MFA code', 500, $e );
		}

		if( !$result ) {
			throw new controllerException( 'Incorrect code provided', 400 );
		}

		//replay attack prevention
		if( $timeslice===null || $timeslice<=$userMultifactor->timeslice ) {
			throw new controllerException( 'This code has already been used', 500 );
		}

		//save the updated timeslice for future verification
		try {
			$userMultifactor->timeslice = $timeslice;
			userMultifactor::save( $userMultifactor );
		}
		catch( \Exception $e ) {
			error_log( 'Failed to save MFA timeslice' );
			error_log( $e );
		}

		return true;
	}

}
