# Deployment Secrets are encrypted with KMS keys in a GCP project of their own

The `sops` keyring that encrypts the Ops Repo — one key per Zone — lives in a GCP project created for
operations and holding nothing else. It is deliberately not the project holding the Cloud KMS master
key for MongoDB queryable encryption.

## Considered Options

Sharing the existing project is the smaller change and the obvious one. The key material is the same
kind, the administration is the same, and per-key IAM would still stop an operator's decrypt on
`internal` from reaching `isolated`. The argument against it is not about the keys at all. It is about
who holds credentials inside the project.

The queryable-encryption key is used by an Application at runtime, so its service-account credential
file sits **on an Application host**. The `sops` keys are used by operators at a workstation and, per
ADR 0003, must never be reachable from a host or from CI. One project for both means a compromised
Application host holds a credential inside the same project as the keys that decrypt every Zone's
Secrets. Per-key bindings contain that today; a project-level binding, or `roles/cloudkms.admin`
granted in eighteen months to solve something unrelated, does not. The separation costs one more
project to administer and removes a class of mistake that stays invisible until it matters.

Access is granted through a Google group per Zone — `sops-internal@`, `sops-bridge@`,
`sops-isolated@` — each holding `roles/cloudkms.cryptoKeyEncrypterDecrypter` on its own key, rather
than through individual principals. Offboarding is then one membership removal instead of three IAM
edits that can be half-finished, and Cloud Audit Logs still name the individual who decrypted, so
nothing is lost from the audit trail. All three groups start with the same members: the per-key split
is what preserves the ability to narrow access later, which costs nothing now and cannot be
retrofitted once Secrets are encrypted to a single shared key.

## Consequences

- **Nothing automated holds `cryptoKeyEncrypterDecrypter`** — no service account, no CI identity, no
  host. ADR 0003 already required this; the separate project makes it checkable by reading one
  project's IAM rather than reasoning about which bindings in a shared project are for what.
- **Two GCP projects to administer,** with the usual risk that the less-used one drifts: its billing,
  its audit log retention and its own IAM go unwatched between incidents. `offboard-an-operator.md`
  is what keeps it honest, because it is the one routine that has to touch both.
- **Group membership is the record of who could decrypt what,** so it has to be read before it is
  revoked. Removing someone from `sops-bridge@` also removes the evidence of which Zones' Secrets now
  need rotating — and a rotation believed complete is worse than one never started, because it stops
  anyone looking again.
