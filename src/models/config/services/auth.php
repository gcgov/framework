<?php

namespace gcgov\framework\models\config\services;


use gcgov\framework\models\config\services\auth\msFront;
use gcgov\framework\models\config\services\auth\oauth;
use gcgov\framework\services\environment\environmentException;

/**
 * Configuration for the authentication service.
 *
 * One service, two providers. Everything downstream of "we have an identity" is common —
 * the JWT guard, the JWKS document, short-lived file tokens, and whether a successful
 * sign-in may create a user — so it lives here. What differs is only how a token is
 * obtained, which is what the provider blocks describe.
 *
 * Because there is one `provider`, two providers cannot be active at once. That is why
 * the framework needs no conflict check between competing auth services.
 */
class auth extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/** Full OAuth server: password, third-party and authorization-code grants, plus MFA. */
	const PROVIDER_OAUTH = 'oauth';

	/** Exchange a Microsoft token the front end already holds for an application token. */
	const PROVIDER_MS_FRONT = 'msFront';

	/** @var string[] */
	const PROVIDERS = [ self::PROVIDER_OAUTH, self::PROVIDER_MS_FRONT ];

	public string $provider = '';

	/**
	 * When true, only users already present in the database may sign in. When false, a
	 * successful authentication provisions a user carrying $defaultNewUserRoles.
	 */
	public bool $blockNewUsers = true;

	/** @var string[] */
	public array $defaultNewUserRoles = [];

	public ?oauth $oauth = null;

	public ?msFront $msFront = null;


	/**
	 * @throws \gcgov\framework\services\environment\environmentException
	 */
	protected function _afterJsonDeserialize(): void {
		if( !in_array( $this->provider, self::PROVIDERS, true ) ) {
			throw new environmentException( 'services.auth.provider must be one of "' . implode( '", "', self::PROVIDERS ) . '"' . ( $this->provider==='' ? ', and is missing' : ', not "' . $this->provider . '"' ) . '.' );
		}

		// A block for the provider that is not selected is configuration that would never
		// be read. Saying so is the whole point of a fail-closed configuration: a setting
		// that appears to do something and does nothing is the failure mode to prevent.
		foreach( self::PROVIDERS as $provider ) {
			if( $provider!==$this->provider && $this->$provider!==null ) {
				throw new environmentException( 'services.auth.' . $provider . ' is configured but services.auth.provider is "' . $this->provider . '", so nothing would read it. Remove the "' . $provider . '" block or change the provider.' );
			}
		}

		// The selected provider's block may be omitted entirely; a missing section
		// hydrating to its defaults is the established rule for every other section.
		if( $this->provider===self::PROVIDER_OAUTH && $this->oauth===null ) {
			$this->oauth = new oauth();
		}
		if( $this->provider===self::PROVIDER_MS_FRONT && $this->msFront===null ) {
			$this->msFront = new msFront();
		}
	}


	public function isOauth(): bool {
		return $this->provider===self::PROVIDER_OAUTH;
	}


	public function isMsFront(): bool {
		return $this->provider===self::PROVIDER_MS_FRONT;
	}

}
