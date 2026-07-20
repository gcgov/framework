<?php

namespace gcgov\framework\models\config\environment;

use gcgov\framework\exceptions\configException;

class microsoft extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $clientId     = "";

	public string $clientSecret = "";

	/**
	 * Path to the PEM encoded public certificate (.crt/.pem) uploaded to the Entra ID app registration.
	 * Absolute, or relative to the application root directory. When certificatePath and privateKeyPath
	 * are both set, certificate credential authentication is used instead of clientSecret.
	 */
	public string $certificatePath = "";

	/**
	 * Path to the PEM encoded private key for the certificate. Absolute, or relative to the
	 * application root directory.
	 */
	public string $privateKeyPath = "";

	/** Passphrase for the private key; leave empty for an unencrypted key */
	public string $privateKeyPassphrase = "";

	public string $tenant       = "";

	public string $driveId      = "";

	public string $fromAddress  = "";


	public function __construct() {
	}


	public function useCertificateAuthentication(): bool {
		return $this->certificatePath!=='' && $this->privateKeyPath!=='';
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function getCertificateContents(): string {
		return $this->readPemFile( $this->certificatePath, 'certificatePath' );
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	public function getPrivateKeyContents(): string {
		return $this->readPemFile( $this->privateKeyPath, 'privateKeyPath' );
	}


	/**
	 * @throws \gcgov\framework\exceptions\configException
	 */
	private function readPemFile( string $path, string $configKey ): string {
		$resolvedPath = $this->resolvePath( $path );
		if( $resolvedPath===null ) {
			throw new configException( 'File for environment.json microsoft.' . $configKey . ' not found: ' . $path );
		}

		$contents = file_get_contents( $resolvedPath );
		if( $contents===false ) {
			throw new configException( 'Unable to read environment.json microsoft.' . $configKey . ' file: ' . $resolvedPath );
		}

		return $contents;
	}


	private function resolvePath( string $path ): ?string {
		if( $path==='' ) {
			return null;
		}

		if( file_exists( $path ) ) {
			return $path;
		}

		//fall back to a path relative to the application root
		try {
			$rootRelativePath = \gcgov\framework\config::getRootDir() . '/' . ltrim( str_replace( '\\', '/', $path ), '/' );
			if( file_exists( $rootRelativePath ) ) {
				return $rootRelativePath;
			}
		}
		catch( \Throwable $e ) {
			//\app\app is not defined (e.g. unit tests) - only explicit paths can resolve
		}

		return null;
	}
}
