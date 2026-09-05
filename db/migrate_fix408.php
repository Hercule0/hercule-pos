<?php
/**
 * Fix408 rollout migration entrypoint.
 * Runs the existing canonical schema migration first, then Entitlement v2.
 * Safe to run repeatedly before deploying the Fix408 desktop.
 *
 * Usage: php db/migrate_fix408.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/migrate.php';
require __DIR__ . '/migrate_multi_entitlement_v2.php';
echo "Fix408 server migration complete.\n";
