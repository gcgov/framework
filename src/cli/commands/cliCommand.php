<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\phpProcess;
use gcgov\framework\cli\routeCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand( name: 'cli', description: 'Run an application CLI route (a route registered with HTTP method \'CLI\')' )]
final class cliCommand extends Command {

	protected function configure(): void {
		$this->addArgument( 'route', InputArgument::OPTIONAL, 'The CLI route to run, e.g. /cli/generate-shifts. Omit to list available CLI routes.', null, self::suggestCliRoutes( ... ) );
		$this->addOption( 'debug', null, InputOption::VALUE_NONE, 'Run the route with Xdebug step debugging enabled (replaces local-debug.bat)' );
		$this->addOption( 'debug-host', null, InputOption::VALUE_REQUIRED, 'Xdebug client host', '127.0.0.1' );
		$this->addOption( 'debug-port', null, InputOption::VALUE_REQUIRED, 'Xdebug client port', '9003' );
		$this->addOption( 'php', null, InputOption::VALUE_REQUIRED, 'PHP binary (or its directory) to run the route with. Defaults to GF_PHP, then environment.json phpPath, then the PHP running gf.' );
		$this->setHelp( 'Executes the route through the full framework lifecycle in a fresh PHP process, exactly like the legacy app/cli/index.php entry. Exit code is 0 on success and 1 when the response status is 400 or higher.' );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$route = $input->getArgument( 'route' );

		if( $route===null || $route==='' ) {
			$listCommand = $this->getApplication()?->find( 'cli:list' );
			if( $listCommand!==null ) {
				return $listCommand->run( new ArrayInput( [] ), $output );
			}

			throw new cliException( 'No route provided. Usage: gf cli /cli/your-route' );
		}

		$context = appContext::require();
		$context->assertAppLoadable();

		$environmentConfig = null;
		try {
			$environmentConfig = $context->loadEnvironmentConfig();
		}
		catch( cliException ) {
			// environment.json missing — the child process will report it through the framework lifecycle
		}

		$phpBinary = phpProcess::findPhpBinary( $input->getOption( 'php' ), $environmentConfig );

		$commandLine = [ $phpBinary ];
		if( $input->getOption( 'debug' ) ) {
			$commandLine = array_merge( $commandLine, phpProcess::xdebugFlags( (string)$input->getOption( 'debug-host' ), (int)$input->getOption( 'debug-port' ) ) );
		}
		$commandLine[] = __DIR__ . '/../internal/run-route.php';
		$commandLine[] = $context->getVendorAutoloadPath();
		$commandLine[] = $route;

		if( $output->isVerbose() ) {
			$output->writeln( '<comment>Running: ' . implode( ' ', $commandLine ) . '</comment>' );
		}

		$process = new Process( $commandLine, $context->rootDir, null, null, null );
		$process->run( function( string $type, string $buffer ) use ( $output ): void {
			if( $type===Process::ERR && $output instanceof \Symfony\Component\Console\Output\ConsoleOutputInterface ) {
				$output->getErrorOutput()->write( $buffer );
			}
			else {
				$output->write( $buffer );
			}
		} );

		return $process->getExitCode() ?? Command::FAILURE;
	}


	/**
	 * @return \Symfony\Component\Console\Completion\Suggestion[]
	 */
	public static function suggestCliRoutes( CompletionInput $completionInput ): array {
		try {
			$context = appContext::locate();
			if( $context===null ) {
				return [];
			}

			$suggestions = [];
			foreach( routeCatalog::getCliRoutes( $context ) as $route ) {
				$suggestions[] = new Suggestion( $route->route, $route->description );
			}

			return $suggestions;
		}
		catch( \Throwable ) {
			return [];
		}
	}

}
