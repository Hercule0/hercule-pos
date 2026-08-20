# Device Management rollout

After deployment, run `php db/migrate_device_management.php` once on production. The migration is idempotent.

Phase 2 adds device naming/notes, reported app version tracking, explicit block/unblock, reset-slot controls, search/filtering, and signed API denial for blocked HWIDs.
