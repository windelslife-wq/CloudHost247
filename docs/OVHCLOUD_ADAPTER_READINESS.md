# OVHcloud / SoYouStart — App Cloud integration readiness

**Status:** legacy transport hardened; **no App Cloud `ovhcloud` infrastructure adapter is registered**. `ProviderRegistry` continues to report OVHcloud as catalog-only (`adapter_available: false`). App Cloud customer provisioning must not be enabled on the basis of the legacy module alone. This is an integration prerequisite, not a claim of completed OVH VPS provisioning.

## Existing authority

- `modules/addons/soyoustart` and `modules/servers/soyoustart_vps` already own an OVH-specific WHMCS order, provider payment, renewal and VPS lifecycle flow. Their account credentials are read from `mod_soyoustart`, and their service identifiers are stored in WHMCS custom fields. No second OVH credential store or API client has been created.
- A WHMCS server product assigned to `soyoustart_vps` (or any other WHMCS provisioning module) is now **rejected** by App Cloud's product mapping. Ordering the same product in two provisioning systems could create duplicate infrastructure and provider charges.

## Completed security prerequisite

The existing OVH `ApiCall` transport and its public catalog fetch now require a trusted HTTPS host, a valid certificate and hostname, and **no redirect following**. Signed OVH headers are accepted only for the exact OVH API host allowlist, never for public catalog hosts or caller-supplied URLs. The separate legacy Google-token transports also verify TLS and do not follow redirects. `TrustedEndpoint.php` centralizes the outbound URL policy. Offline tests exercise rejection before cURL, signed-header host separation, and the actual cURL TLS/redirect options through local shims. A CA bundle that works in the deployment environment is required; the module must fail rather than disabling verification.

## Still blocking an App Cloud provider adapter

1. The legacy `soyoustart_vps_CreateAccount()` places an OVH cart order and handles OVH payment under WHMCS provisioning. It is **not** an idempotent `InfrastructureProviderInterface::createServer()` operation. Blindly calling it from an App Cloud job would risk a second provider order and duplicate billing.
2. The App Cloud VM contract requires normalized resource IDs/states, idempotent create/delete and independently verified lifecycle read-back. These have not been proven for OVH from the existing module or a dedicated provider contract test.
3. The legacy account/custom-field schema and product associations must be mapped and staged against a real WHMCS release before an OVH service can be shown in an App Cloud customer-server view. No production migration, provider credential check or live call was performed here.
4. Other legacy surfaces still need separate review (including the external product-availability feed and lifecycle logging) before any write-capable bridge can be enabled.

**Next gate:** obtain an isolated OVH staging account/product and document a non-duplicating cutover/ownership model for existing SoYouStart services. Build an adapter only for capabilities verified against official vendor contracts and a real staging server, with safe provider-order reconciliation and an explicit rollback plan. Do not register a test fake or advertise `server.create` based on the presence of the legacy module.

Offline checks: from `modules/addons/cloudhost247apps`, run `npm test -- OvhLegacy` and `npm test -- SelfServiceVps`, then `npm run lint`. These tests perform **no** OVH calls.
