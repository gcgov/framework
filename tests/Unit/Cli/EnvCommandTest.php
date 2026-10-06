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
		self::assertStringContainsString( '# MONGO_URI_FILE=/run/secrets/<app>/mongo_uri', $rendered );
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
			# MONGO_URI_FILE=/run/secrets/<app>/mongo_uri
			COMPOSE_PORT=8080
			ENV );

		self::assertArrayHasKey( 'APP_TYPE', $declared );
		self::assertArrayHasKey( 'MONGO_URI', $declared );
		self::assertArrayHasKey( 'EXPORTED', $declared, 'an export prefix is still a declaration' );
		self::assertArrayHasKey( 'COMPOSE_PORT', $declared, 'variables config.json never mentions still count' );
	}


	public function testDeclaredNamesIgnoresCommentedHints(): void {
		$declared = self::declaredNames( "# MONGO_URI_FILE=/run/secrets/<app>/mongo_uri
#APP_TYPE=local
" );

		self::assertArrayNotHasKey( 'MONGO_URI_FILE', $declared, 'a commented hint is guidance, not a declaration' );
		self::assertArrayNotHasKey( 'APP_TYPE', $declared );
	}


	/**
	 * Declared-ness is judged by the same symfony/dotenv parser the runtime loads the
	 * file with: a quoted value spans lines, so a NAME= at line start inside one is part
	 * of the VALUE, not a declaration. The hand-rolled regex this replaced counted it,
	 * told --init the variable was covered, and the app then failed at startup on a
	 * reference the tool had just reported as declared.
	 */
	public function testDeclaredNamesUsesTheRuntimeParserForMultiLineValues(): void {
		$declared = self::declaredNames( "GREETING=\"first line\nMONGO_URI=not-a-declaration\"\nAPP_TYPE=local\n" );

		self::assertArrayHasKey( 'GREETING', $declared );
		self::assertArrayHasKey( 'APP_TYPE', $declared );
		self::assertArrayNotHasKey( 'MONGO_URI', $declared, 'text inside a quoted value is not a declaration' );
	}


	public function testDeclaredNamesRejectsAFileTheRuntimeCannotParse(): void {
		$this->expectException( \gcgov\framework\cli\cliException::class );
		self::declaredNames( "SPACED = value\n" );
	}


	/**
	 * A reserved CGI meta-variable name can never be resolved, so a live `NAME=` line
	 * would be filled in and still report MISSING forever — guidance is written instead.
	 */
	public function testReservedNamesAreWrittenAsGuidanceNotDeadAssignments(): void {
		$rendered = ( new envCommand() )->renderEnvFile( [ 'SERVER_API_TOKEN' => false, 'APP_TYPE' => false ] );

		self::assertStringNotContainsString( "\nSERVER_API_TOKEN=", $rendered );
		self::assertStringContainsString( 'reserved', $rendered );
		self::assertStringContainsString( "APP_TYPE=\n", $rendered, 'ordinary names still get live lines' );
	}


	/** Reflection, because the parser is a private detail of the command. */
	private static function declaredNames( string $env ): array {
		$method = new \ReflectionMethod( envCommand::class, 'declaredNames' );

		return $method->invoke( null, $env, '.env' );
	}

}
