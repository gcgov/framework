<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\services\environment\dotEnvLoader;
use gcgov\framework\services\environment\environmentException;
use gcgov\framework\services\environment\envVarResolver;
use gcgov\framework\services\guid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'cert:generate-auth', description: 'Generate the JWT signing keypairs in jwtAuth.keyPath (default srv/jwtCertificates)' )]
final class certGenerateAuthCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'count', null, InputOption::VALUE_REQUIRED, 'Number of RSA keypairs to generate', '5' );
		$this->addOption( 'yes', 'y', InputOption::VALUE_NONE, 'Skip the confirmation prompt' );
		$this->setHelp( 'Generates RSA-2048 keypairs using the PHP OpenSSL extension (no openssl binary required) and writes guids.json. Regenerating keys invalidates every JWT issued with the previous keys — all users will need to sign in again.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if( !extension_loaded( 'openssl' ) ) {
			throw new cliException( 'The PHP OpenSSL extension is required to generate keys but is not loaded.' );
		}

		$count = (int)$input->getOption( 'count' );
		if( $count<1 ) {
			throw new cliException( '--count must be at least 1' );
		}

		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		// Resolved through the same accessor jwtAuth reads at runtime, so a configured
		// jwtAuth.keyPath gets the keys written where the framework will look for them.
		$certificateDir = self::resolveCertificateDir( $context, $io );

		$existingKeys = glob( $certificateDir . '/*.pem' ) ?: [];
		if( count( $existingKeys )>0 && !$input->getOption( 'yes' ) ) {
			$io->warning( 'Existing JWT signing keys found in ' . $certificateDir . '. Regenerating invalidates every issued token — all users will need to sign in again.' );
			if( !$io->confirm( 'Delete the existing keys and generate new ones?', false ) ) {
				$io->text( 'Aborted. No changes made.' );

				return Command::FAILURE;
			}
		}

		if( !is_dir( $certificateDir ) && !mkdir( $certificateDir, 0775, true ) ) {
			throw new cliException( 'Failed to create directory ' . $certificateDir );
		}

		// keep the certificate directory out of git, same as create-jwt-keys.ps1
		$gitignoreSource = __DIR__ . '/../../services/jwtAuth/jwtCertificates/.gitignore';
		if( !file_exists( $certificateDir . '/.gitignore' ) && file_exists( $gitignoreSource ) ) {
			copy( $gitignoreSource, $certificateDir . '/.gitignore' );
		}

		foreach( $existingKeys as $existingKey ) {
			unlink( $existingKey );
		}
		if( file_exists( $certificateDir . '/guids.json' ) ) {
			unlink( $certificateDir . '/guids.json' );
		}

		$guids = [];
		for( $i = 0; $i<$count; $i++ ) {
			// Lowercased at the source: on Windows guid::create() returns uppercase GUIDs
			// (com_create_guid), and the ops repository's provisioning lowercases every
			// secret filename it writes to the host — so an uppercase GUID here would put
			// a lowercase file on a case-sensitive filesystem while guids.json still
			// names the uppercase spelling jwtAuth looks up, and every sign-in fails.
			$keyGuid = strtolower( guid::create() );
			$guids[] = $keyGuid;

			$privateKey = openssl_pkey_new( [
				                                'private_key_bits' => 2048,
				                                'private_key_type' => OPENSSL_KEYTYPE_RSA,
			                                ] );
			if( $privateKey===false ) {
				throw new cliException( 'openssl_pkey_new() failed: ' . (string)openssl_error_string() . '. On Windows this usually means the openssl.cnf file cannot be found — set the OPENSSL_CONF environment variable to your php/extras/ssl/openssl.cnf path and retry.' );
			}

			if( !openssl_pkey_export( $privateKey, $privateKeyPem ) ) {
				throw new cliException( 'openssl_pkey_export() failed: ' . (string)openssl_error_string() );
			}

			$keyDetails = openssl_pkey_get_details( $privateKey );
			if( $keyDetails===false || !isset( $keyDetails[ 'key' ] ) ) {
				throw new cliException( 'openssl_pkey_get_details() failed: ' . (string)openssl_error_string() );
			}

			file_put_contents( $certificateDir . '/private-' . $keyGuid . '.pem', $privateKeyPem );
			file_put_contents( $certificateDir . '/public-' . $keyGuid . '.pem', $keyDetails[ 'key' ] );

			$io->text( 'Generated keypair ' . $keyGuid );
		}

		// UTF-8 without BOM, matching create-jwt-keys.ps1 output
		file_put_contents( $certificateDir . '/guids.json', json_encode( $guids, JSON_PRETTY_PRINT ) );

		$io->success( 'Generated ' . $count . ' JWT signing keypair(s) in ' . $certificateDir );

		return Command::SUCCESS;
	}


	/**
	 * The directory the keys belong in: jwtAuth.keyPath through the runtime's accessor —
	 * but without demanding that the REST of config.json resolve. Key generation needs
	 * one path, and it runs earliest of all: `gf init` calls it on a scaffold whose .env
	 * is still empty, where loadConfig() fails on the first of many unrelated references
	 * (MONGO_URI among them) and key generation used to fail with it, breaking init's
	 * own contract of producing JWT keys.
	 *
	 * A relative configured path is anchored to the application root rather than the
	 * cwd, so one committed value serves the host CLI wherever gf is invoked from.
	 */
	private static function resolveCertificateDir( appContext $context, SymfonyStyle $io ): string {
		try {
			$dir = $context->loadConfig()->getJwtKeyPath( $context->getSrvDir() );
		}
		catch( cliException $e ) {
			$dir = self::keyPathWithoutFullConfig( $context, $io, $e );
		}

		$dir = rtrim( str_replace( '\\', '/', $dir ), '/' );
		if( !preg_match( '~^(?:/|[A-Za-z]:/)~', $dir ) ) {
			$dir = $context->rootDir . '/' . $dir;
		}

		return $dir;
	}


	/**
	 * jwtAuth.keyPath alone, from the raw config document. A literal is used as-is; a
	 * %env()% reference is resolved by itself; only when that one reference has no value
	 * does this fall back to the default directory — loudly, because a configured path
	 * silently ignored would put the keys where the runtime will not look.
	 */
	private static function keyPathWithoutFullConfig( appContext $context, SymfonyStyle $io, cliException $loadFailure ): string {
		$default = $context->getSrvDir() . '/jwtCertificates';

		$raw        = file_exists( $context->getConfigPath() ) ? (string)file_get_contents( $context->getConfigPath() ) : '';
		$decoded    = json_decode( $raw );
		$configured = $decoded instanceof \stdClass && isset( $decoded->jwtAuth->keyPath ) ? trim( (string)$decoded->jwtAuth->keyPath ) : '';

		if( $configured==='' ) {
			return $default;
		}

		if( str_contains( $configured, '%env(' ) ) {
			// loadConfig() may have thrown before its own .env load ran.
			dotEnvLoader::loadOnce( $context->rootDir );
			try {
				$configured = trim( (string)envVarResolver::resolveDecoded( (object)[ 'keyPath' => $configured ], 'config.json jwtAuth.keyPath' )->keyPath );
			}
			catch( environmentException $e ) {
				$io->warning( 'config.json does not fully resolve (' . $loadFailure->getMessage() . ') and jwtAuth.keyPath itself has no value yet (' . $e->getMessage() . '). Writing the keys to the default ' . $default . ' — if that variable is meant to point somewhere else, set it and re-run cert:generate-auth.' );

				return $default;
			}
		}

		if( $configured==='' ) {
			return $default;
		}

		$io->note( 'config.json does not fully resolve yet — key generation needs only jwtAuth.keyPath, so continuing with it.' );

		return $configured;
	}

}
