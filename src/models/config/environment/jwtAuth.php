<?php

namespace gcgov\framework\models\config\environment;

class jwtAuth extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/**
	 * Token issuer. Leave empty to derive from the application's rootUrl — they are the
	 * same value in every deployment we have, and configuring both invites them to drift.
	 */
	public string $tokenIssuedBy = "";

	/** Token audience. Leave empty to derive from the application's basePath. */
	public string $tokenPermittedFor = "";

	public string $redirectAfterLoginUrl = "";

	public string $redirectAfterLogoutUrl = "";

	/**
	 * Directory holding the RSA signing keypairs and guids.json.
	 *
	 * Empty means the default `{root}/srv/jwtCertificates`, which is where
	 * `gf cert:generate-auth` writes them. Containers must point this at a
	 * provisioned, read-only location (e.g. /run/secrets/jwt): the keys are
	 * secrets, they are gitignored so they are never in a built image, and every
	 * replica has to sign with the same set.
	 */
	public string $keyPath = "";

	public function __construct() {
	}

}
