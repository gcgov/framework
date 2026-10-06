<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// In the production / CI environment ext-mongodb is loaded and the real
// MongoDB\BSON classes are available. Locally, provide runtime-functional
// shims so the test suite can still exercise model code.
if ( !extension_loaded( 'mongodb' ) ) {
	require __DIR__ . '/Shims/MongoDBShims.php';
}

// Shared test helpers. tests/ is not PSR-4 autoloaded, so they are required here.
require __DIR__ . '/Support/seedsFrameworkConfig.php';
require __DIR__ . '/Support/capturesFrameworkLog.php';

// Several framework call sites reflect on \app\app to derive directories.
// Stub the class so tests that touch config::getAppDir() can boot.
if ( !class_exists( '\app\app' ) ) {
	eval( 'namespace app; class app { public static function _before(): void {} public static function _after(): void {} }' );
}

// Stub \app\router with fixture routes so the gf CLI route catalog can be
// exercised (router::getMergedRoutes() instantiates \app\router). None of the
// fixture routes require authentication, so the framework's no-auth-service
// check is satisfied without the stub claiming to authenticate anything.
if ( !class_exists( '\app\router' ) ) {
	eval( 'namespace app;
	class router implements \gcgov\framework\interfaces\appRouter {
		public static function _before(): void {}
		public static function _after(): void {}
		public function providesAuthentication(): bool {
			return false;
		}
		public function getRoutes(): array {
			return [
				new \gcgov\framework\models\route( "GET", "/widget", "\\\\app\\\\controllers\\\\widget", "getAll" ),
				new \gcgov\framework\models\route( "CLI", "/cli/cleanup", "\\\\app\\\\controllers\\\\cli\\\\maintenance", "cleanup", false, [], false, "Clean up temp records" ),
				new \gcgov\framework\models\route( [ "GET", "CLI" ], "/cli/report", "\\\\app\\\\controllers\\\\cli\\\\report", "run" ),
			];
		}
		public function authentication( \gcgov\framework\models\routeHandler $routeHandler ): bool {
			return true;
		}
	}' );
}

// Seed unifiedConfig so config accessors don't try to
// load a JSON file from disk.
$envConfig = new \gcgov\framework\models\unifiedConfig();
$envConfig->basePath = 'api';
$envConfig->rootUrl = 'http://test.local';
$envConfig->type = 'local';
$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'unifiedConfig' );
$prop->setValue( null, $envConfig );
