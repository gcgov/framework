<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\phpProcess;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The `gf cli` child-process entry runs before the framework is loaded, so it must not
 * assume $argv/$argc exist (php.ini-production ships register_argc_argv=Off) — it reports
 * the problem instead of dying on an undefined variable and an undefined STDERR constant.
 */
final class RunRouteScriptTest extends TestCase {

	private static function scriptPath(): string {
		return dirname( __DIR__, 3 ) . '/src/cli/internal/run-route.php';
	}


	public function testScriptExists(): void {
		$this->assertFileExists( self::scriptPath() );
	}


	public function testReportsUsageWhenArgumentsMissing(): void {
		$process = new Process( array_merge( [ PHP_BINARY ], phpProcess::requiredIniFlags(), [ self::scriptPath() ] ) );
		$process->run();

		$this->assertSame( 2, $process->getExitCode() );
		$this->assertStringContainsString( 'usage: php run-route.php', $process->getErrorOutput() );
	}


	public function testReportsActionableErrorWhenArgcArgvDisabled(): void {
		$process = new Process( [ PHP_BINARY, '-dregister_argc_argv=0', self::scriptPath(), 'autoload.php', '/cli/example' ] );
		$process->run();

		$output = $process->getErrorOutput() . $process->getOutput();

		$this->assertSame( 2, $process->getExitCode() );
		$this->assertStringContainsString( 'register_argc_argv', $output );
		$this->assertStringNotContainsString( 'Undefined variable', $output );
		$this->assertStringNotContainsString( 'Undefined constant', $output );
	}


	public function testRequiredIniFlagsRestoreArgumentsWhenPhpIniDisablesThem(): void {
		// gf always passes requiredIniFlags(), so a php.ini with register_argc_argv=Off
		// (the php.ini-production default) can no longer break the route runner.
		$process = new Process( array_merge(
			[ PHP_BINARY, '-dregister_argc_argv=0' ],
			phpProcess::requiredIniFlags(),
			[ self::scriptPath(), dirname( __DIR__, 3 ) . '/nonexistent-autoload.php', '/cli/example' ]
		) );
		$process->run();

		$output = $process->getErrorOutput() . $process->getOutput();

		// Arguments were readable: the script got past its argument checks and reached the
		// (deliberately missing) autoloader.
		//
		// The script reports that itself rather than letting require's fatal stand in for it.
		// Whether that fatal reaches us depends on the host php.ini — with display_errors Off
		// and error_log naming a file, which is an ordinary server configuration, the child
		// prints nothing at all and this assertion had nothing to match.
		$this->assertSame( 2, $process->getExitCode() );
		$this->assertStringNotContainsString( 'register_argc_argv', $output );
		$this->assertStringNotContainsString( 'usage: php run-route.php', $output );
		$this->assertStringContainsString( 'nonexistent-autoload.php', $output );
	}

}
