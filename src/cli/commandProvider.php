<?php

namespace gcgov\framework\cli;

/**
 * Contribute custom commands to the gf CLI.
 *
 * Applications implement this as \app\cli\commandProvider (file: app/cli/commandProvider.php);
 * gf discovers it automatically — no additional registration required.
 *
 * Framework Services do not use this. They live in the framework, so a command belonging
 * to one is registered directly in \gcgov\framework\cli\application's constructor.
 *
 * Commands are ordinary symfony/console commands. Namespace their names to avoid
 * collisions with the built-ins (e.g. 'widget:reindex').
 */
interface commandProvider {

	/**
	 * @return \Symfony\Component\Console\Command\Command[]
	 */
	public static function getCommands(): array;

}
