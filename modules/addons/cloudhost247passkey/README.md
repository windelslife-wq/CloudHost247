# CloudHost247 Passkey addon

Native WHMCS addon work for Passkey/WebAuthn support. It reuses WHMCS identities, sessions, and database connectivity; it is not a standalone application or an alternate account system.

## Phase 3 and Phase 4 status

**Phase 3 WebAuthn core is complete and its library-backed cryptographic gate passes. Phase 4 now adds a tested, opt-in authentication handoff boundary; Passkey login remains disabled by default and no production login endpoint is enabled by this addon.** Existing WHMCS password login, 2FA, recovery, sessions, and account records remain untouched.

Phase 3 includes:

- A forward-only migration for serialized public credential sources and opaque WebAuthn user handles; handles map to existing client, client-user, or administrator IDs and never create duplicate accounts.
- A `web-auth/webauthn-lib` persistence adapter and ceremony service for registration and assertion verification. Signature, authenticator-data, RP-ID-hash, attestation, and counter validation are delegated to the library; this addon does not implement cryptographic verification.
- Strict HTTPS/RP/origin configuration, exact client-data origin comparison (scheme, host, and port), cross-origin rejection, short-lived one-use challenges bound to the active PHP session, identity scope, RP ID, and origin.
- `none` attestation only, stable 32-byte random user handles, per-client/admin credential isolation, and counter updates protected by optimistic concurrency checks.
- A compatibility adapter that converts browser JSON's canonical base64url `userHandle` to the standard Base64 form expected by the pinned v3 library.

Phase 4 adds:

- `PasskeyLoginCoordinator`, which accepts only a library-backed assertion verifier, validates the requested client or administrator audience, resolves the current existing WHMCS identity, and delegates the final session/2FA transition to a host-owned adapter.
- `WhmcsIdentityProviderInterface` and `WhmcsAuthBridgeInterface` boundaries with explicit callback adapters. The identity callback must re-check the current WHMCS account status; the auth callback must use WHMCS's supported session and 2FA mechanism.
- Strict client/admin audience isolation, discoverable-login scope checks, explicit `optional`/`required` policy gates, and preservation of password fallback unless the separately configured policy disables it.
- A browser-safe handoff result that contains only the next step. Session IDs, session tokens, passwords, cookies, assertion JSON, raw challenges, and 2FA secrets never cross the integration boundary.
- A per-request registry exposed through `cloudhost247passkey_register_auth_integration()`. The registry fails closed when a deployment has not supplied both host adapters; it never writes `$_SESSION` or synthesizes a WHMCS login.

The Phase 4 boundary deliberately does **not** guess at WHMCS internals or copy a proprietary/reference login implementation. A deployment must supply and staging-test the two host adapters against its installed WHMCS version before any login surface is enabled. Client-user login is also intentionally rejected until its WHMCS session and authorization semantics are separately audited. No registration or login UI was enabled in this phase.

Private keys and biometrics are never received or stored. Raw challenges and session IDs are not stored in the database; only their hashes are stored. Attestation objects, assertion bodies, signatures, and secret key material are not persisted. WebAuthn assertions are verified by the library; the coordinator only passes the resulting existing identity to the host-owned handoff. The integration fixture generates a temporary test key in memory; no test key or credential secret is checked in.

## Dependency and security gate

The audited compatibility floor is PHP 7.4. `composer.json` pins `web-auth/webauthn-lib` 3.3.12 and declares Nyholm PSR-7 1.8+, plus the runtime extensions JSON, mbstring, OpenSSL, SimpleXML, and BCMath. BCMath is required by the pinned ASN.1 dependency during key/signature processing. The committed `composer.lock` resolves 21 Composer packages against PHP 7.4.33; `vendor/` is intentionally not committed.

The v3.3.12 package metadata declares PHP 7.2+. Its built-in RP-ID suffix check is not an exact origin check, so the addon independently compares the complete configured origin before calling the library. The official [CVE-2026-30964 advisory](https://github.com/web-auth/webauthn-framework/security/advisories/GHSA-f7pm-6hr8-7ggm) lists versions `>=5.2.0,<5.2.4` as affected; 3.3.12 is outside that stated range. The lock also uses Symfony Process 5.4.51, above the fixed floor for [CVE-2026-24739](https://github.com/advisories/GHSA-r39x-jcww-82v6). A GitHub Advisory Database exact-version query on 2026-10-07 found no matching advisories for the 21 locked packages. This is not a guarantee against future or unreported vulnerabilities.

The older WebAuthn 3.x line and the PHP 7.4 runtime are legacy choices; PHP 7.4 is end-of-life. **Keep authentication disabled in production until the deployment owner accepts the runtime/dependency support policy, supplies and tests the WHMCS adapter, or upgrades to a maintained PHP and WebAuthn line.** If the platform can move to PHP 8.2+, prefer a maintained fixed WebAuthn release instead. Phase 5 and later have not started.

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

The test and lint runners honor `PHP_WASM_VERSION` (default `8.3`). Both PHP-WASM 8.3 and 7.4 pass **106 core checks and 12 library-backed registration/assertion plus Phase 4 coordinator checks**, with zero failures. The integration tests exercise a real `none`-attestation registration, ES256 assertion verification, discoverable user-handle normalization, stored counter updates, replay rejection, corrupted-signature rejection, client/admin isolation, and a real library assertion through the Phase 4 handoff coordinator. The fixture key is generated at test time; verification is performed by the PHP WebAuthn library. Lint passes on **45 PHP files** under both runtimes. Composer validation and locked platform requirements also pass under PHP 8.3.8 with the declared extensions.
