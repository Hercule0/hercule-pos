<?php

declare(strict_types=1);

$backup = file_get_contents(dirname(__DIR__) . '/scripts/backup_database.sh');
if (!is_string($backup)) {
    fwrite(STDERR, "FAIL: backup_database.sh could not be read\n");
    exit(1);
}

$checks = [
    'mysqldump --help' => 'backup does not detect installed mysqldump capabilities',
    '--ssl-mode=REQUIRED' => 'MySQL 8 mandatory TLS mode is not supported',
    'dump_tls_args+=(--ssl)' => 'compatible-client TLS fallback is missing',
    'refusing an unencrypted database connection' => 'backup does not fail closed when TLS cannot be requested',
    '"${dump_tls_args[@]}"' => 'selected mandatory TLS arguments are not passed to mysqldump',
];

foreach ($checks as $needle => $message) {
    if (!str_contains($backup, $needle)) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// The old failure came from a literal standalone --ssl argument in the dump
// command. The only allowed legacy --ssl occurrence now is the capability-
// selected array fallback above.
if (str_contains($backup, "  --ssl \\\n")) {
    fwrite(STDERR, "FAIL: backup still hard-codes the incompatible --ssl flag\n");
    exit(1);
}

$requiredPos = strpos($backup, '--ssl-mode=REQUIRED');
$refusePos = strpos($backup, 'refusing an unencrypted database connection');
$dumpPos = strpos($backup, "mysqldump \\\n");
if ($requiredPos === false || $refusePos === false || $dumpPos === false || $refusePos > $dumpPos) {
    fwrite(STDERR, "FAIL: TLS capability gate is not enforced before mysqldump execution\n");
    exit(1);
}

echo "PASS backup mysqldump mandatory-TLS compatibility gate\n";
