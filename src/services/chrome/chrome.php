<?php

namespace gcgov\framework\services\chrome;

use gcgov\framework\config;
use gcgov\framework\exceptions\serviceException;
use HeadlessChromium\BrowserFactory;

/**
 * Headless Chrome service for applications.
 *
 * The chrome-headless-shell binary is installed per application by the gf CLI
 * (`vendor/bin/gf chrome:install`, refreshed with `gf chrome:update`; `gf setup`
 * installs it automatically) into {root}/srv/chrome/.
 *
 * Usage:
 *   $browser = \gcgov\framework\services\chrome\chrome::getBrowserFactory()->createBrowser();
 */
class chrome {

	/**
	 * Absolute path to the currently installed chrome-headless-shell executable.
	 *
	 * @throws \gcgov\framework\exceptions\serviceException When no installation exists
	 */
	public static function getExecutablePath(): string {
		$executablePath = chromeInstallation::getExecutablePath( config::getSrvDir() );

		if( $executablePath===null ) {
			throw new serviceException( 'chrome-headless-shell is not installed for this application. Run `vendor/bin/gf chrome:install` to download it into srv/chrome.' );
		}

		return $executablePath;
	}


	/**
	 * A BrowserFactory bound to the installed chrome-headless-shell executable.
	 * Call ->createBrowser() (optionally with options) on the result.
	 *
	 * @throws \gcgov\framework\exceptions\serviceException When no installation exists
	 */
	public static function getBrowserFactory(): BrowserFactory {
		return new BrowserFactory( self::getExecutablePath() );
	}

}
