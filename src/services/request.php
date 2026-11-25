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
			if( !is_a( \app\models\user::class, \gcgov\framework\interfaces\auth\user::class, true ) ) {
				throw new configException( '\app\models\user must implement \gcgov\framework\interfaces\auth\user to be used in framework authentication routes' );
			}
			return \app\models\user::class;
		}
		return \gcgov\framework\services\mongodb\models\auth\user::class;
	}


	/**
	 * @return array<string, mixed>
	 */
	public static function getPostData(): array {
		$postData = $_POST;

		if( count( $_POST )===0 ) {
			$rawInput = file_get_contents( 'php://input' );
			$postData = $rawInput === false ? [] : json_decode( $rawInput, true );
		}

		if( !is_array( $postData ) ) {
			$postData = [];
		}

		return $postData;
	}


	/**
	 * Populate the request superglobals from the query string of a CLI route.
	 *
	 * The web server SAPIs fill $_GET from the request line. The CLI SAPI never does,
	 * so `gf cli "/cli/import?limit=5"` routed to /cli/import but the controller saw an
	 * empty $_GET. framework::runApp() calls this first for every CLI request, so the
	 * whole lifecycle reads ?limit=5 the same way it does over HTTP.
	 *
	 * A route URI without a query string leaves the superglobals untouched.
	 */
	public static function populateCliQueryParameters(): void {
		if( ( $_SERVER[ 'REQUEST_METHOD' ] ?? '' )!=='CLI' ) {
			return;
		}

		$queryString = self::extractQueryString( (string)( $_SERVER[ 'REQUEST_URI' ] ?? '' ) );
		if( $queryString===null ) {
			return;
		}

		parse_str( $queryString, $parameters );

		$_SERVER[ 'QUERY_STRING' ] = $queryString;
		$_GET                      = $parameters;
		$_REQUEST                  = array_merge( $_REQUEST, $parameters );
	}


	/**
	 * Return the query string of a request URI: the text after the first "?" and before
	 * any "#" fragment. Return null when the URI has no "?".
	 */
	public static function extractQueryString( string $uri ): ?string {
		$position = strpos( $uri, '?' );
		if( $position===false ) {
			return null;
		}

		$queryString = substr( $uri, $position + 1 );

		$fragment = strpos( $queryString, '#' );
		if( $fragment!==false ) {
			$queryString = substr( $queryString, 0, $fragment );
		}

		return $queryString;
	}

}
