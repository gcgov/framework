---
name: gcgov-framework-v7-migration
description: Migrate a Garrett County gcgov/framework v6 PHP API to framework v7.0.0 and to its new home in gcgov/deploy. Covers composer and the five retired service packages, `gf migrate`, the unified config.json with %env() Config References, Framework Services in config, the appRouter contract, the Docker images, the release workflow, the gcgov/deploy entry (compose.yaml, secrets, JWT keys), the move from apps.garrettcounty.org/{app}/api or apps.garrettcountymd.gov/{app}/api to api.{app}.apps.garrettcountymd.gov, and a next-steps document that names the device and the account for every command. Use this skill whenever someone wants to upgrade, migrate, port or containerize a gcgov framework app, mentions framework v7, `gf migrate`, environment-prod.json, web-prod.config, update-production.ps1, registerFrameworkServiceNamespaces, IIS retirement, or moving an app into gcgov/deploy or a Zone, even when they do not say "v7".
---

# Migrate a gcgov/framework v6 application to v7

This skill turns a v6 application repository into a v7 application, drafts its entry for the
**Ops Repo** (`gcgov/deploy`), and writes a document that tells the people involved exactly what
is left to do, where, and as whom.

`gcgov/lab-api` is the worked example. Its `main` branch is v6. Its `v7` branch is the finished
migration, and `zones/bridge/apps/lab-api/` in `gcgov/deploy` is its finished Ops Repo entry.
When a step here is unclear, read what lab-api did.

## What you produce

| # | Output | Where |
|---|---|---|
| 1 | The migrated application | A branch in the application repository, for example `v7-migration`. Commits, no push, no tag. |
| 2 | The draft Ops Repo entry | `docs/v7-migration/deploy/zones/<zone>/apps/<app>/` in the application repository. If a `gcgov/deploy` checkout is available and the user asks for it, also a branch there. |
| 3 | The next-steps document | `docs/v7-migration/NEXT-STEPS.md` in the application repository, built from `assets/NEXT-STEPS.template.md`. |

Finish all three. The third one is the deliverable a person reads, so do not skip it when time is
short. If you cannot finish a code step, record it as an open item in the document instead.

## Ground rules

These rules exist because each one prevents a specific, expensive failure.

- **Never write a secret value into any committed file, the draft Ops Repo entry, or the document.**
  v6 kept credentials in `app/config/environment-*.json`. They are in the git history forever. The
  document must tell the operator to rotate every one of them. Use `CHANGEME` in examples.
- **Never push, tag, or open a pull request unless the user asks.** A `v*` tag on the application
  repository starts a production deploy once `release.yml` exists.
- **Never decrypt or encrypt with `sops`, and never run `bin/provision`.** Those run on an
  operator's workstation with Key Vault access. Your job is to prepare the files and the steps.
- **Use the project vocabulary.** Read the framework's `CONTEXT.md` (in `vendor/gcgov/framework/`
  after the upgrade). Say Zone, Environment, Release, Secret, Secret File, Provisioning, Unified
  Config, Config Reference, Framework Service, Ops Repo, Bootstrap User. Do not say plugin, stage,
  variant or tier.
- **Label every command** in the document with the device and the account that runs it. See
  `references/where-commands-run.md`. This is the part people get wrong in a migration: a
  command run on the wrong machine either fails or, worse, succeeds against the wrong database.

## The workflow

Work in this order. Each step names the reference file that holds its detail. Read a reference
when you reach its step, not before.

### Step 1. Take the inventory

Run the inventory script from the application root. It is read-only and never prints a secret.

```bash
python3 <skill-dir>/scripts/inventory.py .            # Markdown report
python3 <skill-dir>/scripts/inventory.py . --json     # the same facts as JSON
```

Keep the report. It feeds every later step and the document. If Python is not available, collect
the same facts by hand: the report sections are the checklist.

Look for work that already exists before you start, because a second, parallel migration wastes
everyone's review time:

