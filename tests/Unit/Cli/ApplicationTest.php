<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(application::class)]
final class ApplicationTest extends TestCase {

	public function testAllBuiltInCommandsAreRegistered(): void {
		$application = new application();

		foreach( [ 'cli', 'cli:list', 'cert:generate-auth', 'db:restore', 'db:run', 'env', 'setup', 'deploy', 'completion:powershell', 'completion' ] as $commandName ) {
			$this->assertTrue( $application->has( $commandName ), 'missing command: ' . $commandName );
		}
	}

	public function testNormalizeArgvJoinsSpaceSeparatedCommandNames(): void {
		$application = new application();

		$this->assertSame( [ 'gf', 'db:restore', '--from=prod' ], $application->normalizeArgv( [ 'gf', 'db', 'restore', '--from=prod' ] ) );
		$this->assertSame( [ 'gf', 'cert:generate-auth' ], $application->normalizeArgv( [ 'gf', 'cert', 'generate-auth' ] ) );
	}

	public function testNormalizeArgvLeavesNonMatchesAlone(): void {
		$application = new application();

		$this->assertSame( [ 'gf', 'cli', '/cli/cleanup' ], $application->normalizeArgv( [ 'gf', 'cli', '/cli/cleanup' ] ) );
		$this->assertSame( [ 'gf', 'list' ], $application->normalizeArgv( [ 'gf', 'list' ] ) );
		$this->assertSame( [ 'gf' ], $application->normalizeArgv( [ 'gf' ] ) );
		$this->assertSame( [ 'gf', 'env', '--dry-run' ], $application->normalizeArgv( [ 'gf', 'env', '--dry-run' ] ) );
	}

}
