# Configuration is fail-closed: one committed file, every reference required

An Application's configuration is a single committed `config.json` whose environment-varying values
are `%env(...)%` references. Every reference is **required**: there is no `default:` processor, and a
variable that is set but empty counts as unset. A missing value is a startup failure naming the
variable, never a silent fallback.

## Considered Options

v6 split configuration across `app/config/app.json` and per-environment `environment-{name}.json`
files, and an early v7 draft kept a Symfony-style `default:` processor so a value could fall back to
a literal baked in at scaffold time.

`default:` was removed because it made the dangerous case the quiet one. `type` defaulted to
`local`, and `isLocal()` gates real behavior — so a production container that forgot `APP_TYPE`
booted successfully in development posture. Scaffolding also wrote its `{tokens}` *inside* the
default argument, so skipping a setup prompt shipped the literal string `{app_root_url}` to
production as a URL. Both failures were silent.

## Consequences

- Developer environments need real values for every reference. `gf env --init` generates the `.env`
  skeleton by walking `config.json`, so the manifest cannot drift from the config.
- Optional integrations (Microsoft, PayJunction, SMTP) are **absent** from the template rather than
  present-and-blank. An Application adds the block when it needs it; a missing section hydrates to
  its defaults.
- Removing `default:` also removed the resolver's greedy-argument parsing and its ban on `)` inside
  a reference, which existed only to support it.
