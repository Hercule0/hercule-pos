<?php
$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/EntitlementV2.php');
$failures = [];

$check = static function (string $label, bool $ok) use (&$failures): void {
    echo ($ok ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$ok) $failures[] = $label;
};

$start = strpos($source, 'private static function findLicenseForUpdate');
$end = $start !== false ? strpos($source, 'private static function bumpEntitlementVersion', $start) : false;
$block = ($start !== false && $end !== false) ? substr($source, $start, $end - $start) : '';

$check('findLicenseForUpdate block is present', $block !== '');
$check('MySQL lock clause is emitted after LIMIT 1', str_contains($block, "' LIMIT 1 FOR UPDATE'"));
$check('SQLite/non-MySQL path still uses LIMIT 1 without row lock', str_contains($block, "' LIMIT 1';"));
$check('invalid MySQL order FOR UPDATE LIMIT 1 is absent', !str_contains($block, 'FOR UPDATE LIMIT 1'));
$check('license lookup remains parameterized', str_contains($block, "license_key = ?"));

if ($failures) {
    fwrite(STDERR, 'Fix444 MySQL lock-syntax failures: ' . implode(', ', $failures) . "\n");
    exit(1);
}

echo "PASS Fix444 EntitlementV2 MySQL row lock — LIMIT 1 FOR UPDATE ordering certified\n";
