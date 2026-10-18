# Distributed license signing

License entitlements use asymmetric v2 signatures so cloud and edge installations can keep different Laravel `APP_KEY` values. `APP_KEY` remains local and is used only for Laravel encryption and transitional v1 HMAC verification.

## Roles

- The shared cloud **issuer** stores a private signing key and its public key.
- Edge installations and centrally licensed standalone installations are **verify-only** and store only the trusted public-key ring.
- A standalone installation may be an issuer only when it is deliberately allowed to authorize its own licences; configure that explicitly and protect its private key locally.
- Never copy the issuer private key to an edge database, edge `.env`, edge backup, or sync payload. The issuer host keeps it in its protected secret store (or issuer-only `.env`) and in a separately encrypted, access-controlled issuer backup.

OpenSSL SHA-256 with an RSA or EC PEM key is the supported default because it works in the bundled XAMPP PHP runtime. Ed25519 is also supported when the Sodium PHP extension is available; select it with `LICENSE_SIGNATURE_ALGORITHM=ed25519` and supply base64 raw keys.

## Generate an OpenSSL key pair

Generate the keys on the issuer host and protect the private file:

```text
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:3072 -out license-private.pem
openssl pkey -in license-private.pem -pubout -out license-public.pem
```

Store PEM material in environment variables either with escaped newlines or as `base64:<base64-of-the-complete-PEM>`. Set the same stable key ID on the issuer and in every verifier keyring.

Issuer configuration:

```text
LICENSE_SIGNING_MODE=issuer
LICENSE_SIGNATURE_ALGORITHM=openssl-sha256
LICENSE_SIGNATURE_KEY_ID=primary-2026
LICENSE_SIGNATURE_PRIVATE_KEY=base64:...
LICENSE_SIGNATURE_PUBLIC_KEY=base64:...
```

Edge or centrally licensed standalone configuration:

```text
LICENSE_SIGNING_MODE=verify_only
LICENSE_SIGNATURE_KEY_ID=primary-2026
LICENSE_SIGNATURE_PUBLIC_KEYS={"primary-2026":"base64:..."}
LICENSE_LEGACY_HMAC_SIGN=false
LICENSE_ALLOW_UNSIGNED_LEGACY=false
LICENSE_AUTO_UPGRADE_UNSIGNED=false
LICENSE_LEGACY_APP_KEY_FALLBACK=false
```

`LICENSE_SIGNATURE_PUBLIC_KEYS` may contain both the current and previous public keys during rotation. New signatures use `LICENSE_SIGNATURE_KEY_ID`; existing signatures select their verifier by their stored key ID. Remove an old public key only after all licenses signed by it have been renewed or re-signed.

## Compatibility rollout

The v2 migration is additive: legacy payload/signature columns are retained. A record containing any v2 material is verified only as v2 and can never fall back to HMAC. The legacy switches in `.env.example` keep existing standalone installations working during transition. Disable legacy verification and the `APP_KEY` fallback after all licenses have valid v2 signatures.

After deploying the migration and configuring the issuer key, backfill existing rows centrally:

```text
php artisan licenses:resign-entitlements
```

Unsigned records can be upgraded only on an issuer. A verify-only edge or standalone installation rejects them. Offline v2 export tokens carry the same signed entitlement and can therefore be imported without exposing a private key. The normal compact `XXXX-XXXX-XXXX-XXXX` activation flow still expects the signed license row to have synchronized to the edge first. A never-connected verifier can import the signed token through `POST /api/pos/licenses/renew-offline`; the standalone installer package below avoids manually copying that long token. A general-purpose QR activation flow is not implemented.

## Provision a fully local installation

A fresh `standalone` database does not yet contain the Central company UUID, so
the compact key alone cannot bootstrap it. A platform administrator can export
a short-lived signed setup file from Central:

```text
POST /api/companies/{company}/standalone-provisioning-package
```

The `.salexa-company` file contains only the signed company/branch identity and
a v2 licence entitlement. It never contains the issuer private key, Laravel
`APP_KEY`, passwords, node credentials, terminal credentials, or raw database
credentials. Transfer the file to the local server and, after migrations, run:

```text
php artisan salexa:standalone-import C:\path\CompanyCode.salexa-company
```

The target must use `SERVENEST_DEPLOYMENT_MODE=standalone`,
`LICENSE_SIGNING_MODE=verify_only`, and the trusted Central public-key ring.
The importer preserves the exact Central company and branch UUIDs, rejects a
database assigned to another company, provisions branch warehouse defaults,
and records the package identifier and hash without logging the embedded
entitlement. `SERVENEST_STANDALONE_PACKAGE_TTL_HOURS` controls how long an
unimported setup file remains usable; the imported licence retains its own
separately signed expiry date.
