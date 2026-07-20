<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\tokenReplacer;
use gcgov\framework\services\guid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'setup', description: 'Bootstrap a newly scaffolded application: prompt for config values and replace {placeholder} tokens (replaces setup.ps1)' )]
final class setupCommand extends Command {

	/** @var array<string, string> prompt key => label */
	private const array PROMPTS = [
		'app_title'                    => 'Human readable title of application (ex: Timesheet API)',
		'app_root_url'                 => 'DEV Root url of app (ex: https://local-app.garrettcountymd.gov/)',
		'app_base_path'                => 'DEV Base url path of app (ex: /api/, Or: / if site is at url root)',
		'app_frontend_root_url'        => 'DEV If using this app as an API and you have a separate frontend, enter the root of the frontend app (ex: https://localhost:8080/)',
		'app_redirect_after_login'     => 'DEV If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful login (ex: https://localhost:8080/auth/sign-in)',
		'app_redirect_after_logout'    => 'DEV If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful logout (ex: https://localhost:8080/auth/sign-out)',
		'app_smtp_server'              => 'DEV SMTP server address (ex: tenant-com.mail.protection.outlook.com)',
		'app_smtp_sendmail_from_address' => 'DEV Default email address to send emails from (ex: noreply@tenant.com)',
		'app_smtp_sendmail_from_name'  => 'DEV Default human-readable name that will appear as the sender of emails (ex: Tenant Company)',
		'app_ssl_path'                 => 'DEV Absolute path to a current cacert.pem file for CURL and OpenSSL extensions (path only)',
		'app_php_path'                 => 'DEV Absolute path to the PHP executable root directory',
		'prod_app_root_url'            => 'PROD Root url of app (ex: https://app.garrettcountymd.gov/)',
		'prod_app_base_path'           => 'PROD Base url path of app (ex: /api/, Or: / if site is at url root)',
		'prod_app_frontend_root_url'   => 'PROD If using this app as an API and you have a separate frontend, enter the root of the frontend app (ex: https://app.garrettcountymd.gov/)',
		'prod_app_redirect_after_login'  => 'PROD If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful login (ex: https://app.garrettcountymd.gov/app/auth/sign-in)',
		'prod_app_redirect_after_logout' => 'PROD If appConfig.enableAuthRoutes==true, user will be redirected to this url after successful logout (ex: https://app.garrettcountymd.gov/app/auth/sign-out)',
		'prod_app_absolute_path'       => 'PROD Production absolute path to root directory (ex: E:\Web\api)',
		'prod_app_ssl_path'            => 'PROD Absolute path to a current cacert.pem file for CURL and OpenSSL extensions (path only)',
		'prod_app_php_path'            => 'PROD Absolute path to the PHP executable root directory',
	];

	/** @var array<string, string> */
	private const array MICROSOFT_PROMPTS = [
		'app_microsoft_client_id'                 => 'DEV Microsoft Azure App client id',
		'app_microsoft_client_secret'             => 'DEV Microsoft Azure App client secret',
		'app_microsoft_tenant'                    => 'DEV Microsoft Azure App tenant',
		'app_microsoft_drive_id'                  => 'DEV Microsoft Azure App drive id',
		'app_microsoft_default_from_address'      => 'DEV Microsoft Azure App default from address',
		'prod_app_microsoft_client_id'            => 'PROD Microsoft Azure App client id',
		'prod_app_microsoft_client_secret'        => 'PROD Microsoft Azure App client secret',
		'prod_app_microsoft_tenant'               => 'PROD Microsoft Azure App tenant',
		'prod_app_microsoft_drive_id'             => 'PROD Microsoft Azure App drive id',
		'prod_app_microsoft_default_from_address' => 'PROD Microsoft Azure App default from address',
	];


