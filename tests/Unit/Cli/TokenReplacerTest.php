<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Cli;

use gcgov\framework\cli\tokenReplacer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(tokenReplacer::class)]
final class TokenReplacerTest extends TestCase {

	private string $tempRootDir = '';

	protected function setUp(): void {
		$this->tempRootDir = sys_get_temp_dir() . '/gcgov-tokenreplacer-test-' . uniqid();
		mkdir( $this->tempRootDir . '/app/config', 0777, true );
		mkdir( $this->tempRootDir . '/vendor/some/package', 0777, true );
		mkdir( $this->tempRootDir . '/srv', 0777, true );
	}

	protected function tearDown(): void {
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $this->tempRootDir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach( $iterator as $file ) {
			$file->isDir() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $this->tempRootDir );
	}

	public function testReplacesTokensInEligibleExtensions(): void {
		file_put_contents( $this->tempRootDir . '/app/config/app.json', '{"title":"{app_title}"}' );
		file_put_contents( $this->tempRootDir . '/setup.php', '<?php $title = "{app_title}";' );
		file_put_contents( $this->tempRootDir . '/readme.md', 'title: {app_title}' );

		$modified = tokenReplacer::replaceInTree( $this->tempRootDir, [ '{app_title}' => 'Widget API' ] );

		$this->assertCount( 2, $modified );
		$this->assertSame( '{"title":"Widget API"}', file_get_contents( $this->tempRootDir . '/app/config/app.json' ) );
		$this->assertSame( '<?php $title = "Widget API";', file_get_contents( $this->tempRootDir . '/setup.php' ) );
		// .md is not in the eligible extension list
		$this->assertSame( 'title: {app_title}', file_get_contents( $this->tempRootDir . '/readme.md' ) );
	}

	public function testVendorIsExcluded(): void {
		file_put_contents( $this->tempRootDir . '/vendor/some/package/file.json', '{"a":"{app_title}"}' );
		file_put_contents( $this->tempRootDir . '/web.config', '<x>{app_title}</x>' );

		$modified = tokenReplacer::replaceInTree( $this->tempRootDir, [ '{app_title}' => 'X' ] );

		$this->assertSame( [ str_replace( '\\', '/', $this->tempRootDir ) . '/web.config' ], $modified );
		$this->assertStringContainsString( '{app_title}', (string)file_get_contents( $this->tempRootDir . '/vendor/some/package/file.json' ) );
	}

	public function testSrvPhpIniFilesAreReplaced(): void {
		// regression: the scaffold's per-environment php.ini files live under srv/
		// (srv/app.local-cli/php.ini etc.) and MUST receive token replacement
		mkdir( $this->tempRootDir . '/srv/app.local-cli', 0777, true );
		file_put_contents( $this->tempRootDir . '/srv/app.local-cli/php.ini', 'xdebug.output_dir ="{app_absolute_path}\srv\profile"' . "\n" . 'guid={app_guid}' );

		$modified = tokenReplacer::replaceInTree( $this->tempRootDir, [ '{app_absolute_path}' => 'E:\Web\api', '{app_guid}' => 'abc-123' ] );

		$this->assertSame( [ str_replace( '\\', '/', $this->tempRootDir ) . '/srv/app.local-cli/php.ini' ], $modified );
		$contents = (string)file_get_contents( $this->tempRootDir . '/srv/app.local-cli/php.ini' );
		$this->assertStringContainsString( 'xdebug.output_dir ="E:\Web\api\srv\profile"', $contents );
		$this->assertStringContainsString( 'guid=abc-123', $contents );
	}

	public function testBackslashesAreEscapedInJsonFilesOnly(): void {
		file_put_contents( $this->tempRootDir . '/a.json', '{"path":"{app_absolute_path}"}' );
		file_put_contents( $this->tempRootDir . '/a.ini', 'path={app_absolute_path}' );

		tokenReplacer::replaceInTree( $this->tempRootDir, [ '{app_absolute_path}' => 'E:\Web\api' ] );

		$this->assertSame( '{"path":"E:\\\\Web\\\\api"}', file_get_contents( $this->tempRootDir . '/a.json' ) );
		$this->assertSame( 'path=E:\Web\api', file_get_contents( $this->tempRootDir . '/a.ini' ) );
	}

	public function testEmptyValuesAreSkipped(): void {
		file_put_contents( $this->tempRootDir . '/a.json', '{"title":"{app_title}"}' );

		$modified = tokenReplacer::replaceInTree( $this->tempRootDir, [ '{app_title}' => '' ] );

		$this->assertSame( [], $modified );
		$this->assertSame( '{"title":"{app_title}"}', file_get_contents( $this->tempRootDir . '/a.json' ) );
	}

	public function testFormatRelativeUrl(): void {
		$this->assertSame( '/api/', tokenReplacer::formatRelativeUrl( 'api' ) );
		$this->assertSame( '/api/', tokenReplacer::formatRelativeUrl( '/api/' ) );
		$this->assertSame( 'api/', tokenReplacer::formatRelativeUrl( 'api', true, false ) );
		$this->assertSame( '/', tokenReplacer::formatRelativeUrl( '/' ) );
		$this->assertSame( '/a/b/', tokenReplacer::formatRelativeUrl( 'a\b' ) );
	}

}
