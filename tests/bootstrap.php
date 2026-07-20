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
// Stub the class so tests that touch config::getAppDir() can boot. The gf CLI
// additionally calls registerFrameworkServiceNamespaces() during route enumeration.
if ( !class_exists( '\app\app' ) ) {
	eval( 'namespace app; class app { public static function _before(): void {} public static function _after(): void {} public function registerFrameworkServiceNamespaces(): array { return []; } }' );
}

// Stub \app\router with fixture routes so the gf CLI route catalog can be
// exercised (router::getMergedRoutes() instantiates \app\router).
if ( !class_exists( '\app\router' ) ) {
	eval( 'namespace app;
	class router implements \gcgov\framework\interfaces\router {
		public static function _before(): void {}
		public static function _after(): void {}
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

// Seed environmentConfig so config::getEnvironmentConfig() doesn't try to
// load a JSON file from disk.
$envConfig = new \gcgov\framework\models\environmentConfig();
$envConfig->basePath = 'api';
$envConfig->serverName = 'test.local';
$envConfig->rootUrl = 'http://test.local';
$envConfig->type = 'local';
$prop = new \ReflectionProperty( \gcgov\framework\config::class, 'environmentConfig' );
$prop->setValue( null, $envConfig );
