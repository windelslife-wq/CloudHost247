# OVHcloud / SoYouStart — App Cloud integration readiness

**Status:** legacy transport hardened; **no App Cloud `ovhcloud` infrastructure adapter is registered**. The owner selected **read-only existing-service integration**: the SoYouStart WHMCS module retains ordering, renewal and cancellation authority. `ProviderRegistry` continues to report OVHcloud as catalog-only (`adapter_available: false`). App Cloud customer provisioning must not be enabled on the basis of the legacy module alone. This is an integration prerequisite, not a claim of completed OVH VPS provisioning.

## Existing authority

- `modules/addons/soyoustart` and `modules/servers/soyoustart_vps` already own an OVH-specific WHMCS order, provider payment, renewal and VPS lifecycle flow. Their account credentials are read from `mod_soyoustart`, and their service identifiers are stored in WHMCS custom fields. No second OVH credential store or API client has been created.
- A WHMCS server product assigned to `soyoustart_vps` (or any other WHMCS provisioning module) is now **rejected** by App Cloud's product mapping. Ordering the same product in two provisioning systems could create duplicate infrastructure and provider charges.

## Completed security prerequisite

The existing OVH `ApiCall` transport and its public catalog fetch now require a trusted HTTPS host, a valid certificate and hostname, and **no redirect following**. Signed OVH headers are accepted only for the exact OVH API host allowlist, never for public catalog hosts or caller-supplied URLs. The separate legacy Google-token transports also verify TLS and do not follow redirects. `TrustedEndpoint.php` centralizes the outbound URL policy. Offline tests exercise rejection before cURL, signed-header host separation, and the actual cURL TLS/redirect options through local shims. A CA bundle that works in the deployment environment is required; the module must fail rather than disabling verification.

## Additional legacy-module safety work (offline-tested)

The legacy **third-party** product-availability feed no longer uses a checked-in shared HMAC key. Its fixed HTTPS request now requires a deployment-provided `SOYOUSTART_AVAILABILITY_FEED_SECRET` (at least 32 characters), validates TLS/hostname, refuses redirects, uses timeouts, bounds response bytes, and rejects non-200, malformed, missing, or empty regional plan lists. With no secret, the legacy admin product-selection view fails closed rather than silently assuming every VPS plan is available. Provision the feed secret **outside Git** only after confirming the third-party feed's ownership and trust model; rotate the old hardcoded key with its operator. An authenticated request does not independently authenticate the feed's stock assertions, so this is advisory, **not** proof of purchasability; OVH and WHMCS staging remain required.

The legacy OVH request-log insert, server provisioning exception logs, existing-server AddOrder log, and additional-IP payment hook now record status/safe service IDs rather than raw request bodies, signed headers, payment responses, WHMCS `$params`, or exception traces. This is a **bounded** log cleanup, not an audit of every legacy module surface; review other error messages and historical logs separately before production use. These changes do not register an OVH App Cloud adapter or change who places or pays for OVH orders.

## First read-only step: legacy WHMCS inspection (not adoption)

`ovh_legacy_inspection_enabled` defaults **off**. When explicitly enabled after WHMCS schema staging, a staff administrator with customer-server view-all **and** manage permission can call `GET /v1/ovh/legacy-services/{whmcsServiceId}`. It checks the live service/order/invoice owner and paid state, requires an active recurring server product with module `soyoustart_vps`, then reads only that product's WHMCS service custom fields. The response exposes the legacy OVH order ID and server name with `source: whmcs_legacy_custom_fields`, `provider_verified: false`, `binding_created: false`. Account names, passwords, other custom fields, and credential material are not returned. Missing or duplicate identifiers fail closed. Customers cannot call it.

This is a **live WHMCS snapshot, not an OVH provider read-back, persistent adoption, or proof of an existing server**. It performs no OVH request, creates no App Cloud VM/queue job, does not modify legacy billing, and cannot purchase or cancel. Verify the actual WHMCS custom-field schema and authoritative OVH instance ownership in isolated staging before enabling the switch. A future binding must have its own reconciliation and unique-identity gate; do not surface a customer-active VM based on a custom field alone.

## Still blocking an App Cloud provider adapter

1. The legacy `soyoustart_vps_CreateAccount()` places an OVH cart order and handles OVH payment under WHMCS provisioning. It is **not** an idempotent `InfrastructureProviderInterface::createServer()` operation. Blindly calling it from an App Cloud job would risk a second provider order and duplicate billing.
2. The App Cloud VM contract requires normalized resource IDs/states, idempotent create/delete and independently verified lifecycle read-back. These have not been proven for OVH from the existing module or a dedicated provider contract test.
3. The legacy account/custom-field schema and product associations must be mapped and staged against a real WHMCS release before an OVH service can be shown in an App Cloud customer-server view. No production migration, provider credential check or live call was performed here.
4. The third-party availability feed and lifecycle logging have only a narrow offline-tested cleanup; neither proves the feed's authority or covers all legacy data and error surfaces. Full review and real staging remain prerequisites before any write-capable bridge.

**Next gate:** obtain an isolated OVH staging account/product and document a non-duplicating cutover/ownership model for existing SoYouStart services. Build an adapter only for capabilities verified against official vendor contracts and a real staging server, with safe provider-order reconciliation and an explicit rollback plan. Do not register a test fake or advertise `server.create` based on the presence of the legacy module.

Offline checks: from `modules/addons/cloudhost247apps`, run `npm test -- OvhLegacy` and `npm test -- SelfServiceVps`, then `npm run lint`. These tests perform **no** OVH calls.
