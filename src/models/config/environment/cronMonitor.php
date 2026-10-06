<?php

namespace gcgov\framework\models\config\environment;


/**
 * The cron monitor web service this application reports scheduled runs to.
 *
 * Not a Framework Service: \gcgov\framework\services\cronMonitor\cronMonitor contributes
 * no routes and takes no part in the request lifecycle, so there is nothing to activate.
 * An empty url disables reporting.
 */
class cronMonitor extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $url = '';


	public function __construct() {
	}


	public function isConfigured(): bool {
		return trim( $this->url )!=='';
	}

}
