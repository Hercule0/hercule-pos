<?php
function consolidation_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$schema = file_get_contents(__DIR__ . '/../db/schema.sql');
consolidation_check('Canonical schema is readable', $schema !== false);

foreach ([
    'app_releases',
    'license_expiry_alerts',
    'admin_notification_preferences',
    'admin_permission_overrides',
] as $table) {
    consolidation_check("Canonical schema includes {$table}", strpos($schema, 'CREATE TABLE IF NOT EXISTS ' . $table) !== false);
}

foreach ([
    'idx_activations_active_seen',
    'idx_verification_result_created',
    'idx_recovery_status_created',
    'idx_subscription_license_created',
    'idx_licenses_status_expires',
    'idx_admin_audit_action_created',
] as $index) {
    consolidation_check("Canonical schema includes {$index}", strpos($schema, $index) !== false);
}

$adminUsers = file_get_contents(__DIR__ . '/../public/admin/admin_users.php');
consolidation_check('Admin management uses centralized PasswordPolicy', strpos($adminUsers, 'PasswordPolicy::validate($temporaryPassword)') !== false);
consolidation_check('Disabling an admin revokes remembered sessions', strpos($adminUsers, "DELETE FROM user_sessions WHERE admin_id = ?") !== false);

echo "RELEASE CONSOLIDATION TESTS PASSED\n";
