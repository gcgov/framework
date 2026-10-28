<!--
  Template for docs/v7-migration/NEXT-STEPS.md. Fill every {{PLACEHOLDER}} and delete this
  comment. Delete a section only when the inventory proves it does not apply, and leave one line
  that says why. Keep every bold device line.
-->
# {{SLUG}}: finish the move to gcgov/framework v7

This document lists what remains after the code migration. The work is in order. Each command
names the device and the account that runs it.

| | |
|---|---|
| Application | `{{SLUG}}` ({{TITLE}}) |
| Branch | `{{BRANCH}}` |
| Framework | `gcgov/framework` `{{FRAMEWORK_VERSION}}` |
| Zone | `{{ZONE}}`, application host `{{ZONE_HOST}}` |
| v6 URL | `{{V6_URL}}` |
| v7 API URL | `https://api.{{APP_KEY}}.apps.garrettcountymd.gov/api/` |
| v7 frontend URL | `https://{{APP_KEY}}.apps.garrettcountymd.gov` |
| Draft Ops Repo entry | `docs/v7-migration/deploy/zones/{{ZONE}}/apps/{{SLUG}}/` |

## Who does what

| Role | Who | Steps |
|---|---|---|
| Developer | a person with push access to `gcgov/{{SLUG}}` | 1, 10 (the tag), 12, 13 |
| Operator | a member of the `sops-{{ZONE}}` Entra group, with an operator SSH key for `deployer@{{ZONE_HOST}}` | 2 to 9, 11, 14 |
| Reviewer | a required reviewer of the `{{ZONE}}` Environment in `gcgov/deploy` | 10 (the approval) |

One person often holds all three roles. The steps still run on different devices.

## What changed in this branch

{{CHANGE_SUMMARY}}

## What was verified

| Check | Result |
|---|---|
| `composer validate` | {{RESULT_VALIDATE}} |
| `composer ci` (PHPStan, tests) | {{RESULT_CI}} |
| `gf env` with every Config Reference set | {{RESULT_GF_ENV}} |
| `docker build --target php` and `--target nginx` | {{RESULT_DOCKER_BUILD}} |
| Local stack: `GET /api/health` | {{RESULT_LOCAL_HEALTH}} |

A check marked "not run" did not run on the migration machine. Run it in step 1.

## Decisions to confirm

{{DECISIONS}}

## Open items

{{OPEN_ITEMS}}

---

## 1. Review and merge the migration branch

**Run on your developer workstation, in the application checkout:**

```bash
git fetch origin
git switch {{BRANCH}}
composer install
composer ci
```

Then start the local stack and confirm the API answers.

**Run on your developer workstation, in the application checkout:**

```bash
cp .env.example .env                                     # skip if .env already exists
docker compose build php
docker compose run --rm php vendor/bin/gf env --init     # appends what config.json needs; keeps existing values
docker compose run --rm php vendor/bin/gf env            # names the first variable still missing
docker compose run --rm php vendor/bin/gf cert:generate-auth
docker compose up --build
curl -s http://localhost:8080/api/health
```

`LOCAL-DEVELOPMENT.md` in the branch explains each value `.env` needs.

The health route answers `{"status":"ok",...}`. Then open a pull request, review it, and merge it.
Do **not** push a `v*` tag yet. A tag starts a production deploy once step 8 is done.

## 2. Confirm the production MongoDB is a replica set

v7 opens a transaction for every write. A standalone server answers every read and fails every
write.

**In the database**, connected to `{{MONGO_HOSTS}}`:

```javascript
rs.status().ok        // 1 means a replica set. An error means a standalone: stop and fix that first.
```

## 3. Create the database user for this Zone

The v6 account is shared and its password is in the git history. v7 uses a new user per
application **and** per Zone.

**In the database**, as a MongoDB account with `userAdmin` on `{{MONGO_AUTH_SOURCE}}`:

```javascript
use {{MONGO_AUTH_SOURCE}}
db.createUser({ user: "{{SLUG}}-{{ZONE}}-g1", pwd: passwordPrompt(), roles: [ { role: "readWrite", db: "{{MONGO_DATABASE}}" } ] })
```

Keep the password for step 6. Do not delete the v6 user yet. The v6 site still uses it until
step 14.

## 4. Rotate the other v6 credentials

These values sat in plain text in `app/config/environment-*.json`. They are in the git history
of `gcgov/{{SLUG}}` for good, so each one needs a new value.

{{ROTATE_LIST}}

