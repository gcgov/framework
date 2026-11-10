<?php

namespace gcgov\framework\cli\commands;

use gcgov\framework\cli\appContext;
use gcgov\framework\cli\cliException;
use gcgov\framework\cli\mongoTools;
use gcgov\framework\services\environment\envVarResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand( name: 'env', description: 'Validate that config.json resolves; list the variables it needs, or write a .env skeleton' )]
final class envCommand extends Command {

	protected function configure(): void {
		$this->addOption( 'list', null, InputOption::VALUE_NONE, 'Print the variables config.json references, marking which are secrets, and exit' );
		$this->addOption( 'init', null, InputOption::VALUE_NONE, 'Write a .env skeleton containing every variable config.json references' );
		$this->addOption( 'force', null, InputOption::VALUE_NONE, 'With --init, overwrite an existing .env' );
		$this->setHelp( <<<'HELP'
			Configuration is one committed config.json whose environment-varying values are
			%env(...) references. Every reference is required — there are no defaults — so this
			command is how you find out what an environment is missing before the application does.

			  gf env            validate: resolve config.json against the current environment
			  gf env --list     the variables config.json references, and which are secrets
			  gf env --init     write a .env skeleton from that same list

			The manifest is derived from config.json rather than hand-maintained, so it cannot
			drift. Note that .env also carries variables config.json knows nothing about (docker
			compose ports, CORS origins); --init leaves anything already in the file alone.

			A secret reference (%env(secret:NAME)%) is satisfied either by NAME or by NAME_FILE
			pointing at a provisioned file — which is how one config.json serves both a developer
			machine and production.
			HELP );
	}


	protected function execute( InputInterface $input, OutputInterface $output ): int {
		$context = appContext::require();
		$io      = new SymfonyStyle( $input, $output );

		if( $input->getOption( 'list' ) ) {
			return $this->listReferences( $context, $io );
		}

		if( $input->getOption( 'init' ) ) {
			return $this->writeEnvFile( $context, $io, (bool)$input->getOption( 'force' ) );
		}

		return $this->validate( $context, $io );
	}


	private function validate( appContext $context, SymfonyStyle $io ): int {
		$io->section( 'config.json + the current environment' );

		try {
			$config = $context->loadConfig();
		}
		catch( cliException $e ) {
			$io->error( $e->getMessage() );
			$io->text( 'Run `gf env --list` to see every variable config.json needs, or `gf env --init` to write a .env skeleton.' );

			return Command::FAILURE;
		}

		$io->text( 'type: ' . $config->type );
		if( $config->rootUrl!=='' ) {
			$io->text( 'rootUrl: ' . $config->rootUrl . '  basePath: ' . $config->getBasePath() );
		}
		$io->text( 'logging: ' . $config->logging->destination );
		foreach( $config->mongoDatabases as $mongoDatabase ) {
			$io->text( 'mongo: ' . $mongoDatabase->database . ' @ ' . mongoTools::redactUri( $mongoDatabase->uri ) . ( $mongoDatabase->default ? ' (default)' : '' ) );
		}

		$io->success( 'Resolved successfully — every %env(...) reference has a value.' );

		return Command::SUCCESS;
	}


	private function listReferences( appContext $context, SymfonyStyle $io ): int {
		$references = $context->configReferences();

		if( count( $references )===0 ) {
			$io->text( 'config.json references no environment variables.' );

			return Command::SUCCESS;
		}

		$rows = [];
		foreach( $references as $name => $isSecret ) {
			// A reserved CGI meta-variable name is never resolvable, whatever is set —
			// reporting it as MISSING sends the developer filling in a value forever.
			$state  = envVarResolver::isReservedName( $name )
				? 'RESERVED — a CGI meta-variable name is never resolved; rename it'
				: ( $this->isSet( $name ) ? 'set' : 'MISSING' );
			$rows[] = [ $name, $isSecret ? 'secret' : '', $state ];
		}
		$io->table( [ 'Variable', 'Kind', 'Current environment' ], $rows );

		return Command::SUCCESS;
	}


	/**
	 * Write, or extend, the .env skeleton.
	 *
	 * Additive by default, which is what the help text has always promised: a .env carries
	 * variables config.json knows nothing about (compose ports, CORS origins, GF_PHP) along
	 * with the values the developer has already filled in, and this command cannot
	 * reconstruct any of them. It used to replace the file wholesale whenever --force was
	 * given, which is exactly what a developer reaching for --force after the
	 * already-exists refusal would do.
	 *
	 * --force keeps its meaning — rewrite from config.json alone — but now says what it
	 * discards, and is no longer needed merely to pick up a newly added reference.
	 */
	private function writeEnvFile( appContext $context, SymfonyStyle $io, bool $force ): int {
		$envPath    = $context->getEnvFilePath();
		$references = $context->configReferences();

		$existing = file_exists( $envPath ) ? (string)file_get_contents( $envPath ) : '';

		if( $existing!=='' && $force ) {
			$io->warning( 'Replacing ' . $envPath . ' from config.json. Every value it holds, and every variable config.json does not reference, is discarded.' );
			$existing = '';
		}

		if( $existing==='' ) {
			$contents = $this->renderEnvFile( $references );
			$added    = count( $references );
		}
		else {
			$declared = self::declaredNames( $existing, $envPath );
			$missing  = array_filter( $references, static fn( bool $isSecret, string $name ): bool => !isset( $declared[ $name ] ) && !isset( $declared[ $name . envVarResolver::SECRET_FILE_SUFFIX ] ), ARRAY_FILTER_USE_BOTH );

			if( count( $missing )===0 ) {
				$io->success( $envPath . ' already declares every variable config.json references. Nothing to add.' );

				return Command::SUCCESS;
			}

			$contents = rtrim( $existing, "\n" ) . "\n\n" . implode( "\n", array_merge( [ '# Added by `gf env --init` from config.json.' ], self::renderReferenceLines( $missing ) ) ) . "\n";
			$added    = count( $missing );
		}

		if( file_put_contents( $envPath, $contents )===false ) {
			throw new cliException( 'Failed writing ' . $envPath );
		}

		$io->success( 'Wrote ' . $envPath . ' with ' . $added . ' variable(s). Fill in the values — the application will not start until every one has one.' );

		return Command::SUCCESS;
	}


