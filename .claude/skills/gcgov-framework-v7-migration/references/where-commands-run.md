# Where commands run

`gcgov/deploy` starts every command block with a bold line that names the system and the account.
The next-steps document follows the same rule, with the same labels, so an operator who knows the
Ops Repo runbooks reads it without translation.

Put the label on its own line directly above the code block:

```markdown
**Run on your operator workstation, in your `gcgov/deploy` checkout:**

    bin/provision bridge lab-api
```

## The labels

| Label | Device | Account | What it needs |
|---|---|---|---|
| **Run on your developer workstation, in the application checkout** | Your own computer | Your own account | git, Docker Desktop (or Docker Engine and the Compose plugin). PHP 8.4 is optional. |
| **Run on your operator workstation, in your `gcgov/deploy` checkout** | Your own computer. The only place that decrypts a Secret. | Your own account | `sops`, the `az` CLI after `az login`, membership of the `sops-<zone>` Entra group, your operator SSH key for `deployer@<ZONE_HOST>`, and `git config core.hooksPath .githooks` once per clone. |
| **Run on the application host, as `deployer`** | The Zone's application host (`<ZONE_HOST>`) | `deployer`. Reach it from your operator workstation with `ssh deployer@<ZONE_HOST> '…'`, or on the host with `sudo -iu deployer`. | `deployer` is in the `docker` group and reads every decrypted Secret. |
| **Run on the application host, as your `sudo` account** | The Zone's application host | Your own administrator account on that server (for example `gcit`). Commands that change the system start with `sudo`. | For the journal: `sudo journalctl CONTAINER_NAME=<slug>-php-1`. Never `sudo -i` or `sudo su`. |
| **In GitHub, in the application repository** | The GitHub website | A repository administrator | **Settings** → **Secrets and variables** → **Actions** |
| **In GitHub, in `gcgov/deploy`** | The GitHub website | A required reviewer of the Zone's Environment | **Actions** → **Deploy** → the waiting run → **Review deployments** |
| **In Cloudflare** | The Cloudflare dashboard | An account that edits DNS for `garrettcountymd.gov` | |
| **In Azure** | The Azure portal (Entra ID) | An owner of the app registration | |
| **In the database** | `mongosh`, from a computer that reaches the production MongoDB (usually your operator workstation) | A MongoDB account with `userAdmin` on the target database | Never paste the new password into a shell history: let `mongosh` prompt for it (`passwordPrompt()`). |
| **On the v6 IIS server** | The Windows server that hosts the v6 site today | Your Windows administrator account | IIS Manager, Task Scheduler |

Two automated places run without a person. Name them so the reader knows nothing is required:

| Place | What runs there |
|---|---|
| GitHub-hosted runner | `build.yml`: builds and publishes the images, sends the deploy request |
| The Zone's runner host (for example `dev-web-runner`) | `deploy.yml`: takes the approved job and calls `gcgov-deploy` on the application host over SSH. Nobody logs in to it for a migration. |

## The hosts

`zones/<zone>/zone.env` in `gcgov/deploy` is the source of truth. The values committed today:

| Zone | `ZONE_HOST` (application host) | SSH user |
|---|---|---|
| `internal` | `c-web-internal` | `deployer` |
| `bridge` | `dev-web-container` | `deployer` |
| `isolated` | `c-web-isolated` | `deployer` |

Write the real host into each command. Never leave a literal `<zone>` or `<app>` in a block a
person might paste.

## Rules for the commands themselves

- One command per line. A comment after `#` may say what it does.
- Compose commands on the host always carry `-f compose.yaml --env-file .env --env-file image.env`.
  Compose loads only `.env` by default, and the digests live in `image.env`.
- The readiness route is not public. Check it inside the stack:
  `ssh deployer@<ZONE_HOST> 'cd /srv/gcgov/<slug> && docker compose -f compose.yaml --env-file .env --env-file image.env exec nginx wget --quiet --tries=1 --spider http://127.0.0.1/api/health/ready'`.
  It prints nothing and exits `0` on success.
- Do not tell anyone to run the `docker compose up` line that `bin/provision` prints before the
  first deploy. No `image.env` digests exist yet; the first deploy starts the stack.
