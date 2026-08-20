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
13. PR #60 — Release readiness checklist

Resolve any branch conflicts against the final main before merging. Prefer one PR at a time so a regression can be traced to a specific merge.

## Known integration hotspots

The current open PRs are individually mergeable against the current `main`, but several branches were created from the same base and touch shared files. Re-check these after every preceding merge rather than assuming the next PR is still conflict-free.

- PR #48 and PR #49 both append a page-specific stylesheet import to `public/admin/assets/css/style.css`. Preserve both `audit-log.css` and `monitoring.css` imports if Git reports a conflict.
- PR #48 changes `includes/DeviceManager.php`. Preserve its audit hooks together with the existing block/unblock behavior and Device Management columns.
- PR #56 changes `includes/PushNotifier.php`. Treat the existing VAPID configuration and working subscribe/send flow as protected behavior; only the per-admin preference filtering should be added.
- PR #58 changes the central `includes/Auth.php`. After it lands, re-run login, MFA, role checks, Remember Me, forced password change, and permission tests before continuing.
- Feature PRs #50, #51, #52, #56 and #58 contain focused `*_test.php` files. PR #59's regression runner discovers them only after those files are present on the integration base.

Never resolve a conflict by replacing an entire shared file with an older branch copy. Resolve the smallest conflicting hunk and retain newer `main` behavior.

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

PR #59 had an initial focused-test failure because the SQLite fixture did not include the Device Management migration columns. The fixture setup and runner were corrected, and the latest workflow on the corrected head completed successfully. Keep this focused runner as a hard gate for the final integration branch.

A failing focused regression is a release blocker. Do not bypass it just because the legacy core suite passes.

## Production smoke test order

After deployment and migrations:

1. `/public/health.php` returns healthy.
2. Admin login works with an Owner account.
3. Dashboard, Licenses, Customers, Recovery, Devices, Audit Log and Monitoring load without 500 errors.
4. Existing license validation succeeds for a known active device.
5. A blocked device is rejected by activate/validate and succeeds again after unblock.
6. License lifecycle: extend a disposable/test license, change activation limit, then confirm history is written.
7. Recovery request: submit from a test device, approve, claim and complete once; confirm token reuse is rejected.
8. Web Push: run Fast Test, then trigger a real Recovery notification.
9. Notification settings: disable one category for a test admin and verify only that category is suppressed; re-enable it afterward.
10. Expiry alert job: run it against a controlled test license and confirm retry/deduplication behavior without spamming real admins.
11. Release API: publish a test release and verify the client-version response, then unpublish it.
12. Remembered sessions: create a Remember Me login and revoke it from Sessions.
13. Password hardening: verify weak passwords are rejected and a successful password change invalidates remembered sessions.
14. Granular permissions: apply a harmless override to a non-owner test admin and verify Allow / Deny / Inherit behavior; confirm Owner remains unrestricted.
15. Audit Log: verify login failure, MFA failure and device block/unblock events appear without passwords or MFA codes.
16. Backup Health shows the expected encrypted backup status; do not perform web-based restore/decrypt.
17. Re-test the admin PWA on mobile and confirm there is no horizontal overflow.

## Rollback rule

If a production smoke test fails after a specific feature merge, stop the rollout. Revert only that feature PR first. Database migrations in this batch are additive; do not manually drop new columns/tables during an emergency rollback unless the application cannot run with them present.
