<?php

namespace gcgov\framework\services\auth;

use gcgov\framework\exceptions\routeException;
use gcgov\framework\exceptions\serviceException;

/**
 * Validates the framework JWT on a request and establishes the authenticated user.
 *
 * Provider-independent: both providers mint framework access tokens with the same keys
 * and claims, so verifying one is the same work either way. This existed as ~50 near
 * identical lines in each auth package; the only differences were a local variable name
 * and the wording of a message.
 *
 * Authentication only. Authorization — the route's requiredRoles — belongs to
 * {@see \gcgov\framework\router::assertRequiredRoles()}, because a route declaring roles
 * must have them enforced whether or not this optional service is the thing that
 * authenticated the caller.
 */
final class guard {

	/**
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	public static function authenticate( \gcgov\framework\models\routeHandler $routeHandler ): bool {
		$accessToken = self::readToken( $routeHandler );

		$jwtService = new \gcgov\framework\services\jwtAuth\jwtAuth();
		try {
			$parsedToken = $jwtService->validateAccessToken( $accessToken );
			if( !( $parsedToken instanceof \Lcobucci\JWT\UnencryptedToken ) ) {
				throw new routeException( 'Token parsing failed', 401 );
			}

			$tokenData   = $parsedToken->claims()->get( 'data' );
			$tokenScopes = (array)$parsedToken->claims()->get( 'scope' );

			$authUser = \gcgov\framework\services\request::getAuthUser();
			$authUser->setFromJwtToken( is_array( $tokenData ) ? $tokenData : [], $tokenScopes );
		}
		catch( serviceException $e ) {
			//JWT uses invalid kid/guid
			throw new routeException( $e->getMessage(), 401, $e );
		}
		catch( \Lcobucci\JWT\Encoding\CannotDecodeContent|\Lcobucci\JWT\Token\UnsupportedHeaderFound|\Lcobucci\JWT\Token\InvalidTokenStructure $e ) {
			//JWT did not parse
			throw new routeException( 'Token parsing failed', 401, $e );
		}
		catch( \Lcobucci\JWT\Validation\RequiredConstraintsViolated $e ) {
			//JWT parsed successfully but failed validation
			$violationMessages = [];
			foreach( $e->violations() as $violation ) {
				$violationMessages[] = $violation->getMessage();
			}
			throw new routeException( 'Token validation failed: ' . implode( ', ', $violationMessages ), 401, $e );
		}

		// requiredRoles is NOT checked here. It is declared on the framework's own route
		// model and enforced by router::assertRequiredRoles() once the whole guard chain has
		// run, so it holds for routes this service never sees — an application that
		// authenticates itself, or one that opts out through skipsServiceAuthentication.
		// This guard's job is the part only it can do: validate the token and establish the
		// user the router then checks.
		return true;
	}


	/**
	 * The Authorization header, or — only on routes that opt in — the fileAccessToken
	 * query parameter. A URL-borne token ends up in logs, referrers and browser history,
	 * which is why it is per-route opt-in and why the tokens minted for it expire in
	 * seconds.
	 *
	 * @throws \gcgov\framework\exceptions\routeException
	 */
	private static function readToken( \gcgov\framework\models\routeHandler $routeHandler ): string {
		if( isset( $_SERVER[ 'HTTP_AUTHORIZATION' ] ) ) {
			return (string)$_SERVER[ 'HTTP_AUTHORIZATION' ];
		}

		if( !$routeHandler->allowShortLivedUrlTokens || !isset( $_GET[ 'fileAccessToken' ] ) ) {
			throw new routeException( 'Missing Authorization', 401 );
		}

		return (string)$_GET[ 'fileAccessToken' ];
	}

}
