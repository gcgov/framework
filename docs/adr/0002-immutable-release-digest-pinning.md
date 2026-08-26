# Deployment ships an immutable Release, pinned by digest

A Release is a container image built once by CI, pushed to GHCR, and identified in production by
**content digest**. Deploying and rolling back are the same operation: point a host at a different
digest and restart. Nothing is built, resolved, or updated on a production host.

## Considered Options

v6 deployed by running `gf deploy` on the server: `git pull`, `git checkout tags/X`, `composer
update`. That resolves dependencies in production at deploy time, which means two hosts running
"the same tag" can be running different code, and rollback requires a second dependency resolution
that may not reproduce the earlier one.

A moving tag (`:latest`, `:prod`) was rejected for the same reason in miniature: it makes "what is
running right now" unanswerable without trusting a mutable pointer.

## Consequences

- `gf deploy` is deleted. Deployment is a GitHub Actions workflow plus a compose file, not a PHP
  command shipped inside the artifact it deploys.
- `composer.lock` must be committed; without it the image is not reproducible.
- Rollback is re-pinning a prior digest — seconds, no rebuild.
- Anything an Application writes to its own filesystem is lost on every deploy. This is why logging
  goes to stderr rather than `logs/*.log`, and why JWT signing keys are provisioned onto the host
  rather than living in the application tree.
