# Hercule POS release readiness checklist

This checklist is for the current admin/backend development batch. Do not merge the feature PRs in arbitrary order.

## Recommended merge order

1. PR #48 — Audit Log
2. PR #49 — Monitoring Dashboard
3. PR #50 — License Lifecycle
4. PR #51 — Release Management
5. PR #52 — Remembered Sessions
6. PR #53 — Backup Health Dashboard
7. PR #54 — Performance Indexes
8. PR #55 — License Expiry Push
9. PR #56 — Notification Settings
10. PR #57 — Password Security Hardening
11. PR #58 — Granular Permissions
12. PR #59 — Focused Regression Runner

Resolve any branch conflicts against the final main before merging. Prefer one PR at a time so a regression can be traced to a specific merge.

## Required production migrations

Run these from the deployed application root after the corresponding PR has been deployed. Every listed migration is designed to be idempotent, but stop immediately if any command returns a non-zero exit code.

```bash
php db/migrate_device_management.php
php db/migrate_release_management.php
php db/migrate_performance_indexes.php
php db/migrate_expiry_alerts.php
php db/migrate_notification_preferences.php
php db/migrate_admin_permissions.php
```

Also run the canonical schema migration once after the full batch is deployed:

```bash
php db/migrate.php
```

Do not regenerate or replace the license-signing RSA key or the existing Web Push VAPID key pair during this rollout.

## Scheduled jobs

License expiry push alerts are inert until explicitly scheduled. After `migrate_expiry_alerts.php` succeeds, schedule:

```bash
php scripts/send_expiry_alerts.php
```

Daily is sufficient for 30-day / 7-day / 1-day / expired thresholds. Hourly is also safe because the alert table prevents duplicate threshold notifications.

## CI gates before merging

All candidate PRs must pass:

- PHP syntax validation
- shell script syntax validation
- Composer install
- `php tests/run_test.php`
- `php tests/run_regressions.php` once PR #59 is in the integration base

A failing focused regression is a release blocker. Do not bypass it just because the legacy core suite passes.

## Production smoke test order

After deployment and migrations:

1. `/public/health.php` returns healthy.
2. Admin login works with an Owner account.
3. Dashboard, Licenses, Customers, Recovery, Devices and Monitoring load without 500 errors.
4. Existing license validation succeeds for a known active device.
5. A blocked device is rejected by activate/validate and succeeds again after unblock.
6. License lifecycle: extend a disposable/test license, change activation limit, then confirm history is written.
7. Recovery request: submit from a test device, approve, claim and complete once; confirm token reuse is rejected.
8. Web Push: run Fast Test, then trigger a real Recovery notification.
9. Notification settings: disable one category for a test admin and verify only that category is suppressed.
10. Release API: publish a test release and verify the client-version response, then unpublish it.
11. Remembered sessions: create a Remember Me login and revoke it from Sessions.
12. Granular permissions: apply a harmless override to a non-owner test admin and verify Allow / Deny / Inherit behavior.
13. Backup Health shows the expected encrypted backup status; do not perform web-based restore/decrypt.
14. Re-test the admin PWA on mobile and confirm there is no horizontal overflow.

## Rollback rule

If a production smoke test fails after a specific feature merge, stop the rollout. Revert only that feature PR first. Database migrations in this batch are additive; do not manually drop new columns/tables during an emergency rollback unless the application cannot run with them present.
