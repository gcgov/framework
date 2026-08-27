# /app/app.php

`\app\app` is the first app class instantiated, and the instance lasts the entire lifecycle of the
request.

It declares no methods of its own beyond the two lifecycle hooks — but it is not optional.
`\gcgov\framework\config` derives every path in the framework by reflecting on this class's file
location, so an application without it cannot resolve its own root.

Framework Services used to be registered here, by returning their namespaces from
`registerFrameworkServiceNamespaces()`. They are now enabled in the `services` section of
`config.json`, so that switching a service on and configuring it are one statement rather than two.
See ADR 0005.

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

}
```

Enabling services is now a matter of configuration:

```jsonc
// config.json
"services": {
  "auth":          { "provider": "oauth", "blockNewUsers": false, "defaultNewUserRoles": [ "Widget.Read" ] },
  "userCrud":      { },
  "documentation": { }
}
```

Presence enables: a block that is absent means the service is off, a block that is present — even
empty — means it is on, and the block's contents are that service's settings. `blockNewUsers` and
`defaultNewUserRoles` were previously set by calling a singleton from `_before()`; note that `gf`
deliberately does not run `_before()`, so a service configured that way was unconfigured whenever the
CLI enumerated routes.
