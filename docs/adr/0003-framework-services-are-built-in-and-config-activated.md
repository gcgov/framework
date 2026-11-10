# Framework Services live in the framework and are activated from the Unified Config

The five Framework Services are part of `gcgov/framework` and are switched on by a `services` section
of `config.json`. `\app\app::registerFrameworkServiceNamespaces()` is deleted. The two authentication
services become one, selected by `provider`. `cronMonitor` stops being a Framework Service at all.

## Considered Options

Keeping them as separate packages and moving only *activation* into config was the smaller change, and
it is what the evidence first appeared to support — the packages looked untagged and therefore
unreleased. That was a misreading of clones that had not fetched tags: all five are properly released
(`auth-oauth-server` is on v2.2.1, 39 releases between them) and consumed by semver through a committed
lock file. So folding in trades away real, working independent versioning. It is worth it for three
reasons the packaging split caused and could not fix:

- **Configuration had two homes.** Activation and `oauthConfig::setBlockNewUsers()` were PHP in
  `app::_before()`; `jwtAuth` and `appDictionary` were `config.json`. "How is auth configured here?" had
  two answers. Worse, `gf` deliberately skips `_before()`, so a service was unconfigured during CLI route
  enumeration — the HTTP and CLI paths genuinely disagreed.
- **Forgetting a namespace was silent.** The router swallowed the `ReflectionException` for a missing
  `{ns}\router`, so a mistyped or omitted namespace produced 404s, not an error.
- **The duplication was unfixable in place.** `oauthConfig` and `msAuthConfig` were the same class twice
  and the two guards the same fifty lines twice, because there was no shared package below them to hold
  the common part. Merging the auth services removes it and, by making `provider` a single key, makes
  two active auth providers unrepresentable rather than merely discouraged.

An out-of-tree extension point was not preserved. A package can still ship routes and a guard —
`\app\router` composes them explicitly — and `\app\router` already contributes routes, already runs a
guard, and already runs first in the chain. Auto-discovery only added magic, and keeping it would mean
carrying the mechanism being deleted for a case that has never occurred: every Framework Service ever
written is first-party. Reopen this if a second internal service is wanted by three or more applications
and genuinely does not belong in the framework.

Deleting `registerFrameworkServiceNamespaces()` outright, rather than deprecating it, is possible
because no v7 application is deployed. `v7.0.0-rc.1` is published, but a release candidate is where a
break like this belongs. Carrying both mechanisms would have reintroduced "which list won?" — the same
quiet ambiguity ADR 0001 removed the `default:` processor to avoid.

## Consequences

- **The standalone packages are not retired.** They stay published on their v1/v2 lines for v6
  applications until those migrate. Nothing is withdrawn and no v6 application breaks; they simply never
  gain v7 support. A guard fix therefore lands in three places during the transition, and the
  duplication is only truly gone once the last v6 application migrates.
- **The framework declares a `conflict` against all five packages.** `documentation` and `cronMonitor`
  keep their namespaces, so an application with both installed would have two definitions of the same
  class. This is the normal case mid-migration, not an edge case: `gf migrate` only exists in v7, so an
  application must upgrade the framework *before* it can migrate. Composer refuses at resolution time,
  naming the packages, and `gf migrate` removes them from the application's `composer.json`.
- **Presence enables.** A service's block being absent means off; present — even `{}` — means on, and
  the block's contents are its settings. This reuses the nullable-section pattern `kmsProviders::$gcp`
  already established, so `%env(...)%` and `gf env --list` work inside it with no new machinery.
- **The framework refuses to boot** when routes declare `authentication: true` and neither an auth
  service nor `\app\router::providesAuthentication()` will guard them. Those routes were previously
  reachable by anyone while looking protected, because the scaffolded `authentication()` returns true.
- **Four dependencies become unconditional** — `doctrine/annotations`, `robthree/twofactorauth`,
  `bacon/bacon-qr-code`, `andrewsauder/microsoft-services` — matching the framework's existing posture,
  where `hybridauth` and `swagger-php` are already required and unused by the core. `ext-imagick` is
  *not*: `BaconQrCodeProvider` defaults to the Imagick backend, but takes a `format` argument, and the
  SVG backend needs no extension to draw a square.
- **`interfaces\router` no longer carries lifecycle hooks.** It required `_before()`/`_after()` of every
  router and the framework only ever called `\app\router`'s. Those move to `interfaces\appRouter`, and
  the guard-skip method — previously duck-typed through `method_exists()` with no interface declaring
  it — becomes `interfaces\router\skipsServiceAuthentication`.
