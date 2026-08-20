<?php
require_once __DIR__ . '/../includes/BackupManager.php';

$failures = [];
function backup_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hercule-backup-test-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);

try {
    $good = $dir . DIRECTORY_SEPARATOR . 'good.sql.enc';
    file_put_contents($good, 'encrypted-backup-fixture');
    file_put_contents($good . '.sha256', hash_file('sha256', $good) . "  good.sql.enc\n");

    $bad = $dir . DIRECTORY_SEPARATOR . 'bad.sql.enc';
    file_put_contents($bad, 'tampered-backup-fixture');
    file_put_contents($bad . '.sha256', str_repeat('0', 64) . "  bad.sql.enc\n");

    // Ensure deterministic order for assertions.
    touch($bad, time() - 10);
    touch($good, time());

    $rows = BackupManager::listBackups($dir, 20);
    backup_check('Backup list returns encrypted archives', count($rows) === 2);
    backup_check('Valid checksum is verified', ($rows[0]['checksum_status'] ?? '') === 'verified' && $rows[0]['checksum_ok'] === true);
    backup_check('Checksum mismatch is detected', ($rows[1]['checksum_status'] ?? '') === 'mismatch' && $rows[1]['checksum_ok'] === false);
    backup_check('Only basename is exposed for archive name', $rows[0]['name'] === 'good.sql.enc');
    backup_check('Byte formatter handles KiB', BackupManager::formatBytes(2048) === '2 KB');
} finally {
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($dir);
}

if ($failures) exit(1);
echo "BACKUP MANAGER TESTS PASSED\n";