	protected function configure(): void {
		$this->addOption( 'skip-chrome', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Skip downloading chrome-headless-shell' );
		$this->setHelp( 'Run once after scaffolding a project from gcgov/framework-app-template. Prompts for the project configuration values and replaces the {placeholder} tokens across the project files. Press enter at any prompt to skip that value (the token stays in place for a later re-run). Also downloads chrome-headless-shell into srv/chrome (skip with --skip-chrome).' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		if( !$input->isInteractive() ) {
			throw new cliException( 'gf setup is interactive — run it from a terminal without --no-interaction.' );
		}

		$context = appContext::locateScaffold();
		if( $context===null ) {
			throw new cliException( 'gf setup must be run from inside a scaffolded application (a directory containing composer.json and an app/ directory).' );
		}

		$io = new SymfonyStyle( $input, $output );
		$io->title( 'gcgov/framework application setup' );
		$io->text( [ 'Application root: ' . $context->rootDir, 'To skip replacing a value, press enter.', '' ] );

		$prompts = self::PROMPTS;
		if( $io->confirm( 'Do you want to define Microsoft Azure App ids during set up?', false ) ) {
			$prompts = array_merge( $prompts, self::MICROSOFT_PROMPTS );
		}

		$inputs = [];
		foreach( $prompts as $key => $label ) {
			$inputs[ $key ] = (string)( $io->ask( $label ) ?? '' );
		}

		// review/edit loop
		while( true ) {
			$io->section( 'Review' );
			$index = 1;
			$keysByIndex = [];
			foreach( $prompts as $key => $label ) {
				$io->text( $index . '. ' . $key . ': ' . $inputs[ $key ] );
				$keysByIndex[ $index ] = $key;
				$index++;
			}

			$selection = (int)( $io->ask( 'Enter the number of a value to edit, or 0 to confirm all', '0' ) ?? '0' );
			if( $selection===0 ) {
				break;
			}
			if( isset( $keysByIndex[ $selection ] ) ) {
				$key = $keysByIndex[ $selection ];
				$inputs[ $key ] = (string)( $io->ask( 'Enter the new value for ' . $key ) ?? '' );
			}
			else {
				$io->error( 'Invalid selection. Enter a number between 1 and ' . count( $prompts ) . ', or 0 to finish.' );
			}
		}

		$replacements  = $this->buildReplacementTable( $inputs, $context->rootDir );
		$modifiedFiles = tokenReplacer::replaceInTree( $context->rootDir, $replacements );

		if( count( $modifiedFiles )===0 ) {
			$io->text( 'No files contained tokens to replace. (Already set up, or all values were skipped.)' );
		}
		else {
			$io->section( 'Updated files' );
			foreach( $modifiedFiles as $file ) {
				$io->text( '  ' . $file );
			}
		}

		if( !$input->getOption( 'skip-chrome' ) ) {
			$io->section( 'chrome-headless-shell' );
			try {
				( new \gcgov\framework\cli\chromeInstaller( $context->getSrvDir() ) )->install( $io );
			}
			catch( \Throwable $e ) {
				$io->warning( 'chrome-headless-shell was not installed: ' . $e->getMessage() . ' You can install it later with `vendor/bin/gf chrome:install`.' );
			}
		}

		$io->success( 'Setup complete. Next: `gf env local`, then `gf cert:generate-auth`.' );

		return Command::SUCCESS;
	}


	/**
	 * @param array<string, string> $inputs
	 *
	 * @return array<string, string> token => replacement value (empty values are dropped by tokenReplacer)
	 */
	public function buildReplacementTable( array $inputs, string $rootDir ): array {
		$value = fn( string $key ): string => $inputs[ $key ] ?? '';
		$trimmedValue = fn( string $key ): string => rtrim( $value( $key ), '/\\' );

		$replacements = [
			'{app_guid}'                     => guid::create(),
			'{app_title}'                    => $value( 'app_title' ),
			'{app_root_url}'                 => $trimmedValue( 'app_root_url' ),
			'{app_base_path}'                => $value( 'app_base_path' )==='' ? '' : tokenReplacer::formatRelativeUrl( $value( 'app_base_path' ) ),
			'{app_relative_url}'             => $value( 'app_base_path' )==='' ? '' : tokenReplacer::formatRelativeUrl( $value( 'app_base_path' ), true, false ),
			'{app_frontend_root_url}'        => $trimmedValue( 'app_frontend_root_url' ),
			'{app_redirect_after_login}'     => $value( 'app_redirect_after_login' ),
			'{app_redirect_after_logout}'    => $value( 'app_redirect_after_logout' ),
			'{app_absolute_path}'            => rtrim( $rootDir, '/\\' ),
			'{app_smtp_server}'              => $value( 'app_smtp_server' ),
			'{app_smtp_sendmail_from_address}' => $value( 'app_smtp_sendmail_from_address' ),
			'{app_smtp_sendmail_from_name}'  => $value( 'app_smtp_sendmail_from_name' ),
			'{app_ssl_path}'                 => $trimmedValue( 'app_ssl_path' ),
			'{app_php_path}'                 => $trimmedValue( 'app_php_path' ),
			'{prod_app_root_url}'            => $trimmedValue( 'prod_app_root_url' ),
			'{prod_app_base_path}'           => $value( 'prod_app_base_path' )==='' ? '' : tokenReplacer::formatRelativeUrl( $value( 'prod_app_base_path' ) ),
			'{prod_app_relative_url}'        => $value( 'prod_app_base_path' )==='' ? '' : tokenReplacer::formatRelativeUrl( $value( 'prod_app_base_path' ), true, false ),
			'{prod_app_frontend_root_url}'   => $trimmedValue( 'prod_app_frontend_root_url' ),
			'{prod_app_redirect_after_login}'  => $value( 'prod_app_redirect_after_login' ),
			'{prod_app_redirect_after_logout}' => $value( 'prod_app_redirect_after_logout' ),
			'{prod_app_absolute_path}'       => $trimmedValue( 'prod_app_absolute_path' ),
			'{prod_app_ssl_path}'            => $trimmedValue( 'prod_app_ssl_path' ),
			'{prod_app_php_path}'            => $trimmedValue( 'prod_app_php_path' ),
		];

		foreach( array_keys( self::MICROSOFT_PROMPTS ) as $microsoftKey ) {
			$replacements[ '{' . $microsoftKey . '}' ] = $value( $microsoftKey );
		}

		return $replacements;
	}

}
