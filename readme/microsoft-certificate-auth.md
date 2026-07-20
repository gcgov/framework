# Microsoft certificate credential authentication

Entra ID app registrations support two kinds of client credentials: **client secrets** and
**certificates**. Client secrets are capped at a 24 month lifetime (the portal recommends less than
12), so every app built on this framework inherits a recurring secret-rotation chore — and an outage
whenever one lapses. Certificates are Microsoft's recommended credential type, have no enforced
maximum validity by default (your tenant's app management policy can cap them), and rotating one
never requires copying a secret string out of the portal: you upload the new public certificate
alongside the old one, deploy the new key pair, then remove the old certificate. Zero downtime.

## How it works

Everywhere a token request would post `client_secret=...`, certificate authentication instead posts:

```
client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer
client_assertion=<JWT>
```

The JWT is built by the app and signed with the certificate's private key (RS256). Its header
carries `x5t` — the base64url encoded SHA-1 hash of the certificate — which tells Entra ID which
uploaded certificate to verify against. Claims: `aud` (the token endpoint receiving the assertion),
`iss` and `sub` (both the client id), `jti` (unique per request), and `nbf`/`iat`/`exp`
(short-lived; 10 minutes). The private key never leaves the server, and each assertion is
single-purpose and short-lived — strictly stronger than a long-lived shared secret.

This works with every grant the framework's ecosystem uses: `client_credentials` (app-only Graph
tokens), `authorization_code` exchange (interactive sign-in), and the on-behalf-of flow (exchanging
a user's token for a downstream token).

## Creating and registering a certificate

A self-signed certificate is sufficient — Entra ID validates possession of the private key, not the
issuer chain.

```bash
openssl req -x509 -newkey rsa:2048 -sha256 -days 730 -nodes \
  -keyout microsoft-auth.key -out microsoft-auth.crt \
  -subj "/CN=myapp-microsoft-auth"
```

Or with PowerShell on Windows:

```powershell
$cert = New-SelfSignedCertificate -Subject "CN=myapp-microsoft-auth" `
  -CertStoreLocation "Cert:\CurrentUser\My" -KeySpec Signature `
  -KeyExportPolicy Exportable -KeyLength 2048 -HashAlgorithm SHA256 `
  -NotAfter (Get-Date).AddYears(2)
Export-Certificate -Cert $cert -FilePath microsoft-auth.cer
```

1. In the [Entra admin center](https://entra.microsoft.com), open **App registrations** → your app →
   **Certificates & secrets** → **Certificates** → **Upload certificate** and upload the public
   certificate (`.crt`/`.cer`/`.pem`) — never the private key.
2. Copy the certificate and private key PEM files to the server, outside the web root — by
   convention `/srv/microsoftCertificates/`, next to the existing `/srv/jwtCertificates/`.
3. Configure `environment.json` (below). Multiple certificates can be registered at once, which is
   what makes rotation seamless.

## Framework configuration

Add the certificate paths to the `microsoft` block of `/app/config/environment.json`. When both
`certificatePath` and `privateKeyPath` are set, the framework uses certificate authentication and
`clientSecret` is ignored (it can be removed):

```jsonc
{
  "microsoft": {
    "clientId": "00000000-0000-0000-0000-000000000000",
    "tenant": "00000000-0000-0000-0000-000000000000",
    "certificatePath": "srv/microsoftCertificates/microsoft-auth.crt",
    "privateKeyPath": "srv/microsoftCertificates/microsoft-auth.key",
    "privateKeyPassphrase": "",
    "driveId": "",
    "fromAddress": ""
  }
}
```

Paths may be absolute or relative to the application root. `privateKeyPassphrase` is only needed if
the key file is encrypted.

## What uses it

* `\gcgov\framework\services\microsoft\clientAssertion` — builds signed client assertions. This is
  the reusable building block for any code (framework, plugin, or app) that talks to a Microsoft
  token endpoint:
  * `clientAssertion::create( ?string $audience )` — assertion from the `environment.json`
    `microsoft` block; `$audience` defaults to the tenant's v2.0 token endpoint.
  * `clientAssertion::createFromParts(...)` — assertion from explicit credentials.
  * `clientAssertion::CLIENT_ASSERTION_TYPE` — the `client_assertion_type` value to post with it.
  * `clientAssertion::getCertificateThumbprint()` / `getCertificateX5t()` — SHA-1 thumbprint as
    portal-style hex / base64url (JWT `x5t` header).
  * `clientAssertion::getDecryptedPrivateKeyPem()` — strips a passphrase for libraries that cannot
    accept one.
* `\gcgov\framework\services\microsoft\auth` (deprecated service, kept working) — both flows are
  certificate-aware when the config is present:
  * `getApplicationAccessToken()` posts a client assertion instead of `client_secret`.
  * The on-behalf-of flow (`getAccessToken()`/`verify()`) passes the certificate to
    `\TheNetworg\OAuth2\Client\Provider\Azure`, which supports certificates natively via its
    `clientCertificatePrivateKey` and `clientCertificateThumbprint` options.

## Consuming apps and plugins

The `environment.json` `microsoft` block is the shared configuration surface, so plugins and apps
pick the certificate up from one place:

* **framework-service-auth-ms-front / framework-service-auth-oauth-server** — wherever these
  exchange an authorization code or perform on-behalf-of against Microsoft with
  `clientSecret`, substitute the client assertion parameters (or the TheNetworg certificate
  options, mirroring `services\microsoft\auth`). Token *validation* against Microsoft JWKS does not
  involve client credentials and needs no change.
* **Hybridauth-based Microsoft sign-in** — Hybridauth's OAuth2 adapter posts `client_secret` in its
  token exchange and has no native assertion support. Subclass the provider and inject
  `tokenExchangeParameters['client_assertion_type'|'client_assertion']` (built by
  `clientAssertion::create()`) before the exchange.
* **andrewsauder/microsoftServices** — builds its own app-only token requests from config values;
  needs the equivalent parameter substitution in that package (the assertion can be supplied by
  this framework's `clientAssertion` service).

## Rotation procedure

1. Generate a new key pair before the current certificate expires.
2. Upload the new public certificate to the app registration — both certificates are now valid.
3. Deploy the new `.crt`/`.key` files and update `environment.json` paths (or overwrite the files
   in place and recycle the app pool).
4. Remove the expired certificate from the app registration.

Unlike secret rotation there is no window where the old credential must be revoked before the new
one is issued, and nothing sensitive transits the portal UI.
