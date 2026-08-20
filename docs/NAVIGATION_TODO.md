# Navigation integration scope

The combined branch contains direct routes for new admin tools. Final navigation work should add permission-aware links without rewriting the existing PWA/push bootstrap logic.

Target routes:
- `/public/admin/devices.php`
- `/public/admin/monitoring.php`
- `/public/admin/audit_log.php`
- `/public/admin/releases.php`
- `/public/admin/sessions.php`
- `/public/admin/backups.php`
- `/public/admin/notification_settings.php`
- `/public/admin/admin_permissions.php`

License lifecycle should be linked contextually from `/public/admin/license_detail.php?id=<license_id>`.
