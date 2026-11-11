<?php

namespace gcgov\framework\models\config\services\auth;


class oauth extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/**
	 * Extra parameters forwarded on the authorize redirect.
	 *
	 * @var array<string, string>
	 */
	public array $authorizeUrlParameters = [];

}