Create each new value now and keep it for step 6. Revoke each old value only in step 14, after
the v6 site stops.

## 5. Generate the JWT signing keys

The keys never enter the image. Generate them outside the repository, so they never reach a
commit or the developer key folder.

**Run on your operator workstation, in the application checkout:**

```bash
KEYS="$(mktemp -d)"
APP_JWT_KEY_PATH="$KEYS/" vendor/bin/gf cert:generate-auth --count=1 --yes
ls "$KEYS"     # guids.json, private-<guid>.pem, public-<guid>.pem
```

Without PHP 8.4 on that computer, run the same command in the dev container.

**Run on your operator workstation, in the application checkout:**

```bash
docker compose run --rm --user "$(id -u):$(id -g)" -v "$KEYS":/keys -e APP_JWT_KEY_PATH=/keys/ php vendor/bin/gf cert:generate-auth --count=1 --yes
```

## 6. Add the entry to gcgov/deploy

**Run on your operator workstation, in your `gcgov/deploy` checkout:**

```bash
APP_CHECKOUT=~/src/{{SLUG}}     # change to where your application checkout is
git switch main && git pull
git switch -c add-{{SLUG}}
cp -r "$APP_CHECKOUT/docs/v7-migration/deploy/zones/{{ZONE}}/apps/{{SLUG}}" zones/{{ZONE}}/apps/
cp zones/{{ZONE}}/apps/{{SLUG}}/secrets.example.yaml zones/{{ZONE}}/apps/{{SLUG}}/secrets.sops.yaml
```

Open `zones/{{ZONE}}/apps/{{SLUG}}/secrets.sops.yaml` in your editor. Put in the values from steps
3 to 5. Add each key file under its `JWT/` key, exactly as generated. Set `_rotated` to today.
Then encrypt the file in place, and remove the plaintext keys.

**Run on your operator workstation, in your `gcgov/deploy` checkout:**

```bash
az login
sops --encrypt --in-place zones/{{ZONE}}/apps/{{SLUG}}/secrets.sops.yaml
rm -rf "$KEYS"
```

Check `compose.yaml` for any `PLACEHOLDER_` value and replace it. {{PLACEHOLDER_NOTE}}

**Run on your operator workstation, in your `gcgov/deploy` checkout:**

```bash
git add zones/{{ZONE}}/apps/{{SLUG}}
git commit -m "Add {{SLUG}} to {{ZONE}}"
git push -u origin add-{{SLUG}}
```

Open the pull request. Merge it after the **Validate** workflow passes. `deploy.yml` refuses an
application that is not on `main`.

## 7. Create the DNS records

**In Cloudflare**, in the `garrettcountymd.gov` zone:

| Type | Name | Value |
|---|---|---|
| `A` | `api.{{APP_KEY}}.apps` | the address of `{{ZONE_HOST}}` {{A_RECORD_NOTE}} |
| `CNAME` | `_acme-challenge.api.{{APP_KEY}}.apps` | `api.{{APP_KEY}}.apps.garrettcountymd.us` |

Never create a record in `garrettcountymd.us` by hand. Traefik writes the challenge record there.
The hostname is public the moment a certificate issues (Certificate Transparency).
`docs/runbooks/configure-acme-delegation.md` in `gcgov/deploy` has the exact dashboard fields.

## 8. Connect the application repository to gcgov/deploy

**In GitHub, in the application repository** (`gcgov/{{SLUG}}`): **Settings** → **Secrets and
variables** → **Actions** → **New repository secret**. Name it `OPS_DISPATCH_TOKEN`. The value is a
fine-grained personal access token with `contents: write` on `gcgov/deploy` and nothing else.

One setting covers every application. It is likely already on. **In GitHub, in `gcgov/deploy`**:
**Settings** → **Actions** → **General** → **Access** must read **Accessible from repositories in
the 'gcgov' organization**. If it does not, the first tag fails with `workflow was not found`.

## 9. Provision

**Run on your operator workstation, in your `gcgov/deploy` checkout**, on an up-to-date `main`:

```bash
git switch main && git pull
bin/provision {{ZONE}} {{SLUG}}
```

`bin/provision` prints a `docker compose up` line at the end. Do not run it now. No Release digests
exist yet, so compose cannot start the stack. The first deploy starts it.

## 10. Tag the first Release

**Run on your developer workstation, in the application checkout**, on `main` after the merge:

