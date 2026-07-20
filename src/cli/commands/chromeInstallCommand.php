<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\chromeInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'chrome:install', description: 'Download chrome-headless-shell (Chrome for Testing, Stable) into srv/chrome for use via the framework chrome service' )]
final class chromeInstallCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'Reinstall even when the current stable version is already installed' );
		$this->setHelp( 'Detects your platform (win64, win32, linux64, mac-x64, mac-arm64), downloads the matching chrome-headless-shell build (~100-150 MB) from Chrome for Testing, and installs it into {appRoot}/srv/chrome/{version}/. The application then uses it through \gcgov\framework\services\chrome\chrome::getBrowserFactory(). Requires the PHP zip extension. `gf setup` runs this automatically; `gf chrome:update` refreshes to the newest stable and removes old versions.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();

		( new chromeInstaller( $context->getSrvDir() ) )->install( new SymfonyStyle( $input, $output ), force: (bool)$input->getOption( 'force' ), prune: false );

		return Command::SUCCESS;
	}

}
