<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/TrialManager.php';

try {
    TrialManager::ensureSchema();
    Database::pdo()->query('SELECT 1 FROM trial_devices LIMIT 1');
    echo "VERIFIED - trial_devices\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Trial-device migration failed: ' . $e->getMessage() . "\n");
    exit(1);
}
