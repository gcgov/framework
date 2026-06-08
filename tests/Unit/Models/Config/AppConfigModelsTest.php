<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\appConfig;
use gcgov\framework\models\config\app\app;
use gcgov\framework\models\config\app\email;
use gcgov\framework\models\config\app\settings;

#[CoversClass(appConfig::class)]
#[CoversClass(app::class)]
#[CoversClass(email::class)]
#[CoversClass(settings::class)]
final class AppConfigModelsTest extends TestCase {

	public function testAppConfigInstantiates(): void {
		$config = new appConfig();
		$this->assertInstanceOf( appConfig::class, $config );
	}

	public function testAppHasTitleAndGuid(): void {
		$app = new app();
		$this->assertSame( '', $app->title );
		$this->assertSame( '', $app->guid );
		$app->title = 'My App';
		$app->guid = 'guid-1';
		$this->assertSame( 'My App', $app->title );
		$this->assertSame( 'guid-1', $app->guid );
	}

	public function testEmailDefaults(): void {
		$email = new email();
		$this->assertSame( '', $email->fromAddress );
		$this->assertSame( '', $email->fromName );
		$this->assertFalse( $email->useSMTP );
		$this->assertFalse( $email->SMTPAuth );
		$this->assertSame( '', $email->SMTPHost );
		$this->assertSame( 587, $email->SMTPPort );
		$this->assertSame( '', $email->SMTPUsername );
		$this->assertSame( '', $email->SMTPPassword );
		$this->assertSame( '', $email->replyToAddress );
		$this->assertSame( '', $email->replyToName );
	}

	public function testSettingsDefaults(): void {
		$settings = new settings();
		$this->assertFalse( $settings->useSession );
		$this->assertFalse( $settings->forceMfaForPasswordUsers );
	}

	public function testAllConfigsExtendJsonDeserialize(): void {
		foreach ( [ appConfig::class, app::class, email::class, settings::class ] as $class ) {
			$this->assertTrue( is_subclass_of( $class, \andrewsauder\jsonDeserialize\jsonDeserialize::class ) );
		}
	}

}
