# Production secrets never decrypt in CI, and hosts hold no decryption key

Secrets live SOPS-encrypted in the `gcgov/deploy` Ops Repo, encrypted to a **GCP KMS key per Zone**
plus one offline age key held as break-glass. An operator decrypts on their own workstation and
writes the plaintext to the host as files under `/run/secrets` — a **Provisioning** step deliberately
separate from deploying. GitHub Actions never decrypts anything, and no host holds a key that could.

## Considered Options

A root-owned `.env` per host was simpler but keeps every secret in the process environment, visible
through `docker inspect` and `/proc/<pid>/environ`. Holding the SOPS key as an Actions secret would
have let CI decrypt, putting every production credential into GitHub's blast radius and into runner
memory — which would have made SOPS strictly worse than the `.env` it replaced, since the ceremony
would be there without the isolation. Pure age keyfiles were rejected because offboarding becomes a
re-encryption exercise with no record of what the departing operator ever decrypted; KMS makes it an
IAM revocation against an audit log. KMS *alone* was rejected because it puts a network round trip to
Google on the critical path for restarting a container.

## Consequences

- The Ops Repo's read access is equivalent to access to every credential it has ever held, because
  `git log -p` exposes historical values. Encryption protects the repository's contents, not its
  history from its own readers.
- Rotating a secret is two deliberate steps (provision, then deploy) rather than one automatic one.
- CI cannot run tests that need real credentials. Integration tests use throwaway ones.
- Offboarding is revoke **and** rotate. Credentials are therefore scoped per Application per Zone,
  with a `-g{n}` generation suffix so old and new can coexist during a rotation.
