<?php

namespace gcgov\framework\services;

use gcgov\framework\exceptions\configException;

class request {

	public static function getAuthUser(): \gcgov\framework\models\authUser {
		if( class_exists( '\app\models\authUser' ) && is_subclass_of( \app\models\authUser::class, \gcgov\framework\models\authUser::class ) ) {
			return \app\models\authUser::getInstance();
		}
		return \gcgov\framework\models\authUser::getInstance();
	}


	public static function getUserClassFqdn(): string {
		if( class_exists( '\app\models\user' ) ) {
			if( is_a( \app\models\user::class, \gcgov\framework\interfaces\auth\user::class ) ) {
				throw new configException( '\app\models\user must implement \gcgov\framework\interfaces\auth\user to be used in framework authentication routes' );
			}
			return \app\models\user::class;
		}
		return \gcgov\framework\services\mongodb\models\auth\user::class;
	}


	public static function getPostData(): array {
		$postData = $_POST;

		if( count( $_POST )===0 ) {
			$postData = json_decode( file_get_contents( 'php://input' ), true );
		}

		if( !is_array( $postData ) ) {
			$postData = [];
		}

		return $postData;
	}

}
