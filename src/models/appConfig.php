<?php

namespace gcgov\framework\models;

use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;

/**
 * @deprecated v7 — a read-only VIEW over unifiedConfig limited to the former app.json
 *             sections, returned by the deprecated config::getAppConfig() pass-through so
 *             v6 call sites see the v6 shape. New code reads config::getApp() /
 *             getEmail() / getSettings() directly.
 *
 *             Not a full v6 substitute, and deliberately so: v6's appConfig extended
 *             \andrewsauder\jsonDeserialize\jsonDeserialize, whose static
 *             ::jsonDeserialize() and no-argument constructor make no sense for a view
 *             onto configuration that is already loaded. Reading the three sections and
 *             json_encode()ing the object both work; `new appConfig()` with no argument
 *             and `appConfig::jsonDeserialize()` do not.
 */
#[\JetBrains\PhpStorm\Deprecated( reason: 'v7: read config::getApp()/getEmail()/getSettings() directly', replacement: '\gcgov\framework\config' )]
class appConfig implements \JsonSerializable {

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


	/**
	 * v6 call sites serialize this object; the class it used to extend supplied that.
	 *
	 * @return array{app: app, email: email, settings: settings}
	 */
	public function jsonSerialize(): array {
		return [
			'app'      => $this->app,
			'email'    => $this->email,
			'settings' => $this->settings,
		];
	}

}
