# CloudHost247 Passkey addon

Native WHMCS addon work for Passkey/WebAuthn support. It reuses WHMCS identities, sessions, and database connectivity; it is not a standalone application or an alternate account system.

## Phase 3 through Phase 11 status

**Phases 3–10 (WebAuthn core, fail-closed handoff, credential management, policy/recovery/rate-limit/audit, host-verified linking, notification orchestration, retention maintenance, authorized policy management) plus Phase 11's native WHMCS HTTP boundary are implemented and tested. Client and administrator Passkey login, registration, management, step-up confirmation, Passkey-assisted password reset, notifications, and the admin dashboard are connected end to end, still disabled by default.** Existing WHMCS password login, 2FA, recovery, sessions, and account records remain untouched and stay available as secure fallbacks.

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

The Phase 4 boundary deliberately does **not** guess at WHMCS internals or copy a proprietary/reference login implementation. A deployment must supply and staging-test the two host adapters against its installed WHMCS version before any login surface is enabled. Client-user login is also intentionally rejected until its WHMCS session and authorization semantics are separately audited.

Phase 5 adds:

- `PasskeyCredentialManagementService` for authenticated enrollment, masked credential listing, device-name changes, disable/enable, and revocation.
- `CredentialManagementRepository` with mandatory identity and active-session scope on every read or mutation; client credentials cannot be managed through an administrator identity and vice versa.
- Explicit active-credential limits, server-side registration metadata validation, and last-credential protection when password fallback is disabled.
- Real library-backed enrollment coverage for an existing client identity, while leaving registration and management UI disabled until a WHMCS host adapter and CSRF-protected surface are staged.

Phase 6 adds:

- `PasskeyPolicyResolver`, which combines the global client/administrator policy with existing per-identity overrides. Live temporary exemptions fail closed; expired exemptions return to the configured audience policy. The resolver is applied to the Phase 4 coordinator and Phase 5 management service without changing WHMCS sessions or password fallback.
- `PasskeyRateLimiter`, which uses fixed-window, optimistic-concurrency buckets keyed by a domain-separated SHA-256 principal hash. Raw IPs, emails, session identifiers, and other principals are never persisted. `PasskeySecurityService` exposes the limiter as a pre-ceremony host boundary and records `rate_limit.exceeded` events when an audit repository is supplied.
- `PasskeyRecoveryGrantService`, which creates short-lived, single-use grants bound to the active WHMCS session, identity scope, and optional internal challenge. Only the token hash, session-binding hash, expiry, and consumption timestamp are stored. The raw one-time value is delivered only through an explicit host callback; the service does not create a session, reset a password, replace WHMCS recovery, or return the token from its public result.
- `SecurityEventRepository`, an append-only persistence boundary that reuses the existing allowlist and rejects raw ceremony, credential, session, password, biometric, and secret fields. Grant issuance/consumption and rate-limit denials can be audited without token metadata.

Phase 7 adds:

- `ExternalIdentityClaims`, a strict value object for the verified Microsoft Entra subject/tenant reference supplied by an explicit host-owned OIDC adapter. It accepts no ID/access/refresh tokens, profile data, emails, passwords, or client secrets, and requires fresh bounded claims.
- `ExternalIdentityLinkService` and `ExternalIdentityRepository` for identity/session-scoped link, idempotent relink, masked listing, and append-preserving unlink operations. The link is keyed by a provider/tenant/subject hash and cannot be attached to two WHMCS identities.
- Required `entra.link`/`entra.unlink` confirmation challenges bound to the current WHMCS session and identity. Challenge consumption, link mutation, and audit event append occur in one transaction. No external login, WHMCS session creation, OAuth verification, or production endpoint is enabled.
- Phase 7 audit events use the existing `entra.linked` and `entra.unlinked` allowlist types without copying external subjects or token material into metadata. The existing settings reader also now distinguishes secret-shaped keys from safe policy keys such as `password_fallback`.

Phase 8 adds:

- `UserPreferencesRepository` and `PasskeyNotificationService` for identity/session-scoped, opt-in login and security-event notification preferences. Missing preferences default to disabled, and updates accept only the two boolean flags.
- `SecurityNotification`, a redacted host-boundary envelope that contains only an existing WHMCS identity reference, an allowlisted event type, and safe metadata. No email address, profile data, session identifier, token, signature, or password is accepted or persisted.
- An explicit callable delivery sink. The addon does not send mail, create a notification endpoint, resolve contact data, or create a parallel notification/session architecture. Disabled preferences suppress delivery; sink failures are not treated as successful delivery.

Phase 9 adds:

- `PasskeyMaintenanceService`, a bounded, host-invoked cleanup boundary for expired challenges, reset grants, rate-limit buckets, and security events beyond the configured retention period. Each table is capped by a validated batch limit and cleanup runs transactionally.
- Safe validation of `event_retention_days` and `challenge_retention_hours`. Cleanup returns only timestamps, configured bounds, per-table counts, and a total; it never returns row IDs, token hashes, challenge values, or event payloads.
- Deliberate preservation of credentials, external-identity history, notification preferences, policy rows, and recent audit history. The addon does not register a scheduler, background worker, maintenance route, or production endpoint.

Phase 10 adds:

