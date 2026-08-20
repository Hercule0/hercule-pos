<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/PermissionResolver.php';
require_once __DIR__ . '/../includes/AuthPermissionBridge.php';

function assert_permission(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE admin_permission_overrides (admin_id INTEGER NOT NULL, permission TEXT NOT NULL, allowed INTEGER NOT NULL, PRIMARY KEY (admin_id, permission))');
Database::setTestInstance($pdo);

PermissionResolver::clearCache();
assert_permission('Missing override inherits role default allow', PermissionResolver::resolve(7, 'licenses.manage', true) === true);
assert_permission('Missing override inherits role default deny', PermissionResolver::resolve(7, 'customers.manage', false) === false);
assert_permission('Release management is denied when the role default is deny', AuthPermissionBridge::resolve(7, 'releases.manage', false, 'support') === false);
assert_permission('Owner always retains release management', AuthPermissionBridge::resolve(1, 'releases.manage', false, 'owner') === true);

$pdo->prepare('INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (?, ?, ?)')->execute([7, 'licenses.manage', 0]);
PermissionResolver::clearCache();
assert_permission('Explicit deny overrides role allow', PermissionResolver::resolve(7, 'licenses.manage', true) === false);

$pdo->prepare('INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (?, ?, ?)')->execute([7, 'customers.manage', 1]);
$pdo->prepare('INSERT INTO admin_permission_overrides (admin_id, permission, allowed) VALUES (?, ?, ?)')->execute([7, 'releases.manage', 1]);
PermissionResolver::clearCache();
assert_permission('Explicit allow overrides role deny', PermissionResolver::resolve(7, 'customers.manage', false) === true);
assert_permission('Owner can explicitly grant release management to support', AuthPermissionBridge::resolve(7, 'releases.manage', false, 'support') === true);

$pdo->exec('DROP TABLE admin_permission_overrides');
PermissionResolver::clearCache();
assert_permission('Missing migration fails open to role default', PermissionResolver::resolve(7, 'licenses.manage', true) === true);
assert_permission('Missing migration preserves role deny', PermissionResolver::resolve(7, 'customers.manage', false) === false);
assert_permission('Missing migration does not accidentally grant release management', AuthPermissionBridge::resolve(7, 'releases.manage', false, 'support') === false);

echo "PERMISSION RESOLVER TESTS PASSED\n";
