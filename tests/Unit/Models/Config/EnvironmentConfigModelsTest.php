<?php

declare(strict_types=1);

namespace gcgov\framework\tests\Unit\Models\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use gcgov\framework\models\config\environment\microsoft;
use gcgov\framework\models\config\environment\jwtAuth;
use gcgov\framework\models\config\environment\logging;
use gcgov\framework\models\config\environment\payjunction;
use gcgov\framework\models\config\environment\sqlDatabase;
use gcgov\framework\models\config\environment\sqlDatabaseUser;
use gcgov\framework\models\config\environment\mongoDatabase;
use gcgov\framework\models\config\environment\mongoDatabase\encryption;
use gcgov\framework\models\config\environment\mongoDatabase\kmsProviders;
use gcgov\framework\models\config\environment\mongoDatabase\kmsProviders\gcp;
use gcgov\framework\models\config\environment\mongoDatabase\encryptedFieldMap;
use gcgov\framework\models\config\environment\mongoDatabase\encryptedCollectionsFields;

#[CoversClass(microsoft::class)]
#[CoversClass(jwtAuth::class)]
#[CoversClass(logging::class)]
#[CoversClass(payjunction::class)]
#[CoversClass(sqlDatabase::class)]
#[CoversClass(sqlDatabaseUser::class)]
#[CoversClass(mongoDatabase::class)]
#[CoversClass(encryption::class)]
#[CoversClass(kmsProviders::class)]
#[CoversClass(gcp::class)]
#[CoversClass(encryptedFieldMap::class)]
#[CoversClass(encryptedCollectionsFields::class)]
final class EnvironmentConfigModelsTest extends TestCase {

	public function testMicrosoftDefaults(): void {
		$ms = new microsoft();
		$this->assertSame( '', $ms->clientId );
		$this->assertSame( '', $ms->clientSecret );
		$this->assertSame( '', $ms->certificatePath );
		$this->assertSame( '', $ms->privateKeyPath );
		$this->assertSame( '', $ms->privateKeyPassphrase );
		$this->assertSame( '', $ms->tenant );
		$this->assertSame( '', $ms->driveId );
		$this->assertSame( '', $ms->fromAddress );
		$this->assertFalse( $ms->useCertificateAuthentication() );
	}

	public function testMicrosoftUseCertificateAuthenticationRequiresBothPaths(): void {
		$ms = new microsoft();
		$ms->certificatePath = '/srv/microsoftCertificates/app.crt';
		$this->assertFalse( $ms->useCertificateAuthentication() );

		$ms->privateKeyPath = '/srv/microsoftCertificates/app.key';
		$this->assertTrue( $ms->useCertificateAuthentication() );
	}

	public function testMicrosoftCertificateContentsReadFromDisk(): void {
		$certificatePath = tempnam( sys_get_temp_dir(), 'cert' );
		$privateKeyPath  = tempnam( sys_get_temp_dir(), 'key' );
		file_put_contents( $certificatePath, "-----BEGIN CERTIFICATE-----\ncert\n-----END CERTIFICATE-----\n" );
		file_put_contents( $privateKeyPath, "-----BEGIN PRIVATE KEY-----\nkey\n-----END PRIVATE KEY-----\n" );

		try {
			$ms = new microsoft();
			$ms->certificatePath = $certificatePath;
			$ms->privateKeyPath  = $privateKeyPath;

			$this->assertStringContainsString( 'BEGIN CERTIFICATE', $ms->getCertificateContents() );
			$this->assertStringContainsString( 'BEGIN PRIVATE KEY', $ms->getPrivateKeyContents() );
		}
		finally {
			unlink( $certificatePath );
			unlink( $privateKeyPath );
		}
	}

	public function testMicrosoftCertificateContentsThrowsWhenFileMissing(): void {
		$ms = new microsoft();
		$ms->certificatePath = '/nonexistent/path/app.crt';
		$ms->privateKeyPath  = '/nonexistent/path/app.key';

		$this->expectException( \gcgov\framework\exceptions\configException::class );
		$ms->getCertificateContents();
	}