```bash
git switch main && git pull
git tag v{{FIRST_VERSION}}
git push origin v{{FIRST_VERSION}}
```

The tag runs `release.yml` on a GitHub-hosted runner. It builds and publishes the images and asks
`gcgov/deploy` to deploy them.

**In GitHub, in `gcgov/deploy`**, as a required reviewer: **Actions** → **Deploy** → the waiting
run → **Review deployments** → approve `{{ZONE}}`. The `{{ZONE}}` runner host then runs the job.
Nobody logs in to it.

If the deploy fails at the health gate, the stack stays down, because a first deploy has nothing
to roll back to. `docs/runbooks/review-logs.md` in `gcgov/deploy` says where the reason is.

## 11. Verify the Release

**Run on your operator workstation:**

```bash
ssh deployer@{{ZONE_HOST}} 'cd /srv/gcgov/{{SLUG}} && docker compose -f compose.yaml --env-file .env --env-file image.env ps'
ssh deployer@{{ZONE_HOST}} 'cd /srv/gcgov/{{SLUG}} && docker compose -f compose.yaml --env-file .env --env-file image.env exec nginx wget --quiet --tries=1 --spider http://127.0.0.1/api/health/ready'
curl -s -o /dev/null -w '%{http_code}\n' https://api.{{APP_KEY}}.apps.garrettcountymd.gov/api/
```

The readiness check prints nothing and exits `0`. The `curl` prints an HTTP status, such as `404`
or `401`, from the framework. A TLS or connection error means the DNS record or the certificate is
not ready yet.

To read the application's log, **run on the application host, as your `sudo` account:**

```bash
sudo journalctl CONTAINER_NAME={{SLUG}}-php-1 --since "1 hour ago"
```

Then sign in through the frontend or with the OAuth password grant, and call one route that reads
and one that writes. A write that fails with "Transaction numbers are only allowed on a replica set
member" means step 2 did not hold.

## 12. Update every client of the API

A redirect does not move an API client. Each one needs the new URL:
`https://api.{{APP_KEY}}.apps.garrettcountymd.gov/api/`.

{{CLIENT_LIST}}

{{ENTRA_NOTE}}

Every signed-in user signs in once more after the switch. The token issuer changes with the host.

## 13. Scheduled CLI Routes

{{SCHEDULED_ROUTES}}

## 14. Cut over and retire v6

Do this only after step 11 passes and every client in step 12 points at v7. Until then the v6
site is the rollback path.

Between step 10 and this step, v6 and v7 both run against the same database. That is safe for
requests. It is not safe for scheduled CLI Routes: make sure each one runs in exactly one place
(step 13) for the whole period.

1. **On the v6 IIS server**, as your Windows administrator account: disable each Task Scheduler
   entry that calls `app\cli\prod.bat`. Then stop the IIS site or application for `{{V6_URL}}`.
2. **In Cloudflare**: add `{{V6_URL}}` to the `apps.garrettcounty.org` bulk redirect, with
   `https://{{APP_KEY}}.apps.garrettcountymd.gov` as the target for the frontend path.
   {{V6_HOST_NOTE}}
3. **In the database**: drop the v6 database user, after the v6 site stops.
4. Revoke each old credential from step 4 (**In Azure** for an Entra client secret; the vendor
   portal for any other).
5. **On the v6 IIS server**: remove the site's files after one full business cycle with no
   problem reports. Keep a copy of nothing that holds a credential.

## Roll back

- **A later Release misbehaves.** Re-run the **Deploy** workflow with the previous image list.
  **Run on your operator workstation, in your `gcgov/deploy` checkout:**
  `git log -p -- zones/{{ZONE}}/apps/{{SLUG}}/image.env` shows it. Then, **in GitHub, in
  `gcgov/deploy`**: **Actions** → **Deploy** → **Run workflow**, with app `{{SLUG}}`, Zone
  `{{ZONE}}`, and the list.
- **The first v7 Release misbehaves before step 14.** Point the clients back at `{{V6_URL}}`. The
  v6 site and its database user still work.

## References

- `gcgov/deploy`: `README.md`, `docs/runbooks/add-an-application.md`, `docs/runbooks/rotate-a-secret.md`,
  `docs/runbooks/review-logs.md`
- `gcgov/framework`: `CONTEXT.md`, `readme/gf.md`, `readme/environment-variables.md`,
  `readme/local-development.md`
- The worked example: `gcgov/lab-api`, branch `v7`, and `zones/bridge/apps/lab-api/` in `gcgov/deploy`
