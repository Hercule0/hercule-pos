<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$backup = file_get_contents($root . '/scripts/backup_database.sh');
$verify = file_get_contents($root . '/scripts/verify_backup.sh');
$restore = file_get_contents($root . '/scripts/restore_database.sh');
$workflow = file_get_contents($root . '/.github/workflows/database-backup.yml');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($backup) || !is_string($verify) || !is_string($restore) || !is_string($workflow)) {
    $fail('backup pipeline sources could not be read');
}

foreach ([
    '.v2.sql.enc' => 'new backups are not versioned for authenticated integrity',
    'hercule-backup-hmac-v2\\0' => 'backup HMAC does not use a domain-separated key derivation',
    'hash_init("sha256", HASH_HMAC' => 'backup HMAC is not generated with SHA-256',
    '"$encrypted.hmac"' => 'backup HMAC sidecar is not written',
] as $needle => $message) {
    if (!str_contains($backup, $needle)) $fail($message);
}

if (!str_contains($verify, 'Backup SHA-256 metadata is missing.')) {
    $fail('restore verification still allows a missing checksum');
}
if (!str_contains($verify, 'Authenticated v2 backup is missing its HMAC metadata.')) {
    $fail('v2 restore verification does not require HMAC metadata');
}
if (!str_contains($verify, 'Backup authentication failed. The archive may be corrupted or tampered with.')) {
    $fail('v2 restore verification does not fail on HMAC mismatch');
}
if (!str_contains($verify, 'VERIFY_DB_NAME must contain only letters, numbers, and underscores.')) {
    $fail('restore verification database identifier is not validated before SQL interpolation');
}
if (str_contains($verify, 'sha256sum -c')) {
    $fail('restore verification still trusts file paths embedded in a checksum sidecar');
}

foreach ([
    'Refusing restore: backup SHA-256 metadata is missing.' => 'production restore allows a missing checksum',
    'Refusing restore: authenticated v2 backup is missing HMAC metadata.' => 'production restore allows v2 without HMAC',
    'Refusing restore: backup authentication failed. Archive may be tampered with.' => 'production restore does not reject HMAC mismatch',
    'required_dump_tables=(' => 'production restore does not require the Hercule schema before import',
    'DB_NAME must contain only letters, numbers, and underscores.' => 'production restore does not validate database identifiers',
    'pre-restore-${timestamp}.v2.sql.enc' => 'pre-restore safety snapshots are not authenticated v2 archives',
    '"$safety_enc.hmac"' => 'pre-restore safety snapshot HMAC is not written',
] as $needle => $message) {
    if (!str_contains($restore, $needle)) $fail($message);
}
if (str_contains($restore, 'sha256sum -c')) {
    $fail('production restore still trusts file paths embedded in a checksum sidecar');
}
$inputAuth = strpos($restore, 'backup authentication failed');
$productionWrite = strpos($restore, 'mysql --host="$DB_HOST" --port="$DB_PORT" --user="$DB_USER" "$DB_NAME" < "$plain"');
if ($inputAuth === false || $productionWrite === false || $inputAuth > $productionWrite) {
    $fail('production writes can occur before input archive authentication');
}

if (!str_contains($workflow, '${{ runner.temp }}/encrypted-backup/*.hmac')) {
    $fail('authenticated backup metadata is not retained as an artifact');
}
if (!str_contains($workflow, 'shivammathur/setup-php@f3e473d116dcccaddc5834248c87452386958240')) {
    $fail('backup workflow does not provision the pinned PHP runtime required for HMAC verification');
}

echo "PASS authenticated database backup and restore pipeline hardening\n";
