<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/UpdateSigner.php';

$key = openssl_pkey_new([
    'private_key_bits' => 2048,
    'private_key_type' => OPENSSL_KEYTYPE_RSA,
]);
if ($key === false) {
    fwrite(STDERR, "FAIL: could not generate ephemeral RSA key\n");
    exit(1);
}
$privatePem = '';
if (!openssl_pkey_export($key, $privatePem)) {
    fwrite(STDERR, "FAIL: could not export ephemeral RSA key\n");
    exit(1);
}
$details = openssl_pkey_get_details($key);
$publicPem = is_array($details) ? (string)($details['key'] ?? '') : '';
if ($publicPem === '') {
    fwrite(STDERR, "FAIL: could not obtain public key\n");
    exit(1);
}
putenv('UPDATE_PRIVATE_KEY=' . str_replace("\n", '\\n', $privatePem));
$_ENV['UPDATE_PRIVATE_KEY'] = str_replace("\n", '\\n', $privatePem);

$input = [
    'release_id' => 41,
    'version' => '1.2.3',
    'channel' => 'stable',
    'minimum_supported_version' => '1.1.0',
    'mandatory' => true,
    'below_minimum_supported' => false,
    'installer_file' => 'Hercule-POS-Setup-1.2.3-x64.exe',
    'installer_size' => 98765432,
    'installer_sha256' => str_repeat('a', 64),
    'installer_sha512' => str_repeat('b', 128),
    'published_at' => '2026-08-26 00:00:00',
];

$envelope = UpdateSigner::sign($input);
if (($envelope['alg'] ?? '') !== 'RSA-SHA256' || ($envelope['key_id'] ?? '') !== 'hercule-update-v1') {
    fwrite(STDERR, "FAIL: signer metadata mismatch\n");
    exit(1);
}

$expectedKeys = [
    'release_id','version','channel','minimum_supported_version','mandatory',
    'below_minimum_supported','installer_file','installer_size','installer_sha256',
    'installer_sha512','published_at',
];
if (array_keys($envelope['payload'] ?? []) !== $expectedKeys) {
    fwrite(STDERR, "FAIL: canonical update payload order changed\n");
    exit(1);
}

$json = json_encode($envelope['payload'], JSON_UNESCAPED_SLASHES);
$signature = base64_decode((string)$envelope['signature'], true);
if (!is_string($json) || $signature === false || openssl_verify($json, $signature, $publicPem, OPENSSL_ALGO_SHA256) !== 1) {
    fwrite(STDERR, "FAIL: signature did not verify\n");
    exit(1);
}

$tampered = $envelope['payload'];
$tampered['version'] = '9.9.9';
$tamperedJson = json_encode($tampered, JSON_UNESCAPED_SLASHES);
if (!is_string($tamperedJson) || openssl_verify($tamperedJson, $signature, $publicPem, OPENSSL_ALGO_SHA256) === 1) {
    fwrite(STDERR, "FAIL: tampered manifest verified\n");
    exit(1);
}

echo "PASS update signer contract\n";
