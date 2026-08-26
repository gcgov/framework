<?php

namespace gcgov\framework\models;

use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;

/**
 * @deprecated v7 — a read-only VIEW over unifiedConfig limited to the former
 *             app.json sections, returned by the deprecated config::getAppConfig()
 *             pass-through so v6 call sites (including ones that serialize the
 *             object) see exactly the v6 shape and nothing more. New code reads
 *             config::getApp() / getEmail() / getSettings() directly.
 */
#[\JetBrains\PhpStorm\Deprecated( reason: 'v7: read config::getApp()/getEmail()/getSettings() directly', replacement: '\gcgov\framework\config' )]
class appConfig {

	public app      $app;

	public email    $email;

	public settings $settings;


	public function __construct( unifiedConfig $unifiedConfig ) {
		// Shares the same section objects — reads through the view always match
		// the live configuration.
		$this->app      = $unifiedConfig->app;
		$this->email    = $unifiedConfig->email;
		$this->settings = $unifiedConfig->settings;
	}

}
