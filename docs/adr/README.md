# Architecture decision records

These ADRs record decisions about the framework itself. During the `v7` review, four
operational ADRs moved out to `gcgov/deploy`, and the ADRs left here were renumbered into a
clean `0001`-`0004` sequence.

The four that moved carry the county's operational threat model — where secrets decrypt, how
the deploy runners are isolated, how certificates issue, and where the SOPS keys live. This
repository is public; that material belongs in the Ops Repo beside the mechanism it
describes. See `gcgov/deploy` `docs/adr/README.md`.

## What stayed, and its number

| Now | Was | Decision |
|---|---|---|
| `0001` | `0001` | Fail-closed configuration |
| `0002` | `0002` | Immutable Release, pinned by digest |
| `0003` | `0005` | Framework Services are built in and config-activated |
| `0004` | `0008` | Writes are transactional, so MongoDB is a replica set |

## What moved to `gcgov/deploy`

| Was here | Now (`gcgov/deploy`) | Decision |
|---|---|---|
| `0003` | `0001` | Secrets never decrypt in CI or on hosts |
| `0004` | `0002` | One self-hosted runner per Zone |
| `0006` | `0003` | Let's Encrypt DNS-01 on a shared registered domain |
| `0007` | `0004` | Azure Key Vault per Zone for deployment secrets |

## How to cite an ADR

Both repositories now number `0001`-`0004`, so a bare "ADR 0002" is ambiguous. Every
citation names its repository:

- A local ADR: `docs/adr/0002-immutable-release-digest-pinning.md`
- A foreign ADR: `gcgov/deploy docs/adr/0002-self-hosted-runners-per-zone.md`
