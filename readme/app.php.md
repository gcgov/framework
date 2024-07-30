# /app/app.php

\app\app will be the first app class instantiated and the instance will last the entire lifecycle of the request.

`registerFrameworkServiceNamespaces` can be used to register framework extensions. Extensions can provide services and/or app functionality like adding a documentation endpoint or adding JWT authentication.

```php
namespace app;

final class app implements \gcgov\framework\interfaces\app {
	/**
	 * Processed prior to __constructor() being called when the app is instantiated
	 */
	public static function _before() : void {
	}
	
	/**
	 * Processed after lifecycle is complete with this instance
	 */
	public static function _after() : void {
	}
	
	/**
	 * Register framework extensions
	 */
	public function registerFrameworkServiceNamespaces(): array {
		return [
		    //enable framework extensions
			//'gcgov\framework\services\cronMonitor',
			//'\gcgov\framework\services\documentation',
		];
	}

}
```
