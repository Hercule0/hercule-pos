<?php

declare(strict_types=1);

/**
 * Domain-separated signer for Multi-Cashier OTA compatibility metadata.
 *
 * The normal UpdateSigner continues to sign the immutable installer manifest
 * for every existing desktop version. This second envelope is optional and
 * therefore backward-compatible: current Single clients simply ignore it.
 */
final class MultiUpdateSigner
{
    public const DOMAIN = "HERCULE_MULTI_UPDATE_TARGET_V1\n";

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return ($value === false || $value === null) ? '' : trim((string) $value);
    }

    private static function expectedPublicFingerprint(): string
    {
        if (strtolower(self::env('APP_ENV')) === 'test') {
            $testFingerprint = strtolower(self::env('UPDATE_TEST_PUBLIC_KEY_SHA256'));
            if (preg_match('/^[a-f0-9]{64}$/', $testFingerprint)) return $testFingerprint;
        }
        return UpdateSigner::EXPECTED_PUBLIC_KEY_SHA256;
    }

    private static function privateKeyPem(): string
    {
        $pem = self::env('UPDATE_PRIVATE_KEY');
        if ($pem === '') throw new RuntimeException('UPDATE_PRIVATE_KEY environment variable is not configured.');
        $pem = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $pem);

        foreach ([
            ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'],
            ['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----'],
        ] as [$begin, $end]) {
            if (strpos($pem, $begin) === false || strpos($pem, $end) === false) continue;
            $pattern = '/' . preg_quote($begin, '/') . '(.*?)' . preg_quote($end, '/') . '/s';
            if (preg_match($pattern, $pem, $matches)) {
                $body = preg_replace('/\s+/', '', (string) $matches[1]);
                if (!is_string($body) || $body === '') break;
                $pem = $begin . "\n" . chunk_split($body, 64, "\n") . $end . "\n";
            }
            break;
        }

        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) throw new RuntimeException('UPDATE_PRIVATE_KEY is not a valid RSA private key.');
        $details = openssl_pkey_get_details($privateKey);
        $publicPem = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($publicPem === '' || !hash_equals(self::expectedPublicFingerprint(), hash('sha256', $publicPem))) {
            throw new RuntimeException('UPDATE_PRIVATE_KEY does not match the desktop update trust key.');
        }
        return $pem;
    }

    /** @return array<string,mixed> */
    public static function canonicalPayload(array $input): array
    {
        $protocolMin = max(1, (int) ($input['protocol_min'] ?? 1));
        $protocolMax = max($protocolMin, (int) ($input['protocol_max'] ?? $protocolMin));
        return [
            'schema_version' => 1,
            'release_id' => max(0, (int) ($input['release_id'] ?? 0)),
            'version' => (string) ($input['version'] ?? ''),
            'installer_sha256' => strtolower((string) ($input['installer_sha256'] ?? '')),
            'protocol_min' => $protocolMin,
            'protocol_max' => $protocolMax,
            'central_schema_version' => max(0, (int) ($input['central_schema_version'] ?? 0)),
        ];
    }

    /** @return array{alg:string,key_id:string,payload:array<string,mixed>,signature:string} */
    public static function sign(array $input): array
    {
        $payload = self::canonicalPayload($input);
        if ($payload['release_id'] <= 0
            || !preg_match('/^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$/', $payload['version'])
            || !preg_match('/^[a-f0-9]{64}$/', $payload['installer_sha256'])
            || $payload['central_schema_version'] < 1) {
            throw new InvalidArgumentException('Multi update target metadata is incomplete.');
        }
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('Could not encode Multi update target.');
        $key = openssl_pkey_get_private(self::privateKeyPem());
        if ($key === false) throw new RuntimeException('Could not load Multi update signing key.');
        $signature = '';
        if (!openssl_sign(self::DOMAIN . $json, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign Multi update target.');
        }
        return [
            'alg' => UpdateSigner::ALGORITHM,
            'key_id' => UpdateSigner::KEY_ID,
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }
}