	/**
	 * The variable names a .env already declares, so --init can skip them.
	 *
	 * Parsed with the same symfony/dotenv parser the framework loads the file with, so
	 * "declared" here is exactly what the runtime will see. The hand-rolled regex this
	 * replaces disagreed with it on multi-line quoted values: a NAME= at line start
	 * inside one counted as a declaration, and --init skipped appending a variable the
	 * file does not actually define. (A commented `# NAME_FILE=` hint is guidance, not a
	 * declaration, in both readings.)
	 *
	 * @return array<string, true>
	 * @throws \gcgov\framework\cli\cliException
	 */
	private static function declaredNames( string $env, string $envPath ): array {
		try {
			$parsed = ( new Dotenv() )->parse( $env, $envPath );
		}
		catch( \Symfony\Component\Dotenv\Exception\FormatException $e ) {
			throw new cliException( 'Cannot read ' . $envPath . ': ' . $e->getMessage(), 0, $e );
		}

		$names = [];
		foreach( array_keys( $parsed ) as $name ) {
			$names[ (string)$name ] = true;
		}

		return $names;
	}


	/**
	 * @param  array<string, bool>  $references  variable name => is a secret
	 */
	public function renderEnvFile( array $references ): string {
		$lines = [
			'# Generated by `gf env --init` from config.json. Never commit this file.',
			'#',
			'# Every variable below is REQUIRED: config.json has no defaults, and a variable',
			'# set to the empty string counts as unset. Re-run `gf env` to check.',
			'',
		];

		return implode( "\n", array_merge( $lines, self::renderReferenceLines( $references ) ) ) . "\n";
	}


	/**
	 * The variable lines themselves, shared by the fresh-file and append paths so the two
	 * cannot describe the secret convention differently.
	 *
	 * @param  array<string, bool>  $references  variable name => is a secret
	 *
	 * @return string[]
	 */
	private static function renderReferenceLines( array $references ): array {
		$lines   = [];
		$secrets = array_keys( array_filter( $references ) );
		$plain   = array_keys( array_filter( $references, static fn( bool $isSecret ): bool => !$isSecret ) );

		foreach( $plain as $name ) {
			$lines[] = self::referenceLine( $name );
		}

		if( count( $secrets )>0 ) {
			$lines[] = '';
			$lines[] = '# Secrets. In production these are provisioned as files and read through';
			$lines[] = '# the companion {NAME}_FILE variable instead — set one or the other, never both.';
			foreach( $secrets as $name ) {
				$lines[] = self::referenceLine( $name );
				if( !envVarResolver::isReservedName( $name ) ) {
					$lines[] = self::secretFileHint( $name );
				}
			}
		}

		return $lines;
	}


	/**
	 * A live `NAME=` line — or, for a reserved CGI meta-variable name, guidance instead:
	 * the resolver never satisfies such a name, so a live line would be filled in and
	 * still report MISSING forever. The fix is renaming the reference, not a value.
	 */
	private static function referenceLine( string $name ): string {
		return envVarResolver::isReservedName( $name )
			? '# ' . $name . ' is a reserved CGI meta-variable name the framework never resolves — rename the %env(' . $name . ')% reference in config.json'
			: $name . '=';
	}


	/**
	 * The commented `{NAME}_FILE` hint written beside a secret's plain line — the one
	 * writer of the convention, shared with `gf migrate` so the two commands cannot
	 * describe it differently. The path carries the per-application segment the
	 * deployment convention uses (bin/provision writes /etc/gcgov/secrets/<app>/<name>,
	 * mounted into the container at /run/secrets/<app>/<name>): a set _FILE never falls
	 * back, so a hint without the segment pointed everyone who uncommented it at a file
	 * that never exists.
	 */
	public static function secretFileHint( string $name ): string {
		return '# ' . $name . envVarResolver::SECRET_FILE_SUFFIX . '=/run/secrets/<app>/' . strtolower( $name );
	}


	/** Whether a variable currently has a value, by either the plain or the _FILE name. */
	private function isSet( string $name ): bool {
		return envVarResolver::isSatisfied( $name );
	}

}
