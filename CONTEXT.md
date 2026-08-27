# gcgov/framework

The domain language of the framework itself and of the applications built on it. This file is a
glossary: it fixes what each term means so that code, documentation, and conversation use one word
per concept. It is not a specification — see `README.md`, `readme/`, and `docs/adr/` for those.

## Language

### Applications and extensions

**Application**:
A deployable REST API (optionally server-rendered) built on the framework, living in its own
repository and depending on the framework as a library.
_Avoid_: project, site, instance, consumer

**Framework Service**:
An optional part of the framework that contributes routes, controllers and an auth guard to an
Application when the Application enables it in the `services` section of its Unified Config. A
Framework Service ships inside the framework; it is not separately installable.
_Avoid_: plugin, module, extension, package

**Provider**:
One of the ways the authentication Framework Service can establish an identity — a full OAuth server,
or the exchange of a Microsoft token the front end already holds. Exactly one is selected, because
`provider` is a single key.
_Avoid_: driver, strategy, backend, adapter

**Scaffold**:
The one-time act of creating a new Application from the application template.
_Avoid_: setup, bootstrap, generate

### Request handling

**Route**:
A binding of an HTTP method and URL pattern to a controller method, together with the
authentication and role requirements that reaching it implies.

**CLI Route**:
A Route dispatched from the command line rather than from an HTTP request. CLI Routes are never
authenticated.
_Avoid_: command, task, job

**Auth Guard**:
A router's authentication check, run before a Route with authentication enabled is dispatched. An
Application has its own; each Framework Service may add one.
_Avoid_: middleware, filter, interceptor

**Auth User**:
The authenticated identity for the current request, carrying its roles. Populated by an Auth Guard
and absent on unauthenticated Routes.
_Avoid_: current user, principal, session user

**Controller Response**:
The value a controller method returns, describing what to send and how to serialize it. Returning
one is the only way a controller may end a request.
_Avoid_: result, output, payload

### Documents

**Model**:
A document that is stored as a collection in its own right and can be loaded, saved, and deleted
independently.
_Avoid_: entity, record, document class

**Embeddable**:
A document that exists only nested inside a Model or another Embeddable, and is never stored in a
collection of its own.
_Avoid_: sub-document, nested model, value object

**Embedded Copy**:
A duplicate of a Model's data stored inside other documents for read convenience, which the
framework refreshes wherever it appears whenever the original Model is saved.
_Avoid_: join, denormalization, reference, cache

**Typemap**:
The declaration of which class each part of a stored document hydrates into. Typed arrays require
an explicit element type; without one the array cannot be hydrated.

### Configuration and deployment

**Environment**:
A deployment target — local, production — distinguished *only* by the set of variables its
processes are given. Nothing is activated, copied, or selected by name; supplying a different
variable set is what makes an Environment different.
_Avoid_: variant, stage, tier, environment file, profile

**Unified Config**:
The single committed configuration file at an Application's root. It is Environment-invariant: the
same bytes are correct in every Environment.
_Avoid_: app config, environment config, config files, settings file

**Config Reference**:
A placeholder inside the Unified Config naming an environment variable, optionally through a chain
of processors. Every Config Reference is required — an unresolvable one is a startup failure, never
a silent fallback.
_Avoid_: token, placeholder, interpolation, variable expansion

**Secret**:
A configuration value that must never be committed and must never enter a process's environment —
credentials, connection strings, signing keys, API keys.
_Avoid_: credential, sensitive value, private setting

**Secret File**:
The file a Secret is delivered as at runtime, named by a Config Reference rather than carrying the
Secret's value in the environment itself.

**Zone**:
A network isolation boundary, defined by what it can reach and what can reach it. Three exist:
internal-only, public with internal access, and public without. An Application in production runs in
exactly one Zone; a Zone is not an Environment, and the two vary independently.
_Avoid_: server, host, network, tier, segment

**Ops Repo**:
The single private repository describing what runs on every host — encrypted Secrets, production
compose definitions, and the shared proxy stack — organized by Zone. An Application repository never
holds production topology or Secrets.
_Avoid_: infra repo, config repo

**Provisioning**:
Writing decrypted Secrets onto a host. Performed by an operator as a step deliberately separate from
deploying, so that no host holds a decryption key and no deploy needs one.
_Avoid_: secret sync, secret deploy, key distribution

**Release**:
A tagged, immutable build of an Application, identified in production by content digest rather than
by tag or branch. Deploying and rolling back are both the act of pointing a host at a different
Release.
_Avoid_: version, build, deployment

**Zone Key Vault**:
The vault holding the single key that encrypts one Zone's Secrets, and nothing else. Three exist,
one per Zone, deliberately apart from the cloud project holding an Application's data-encryption
keys — those are reachable from a host and these must never be.
_Avoid_: KMS, key store, secrets vault, ops project

**Delegation Zone**:
A DNS zone holding only the ACME challenge records for one Zone, so that Zone's DNS credential can be
scoped to it rather than to a domain that serves traffic.
_Avoid_: ACME zone, challenge domain, validation domain

**Break-glass Key**:
The offline key every file in the Ops Repo is encrypted to in addition to its Zone's key, so that a
total loss of cloud access is still recoverable. Retrieving it obliges replacing it.
_Avoid_: recovery key, backup key, master key

**Escrow Custodian**:
One of the two named people who may retrieve the Break-glass Key from physical escrow, drawn from
different reporting lines so that retrieval crosses a departmental boundary and is witnessed by
someone with no stake in it.
_Avoid_: key holder, key owner, keeper

### Retired language

These terms named real things in v6 and no longer name anything. They are listed so that they are
recognized as history rather than reintroduced.

- **App config / Environment config** — the two configuration files merged into the Unified Config.
- **Environment variant** — a named Environment whose connection details were committed. Removed
  along with the ability to read another Environment's database from a workstation.
- **Scaffolding token** — a marker replaced once at Scaffold time. Replaced by Config References
  and by generated developer environment files.
- **Service namespace registration** — the array of namespace strings an Application returned from
  `\app\app::registerFrameworkServiceNamespaces()` to enable Framework Services. Replaced by the
  `services` section of the Unified Config, so that enabling a service and configuring it are one
  statement. The separately published `gcgov/framework-service-*` packages remain real, but only for
  v6 Applications; the framework conflicts with them.
- **Auth plugin** — either of the two separate authentication packages. There is now one
  authentication Framework Service with two Providers.
