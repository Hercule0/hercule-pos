<?php

function fail_support_test(string $message): void
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function assert_support(bool $condition, string $message): void
{
    if (!$condition) {
        fail_support_test($message);
    }
}

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/SupportTicket.php');
$migration = file_get_contents($root . '/db/migrate_support_feedback.php');
$canonicalMigration = file_get_contents($root . '/db/migrate.php');
$releaseMigrations = file_get_contents($root . '/scripts/run_release_migrations.sh');
$admin = file_get_contents($root . '/public/admin/support.php');
$bootstrap = file_get_contents($root . '/public/admin/includes/bootstrap.php');

foreach ([$service, $migration, $canonicalMigration, $releaseMigrations, $admin, $bootstrap] as $content) {
    assert_support(is_string($content) && $content !== '', 'Required support foundation file is unreadable.');
}

assert_support(str_contains($migration, 'CREATE TABLE IF NOT EXISTS support_tickets'), 'support_tickets table is missing.');
assert_support(str_contains($migration, 'CREATE TABLE IF NOT EXISTS support_messages'), 'support_messages table is missing.');
assert_support(str_contains($migration, 'CREATE TABLE IF NOT EXISTS support_status_history'), 'support_status_history table is missing.');
assert_support(str_contains($migration, 'CREATE TABLE IF NOT EXISTS support_attachments'), 'support_attachments table is missing.');
assert_support(str_contains($migration, 'UNIQUE KEY uq_support_ticket_request (license_id, client_request_id)'), 'Offline retry idempotency index is missing.');
assert_support(str_contains($migration, 'FOREIGN KEY (license_id) REFERENCES licenses(id)'), 'Support tickets are not linked to licenses.');

assert_support(str_contains($service, 'License::validate($licenseKey, $hwid, $ip)'), 'Support client authentication must reuse license/HWID validation.');
assert_support(str_contains($service, "sprintf('HRC-%s-%08d'"), 'Stable HRC ticket numbering is missing.');
assert_support(str_contains($service, 'findByClientRequestId'), 'Idempotent client request handling is missing.');
assert_support(!str_contains($service, "'license_key' =>"), 'Desktop public ticket payload must not expose the license key.');
assert_support(!str_contains($service, "'hwid' =>"), 'Desktop public ticket payload must not expose HWID.');

foreach (['support_create.php', 'support_list.php', 'support_detail.php', 'support_message.php'] as $endpoint) {
    $content = file_get_contents($root . '/public/api/' . $endpoint);
    assert_support(is_string($content) && str_contains($content, "require_once __DIR__ . '/_support_common.php';"), "{$endpoint} does not use the shared hardened support bootstrap.");
    assert_support(str_contains($content, "REQUEST_METHOD'] !== 'POST'"), "{$endpoint} must use POST so credentials do not leak through URLs.");
}

assert_support(str_contains($admin, 'Csrf::guard();'), 'Admin support mutations are missing CSRF protection.');
assert_support(str_contains($admin, "Auth::requirePermission('recovery.review');"), 'Admin support mutations are missing a write permission gate.');
assert_support(str_contains($admin, 'SupportTicket::adminReply'), 'Admin reply flow is missing.');
assert_support(str_contains($admin, 'SupportTicket::adminChangeStatus'), 'Admin status workflow is missing.');
assert_support(str_contains($bootstrap, '/public/admin/support.php'), 'Support center is not visible in the admin navigation.');

assert_support(str_contains($canonicalMigration, "require __DIR__ . '/migrate_support_feedback.php';"), 'Canonical migration does not include support tables.');
assert_support(str_contains($releaseMigrations, 'db/migrate_support_feedback.php'), 'Release migration preflight does not include support migration.');

echo "SUPPORT FEEDBACK FOUNDATION TEST PASSED\n";
