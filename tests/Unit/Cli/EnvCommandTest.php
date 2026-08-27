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

}
