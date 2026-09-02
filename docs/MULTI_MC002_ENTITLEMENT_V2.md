# MC-002 — Entitlement v2

This document records the server-side contract introduced for Hercule Multi-Cashier.

## Compatibility rule

API v1 remains supported and keeps using the existing `license_key`, `hwid`, and `max_activations` contract.

API v2 adds stable identities and explicit Multi-Cashier entitlement:

- `license_uuid`
- `store_uuid`
- `device_uuid`
- `multi_cashier`
- `max_terminals`
- `max_management_devices`
- `features_json`
- `entitlement_version`
- `device_role`
- `counts_as_terminal`
- `protocol_version`

Existing rows are backfilled during the MC-002 migration. A license or activation created later through an old v1 code path receives its stable UUID lazily on first v2 access. The UUID is then retained.

## Seat semantics

Terminal roles consume `max_terminals`:

- `single_terminal`
- `cashier_terminal`
- `manager_terminal`

Management-only roles use `max_management_devices` and cannot be selected through the public activation endpoint:

- `manager_server`
- `management_only`

This prevents a client from claiming a free management role to bypass terminal licensing.

## Upgrade behavior

A Multi-Cashier upgrade keeps the same license and store identity:

```text
1 → 2 → 3 → N
```

When Multi-Cashier is enabled, changing terminal capacity also mirrors `max_activations` so existing API v1 clients and API v2 clients cannot observe conflicting seat limits. Every entitlement change increments `entitlement_version`, writes audit/subscription history, and emits a license-change notification.

## Signed API v2

- `POST /public/api/v2/activate.php`
- `POST /public/api/v2/validate.php`
- `POST /public/api/v2/entitlement.php`

All success and failure payloads are RSA-SHA256 signed using the existing license signing infrastructure.

## Safety gates

MC-002 is not production-ready unless all of the following pass:

- PHP syntax validation
- repository security gate
- Composer validation and audit
- legacy v1 regression suite
- MC-001 seat-safety regressions
- entitlement v2 regressions
- migration ordering validation

Production deployment remains disabled while the PR is draft/unmerged.
