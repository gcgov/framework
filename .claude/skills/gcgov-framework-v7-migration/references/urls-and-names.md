# Names, URLs and the Zone

## Contents

1. The URL change
2. Names that derive from the slug
3. Values that change with the host
4. Clients that must learn the new URL
5. The old URL after cutover
6. Choosing the Zone

## 1. The URL change

A v6 API is a sub-path of a shared IIS site. A v7 API is a host of its own behind the Zone's Traefik.

| | v6 | v7 |
|---|---|---|
| API root URL (`APP_ROOT_URL`) | `https://apps.garrettcounty.org` or `https://apps.garrettcountymd.gov` (sometimes `https://internal-apps.garrettcountymd.gov`) | `https://api.<app key>.apps.garrettcountymd.gov` |
| Base path (`APP_BASE_PATH`) | `/<app key>/api/` | `/api` |
| Full API URL | `https://apps.garrettcounty.org/<app key>/api/` | `https://api.<app key>.apps.garrettcountymd.gov/api/` |
| Frontend URL | `https://apps.garrettcounty.org/<app key>/app/` | `https://<app key>.apps.garrettcountymd.gov` |
| Health (internal only) | none | `/api/health`, `/api/health/ready` |

The **app key** is the path segment before `/api` in the v6 base path: `/lab/api/` gives `lab`.
`inventory.py` derives it. If the v6 base path has no such segment, ask the user.

### Why the base path stays `/api`

`lab-api` first ran at the domain root and failed: the nginx image's health probe fetched
`/api/health`, got a 404, and Traefik dropped the container without a log line at INFO. The deploy
template, the frontend template (`APP_API_URL` ends in `/api/`) and lab-api all use `/api`. Use
`/api` in every Environment, the developer's `.env` included, so a path that works locally works
in the Zone.

Every place that names the path must agree:

| Place | Value |
|---|---|
| `.env` and the Ops Repo `compose.yaml` | `APP_BASE_PATH=/api` |
| `Dockerfile`, nginx stage | `ENV HEALTH_URL="http://127.0.0.1/api/health"` |
| `docker-compose.yml`, nginx healthcheck | `http://127.0.0.1/api/health` |
| Ops Repo `compose.yaml` | `HEALTH_URL`, the router rule's health exclusion, the Traefik healthcheck path |
| `app/app.php` | `#[OA\Server(url:'/api/')]`, a relative URL |
| `app/router.php` | `config::getRoutePrefix()`, never a literal |

Use `127.0.0.1`, not `localhost`, in a health URL. In the Alpine nginx image `localhost`
resolves to `::1` first. Unless the nginx template also has `listen [::]:80;`, the probe is
refused and the container stays unhealthy. Add the IPv6 listen line too, as lab-api did.

## 2. Names that derive from the slug

The slug is the repository name. `build.yml` rejects anything but `^[a-z0-9]([a-z0-9-]*[a-z0-9])?$`.

| Thing | Value |
|---|---|
| Images | `ghcr.io/gcgov/<slug>/php`, `ghcr.io/gcgov/<slug>/nginx` |
| Ops Repo directory | `zones/<zone>/apps/<slug>/` |
| Compose project `name:` | `<slug>`. Validate refuses any other value. |
| Secrets on the host | `/etc/gcgov/secrets/<slug>/`, mounted at `/run/secrets/<slug>/` |
| Traefik router and service | `<slug>` |
| Containers | `<slug>-php-1`, `<slug>-nginx-1` |
| Database user | `<slug>-<zone>-g1` (generation suffix for rotation) |
| Frontend slug, if any | its own repository name, for example `lab-app` |

The hostname does **not** derive from the slug. `lab-api` serves `api.lab.apps.garrettcountymd.gov`.

## 3. Values that change with the host