- the report's "Refs that look like earlier v7 work" line (a `v7` branch, a migration branch),
- `zones/*/apps/<slug>/` in a `gcgov/deploy` checkout, if one is on the machine,
- a `config.json` or `Dockerfile` already in the tree.

If any exists, build on it: compare it with this skill's checklist, fix the gaps, and say in the
document which branch is the source of truth. Do not start over.

Confirm the working tree is clean, then create the branch: `git switch -c v7-migration`.

### Step 2. Settle the names, the URL and the Zone

Read `references/urls-and-names.md`. Derive these values and write them down:

| Value | Rule | lab-api |
|---|---|---|
| App slug | The repository name. Lowercase letters, digits, hyphens. | `lab-api` |
| App key | The path segment before `/api` in the v6 URL | `lab` |
| API host | `api.<app key>.apps.garrettcountymd.gov` | `api.lab.apps.garrettcountymd.gov` |
| Base path | `/api` in every Environment | `/api` |
| Frontend host | `<app key>.apps.garrettcountymd.gov`, if the API has a frontend | `lab.apps.garrettcountymd.gov` |
| Zone | `internal`, `bridge` or `isolated` | `bridge` |

The Zone is a security decision, not a naming rule. Infer a default from the v6 host
(`internal-apps.` suggests `internal`; a public host suggests `bridge`), then ask the user to
confirm it before you write the release workflow. Ask in one question, with the reason for your
default.

### Step 3. Upgrade composer

Read `references/code-changes.md` §1. In short:

1. Make `composer-local.json` the one committed `composer.json`. Delete `composer-prod.json` and
   `composer-ci.json`.
2. Require `"gcgov/framework": "^7.0"`. Remove the five `gcgov/framework-service-*` packages:
   the framework declares a `conflict` with them, so composer cannot resolve while they remain.
3. Add `config.platform.php` (the newest 8.4 patch) and the `ext-*` the code needs.
4. Stop ignoring `composer.json` and `composer.lock` in `.gitignore`.
5. Run `composer update` and commit the lock.

If the machine has no PHP 8.4, use the `composer:2` image (the reference shows the command). If
neither works, finish the remaining file edits and record the lock as an open item.

### Step 4. Convert the configuration with `gf migrate`

`gf migrate` is the deterministic half of the migration. It reads `app/config/app.json` and
`app/config/environment.json`, writes `config.json` and `.env`, and deletes the v6 IIS files.

Before you run it, copy the **local** variant into place, so `.env` gets development values and
never production ones:

```bash
cp app/config/environment-local.json app/config/environment.json
vendor/bin/gf migrate --dry-run
vendor/bin/gf migrate
```

Then read `references/code-changes.md` §2 and finish what `gf migrate` reports and leaves alone.
The important ones: set `logging.destination` to `"stderr"`, move the `setBlockNewUsers()` call
into `services.auth`, add `jwtAuth.keyPath`, decide literal versus reference for each value, and
convert `sqlDatabases` by hand.

`gf migrate` extracts a fixed list of keys. Every other value keeps whatever the file it read
held, and that file is the local variant. The inventory lists these under "Values that differ
between the environment files". Make each one a Config Reference (or a literal with the
production value, if the local value was a mistake), or the image ships a developer's value.

The inventory also lists v6 credentials found outside `app/config` (a `README.md`, a
`db/*.js` script). Remove each value from the tree, and add its file to the rotation list.

### Step 5. Update the application code

Read `references/code-changes.md` §3 and work through every finding in the inventory's "Code that
needs attention" section. The changes that break at runtime, not at build time:

- `\app\router` must implement `\gcgov\framework\interfaces\appRouter` and add
  `providesAuthentication(): bool`. Without it the first request is a `TypeError`.
- Route prefixes use `config::getRoutePrefix()`.
- A v6 `getRunFrameworkServiceRouteAuthentication()` must now declare the
  `skipsServiceAuthentication` interface, or v7 ignores it and those routes return 401.
