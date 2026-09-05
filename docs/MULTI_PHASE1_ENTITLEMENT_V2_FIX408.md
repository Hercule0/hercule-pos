# Hercule Multi-Cashier — Phase 1 / Fix408

This branch implements the first incomplete phase from the official Multi-Cashier v1.3 plan: License Seat Safety + Entitlement v2.

## What changes

- Keeps `/public/api/activate.php` v1 response contract unchanged.
- Closes the inactive-HWID seat bypass in v1 using a per-license seat lock + preflight.
- Adds persistent `license_uuid` while binding `store_uuid` to the Desktop's existing Fix350 store identity on first successful v2 activation.
- Adds `multi_cashier`, `max_terminals`, `max_management_devices`, `features_json`, `entitlement_version`, and `offline_valid_until`.
- Adds device UUID/role/seat classification and final revoke metadata.
- Separates temporary deactivate from final revoke.
- Adds atomic seat-level device replacement.
- Adds signed API v2 endpoints:
  - `/public/api/v2/activate.php`
  - `/public/api/v2/validate.php`
  - `/public/api/v2/entitlement.php`
  - `/public/api/v2/device/revoke.php`
  - `/public/api/v2/device/replace.php`
- Existing v1 clients remain supported.

## Migration

Before enabling the Fix408 desktop against this branch/release, run:

```bash
php db/migrate_fix408.php
```

The wrapper runs the existing migration and then the idempotent Multi entitlement migration.

## Safety rules

- A permanently revoked device cannot return through v1 or v2.
- Reactivating an inactive legacy HWID must have a free legacy activation seat.
- `manager_server` / `management_only` use management-device capacity; POS terminal roles use terminal capacity.
- Store identity mismatch is fail-closed.
- Device UUID collision is fail-closed.
- v2 responses are RSA-SHA256 signed using the existing signing system.
- v1 is not removed and its payload/signature layout is not changed.

## Test gate G1

`tests/multi_entitlement_v2_test.php` is automatically included by `tests/run_regressions.php` and checks legacy seat bypass, store binding, separate management/terminal seats, final revoke, replacement, and entitlement version behavior.

Do not merge/deploy this branch until CI passes and the migration has been validated against a staging copy of the production license database.
