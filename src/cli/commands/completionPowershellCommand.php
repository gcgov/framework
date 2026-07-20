<?php

namespace gcgov\framework\cli\commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\CompleteCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand( name: 'completion:powershell', description: 'Print the PowerShell tab-completion script for gf' )]
final class completionPowershellCommand extends Command {

	protected function configure(): void {
		$this->setHelp( 'PowerShell counterpart to the built-in `gf completion bash|zsh|fish`. Install by adding this line to your PowerShell $PROFILE:' . PHP_EOL . PHP_EOL . '  vendor\bin\gf completion:powershell | Out-String | Invoke-Expression' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$template = file_get_contents( __DIR__ . '/../resources/completion.ps1' );
		if( $template===false ) {
			$output->writeln( '<error>completion.ps1 resource is missing from the framework package</error>' );

			return Command::FAILURE;
		}

		$output->write( str_replace( '{{API_VERSION}}', CompleteCommand::COMPLETION_API_VERSION, $template ) );

		return Command::SUCCESS;
	}

}