- `registerFrameworkServiceNamespaces()` is gone. Delete it. Nothing calls it, so a leftover
  copy silently does nothing.
- `www/index.php` includes `__DIR__ . '/../vendor/autoload.php'` and constructs the framework
  with no argument.
- `tests/bootstrap.php` seeds `unifiedConfig`, not `environmentConfig`.

### Step 6. Add the container and the local stack

Read `references/container-and-ci.md`. Copy the container files from
`gcgov/framework-app-template` (its `v7` branch until v7 merges to `main`), then adjust them from
the inventory: the base path, the CORS origins from `web-prod.config`, headless Chrome for PDF
routes, extra PHP extensions, upload limits, and static theme files. Delete the IIS and
PowerShell files that remain.

### Step 7. Replace CI and add the release workflow

Also in `references/container-and-ci.md`. CI installs from the one committed lock and no longer
checks out sibling repositories. `release.yml` comes from `assets/app/release.yml` and calls
`gcgov/deploy/.github/workflows/build.yml@main` with the slug, the Zone and `kind: api`. Replace
only `{{SLUG}}` and `{{ZONE}}` in it; `${{ ... }}` is GitHub Actions syntax.

### Step 8. Verify what you can

Run whichever of these the machine supports, and record the result of each in the document:

```bash
composer validate --no-check-publish --no-check-all
composer ci
APP_TYPE=local APP_ROOT_URL=https://ci.invalid APP_BASE_PATH=/api ... vendor/bin/gf env
docker build --target php -t <app>:php . && docker build --target nginx -t <app>:nginx .
docker compose up --build   # then: curl -s localhost:8080/api/health
```

`gf env` needs every Config Reference set. Give each a dummy value on the command line, as
lab-api's CI `config` job does. Never put a real secret on a command line.

If a check fails because of the migration, fix it. If it fails for a reason that predates the
migration, say so in the document and leave it.

### Step 9. Draft the Ops Repo entry

Read `references/deploy-entry.md`. Fill in the templates in `assets/deploy/` and write them to
`docs/v7-migration/deploy/zones/<zone>/apps/<app>/`:

- `compose.yaml`: every non-secret Config Reference with its production value, every Secret as a
  `<NAME>_FILE` path under `/run/secrets/<app>/`, the Traefik labels for the API host.
- `secrets.example.yaml`: one key per Secret, `CHANGEME` values, the JWT key shape.
- `jwt/README.md` and `image.env`, copied from the assets.

This is the part that moves out of the application repository for good: production topology,
production values and Secrets live only in the Ops Repo.

### Step 10. Write the next-steps document

Copy `assets/NEXT-STEPS.template.md` to `docs/v7-migration/NEXT-STEPS.md` and fill it in. Rules:

- Fill every `{{placeholder}}`. Delete a section only when the inventory proves it does not apply,
  and say in one line why (for example, "No scheduled CLI Routes: the v6 server ran none").
- Every code block gets its own bold device line directly above it, even when the block before it
  had the same one. A reader copies one block at a time, often from the middle of a step. Replace
  `<ZONE_HOST>` with the real host from `references/where-commands-run.md`.
- List each v6 credential the inventory found, by key path, under "Rotate". Never the value.
- Put the results from step 8 in "What was verified".
- Keep the template's plain style: short sentences, active voice, one instruction per sentence.

The placeholders that need judgement:

