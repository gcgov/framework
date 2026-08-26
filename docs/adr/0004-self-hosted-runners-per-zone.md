# One self-hosted runner per Zone, on a dedicated host, without Docker access

Each Zone has its own ephemeral self-hosted GitHub Actions runner, registered to the `gcgov/deploy`
Ops Repo only, living on a small host of its own with **no Docker socket**. It deploys to the
Application hosts over SSH using a forced-command key that can run only `deploy <app> <digest>`.

## Considered Options

An internal-only Zone cannot accept inbound SSH from GitHub, so push-based deployment does not reach
it. A pull agent on each host watching the registry would work but loses the health gate and the
"did my deploy land" answer, and would give internal Applications a second deployment mechanism to
debug. Runners let Actions drive every Zone identically over outbound connections only.

The isolation choices exist because a self-hosted runner executes workflow code inside the Zone:

- **Ops Repo only.** Registering to app repositories would give the contributors of thirty
  repositories code execution inside the network. Applications fire a `repository_dispatch` at the
  Ops Repo with an image digest; the Ops Repo runs its own trusted workflow. This cannot use
  `workflow_call`, which executes in the caller's context and would erase the boundary.
- **Ephemeral.** A persistent runner lets one poisoned job leave something behind for the next.
- **No Docker socket.** Access to the socket is root on that host, with no partial version. Keeping
  the runner off the Application hosts means runner compromise is bounded by what the forced command
  permits, rather than being equivalent to owning the Zone.

## Consequences

- Three additional small hosts to build and patch.
- The image digest arriving by dispatch is untrusted input and must be validated, not interpolated.
- Deploys are gated on a protected GitHub Environment, which doubles as the deploy approval.
