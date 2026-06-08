<?php

// PHPStan scan-only stubs for the user-app classes that the framework boots
// into. Each application consuming the framework defines concrete app\app,
// app\router, app\renderer classes (see gcgov/framework-app-template for the
// reference implementation). These declarations let PHPStan reason about the
// late-bound calls in framework.php, router.php, and renderer.php without
// requiring an application to be checked out alongside.

namespace app;

class app {
	public static function _before(): void {}
	public static function _after(): void {}
}

class router {
	public static function _before(): void {}
	public static function _after(): void {}
}

class renderer {
	public static function _before(): void {}
	public static function _after(): void {}

	public static function processRouteException( \gcgov\framework\exceptions\routeException $e ): \gcgov\framework\interfaces\_controllerResponse {
		throw new \LogicException( 'stub' );
	}

	public static function processModelException( \gcgov\framework\exceptions\modelException $e ): \gcgov\framework\interfaces\_controllerResponse {
		throw new \LogicException( 'stub' );
	}

	public static function processControllerException( \gcgov\framework\exceptions\controllerException $e ): \gcgov\framework\interfaces\_controllerResponse {
		throw new \LogicException( 'stub' );
	}

	public static function processSystemErrorException( \Throwable $e ): \gcgov\framework\interfaces\_controllerResponse {
		throw new \LogicException( 'stub' );
	}
}