| Placeholder | Fill with |
|---|---|
| `{{CHANGE_SUMMARY}}` | A bulleted list of the commits, one line each, in plain words |
| `{{DECISIONS}}` | Each choice you made that a person must confirm: the Zone, literal versus reference, origins kept or dropped, pinned token values, Dockerfile additions. One line each, with the reason. |
| `{{OPEN_ITEMS}}` | Each thing you could not finish or verify, with the file and the reason |
| `{{ROTATE_LIST}}` | One bullet per credential key path from the inventory (`microsoft.clientSecret`, `payjunction.password`, ...), with where a new value comes from and the device label. `MONGO_URI` is step 3, so leave it out here. |
| `{{CLIENT_LIST}}` | The frontend (its Ops Repo `APP_API_URL`, or its build config), each CORS origin's application, and every hit for the v6 URL outside this repository that you know of. Say which you could not check. |
| `{{ENTRA_NOTE}}` | For `provider: oauth` with Microsoft sign-in: add the callback URL from `references/urls-and-names.md` §3 as a redirect URI, **In Azure**. For `msFront`: the frontend's new host as an SPA redirect URI. Otherwise delete the line. |
| `{{SCHEDULED_ROUTES}}` | See below. |
| `{{A_RECORD_NOTE}}` | For `internal`: "No `A` record: internal hostnames resolve on internal DNS only. Ask IT for the internal record." Otherwise empty. |
| `{{V6_HOST_NOTE}}` | For a v6 URL on `apps.garrettcountymd.gov` or `internal-apps.garrettcountymd.gov`: that host stays on the v6 server, so a redirect there is an IIS rule, not Cloudflare. Otherwise empty. |
| `{{PLACEHOLDER_NOTE}}` | The `PLACEHOLDER_` values left in the draft `compose.yaml`, and who supplies each |
| `{{FIRST_VERSION}}` | The next version after the repository's latest tag, as a major bump (lab-api went from `1.0.7` to `1.1.0`; a major is clearer for a platform move) |
| `{{MONGO_HOSTS}}`, `{{MONGO_AUTH_SOURCE}}` | From the v6 production URI: the host part and `authSource`. Never the user or the password. |

**Scheduled CLI Routes.** `gcgov/deploy` has no scheduler for CLI Routes today
(`docs/plans/azure-app-service.md` in `gcgov/deploy` names the gap). For each CLI Route the
inventory lists, write a row: the route, what it does (read its controller), and whether the v6
server ran it on a schedule (unknown unless the user said so). Then state the decision the
operator must make, with these options, and do not pick one for them:

1. A systemd timer on the application host that runs, as `deployer`,
   `docker compose -f compose.yaml --env-file .env --env-file image.env exec -T php vendor/bin/gf cli <route>`.
   This needs a change to `gcgov/deploy` (the timer is host configuration), so it is a pull
   request there first.
2. Keep the v6 server's Task Scheduler entry for a while. Only safe when the route is idempotent
   and v6 and v7 never both run it: the v6 code still talks to the same database.
3. Turn the job into an authenticated HTTP route that an existing scheduler calls.

A route that is only run by hand (a one-time import, an index rebuild) needs no schedule. Say how
to run it in the Zone: **Run on the application host, as `deployer`**, the `exec -T php` command
above, from `/srv/gcgov/<slug>`.

Read each scheduled route's controller against the v7 API. The v6 `.bat` entry always exited `0`,
so a route that has thrown every night for months looked healthy. If the route would fail under v6
and work under v7, its first v7 run processes the whole backlog at once. Say so in the document.

### Step 11. Commit and report

Commit in small, reviewable steps (composer, config, code, container, CI, docs). Then tell the user:

1. the branch name and what it contains,
2. the decisions you made and the ones you could not make,
3. the path of `NEXT-STEPS.md`, as the place to start.

## Reference files

| File | Read it at |
|---|---|
| `references/urls-and-names.md` | Step 2, and whenever a URL appears |
| `references/code-changes.md` | Steps 3 to 5 |
| `references/container-and-ci.md` | Steps 6 and 7 |
| `references/deploy-entry.md` | Step 9 |
| `references/where-commands-run.md` | Step 10, and before you write any command for a person |
| `assets/NEXT-STEPS.template.md` | Step 10 |
| `assets/deploy/*` | Step 9 |
| `assets/app/release.yml` | Step 7 |
