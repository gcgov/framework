# Certificates come from Let's Encrypt over DNS-01, on one registered domain every Zone shares

Every Zone's Traefik obtains certificates from Let's Encrypt using the DNS-01 challenge against
Cloudflare. All three Zones serve names under `garrettcountymd.gov`, so all three hold a Cloudflare
token with `Zone:DNS:Edit` on that one domain. Per-Zone token scoping — which the Ops Repo's own
follow-up list asked for — is not achievable in this shape. It is accepted for the pilot rather than
pretended, on a condition that is enforced rather than remembered.

## Considered Options

DNS-01 is not itself a choice. The internal-only Zone has no inbound path from the internet and so
cannot complete an HTTP-01 challenge; using DNS-01 everywhere means one ACME mechanism to understand
rather than two that drift apart.

What was genuinely open is how to stop a credential in one Zone from being a credential over another
Zone's names. A Cloudflare API token scopes to a *registered domain* — there is no per-subdomain
record filtering short of Enterprise subdomain zones. Three ways out were weighed:

- **An internal CA for the internal Zone.** Certificates for `internal-apps`, `swagger` and
  `netops-tools` would come from AD CS and be provisioned like any other Secret, so the internal host
  would hold no Cloudflare token at all and its hostnames would never be published. Rejected because
  it reintroduces exactly the second certificate mechanism that DNS-01-everywhere exists to avoid,
  and there is no internal PKI stood up to carry it. Worth reopening if one is built, since it is the
  only option that removes the internal Zone's token entirely.
- **A wildcard certificate per Zone.** One `*.garrettcountymd.gov` certificate would keep individual
  hostnames out of Certificate Transparency. Rejected outright — and it is the option that looks most
  attractive while being the worst available. A wildcard on the internal host is a certificate valid
  for `www.garrettcountymd.gov`, so a compromise of the least-exposed host yields a credential for
  the most-exposed name.
- **`_acme-challenge` delegation.** Each Zone's challenge records are CNAMEd into a DNS zone of its
  own, so its token can be scoped to that zone and to nothing that serves traffic. This is the
  correct end state. It is deferred, not dismissed.

Accepting the shared scope is defensible only because the pilot is a single Zone. While `bridge` is
the only Zone provisioned there is exactly one token and the cross-Zone capability does not exist. It
comes into being the moment a second Zone is provisioned — which is why the condition is a mechanism
and not a sentence. `internal` and `isolated` carry an unresolved placeholder in
`ZONE_ACME_DELEGATION`, and `bin/provision` already refuses to send any file containing a placeholder
to a host. Whoever provisions Zone 2, months from now and with none of this context, has to resolve
it deliberately to get past it.

## Consequences

- **A DNS-edit credential exists in every Zone, and they are equal in power.** Concretely: a
  compromise of `c-web-isolated`, the most exposed of the three, yields a token that can repoint
  `payments-api` and `dmr` over in `bridge` and issue valid certificates for them. Three separate
  tokens are still issued, so one can be revoked without disturbing the others and Cloudflare's audit
  log tells them apart — but that limits what a revocation costs, it does not prevent the capability.
- **Internal hostnames become public and permanent.** `internal-apps`, `swagger` and `netops-tools`
  do not resolve in public DNS at all today. DNS-01 publishes every name it issues for to Certificate
  Transparency, where it stays searchable indefinitely. Accepted: hostnames are not secrets, and
  obscurity that is depended on but not maintained is worse than obscurity that has been written off.
  It is why `paloalto-tools` was renamed to `netops-tools` first — a hostname that names a vendor
  tells a reader which CVE feed to watch, and the rename is free before first issuance and impossible
  after it.
- **All three Zones share one Let's Encrypt rate limit,** which is counted per registered domain.
  Delegation would not change this, because the limit follows the certificate's names rather than
  where the challenge was answered. Losing one Zone's `acme` volume therefore competes with every
  other Zone's renewals, which is what turns backing that volume up from a nicety into a requirement.
- **`garrettcounty.org` is retired to a Cloudflare redirect** rather than served from an origin, so no
  second registered domain enters any token's scope. Every domain that reaches an origin is a domain
  whose certificate and token scope somebody maintains indefinitely.
- **Adding an Application now has a DNS step,** and gains a second one once delegation lands. The
  `_acme-challenge` CNAME is easy to forget and its absence surfaces only as a certificate that never
  issues, so it belongs in the runbook rather than in anyone's memory.
- **`ZONE_ACME_EMAIL` is a registration contact, not a monitoring backstop.** Let's Encrypt stopped
  sending expiry notification emails in June 2025, so a renewal that silently stops working surfaces
  as an outage unless something else watches for it.
