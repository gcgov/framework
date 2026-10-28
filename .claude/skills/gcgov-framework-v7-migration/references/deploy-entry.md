# What moves to gcgov/deploy

## Contents

1. The split
2. The entry, file by file
3. Rules the Validate workflow enforces
4. What the operator does with the draft

## 1. The split

In v6 the application repository held everything: code, per-Environment config with credentials,
`web.config` for each server, and `update-production.ps1`, which ran on the server. In v7 the
application repository builds a **Release**. The **Ops Repo** decides what runs.

| v6, in the application repository | v7, where it lives now |
|---|---|
| `app/config/environment-prod.json` values that are not Secrets | `environment:` in `zones/<zone>/apps/<slug>/compose.yaml` |
| Credentials in `environment-prod.json` | `zones/<zone>/apps/<slug>/secrets.sops.yaml`, encrypted. On the host as Secret Files under `/etc/gcgov/secrets/<slug>/`. |
| `srv/jwtCertificates/*` on the server | `JWT/...` keys in the same `secrets.sops.yaml` |
| `www/web-prod.config` CORS origins | `CORS_ORIGIN_*` in the Ops Repo `compose.yaml` |
| `www/web-prod.config` host binding, TLS | Traefik labels in the Ops Repo `compose.yaml`, and DNS in Cloudflare |
| `update-production.ps1` | `build.yml` (called by the app's `release.yml`) and `deploy.yml` |
| `version.json` | `zones/<zone>/apps/<slug>/image.env`, written by `deploy.yml` after a healthy deploy |

The application repository keeps `config.json`, which names every value as a Config Reference and
holds none of the production values. One `config.json` serves every Environment.

## 2. The entry, file by file

Write the draft to `docs/v7-migration/deploy/zones/<zone>/apps/<slug>/` in the application
repository. The operator copies it into `gcgov/deploy`.

### `compose.yaml`

Fill in `assets/deploy/compose.yaml`. It matches `zones/bridge/apps/lab-api/compose.yaml`, the
reference entry. Replace every `{{...}}`:

| Placeholder | Value |
|---|---|
| `{{SLUG}}` | the slug |
| `{{APP_KEY}}` | the app key |
| `{{MONGO_DATABASE}}` | the v6 production database name (not a Secret) |
| `{{EXTRA_ENVIRONMENT}}` | one line per remaining non-secret Config Reference, with its production value. Example: `MICROSOFT_DRIVE_ID: b!...`. |
| `{{SECRET_FILES}}` | one `<NAME>_FILE: /run/secrets/{{SLUG}}/<name lowercased>` line per Secret other than `MONGO_URI` |
| `{{CORS_ORIGINS}}` | one `CORS_ORIGIN_<NAME>: https://...` line per origin the image's nginx template names |
| `{{ZONE}}` | the Zone, in the header comment |

Leave `{{.Name}}` in the `logging:` block alone. It is a Docker template, not a placeholder.
Indent the three multi-line placeholders to match the lines around them.

Values come from `app/config/environment-prod.json` in the v6 history. Read them with
`git show <v6 commit>:app/config/environment-prod.json`. Copy only values that are not Secrets.
Where the value is a URL, translate it to the v7 host.

A value only IT can supply (a Graph drive id for a new Environment, for example) gets a
`PLACEHOLDER_<WHAT>` value, such as `PLACEHOLDER_MICROSOFT_DRIVE_ID`. `bin/provision` greps for
`PLACEHOLDER_` and refuses to send a compose file that holds one, which is the point. Use
`CHANGEME` only in `secrets.example.yaml`, which is never provisioned.

### `secrets.example.yaml`

Fill in `assets/deploy/secrets.example.yaml`. One key per Secret, each `CHANGEME`. Keys are the
Config Reference names in upper case: `MONGO_URI`, `MICROSOFT_CLIENT_SECRET`, `SMTP_PASSWORD`,
`PAYJUNCTION_PASSWORD`, `SQL_<NAME>_PASSWORD`. `bin/provision` lowercases each key into a file
name, and `compose.yaml` points `<NAME>_FILE` at that file.

The Mongo user in the example is `<slug>-<zone>-g1`, scoped to this app **and** this Zone, with
`readWrite` on its own database only. A shared v6 account must not carry over.

Placeholders in the template:

| Placeholder | Value |
|---|---|
| `{{SLUG}}`, `{{ZONE}}`, `{{MONGO_DATABASE}}` | as in `compose.yaml` |
| `{{MONGO_HOSTS}}` | the host and port part of the v6 production URI, for example `c-mongo:27017`. A host name is not a Secret; the user name and password are. |
| `{{MONGO_AUTH_SOURCE}}` | the database the new user is created in. The v6 URI's `authSource` is the usual answer. |
| `{{MONGO_REPLICA_SET}}` | the replica set name, if known. If not, delete the parameter and add an open item: v7 writes need a replica set. |
| `{{OTHER_SECRETS}}` | one `NAME: "CHANGEME"` line per other Secret, each with a comment that says what it is and where the operator gets a new value (Entra, the vendor portal, the SMTP admin) |

Never write `secrets.sops.yaml`. Only an operator can encrypt it.

### `jwt/README.md`

Copy `assets/deploy/jwt-README.md` unchanged. It tells the operator how to generate the keys and
add them under the `JWT/` prefix. Two traps it covers: `guids.json` is a JSON **array**, and every
filename must be lowercase.

### `image.env`

Copy `assets/deploy/image.env`. `deploy.yml` writes the digests on the first deploy.

## 3. Rules the Validate workflow enforces

The draft must pass these, or the pull request in `gcgov/deploy` fails:

- `name:` equals the directory name, the slug.
- The only host mount is `/etc/gcgov/secrets/<slug>:/run/secrets/<slug>:ro`. No log volume.
- `php` is on the stack's private `backend` network only. `nginx` is on `backend` and `gcgov`.
  FastCGI has no authentication, so `php` on `gcgov` is code execution for every container in
  the Zone.
- Every service on `gcgov` has a Traefik router.
- The `logging:` block uses the `journald` driver with `tag: "{{.Name}}"`.
- `networks.backend` is not `external` and has no fixed `name`.

## 4. What the operator does with the draft

The document's sections C to G walk through it. In order: create the database user, rotate the v6
credentials, generate the JWT keys, copy the draft into a `gcgov/deploy` branch, create and encrypt
`secrets.sops.yaml`, open the pull request, create the DNS records, provision, and push the first
tag. See `docs/runbooks/add-an-application.md` in `gcgov/deploy` for the authoritative
runbook. The document must follow it, not contradict it.
