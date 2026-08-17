# Environment variables in config (`%env(...)%`)

`gcgov/framework` can resolve **environment variables** inside your JSON config files
(`app/config/app.json` and `app/config/environment.json`) at load time. This lets you keep
secrets — Mongo URIs, Microsoft client secrets, SMTP/PayJunction credentials — **out of the
config files entirely** and inject them from the process environment, Docker/Kubernetes
secrets, or a local `.env` file. This is what makes the framework hostable in Docker (see the
app template's `DOCKER.md`).

The syntax is intentionally **Symfony-compatible** (`%env(processor:VAR)%`), but the resolver
is a small standalone class in the framework
(`\gcgov\framework\services\environment\envVarResolver`) — it is **not** coupled to Symfony's
dependency-injection container.

---

## Backwards compatibility

**Existing config files keep working unchanged.** A config file that contains no `%env(`
substring takes a byte-for-byte identical path through the loader (including today's
malformed-JSON error behavior). You only opt in by writing `%env(...)%` somewhere in the file.

> **BC edge case (documented):** because `%env(` is now meaningful, a config *value* that needs
> to contain the literal text `%env(...)%` can no longer be stored verbatim. There is no known
> usage of such a value.

---

## Where it applies

Resolution runs at the three points where the framework reads config JSON:

| Source | Loader |
|--------|--------|
| `app/config/app.json` | `\gcgov\framework\config::getAppConfig()` |
| `app/config/environment.json` | `\gcgov\framework\config::getEnvironmentConfig()` |
| `environment.json` + `app/config/{variant}.env` overlay | the `gf` CLI (`appContext::loadEnvironmentConfig($variant)`) — see "Per-variant overlay files" below |

Untyped config regions (`appDictionary`, plugin `clientParams`, etc.) are resolved too — the
resolver walks the whole decoded tree.

A failed resolution (e.g. a required variable is missing) throws:
- a `configException` (HTTP 500) at request time, or
- a `cliException` from `gf`,

each with a message naming the offending variable and the source file.

---

## Syntax

```
%env(PROCESSOR:...:VAR_NAME)%
```

- The **last** `:`-delimited segment is the environment variable name
  (`[A-Za-z_][A-Za-z0-9_]*`).
- Preceding segments form a **processor chain applied right-to-left** (Symfony order):
  `%env(trim:file:DB_PASS_FILE)%` = `trim( file( env(DB_PASS_FILE) ) )`.

### Typed vs. embedded

- **Whole-value reference** — when the entire JSON string is a single `%env(...)%`, the
  **typed** result replaces the value (int/bool/float/array/stdClass/string):

  ```jsonc
  "SMTPPort": "%env(int:SMTP_PORT)%"     // → 587  (an integer, not "587")
  "uri":      "%env(MONGO_URI)%"         // → "mongodb+srv://…"  (a string)
  ```

- **Embedded reference** — when `%env(...)%` appears inside a larger string, its result is
  substituted as a **string**. A non-scalar embedded result (e.g. `json:`) throws.

  ```jsonc
  "baseUrl": "https://%env(SERVER_NAME)%/api"
  ```

---

## Environment lookup precedence

For each variable the resolver looks in, in order:

1. `$_ENV`
2. `$_SERVER` — **excluding `HTTP_*` keys** (request headers can never satisfy an env
   reference)
3. `getenv()`

A variable that is *set but empty* resolves to `''`. A variable that is genuinely **unset**
triggers the `default:` fallback if present, otherwise an error.

### `.env` files

Before resolving, the framework loads (once per process, if present):

```
{app-root}/.env         then   {app-root}/.env.local
```

via `symfony/dotenv`. Precedence, highest wins:

```
real process environment   >   .env.local   >   .env
```

The **real environment always wins** — dotenv never overrides a variable already present in
the process environment. There is no `APP_ENV` cascade: an "environment" is simply the set of
variable values the process is given — a prod container gets prod values from its runtime
environment/secrets, a dev machine gets dev values from `.env`. Nothing is activated or copied.

Keep `.env` / `.env.local` **out of version control** (the app template gitignores them and
ships a committed `.env.example`).

---

## Processors

| Processor | Effect |
|-----------|--------|
| `string`  | Cast to string. |
| `bool`    | Truthy → `true` (`1/true/yes/on`), else `false`. |
| `not`     | Boolean negation of `bool`. |
| `int`     | Cast to integer (errors on a non-numeric value). |
| `float`   | Cast to float (errors on a non-numeric value). |
| `trim`    | Trim surrounding whitespace. |
| `file`    | **Read the file whose path is the variable's value** — the Docker/Kubernetes secrets pattern. |
| `base64`  | Base64-decode (URL-safe tolerant; padding optional). |
| `json`    | JSON-decode into an array/object/scalar. |
| `default` | Literal fallback when the variable is unset (see below). |

Chains apply right-to-left. The canonical Docker-secret read:

```jsonc
"uri": "%env(trim:file:MONGO_URI_FILE)%"
```

`MONGO_URI_FILE=/run/secrets/mongo_uri` → read that file → trim the trailing newline → use the
contents as the Mongo URI.

---

## The `default` processor (deliberate deviation from Symfony)

Unlike Symfony — where `default:` names a fallback **parameter** — here `default` provides a
**literal** fallback value. Rules:

- It must be **innermost** (closest to the variable name).
- Its argument is **greedy**: everything between `default:` and the final `:VAR`, so **colons
  are legal** in the fallback.
- The fallback applies **only when the variable is unset** (a set-but-empty variable wins).

```jsonc
// dev-safe fallback that itself contains colons:
"uri": "%env(default:mongodb://mongodb:27017:MONGO_URI)%"

// empty-string fallback:
"clientSecret": "%env(default::MICROSOFT_CLIENT_SECRET)%"

// composed with another processor (default is still innermost):
"SMTPPort": "%env(int:default:587:SMTP_PORT)%"    // → int 587 when SMTP_PORT is unset
```

With a single committed `environment.json`, the split is per **value**, not per file: give
`default:` fallbacks only to non-secret dev conveniences (identity URLs, a local `type`), and
leave secrets and database coordinates as **hard references** so a misconfigured prod container
fails loudly, naming exactly what to set — dev covers them via `.env` (`cp .env.example .env`):

```jsonc
// app/config/environment.json — one file for every environment:
"type":         "%env(default:local:APP_TYPE)%",          // dev-safe default; prod sets APP_TYPE=prod
"uri":          "%env(MONGO_URI)%",                        // hard: fail fast when unset
"clientSecret": "%env(MICROSOFT_CLIENT_SECRET)%"

// …or, preferring file-based secrets:
"uri": "%env(trim:file:MONGO_URI_FILE)%"
```

---

## Per-variant overlay files (gf CLI)

The gf CLI sometimes needs a **foreign** environment's values without activating anything —
`gf db:restore --from=prod` must resolve prod's Mongo URI while your shell holds local values.
That is what per-variant overlay files are for: a gitignored dotenv file
`app/config/{variant}.env` (e.g. `app/config/prod.env`; start from the app template's
`prod.env.example`). `appContext::loadEnvironmentConfig('prod')` resolves the committed
`environment.json` with that file's variables applied on top. Precedence for such a read:

```
{variant}.env overlay  >  real environment  >  .env.local  >  .env  >  default: fallback
```

Two things to know:

- **An overlay must define every environment-specific variable.** A variable missing from the
  overlay falls back to your *local* value silently. `gf env <name>` validates that an overlay
  fully resolves `environment.json`, and `db:restore` refuses a pair whose source and target
  resolve to the same database — but neither catches everything.
- Overlay files are parsed with `dotEnvLoader::parseFile()` — they are **never loaded into the
  process environment** and never affect the running app; only the one gf resolution sees them.

---

## Why file-based secrets are preferred

Process environment variables are visible to anyone who can run `docker inspect` on the
container, and can leak into logs and crash dumps. A **Docker/Swarm/Kubernetes secret** mounted
as a file at `/run/secrets/<name>` and read with `%env(trim:file:<NAME>_FILE)%` keeps the
secret value off the process environment entirely. See the app template's `DOCKER.md` for the
full deployment guidance.
