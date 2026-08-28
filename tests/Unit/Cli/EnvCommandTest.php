<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\commands\envCommand;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(envCommand::class)]
final class EnvCommandTest extends TestCase {

	public function testRenderedEnvListsEveryReferencedVariable(): void {
		$rendered = ( new envCommand() )->renderEnvFile( [ 'APP_TYPE' => false, 'APP_ROOT_URL' => false ] );

		self::assertStringContainsString( "APP_TYPE=\n", $rendered );
		self::assertStringContainsString( "APP_ROOT_URL=\n", $rendered );
	}


	/**
	 * A secret gets its `_FILE` companion shown as a comment, because production supplies
	 * it that way and a developer reading the file should see that the option exists.
	 */
	public function testSecretsAreGroupedAndShowTheFileAlternative(): void {
		$rendered = ( new envCommand() )->renderEnvFile( [ 'APP_TYPE' => false, 'MONGO_URI' => true ] );

		self::assertStringContainsString( "MONGO_URI=\n", $rendered );
		self::assertStringContainsString( '# MONGO_URI_FILE=/run/secrets/mongo_uri', $rendered );
		self::assertStringContainsString( 'never commit', strtolower( $rendered ) );

		// Plain variables come first, secrets in their own labelled block after.
		self::assertLessThan( strpos( $rendered, 'MONGO_URI=' ), strpos( $rendered, 'APP_TYPE=' ) );
	}


	public function testRenderedEnvSaysEveryVariableIsRequired(): void {
		$rendered = ( new envCommand() )->renderEnvFile( [ 'APP_TYPE' => false ] );

		self::assertStringContainsString( 'REQUIRED', $rendered );
		self::assertStringContainsString( 'empty string counts as unset', $rendered );
	}


	public function testEmptyReferenceSetStillProducesAUsableFile(): void {
		$rendered = ( new envCommand() )->renderEnvFile( [] );

		self::assertStringStartsWith( '#', $rendered );
		self::assertStringEndsWith( "\n", $rendered );
	}


	/**
	 * `gf env --init` promises in its own help text that it "leaves anything already in the
	 * file alone". It used to write the skeleton over the top of the file whenever --force
	 * was given — which is exactly what a developer does after the already-exists refusal —
	 * destroying every filled-in value and every variable config.json knows nothing about.
	 */
	public function testDeclaredNamesFindsExistingAssignments(): void {
		$declared = self::declaredNames( <<<'ENV'
			# a comment
			APP_TYPE=local
			MONGO_URI='mongodb://localhost'
			export EXPORTED=1
			SPACED = value
			# MONGO_URI_FILE=/run/secrets/mongo_uri
			COMPOSE_PORT=8080
			ENV );

		self::assertArrayHasKey( 'APP_TYPE', $declared );
		self::assertArrayHasKey( 'MONGO_URI', $declared );
		self::assertArrayHasKey( 'EXPORTED', $declared, 'an export prefix is still a declaration' );
		self::assertArrayHasKey( 'SPACED', $declared );
		self::assertArrayHasKey( 'COMPOSE_PORT', $declared, 'variables config.json never mentions still count' );
	}


	public function testDeclaredNamesIgnoresCommentedHints(): void {
		$declared = self::declaredNames( "# MONGO_URI_FILE=/run/secrets/mongo_uri
#APP_TYPE=local
" );

		self::assertArrayNotHasKey( 'MONGO_URI_FILE', $declared, 'a commented hint is guidance, not a declaration' );
		self::assertArrayNotHasKey( 'APP_TYPE', $declared );
	}


	/** Reflection, because the parser is a private detail of the command. */
	private static function declaredNames( string $env ): array {
		$method = new \ReflectionMethod( envCommand::class, 'declaredNames' );

		return $method->invoke( null, $env );
	}

}
