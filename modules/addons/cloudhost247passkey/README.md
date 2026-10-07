# CloudHost247 Passkey addon

Native WHMCS addon work for Passkey/WebAuthn support. It reuses WHMCS identities, sessions, and database connectivity; it is not a standalone application or an alternate account system.

## Phase 3 status

**The WebAuthn core and its library-backed cryptographic test gate now pass. Passkey login remains disabled and is not wired into WHMCS; Phase 4 has not started.** Existing WHMCS password login, 2FA, recovery, sessions, and account records are untouched.

The core now includes:

- A forward-only migration for serialized public credential sources and opaque WebAuthn user handles; handles map to existing client, client-user, or administrator IDs and never create duplicate accounts.
- A `web-auth/webauthn-lib` persistence adapter and ceremony service for registration and assertion verification. Signature, authenticator-data, RP-ID-hash, attestation, and counter validation are delegated to the library; this addon does not implement cryptographic verification.
- Strict HTTPS/RP/origin configuration, exact client-data origin comparison (scheme, host, and port), cross-origin rejection, short-lived one-use challenges bound to the active PHP session, identity scope, RP ID, and origin.
- `none` attestation only, stable 32-byte random user handles, per-client/admin credential isolation, and counter updates protected by optimistic concurrency checks.
- A compatibility adapter that converts browser JSON's canonical base64url `userHandle` to the standard Base64 form expected by the pinned v3 library.

Private keys and biometrics are never received or stored. Raw challenges and session IDs are not stored in the database; only their hashes are stored. Attestation objects, assertion bodies, signatures, and secret key material are not persisted. Authentication assertions do not create WHMCS sessions. The integration fixture generates a temporary test key in memory; no test key or credential secret is checked in.

## Dependency and security gate

The audited compatibility floor is PHP 7.4. `composer.json` pins `web-auth/webauthn-lib` 3.3.12 and declares Nyholm PSR-7 1.8+, plus the runtime extensions JSON, mbstring, OpenSSL, SimpleXML, and BCMath. BCMath is required by the pinned ASN.1 dependency during key/signature processing. The committed `composer.lock` resolves 21 Composer packages against PHP 7.4.33; `vendor/` is intentionally not committed.

The v3.3.12 package metadata declares PHP 7.2+. Its built-in RP-ID suffix check is not an exact origin check, so the addon independently compares the complete configured origin before calling the library. The official [CVE-2026-30964 advisory](https://github.com/web-auth/webauthn-framework/security/advisories/GHSA-f7pm-6hr8-7ggm) lists versions `>=5.2.0,<5.2.4` as affected; 3.3.12 is outside that stated range. The lock also uses Symfony Process 5.4.51, above the fixed floor for [CVE-2026-24739](https://github.com/advisories/GHSA-r39x-jcww-82v6). A GitHub Advisory Database exact-version query on 2026-10-07 found no matching advisories for the 21 locked packages. This is not a guarantee against future or unreported vulnerabilities.

The older WebAuthn 3.x line and the PHP 7.4 runtime are legacy choices; PHP 7.4 is end-of-life. **Keep authentication disabled in production until the deployment owner accepts the runtime/dependency support policy or upgrades to a maintained PHP and WebAuthn line.** If the platform can move to PHP 8.2+, prefer a maintained fixed WebAuthn release instead. No later project phase has started.

`WebAuthnService` fails closed if its Composer dependencies or required runtime extensions are missing. The addon still defaults to `service_enabled=0`, an unset RP ID, and an empty origin list. HTTPS state supplied to the config factory must come from trusted WHMCS/server TLS state, never an untrusted forwarding header.

## Owned storage

The forward-only migrations create only `mod_ch247pk_*` tables for credentials, challenges, security events, settings, per-identity policies and notification preferences, reset grants, rate limits, external identity mappings, opaque user handles, and the migration ledger. They do not create duplicate WHMCS identity/billing tables or alter WHMCS-owned records. Credential hashes are unique across identity types. Deactivation preserves credentials, policy, and audit history. SQLite is used only for isolated tests; production PDO comes from WHMCS Capsule.

## Checks

From this directory, with Composer available:

```sh
npm ci
composer install --no-dev
npm test
npm run lint
```

The test and lint runners honor `PHP_WASM_VERSION` (default `8.3`). Both PHP-WASM 8.3 and 7.4 pass **91 core checks and 11 library-backed registration/assertion integration checks**, with zero failures. The integration tests exercise a real `none`-attestation registration, ES256 assertion verification, discoverable user-handle normalization, stored counter updates, replay rejection, corrupted-signature rejection, and client/admin credential isolation. The fixture key is generated at test time; verification is performed by the PHP WebAuthn library. Lint passes on **32 PHP files** under both runtimes. Composer validation and locked platform requirements also pass under PHP 8.3.8 with the declared extensions.
