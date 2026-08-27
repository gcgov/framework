<?php

namespace gcgov\framework\models\config;


use gcgov\framework\models\config\services\auth;
use gcgov\framework\models\config\services\documentation;
use gcgov\framework\models\config\services\userCrud;

/**
 * Which Framework Services this application runs, and how each is configured.
 *
 * Presence enables: a service whose block is absent is not constructed and contributes
 * no routes. An empty block (`{}`) enables the service with its default settings. The
 * block's contents are that service's configuration — activation and settings are the
 * same declaration, so "how is auth set up here?" has exactly one answer.
 */
class services extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public ?auth $auth = null;

	public ?userCrud $userCrud = null;

	public ?documentation $documentation = null;

}
