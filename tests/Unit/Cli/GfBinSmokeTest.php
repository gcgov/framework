<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Process-level smoke test: bin/gf must boot and list commands even when run
 * outside an application (here: inside the framework repo itself), proving
 * lazy app discovery never breaks the tool.
 */
#[CoversNothing]
final class GfBinSmokeTest extends TestCase {

	public function testGfListRunsOutsideAnApplication(): void {
		$frameworkRoot = dirname( __DIR__, 3 );
		if( !file_exists( $frameworkRoot . '/vendor/autoload.php' ) ) {
			$this->markTestSkipped( 'framework vendor/ not installed' );
		}

		$process = new Process( [ PHP_BINARY, $frameworkRoot . '/bin/gf', 'list', '--no-ansi' ], $frameworkRoot );
		$process->run();

		$this->assertSame( 0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput() );
		$this->assertStringContainsString( 'cli:list', $process->getOutput() );
		$this->assertStringContainsString( 'db:restore', $process->getOutput() );
	}

	public function testGfSpaceSeparatedCommandNameResolves(): void {
		$frameworkRoot = dirname( __DIR__, 3 );
		if( !file_exists( $frameworkRoot . '/vendor/autoload.php' ) ) {
			$this->markTestSkipped( 'framework vendor/ not installed' );
		}

		$process = new Process( [ PHP_BINARY, $frameworkRoot . '/bin/gf', 'db', 'restore', '--help', '--no-ansi' ], $frameworkRoot );
		$process->run();

		$this->assertSame( 0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput() );
		$this->assertStringContainsString( 'db:restore', $process->getOutput() );
	}

}