	public function testJwtAuthDefaults(): void {
		$jwt = new jwtAuth();
		$this->assertSame( '', $jwt->tokenIssuedBy );
		$this->assertSame( '', $jwt->tokenPermittedFor );
		$this->assertSame( '', $jwt->redirectAfterLoginUrl );
		$this->assertSame( '', $jwt->redirectAfterLogoutUrl );
	}

	public function testLoggingDefaults(): void {
		$log = new logging();
		$this->assertFalse( $log->lifecycle );
		$this->assertFalse( $log->renderer );
	}

	public function testPayjunctionDefaults(): void {
		$pj = new payjunction();
		$this->assertSame( '', $pj->username );
		$this->assertSame( '', $pj->password );
		$this->assertSame( '', $pj->apiKey );
		$this->assertSame( '', $pj->terminalId );
		$this->assertSame( '', $pj->merchantId );
	}

	public function testSqlDatabaseDefaults(): void {
		$db = new sqlDatabase();
		$this->assertFalse( $db->default );
		$this->assertSame( '', $db->name );
		$this->assertSame( '', $db->dsn );
	}

	public function testSqlDatabaseUserDefaults(): void {
		$u = new sqlDatabaseUser();
		$this->assertSame( '', $u->username );
		$this->assertSame( '', $u->password );
	}

	public function testMongoDatabaseDefaults(): void {
		$db = new mongoDatabase();
		$this->assertFalse( $db->default );
		$this->assertSame( '', $db->uri );
		$this->assertSame( '', $db->database );
		$this->assertSame( [], $db->clientParams );
		$this->assertTrue( $db->include_meta );
		$this->assertTrue( $db->include_metaLabels );
		$this->assertFalse( $db->include_metaFields );
		$this->assertFalse( $db->logging );
		$this->assertFalse( $db->audit );
		$this->assertInstanceOf( encryption::class, $db->encryption );
	}

	public function testMongoDatabaseAuditDefaultsCopyFromPrimary(): void {
		$db = new mongoDatabase();
		$db->database = 'app-db';
		$db->uri = 'mongodb://localhost';
		$db->clientParams = [ 'k' => 'v' ];
		$db->audit = true;

		$method = new \ReflectionMethod( $db, '_afterJsonDeserialize' );
		$method->invoke( $db );

		$this->assertSame( 'app-db', $db->auditDatabaseName );
		$this->assertSame( 'mongodb://localhost', $db->auditDatabaseUri );
	}

	public function testEncryptionDefaultsInstantiate(): void {
		$enc = new encryption();
		$this->assertInstanceOf( encryption::class, $enc );
	}

	public function testKmsProvidersInstantiate(): void {
		$kms = new kmsProviders();
		$this->assertInstanceOf( kmsProviders::class, $kms );
	}

	public function testGcpKmsInstantiate(): void {
		$gcp = new gcp();
		$this->assertInstanceOf( gcp::class, $gcp );
	}

	public function testEncryptedFieldMapInstantiates(): void {
		$map = new encryptedFieldMap();
		$this->assertInstanceOf( encryptedFieldMap::class, $map );
	}

	public function testEncryptedCollectionsFieldsInstantiates(): void {
		$ecf = new encryptedCollectionsFields();
		$this->assertInstanceOf( encryptedCollectionsFields::class, $ecf );
	}

	public function testAllExtendJsonDeserialize(): void {
		$classes = [
			microsoft::class, jwtAuth::class, logging::class, payjunction::class,
			sqlDatabase::class, sqlDatabaseUser::class, mongoDatabase::class,
			encryption::class, kmsProviders::class, gcp::class,
			encryptedFieldMap::class, encryptedCollectionsFields::class,
		];
		foreach ( $classes as $class ) {
			$this->assertTrue( is_subclass_of( $class, \andrewsauder\jsonDeserialize\jsonDeserialize::class ) );
		}
	}

}
