<?php

namespace gcgov\framework\cli;

/**
 * Contribute custom commands to the gf CLI.
 *
 * Applications implement this as \app\cli\commandProvider (file: app/cli/commandProvider.php).
 * Framework-service plugins implement it as \gcgov\framework\services\{name}\cli\commandProvider
 * (file: src/cli/commandProvider.php in the plugin repo). gf discovers implementations
 * automatically for the app namespace and every namespace returned by
 * \app\app::registerFrameworkServiceNamespaces() — no additional registration required.
 *
 * Commands are ordinary symfony/console commands. Plugin commands should namespace their
 * names to avoid collisions (e.g. 'docs:regenerate').
 */
interface commandProvider {

	/**
	 * @return \Symfony\Component\Console\Command\Command[]
	 */
	public static function getCommands(): array;

}
