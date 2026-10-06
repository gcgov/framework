<?php

namespace gcgov\framework\models\config\environment;

class logging extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	/** Write log records to stderr as JSON lines — the default, and what a container needs. */
	public const string DESTINATION_STDERR = 'stderr';

	/** Write log records to {root}/logs/{channel}.log, as v6 did. */
	public const string DESTINATION_FILE = 'file';

	/** Both of the above. */
	public const string DESTINATION_BOTH = 'both';

	public bool $lifecycle = false;

	public bool $renderer = false;

	/**
	 * Where log records go: 'stderr' (default), 'file', or 'both'.
	 *
	 * stderr is the default because a container's filesystem does not survive a
	 * deploy — file logs would be per-replica and destroyed on every release.
	 * Applications still hosted on IIS set 'file'.
	 */
	public string $destination = self::DESTINATION_STDERR;

	public function __construct() {
	}


	public function writesToStderr(): bool {
		return $this->destination===self::DESTINATION_STDERR || $this->destination===self::DESTINATION_BOTH;
	}


	public function writesToFile(): bool {
		return $this->destination===self::DESTINATION_FILE || $this->destination===self::DESTINATION_BOTH;
	}

}
