# Running an Application on a development computer

What every Application built on this framework needs in order to run locally, and why. The
**commands** belong to the Application: `gcgov/framework-app-template` ships a container stack and a
[LOCAL-DEVELOPMENT.md](https://github.com/gcgov/framework-app-template/blob/main/LOCAL-DEVELOPMENT.md)
that names its own service names, ports and routes. This page is the half that is true whatever
stack an Application runs, and it is the half that changes — it reaches you through Composer, while
a scaffolded Application's own copy of anything is frozen at Scaffold time.

Four things stand between a fresh checkout and a request that returns data. Three of them fail in
ways that look like something else.

---

## 1. The database must be a replica set

Every write the framework makes runs inside a transaction. `save()`, `saveMany()`, `delete()`,
`deleteMany()` and `deleteManyBy()` each open one when they are not handed a session, because a
single `save()` is already several writes — the document, its `#[autoIncrement]` counters, and the
Embedded Copies the dispatcher pushes into every other collection that holds one.

MongoDB offers transactions only on a replica set or a sharded cluster. A standalone `mongod` serves
every **read** perfectly and fails every **write**:

```
Transaction numbers are only allowed on a replica set member or mongos
```

That asymmetry is why this is the first thing to check and the last thing anyone suspects: the
Application starts, `/health` is green, list endpoints return data, and only saving fails. A
single-member set is enough:

```bash
mongod --replSet rs0 --dbpath /path/to/data
mongosh --eval 'rs.initiate()'
```

Production is unaffected — a managed cluster reached over `mongodb+srv://` is a replica set already.
The reasoning, and the alternative that was rejected, are in
[ADR 0008](../docs/adr/0008-writes-are-transactional-so-mongodb-is-a-replica-set.md).

## 2. Configuration resolves, or the Application refuses to start

Every Config Reference in the Unified Config is required. There are no defaults, and a variable set
to the empty string counts as unset — a half-configured Application should refuse to start rather
than run in a posture nobody chose ([ADR 0001](../docs/adr/0001-fail-closed-configuration.md)).

```bash
vendor/bin/gf env            # resolve it, or name the first thing missing
vendor/bin/gf env --list     # every variable, which are Secrets, which are set
```

Two consequences catch people out:

- **A path is `/`, not blank.** `basePath` at the domain root is `/`. Blank is unset, and unset is a
  startup failure like any other.
- **`.env` is loaded, but the real environment always wins.** Precedence is
  real env > `.env.local` > `.env`. A variable exported in your shell silently outranks the file you
  are editing.

Full reference: [environment variables](environment-variables.md).

### Variables whose correct value depends on who is reading

When an Application runs in a container while its `gf` CLI runs on the host, some references cannot
have one correct value. A connection string names `localhost` from the host and a service name from
inside the network; a key directory is relative on one filesystem and absolute on the other.

The rule: **`.env` carries the host's value, and the container's value is pinned where `.env` cannot
reach it** — for Docker Compose that is the service's `environment:` block, which beats `env_file:`.
One file then serves both, and neither side has to know about the other. An Application's own
documentation should say which of its variables are in this category; getting one wrong is silent,
because the host's value resolves perfectly well inside the container and simply points nowhere.

## 3. JWT signing keys exist and the Application is pointed at them

Signing keys are Secrets, so they are gitignored and never enter a build context or an image. They
are generated per Environment:

```bash
vendor/bin/gf cert:generate-auth
```

They go to `jwtAuth.keyPath`, defaulting to `{root}/srv/jwtCertificates`. Mounting keys without
pointing the Application at them is the failure that looks like success — which is why, when
`services.auth` is enabled, readiness checks that the directory holds usable keys rather than
letting the first sign-in discover it. Regenerating keys invalidates every issued token.

## 4. The Bootstrap User

An Application with `services.auth` enabled starts with no way in, and the circle is closed on every
side:

- `blockNewUsers` defaults true, so only users already stored may sign in.
- Every `/user` route from `services.userCrud` requires a caller already holding `User.Write`.
- Writing the document by hand does not help: the user model hashes the password as it serialises,
  so a `mongosh` insert has no password anyone can sign in with.

`gf user:create` is the way out. It saves through the model the Application actually resolves —
`\app\models\user` when it defines one, otherwise the framework's — so hashing and every model hook
run exactly as they do when the Application writes a user itself.

```bash
vendor/bin/gf user:create --email=dev@example.test --roles="User.Read,User.Write"
```

Roles are strings a Route's `requiredRoles` compares against; nothing validates them. Give the
Bootstrap User the roles its own Routes name, plus `User.Read` and `User.Write` to administer others
through `services.userCrud`. `--force` updates an existing email in place and leaves every option
you did not pass alone, including the password — so it is also how you grant a role later.

Creating a user is a transactional write like any other, so §1 applies: this is usually the first
command that discovers a standalone `mongod`.

Full reference: [the gf CLI](gf.md).

---

## Bootstrap, in order

```bash
vendor/bin/gf init --title="…"   # title, guid, .env, signing keys, chrome
# fill in .env
vendor/bin/gf env                # does it resolve?
# start the Application
vendor/bin/gf user:create --email=… --roles="…"
```

`gf init` is idempotent and meant to be re-run as configuration grows — every step adds only what is
missing, and an existing guid is kept rather than reminted. It cannot create the Bootstrap User,
because nothing can be written to a database that `.env` does not yet describe.

## Is it working?

Every Application gets two endpoints from the framework itself, so a deploy pipeline can gate on
something no Application can forget to provide.

| | `{basePath}/health` | `{basePath}/health/ready` |
|---|---|---|
| Answers | is this process able to serve? | should traffic be sent here right now? |
| Does I/O | no | pings every configured database; checks signing keys when `services.auth` is on |
| Backs | the container healthcheck | the deploy gate |

They are separate deliberately. A readiness failure that also failed liveness would restart every
replica at once, turning a brief database outage into a crash loop.

`/health/ready` answers `503` with the failing check named, which makes it the right first stop for
all three failures above:

```json
{ "status": "ok", "version": "unknown", "checks": { "mongo:myapp": "ok", "jwtKeys": "ok" } }
```

`mongo:*` failing means §1 or §2. `jwtKeys` failing means §3. Neither appears in `/health`, which
stays I/O-free on purpose.

When a request is answered by something you did not expect, set `logging.lifecycle: true` in the
Unified Config to trace routing and every Auth Guard end to end. Logs go to stderr by default, not
to `logs/*.log`.
