<?php
require_once __DIR__ . '/../includes/Database.php';
require_once __DIR__ . '/../includes/ReleaseManager.php';

function release_check(string $label, bool $condition): void
{
    if (!$condition) {
        fwrite(STDERR, "[FAIL] {$label}\n");
        exit(1);
    }
    echo "[PASS] {$label}\n";
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE app_releases (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    minimum_supported_version TEXT NULL,
    download_url TEXT NULL,
    release_notes TEXT NULL,
    is_mandatory INTEGER NOT NULL DEFAULT 0,
    is_published INTEGER NOT NULL DEFAULT 0,
    published_at TEXT NULL,
    created_by TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
)');
Database::setTestInstance($pdo);

$id = ReleaseManager::create([
    'version' => '1.2.0',
    'minimum_supported_version' => '1.0.0',
    'download_url' => 'https://example.com/hercule-1.2.0.exe',
    'release_notes' => 'Regression release',
    'is_mandatory' => 0,
    'is_published' => 1,
], 'tester');
release_check('Published release is created', $id > 0);

$latest = ReleaseManager::latestPublished();
release_check('Latest published release is returned', $latest !== null && $latest['version'] === '1.2.0');
release_check('Version comparison detects older client', ReleaseManager::compare('1.1.9', '1.2.0') < 0);
release_check('Version comparison accepts v prefix', ReleaseManager::compare('v1.2.0', '1.2.0') === 0);

ReleaseManager::setMandatory($id, true);
$row = $pdo->query('SELECT is_mandatory FROM app_releases WHERE id = ' . $id)->fetch();
release_check('Mandatory flag can be enabled', (int) $row['is_mandatory'] === 1);

ReleaseManager::setPublished($id, false);
release_check('Unpublishing removes release from public latest lookup', ReleaseManager::latestPublished() === null);

$draftId = ReleaseManager::create([
    'version' => '1.3.0',
    'release_notes' => 'Draft without binary',
], 'tester');
release_check('Draft release may exist before a download URL is ready', $draftId > 0);
$publishWithoutUrlRejected = false;
try {
    ReleaseManager::setPublished($draftId, true);
} catch (InvalidArgumentException $e) {
    $publishWithoutUrlRejected = true;
}
release_check('Publishing without an HTTPS download URL is rejected', $publishWithoutUrlRejected);

$immediatePublishWithoutUrlRejected = false;
try {
    ReleaseManager::create([
        'version' => '1.3.1',
        'is_published' => 1,
    ], 'tester');
} catch (InvalidArgumentException $e) {
    $immediatePublishWithoutUrlRejected = true;
}
release_check('Immediate publish without a download URL is rejected', $immediatePublishWithoutUrlRejected);

$futureMinimumRejected = false;
try {
    ReleaseManager::create([
        'version' => '1.4.0',
        'minimum_supported_version' => '1.5.0',
        'download_url' => 'https://example.com/hercule-1.4.0.exe',
    ], 'tester');
} catch (InvalidArgumentException $e) {
    $futureMinimumRejected = true;
}
release_check('Minimum supported version cannot exceed release version', $futureMinimumRejected);

$badUrlRejected = false;
try {
    ReleaseManager::create([
        'version' => '1.2.1',
        'download_url' => 'http://example.com/insecure.exe',
    ], 'tester');
} catch (InvalidArgumentException $e) {
    $badUrlRejected = true;
}
release_check('Non-HTTPS download URL is rejected', $badUrlRejected);

$badVersionRejected = false;
try {
    ReleaseManager::create(['version' => 'bad version!'], 'tester');
} catch (InvalidArgumentException $e) {
    $badVersionRejected = true;
}
release_check('Invalid version string is rejected', $badVersionRejected);

ReleaseManager::delete($id);
ReleaseManager::delete($draftId);
$count = (int) $pdo->query('SELECT COUNT(*) FROM app_releases')->fetchColumn();
release_check('Release records can be deleted', $count === 0);

echo "RELEASE MANAGER TESTS PASSED\n";
