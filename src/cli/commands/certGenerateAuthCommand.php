<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\services\guid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'cert:generate-auth', description: 'Generate the JWT signing keypairs in srv/jwtCertificates (replaces create-jwt-keys.ps1)' )]
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

		$context        = appContext::require();
		$certificateDir = $context->getSrvDir() . '/jwtCertificates';

		$io = new SymfonyStyle( $input, $output );

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
			$keyGuid = guid::create();
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

}
