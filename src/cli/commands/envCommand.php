<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\environmentFiles;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand( name: 'env', description: 'Activate an environment: copy environment-{env}.json, composer-{env}.json, and www/web-{env}.config to their canonical names' )]
final class envCommand extends Command {

	protected function configure(): void {
		$this->addArgument( 'environment', InputArgument::REQUIRED, 'Environment name, e.g. local or prod', null, self::suggestEnvironments( ... ) );
		$this->addOption( 'dry-run', null, InputOption::VALUE_NONE, 'Show what would be copied without changing any files' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context     = appContext::require();
		$environment = $input->getArgument( 'environment' );

		$results = environmentFiles::apply( $context->rootDir, $environment, (bool)$input->getOption( 'dry-run' ) );

		foreach( $results as $result ) {
			if( str_starts_with( $result[ 'status' ], 'skipped' ) ) {
				$output->writeln( '<comment>' . $result[ 'status' ] . '</comment>' );
			}
			else {
				$output->writeln( '<info>' . $result[ 'status' ] . '</info>: ' . $result[ 'source' ] . ' -> ' . $result[ 'target' ] );
			}
		}

		return Command::SUCCESS;
	}


	/**
	 * @return string[]
	 */
	public static function suggestEnvironments( CompletionInput $completionInput ): array {
		try {
			$context = appContext::locate();

			return $context===null ? [] : $context->getEnvironmentVariants();
		}
		catch( \Throwable ) {
			return [];
		}
	}

}
