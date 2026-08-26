<?php

namespace gcgov\framework\models\config;

/**
 * One entry of config.json's `environments` section — the per-environment
 * connection info the gf CLI needs for foreign-environment reads
 * (`db:restore --from=prod`, `db:run --env=prod`, `gf env prod`).
 *
 * The runtime never reads this section (configLoader strips it before resolving
 * the active configuration), so its `%env(...)%` references should use
 * environment-prefixed variable names (e.g. PROD_MONGO_URI) that are distinct
 * from the active configuration's names — a missing value then fails loudly
 * instead of silently resolving to the local environment's value.
 *
 * `type` should be a committed literal (e.g. "prod"), NOT an `%env()` reference:
 * the db:restore prod guard relies on it.
 */
class variantEnvironment extends \andrewsauder\jsonDeserialize\jsonDeserialize {

	public string $type = '';

	/** @var \gcgov\framework\models\config\environment\mongoDatabase[] */
	public array $mongoDatabases = [];

}
