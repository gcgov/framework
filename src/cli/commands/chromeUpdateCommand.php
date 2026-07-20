<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\chromeInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'chrome:update', description: 'Update chrome-headless-shell to the current Stable version and remove old versions from srv/chrome' )]
final class chromeUpdateCommand extends Command {

	protected function configure(): void {
		$this->setHelp( 'Checks the Chrome for Testing Stable channel, downloads a newer chrome-headless-shell build when available, then removes superseded version directories and stale temp files from srv/chrome. Idempotent — safe to run on a schedule.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();

		( new chromeInstaller( $context->getSrvDir() ) )->install( new SymfonyStyle( $input, $output ), force: false, prune: true );

		return Command::SUCCESS;
	}

}
