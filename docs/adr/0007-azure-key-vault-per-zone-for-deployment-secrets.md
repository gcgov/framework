# Deployment Secrets are encrypted with Azure Key Vault, one vault per Zone

The Ops Repo's Secrets are encrypted by SOPS to a key held in Azure Key Vault — one vault and one
key per Zone, with decrypt granted to an Entra group per Zone. MongoDB queryable encryption keeps its
own Cloud KMS key in GCP and is untouched.

## Considered Options

This began as a dedicated GCP project, for a reason that still holds. The queryable-encryption key is
used by an Application at runtime, so its service-account credential file sits **on an Application
host**, while the `sops` keys are used by operators at a workstation and, per ADR 0003, must never be
reachable from a host or from CI. One project for both means a compromised Application host holds a
credential inside the same project as the keys that decrypt every Zone's Secrets — contained today by
per-key bindings, and not contained at all by a project-level binding, or by a `roles/cloudkms.admin`
granted in eighteen months to solve something unrelated.

Building it is what changed the answer. There is no GCP organization and no group layer: operators
sign in with individual Google accounts. Access would therefore be individual IAM bindings, and
offboarding one edit per key per person — three chances to half-finish a revocation, which is the
precise failure this decision was written to prevent. A group layer could be built, since Cloud
Identity's free tier supplies Google Groups without Workspace, but it means verifying a domain that
Microsoft 365 already holds in order to replicate a directory that is already running.

Azure Key Vault has the group layer, because the county already operates Entra. More importantly it
has the *process*: a joiner/mover/leaver routine already exists, so revoking decrypt stops being a
separate checklist item somebody has to remember and becomes a consequence of offboarding that
happens anyway. SOPS supports Key Vault natively and `bin/provision` only shells out to `sops`, so
nothing in the provisioning path changes.

The separation argument survives the move and gets stronger. With Mongo staying on GCP, the deploy
keys and the host-resident credential are no longer merely in different projects but in different
clouds — the strongest available form of what the original decision was reaching for, arrived at
sideways rather than by design.

Three vaults rather than one vault holding three keys. Key-scoped RBAC is possible, but vault-scoped
is easier to read in a role listing and to reason about mid-incident, and it gives each Zone its own
firewall and network rules if those are ever wanted. Premium SKU rather than Managed HSM: both give
HSM-backed keys, but Managed HSM is a dedicated pool billed hourly and would cost more per month than
every host in this design combined. `Key Vault Crypto User` at vault scope rather than `Crypto
Officer`, which can also create and destroy keys — no operator needs that to do their job, and the
gap between the two roles is the gap between losing one Secret and losing every Secret.

Doing this before the pilot is most of why it is cheap. Nothing has been encrypted yet, so there is
no re-encryption and no rotation of Secrets exposed under superseded keys. That window closes at the
first `sops --encrypt`.

## Consequences

- **The Break-glass Key becomes more load-bearing, not less.** The vaults are Entra, so a
  tenant-wide compromise takes the primary decryption path outright. The offline age key is the only
  part of this design that does not depend on Entra, which is why its escrow must not sit anywhere
  Entra can sign you in, and why the break-glass runbook now says so outright rather than leaving it
  to be inferred.
- **Nothing automated holds a crypto role** — no service principal, no CI identity, no host. ADR
  0003 already required it; vault-scoped RBAC makes it checkable by listing role assignments on three
  resources instead of reasoning about which bindings in a shared project are for what. That listing
  has to include *inherited* assignments: a subscription-level `Owner` reaches all three vaults at
  once and silently defeats the per-Zone split this ADR exists to create.
- **Key URLs in `.sops.yaml` are version-pinned.** Unlike a GCP resource id, an Azure key URL names
  one specific version, so rotating a key means editing `.sops.yaml` and running `sops updatekeys`
  across every file rather than a transparent switch behind a stable identifier. Rotating a *Secret*
  is unaffected and stays cheap, which is the operation that actually happens often.
- **Two clouds, but not one more than before.** GCP was already there for Mongo. What changes is
  which cloud holds what, not how many consoles exist — though the less-used one still drifts, and
  its billing and audit retention go unwatched between incidents.
- **Key Vault audit logging is off by default,** exactly as GCP's Data Access logging was. Per-vault
  diagnostic settings into a Log Analytics workspace are what make the offboarding runbook's claim
  about reading what someone decrypted true rather than aspirational.
- **Access is standing, not just-in-time.** Entra ID P2 would allow PIM to make the crypto role
  eligible rather than active, so decrypt would be time-boxed and approval-gated. The county holds
  P1, so that is an upgrade path rather than a property of the design today — worth revisiting at the
  next licensing review, because it is the one control neither cloud's plain RBAC offers.
