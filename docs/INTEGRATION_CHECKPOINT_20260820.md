# Integration checkpoint — 2026-08-20

This branch is a non-production coordination checkpoint created from `main`. It does **not** merge any feature PR and must not be deployed as if the batch were integrated.

## Candidate PR heads

| PR | Feature | Head SHA | Mergeable vs current main | Latest PR CI |
|---:|---|---|---|---|
| #59 | Focused regression runner | `933200fadace040b0f0fab5bf9eb1e2f0208cc9f` | yes | success |
| #48 | Admin audit log | `99fa3ef7ab765fe91d721837786fa8238a3bfbc4` | yes | success |
| #49 | Monitoring dashboard | `cec9fa98d28e830a4e590bbe4af3cf0852aa1503` | yes | test/build steps passed; PR workflow ended cancelled after deployment steps were skipped |
| #50 | License lifecycle | `61022d83d828c127795f64b948357cfd01222f12` | yes | success |
| #51 | Desktop release management | `613dcaff36839d6f436515973d40c8641d4f69a4` | yes | success |
| #52 | Remembered sessions | `bb8600b2c0724b963b47ad663a2f8930c6f4abe9` | yes | success |
| #53 | Backup health | `6fbe28d6063c0ac5bd47b198c061778d1b5badb9` | yes | success |
| #54 | Performance indexes | `654ee84a7103038acc57b84858a757d9c5e78d1e` | yes | success |
| #55 | License expiry push | `62c525f48ddcb7ac062dacc51fcedc919acfeff6` | yes | success |
| #56 | Notification settings | `bba03d9c55dd4c669fed957a94e868ebff7bce79` | yes | success |
| #57 | Password hardening | `3df20ca0d6ab586d0a9dadd4d7610346ababa387` | yes | success |
| #58 | Granular permissions | `4e48e53962b31f15047d6845422d985b56186cce` | yes | success |
| #60 | Release readiness | `edd1b93a4cf34ef6e0d67d12cacb535813d032c0` | yes | success |

## Protected integration rules

1. Do not update `main` directly from this checkpoint.
2. Do not regenerate or replace the existing Web Push VAPID key pair.
3. Do not modify the license-signing RSA private key.
4. PR #59 must become the shared test gate before the rest of the batch is integrated.
5. Resolve shared-file conflicts hunk-by-hunk; never replace a whole shared file with an older branch copy.
6. Preserve both CSS imports from #48 and #49 if `public/admin/assets/css/style.css` conflicts.
7. Preserve DeviceManager audit hooks from #48 together with existing device block/unblock behavior.
8. Preserve PushNotifier category filtering, active-admin filtering, stale-account subscription isolation, and existing VAPID/send behavior when #55/#56 are combined.
9. Preserve `Auth.php` login/MFA/Remember Me behavior when #58 is integrated.
10. Keep Password Recovery's dedicated `recovery_audit_log`; admin audit is additive, not a replacement.

## Migration order after eventual integration

```bash
php db/migrate_device_management.php
php db/migrate_release_management.php
php db/migrate_performance_indexes.php
php db/migrate_expiry_alerts.php
php db/migrate_notification_preferences.php
php db/migrate_admin_permissions.php
php db/migrate.php
```

Preferred once #60 is integrated:

```bash
bash scripts/run_release_migrations.sh
```

## Release blocker rule

Do not consider the batch release-ready until all candidate changes exist together on a dedicated integration branch and the legacy core suite plus every focused `tests/*_test.php` suite pass from that same combined tree. Individual PR success is necessary but is not sufficient proof of cross-PR integration correctness.
