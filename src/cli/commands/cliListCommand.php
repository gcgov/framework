<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\routeCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand( name: 'cli:list', description: 'List the application\'s CLI routes' )]
final class cliListCommand extends Command {

	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();

		$cliRoutes = routeCatalog::getCliRoutes( $context );

		if( count( $cliRoutes )===0 ) {
			$output->writeln( 'No CLI routes are registered. Add routes with http method \'CLI\' in \app\router::getRoutes().' );

			return Command::SUCCESS;
		}

		$table = new Table( $output );
		$table->setHeaders( [ 'Route', 'Handler', 'Description' ] );
		foreach( $cliRoutes as $route ) {
			$table->addRow( [ $route->route, $route->class . '::' . $route->method, $route->description ] );
		}
		$table->render();

		$output->writeln( '' );
		$output->writeln( 'Run a route with: <info>gf cli <route></info>' );

		return Command::SUCCESS;
	}

}