| Value | v6 | v7 |
|---|---|---|
| `jwtAuth.tokenIssuedBy` | the v6 root URL | omit it. The framework derives it from `rootUrl`. |
| `jwtAuth.tokenPermittedFor` | the v6 base path | omit it. The framework derives it from `basePath`. |
| `jwtAuth.redirectAfterLoginUrl` | `.../<app key>/app/auth/sign-in` | `https://<app key>.apps.garrettcountymd.gov/auth/sign-in` |
| `jwtAuth.redirectAfterLogoutUrl` | `.../<app key>/app/auth/sign-out` | `https://<app key>.apps.garrettcountymd.gov/auth/sign-out` |
| CORS origins | `<add origin=...>` in `web-prod.config` | `CORS_ORIGIN_*` in the Ops Repo `compose.yaml` |
| OAuth third-party callback | `<v6 URL>auth/hybridauth/<provider>` | `https://api.<app key>.apps.garrettcountymd.gov/api/auth/hybridauth/<provider>` |

**Tokens.** Every token in circulation stops working at cutover. The issuer changes with the
host, and the frontend moves to a new origin, so its stored tokens are gone anyway. Every user
signs in once. Say so in the document. Pin `tokenIssuedBy` and `tokenPermittedFor` only when a
client outside the frontend checks `iss` or `aud`; then pin them to the new values.

**CORS.** Carry each v6 production origin across, translated to its v7 host. Drop
`https://apps.garrettcounty.org` and `https://apps.garrettcountymd.gov` once the frontend moves.
Add the new frontend host. Keep every origin distinct and non-empty: each one is a key in an
nginx `map`, and a duplicate or an unset one stops nginx. A client not yet served from a Zone
keeps its current origin (lab-api kept `https://dmr.garrettcountymd.gov`).

**Entra ID.** An app that uses `services.auth` with `provider: "oauth"` and Microsoft sign-in
redirects to the callback in the table. Its Entra app registration needs that URL as a redirect
URI. A frontend that uses `provider: "msFront"` needs its new host as an SPA redirect URI.

## 4. Clients that must learn the new URL

A redirect does not move an API client. A browser follows a 301 for a `GET`, but a `POST` with an
`Authorization` header is not safe to redirect, and a CORS preflight never follows one. List every
client in the document:

- the application's own frontend: `APP_API_URL` in its Ops Repo entry, or its build config if it is
  still on IIS,
- other county applications that call this API (search their repositories for the v6 URL),
- webhooks or integrations that call in (`grep` the v6 URL in the app's `db/`, `README.md`, and
  anything that registers a callback with a vendor),
- Swagger UI or Postman collections that name the old server.

## 5. The old URL after cutover

- `apps.garrettcounty.org` is being retired to a Cloudflare bulk redirect, not served from an
  origin (see `zones/bridge/zone.env` in `gcgov/deploy`). Add the app's path to that redirect list.
- `apps.garrettcountymd.gov/<app key>/` and `internal-apps.garrettcountymd.gov/<app key>/` stay
  on the v6 IIS server until someone removes the site. Keep the v6 site running, unchanged, until
  the v7 Release passes its checks. It is the rollback path. Then stop it and remove its Task
  Scheduler entries.

## 6. Choosing the Zone

| Zone | Application host | Reaches internal | Reachable from the internet |
|---|---|---|---|
| `internal` | `c-web-internal` | yes | no |
| `bridge` | `dev-web-container` | yes | yes |
| `isolated` | `c-web-isolated` | **no** | yes |

`zones/<zone>/zone.env` in `gcgov/deploy` is the source of truth for each host name.

Questions that decide it:

- Do people outside the county network use it? If not, `internal`.
- Does it reach an internal system (the county MongoDB, file shares, SQL Server, Graph is fine
  everywhere)? A public app that does goes in `bridge`. A public app that does not goes in
  `isolated`.

Infer a default, explain it, and let the user decide. An `internal` hostname gets no public `A`
record, only the ACME CNAME.
