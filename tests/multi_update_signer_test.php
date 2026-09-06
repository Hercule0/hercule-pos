<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/UpdateSigner.php';
require_once __DIR__ . '/../includes/MultiUpdateSigner.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
check($key !== false, 'Could not generate test RSA key.');
$privatePem = '';
check(openssl_pkey_export($key, $privatePem), 'Could not export test private key.');
$details = openssl_pkey_get_details($key);
$publicPem = is_array($details) ? (string) ($details['key'] ?? '') : '';
check($publicPem !== '', 'Could not export test public key.');

putenv('APP_ENV=test');
putenv('UPDATE_PRIVATE_KEY=' . str_replace("\n", '\\n', $privatePem));
putenv('UPDATE_TEST_PUBLIC_KEY_SHA256=' . hash('sha256', $publicPem));

$input = [
    'release_id' => 44,
    'version' => '1.2.0',
    'installer_sha256' => str_repeat('ab', 32),
    'protocol_min' => 2,
    'protocol_max' => 3,
    'central_schema_version' => 14,
];
$envelope = MultiUpdateSigner::sign($input);
check(($envelope['alg'] ?? null) === UpdateSigner::ALGORITHM, 'Algorithm mismatch.');
check(($envelope['key_id'] ?? null) === UpdateSigner::KEY_ID, 'Key id mismatch.');
check(($envelope['payload']['schema_version'] ?? 0) === 1, 'Schema version mismatch.');

$json = json_encode($envelope['payload'], JSON_UNESCAPED_SLASHES);
$signature = base64_decode((string) $envelope['signature'], true);
check(is_string($json) && is_string($signature), 'Signed envelope encoding failed.');
check(openssl_verify(MultiUpdateSigner::DOMAIN . $json, $signature, $publicPem, OPENSSL_ALGO_SHA256) === 1, 'Valid Multi target signature was rejected.');

$tampered = $envelope['payload'];
$tampered['central_schema_version'] = 15;
$tamperedJson = json_encode($tampered, JSON_UNESCAPED_SLASHES);
check(openssl_verify(MultiUpdateSigner::DOMAIN . $tamperedJson, $signature, $publicPem, OPENSSL_ALGO_SHA256) !== 1, 'Tampered Multi target was accepted.');

$normalJson = json_encode(UpdateSigner::canonicalPayload([
    'release_id' => 44,
    'version' => '1.2.0',
    'installer_file' => 'x.exe',
    'installer_size' => 10,
    'installer_sha256' => str_repeat('ab', 32),
    'installer_sha512' => str_repeat('cd', 64),
]), JSON_UNESCAPED_SLASHES);
check(openssl_verify((string) $normalJson, $signature, $publicPem, OPENSSL_ALGO_SHA256) !== 1, 'Domain separation from normal update signatures failed.');

echo "PASS multi_update_signer_test\n";