- `PasskeyPolicyAdministrationService` and `IdentityPolicyAdministrationRepository` for explicitly authorized administrator changes to an existing client or administrator's Passkey policy. The service supports global-policy fallback, optional/required policy, and bounded future temporary disables without deleting the scoped policy row.
- `PasskeyPolicyAdminAuthorizationInterface` with an explicit callback adapter. The host must resolve the current WHMCS administrator and target identity and authorize the operation; client-user scope, missing/disabled identities, non-administrators, and unauthorized operations fail closed.
- Transactional policy mutation plus `policy.changed` audit append. Invalid policy input, expired temporary dates, secret audit metadata, or audit persistence failure leaves the prior policy unchanged. No password, session, token, contact, or WHMCS account record is copied.

Phase 11 adds:

- `PasskeyHttpKernel`, the client JSON boundary: discoverable login ceremonies, authenticated registration/management, preferences, recent activity, step-up confirmation APIs, and enumeration-safe Passkey-assisted password reset with server-side recovery grants.
- `WhmcsNativeIdentityProvider` and `WhmcsNativeAuthBridge`, which resolve current WHMCS account state and establish the standard WHMCS session shape with a regenerated session ID. Existing WHMCS 2FA is never bypassed: 2FA-enabled accounts receive a `two_factor` next step instead of a session (documented Option C).
- `PasskeyActionConfirmationService` for reusable step-up confirmation: 120-second one-use tickets bound to user, action, session, RP ID, and origin, consumed only after a fresh verified assertion.
- `WhmcsMailNotificationSink` (`localAPI` delivery, double-gated by global switch and per-user preference), `PasskeyDiagnostics` (fail-closed readiness checks), `Client`/`Admin` controllers with Smarty templates and dependency-free WebAuthn JS, native WHMCS hooks (login buttons, navigation, daily maintenance), and migration `0003` for notification, sensitive-action, and Entra settings.

Private keys and biometrics are never received or stored. Raw challenges and session IDs are not stored in the database; only their hashes are stored. Attestation objects, assertion bodies, signatures, and secret key material are not persisted. WebAuthn assertions are verified by the library; the coordinator only passes the resulting existing identity to the handoff. Recovery grants back the password-reset flow with server-side tokens and never replace standard WHMCS recovery. External identity linking receives only claims already verified by the host adapter and never performs OAuth cryptography itself; interactive Entra sign-in reports `CONFIGURATION_REQUIRED` until a dedicated OIDC phase lands. The integration fixture generates a temporary test key in memory; no test key or credential secret is checked in.

## Dependency and security gate

The audited compatibility floor is PHP 7.4. `composer.json` pins `web-auth/webauthn-lib` 3.3.12 and declares Nyholm PSR-7 1.8+, plus the runtime extensions JSON, mbstring, OpenSSL, SimpleXML, and BCMath. BCMath is required by the pinned ASN.1 dependency during key/signature processing. The committed `composer.lock` resolves 21 Composer packages against PHP 7.4.33; `vendor/` is intentionally not committed.

The v3.3.12 package metadata declares PHP 7.2+. Its built-in RP-ID suffix check is not an exact origin check, so the addon independently compares the complete configured origin before calling the library. The official [CVE-2026-30964 advisory](https://github.com/web-auth/webauthn-framework/security/advisories/GHSA-f7pm-6hr8-7ggm) lists versions `>=5.2.0,<5.2.4` as affected; 3.3.12 is outside that stated range. The lock also uses Symfony Process 5.4.51, above the fixed floor for [CVE-2026-24739](https://github.com/advisories/GHSA-r39x-jcww-82v6). A GitHub Advisory Database exact-version query on 2026-10-07 found no matching advisories for the 21 locked packages. This is not a guarantee against future or unreported vulnerabilities.

The older WebAuthn 3.x line and the PHP 7.4 runtime are legacy choices; PHP 7.4 is end-of-life. **Keep authentication enabled only after the deployment owner verifies RP ID, origins, and HTTPS on staging, registers a Super Admin Passkey, and confirms recovery; prefer a maintained PHP and WebAuthn line when the platform can move to PHP 8.2+.** Client-user login handoff, combined Passkey+2FA in one flow, interactive Entra sign-in, and granular staff roles remain scheduled follow-ups (see `docs/PASSKEY.md`).

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

The test and lint runners honor `PHP_WASM_VERSION` (default `8.3`). Both PHP-WASM 8.3 and 7.4 pass **200 core checks, including Phase 6, 7, 8, 9, 10, and 11 controls, and 13 library-backed registration/assertion plus Phase 4/5 integration checks**, with zero failures. The integration tests exercise a real `none`-attestation registration, ES256 assertion verification, discoverable user-handle normalization, stored counter updates, replay rejection, corrupted-signature rejection, client/admin isolation, a real Phase 4 handoff, and a real Phase 5 authenticated enrollment. The core suite also covers expiring policy overrides, hashed fixed-window limits, one-use session-bound recovery grants, redacted audit events, host-verified Entra link/relink/unlink scope, opt-in notification suppression/redaction, bounded retention cleanup that preserves recent and durable records, authorized administrator policy changes with transactional audit rollback, step-up confirmation tickets (issue/consume/replay/session/expiry/identity binding), native bridge audience isolation and unavailable-platform handling, fail-closed diagnostics, trusted request-context handling, and CSRF fallback verification. The fixture key is generated at test time; verification is performed by the PHP WebAuthn library. Lint passes on **81 PHP files** under both runtimes. Composer validation and locked platform requirements also pass under PHP 8.3.8 with the declared extensions.
