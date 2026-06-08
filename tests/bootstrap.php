<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// In the production / CI environment ext-mongodb is loaded and the real
// MongoDB\BSON classes are available. Locally, provide runtime-functional
// shims so the test suite can still exercise model code.
if ( !extension_loaded( 'mongodb' ) ) {
	require __DIR__ . '/Shims/MongoDBShims.php';
}

// Several framework call sites reflect on \app\app to derive directories.
// Stub the class so tests that touch config::getAppDir() can boot.
if ( !class_exists( '\app\app' ) ) {
	eval( 'namespace app; class app { public static function _before(): void {} public static function _after(): void {} }' );
}

// Seed environmentConfig so config::getEnvironmentConfig() doesn't try to
// load a JSON file from disk.
$envConfig = new \gcgov\framework\models\environmentConfig();
$envConfig->basePath = 'api';
$envConfig->serverName = 'test.local';
$envConfig->rootUrl = 'http://test.local';
$envConfig->type = 'local';
$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'environmentConfig' );
$prop->setValue( null, $envConfig );
