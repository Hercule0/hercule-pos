<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/public/admin/releases.php');
$fast = file_get_contents($root . '/public/admin/release_upload_fast.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($page) || !is_string($fast)) {
    $fail('release audit sources could not be read');
}

foreach ([
    'release_v2_setup',
    'release_bundle_uploaded',
    'release_published',
    'release_unpublished',
    'release_paused',
    'release_resumed',
    'release_mandatory_changed',
    'release_audience_all',
    'release_deleted',
] as $action) {
    $pattern = '/AuditLog::adminAction\s*\(\s*[\'\"]' . preg_quote($action, '/') . '[\'\"]/m';
    if (preg_match($pattern, $page) !== 1) {
        $fail("release admin action is not audited: {$action}");
    }
}

if (preg_match('/AuditLog::adminAction\s*\(\s*[\'\"]release_bundle_uploaded[\'\"]/m', $fast) !== 1) {
    $fail('parallel release upload completion is not audited');
}
if (!str_contains($fast, '; transport=parallel')) {
    $fail('parallel upload audit entry does not identify its transport');
}

$createPosition = strpos($fast, 'ReleaseManager::createFromBundle(');
$auditPosition = strpos($fast, "'release_bundle_uploaded'", $createPosition === false ? 0 : $createPosition);
if ($createPosition === false || $auditPosition === false || $auditPosition < $createPosition) {
    $fail('fast upload is audited before release creation succeeds');
}

echo "PASS release management audit coverage\n";
