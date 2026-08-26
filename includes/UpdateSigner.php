<?php

declare(strict_types=1);

/**
 * Signs the immutable update manifest returned to Hercule POS desktop.
 *
 * Security rules:
 * - UPDATE_PRIVATE_KEY must exist only in the production environment.
 * - The key is independent from LICENSE_PRIVATE_KEY so a compromise can be
 *   rotated without replacing the license-signing trust root.
 * - The desktop embeds only the matching public key.
 */
final class UpdateSigner
{
    public const KEY_ID = 'hercule-update-v1';
    public const ALGORITHM = 'RSA-SHA256';
    // SHA-256 of the exact PEM public key embedded in the desktop hardening snapshot.
    public const EXPECTED_PUBLIC_KEY_SHA256 = '9ba9568144ca9b7ae462cd7662a1bf76b8286d1d4e759a1360a59c0c47da932e';

    private static function env(string $name): string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return ($value === false || $value === null) ? '' : trim((string) $value);
    }

    private static function expectedPublicFingerprint(): string
    {
        // CI may use an ephemeral key only when explicitly running as test.
        // Production can never override the desktop trust root.
        if (strtolower(self::env('APP_ENV')) === 'test') {
            $testFingerprint = strtolower(self::env('UPDATE_TEST_PUBLIC_KEY_SHA256'));
            if (preg_match('/^[a-f0-9]{64}$/', $testFingerprint)) {
                return $testFingerprint;
            }
        }
        return self::EXPECTED_PUBLIC_KEY_SHA256;
    }

    private static function privateKeyPem(): string
    {
        $pem = self::env('UPDATE_PRIVATE_KEY');
        if ($pem === '') {
            throw new RuntimeException('UPDATE_PRIVATE_KEY environment variable is not configured.');
        }

        $pem = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $pem);

        foreach ([
            ['-----BEGIN PRIVATE KEY-----', '-----END PRIVATE KEY-----'],
            ['-----BEGIN RSA PRIVATE KEY-----', '-----END RSA PRIVATE KEY-----'],
        ] as [$begin, $end]) {
            if (strpos($pem, $begin) === false || strpos($pem, $end) === false) {
                continue;
            }
            $pattern = '/' . preg_quote($begin, '/') . '(.*?)' . preg_quote($end, '/') . '/s';
            if (preg_match($pattern, $pem, $matches)) {
                $body = preg_replace('/\s+/', '', (string) $matches[1]);
                if (!is_string($body) || $body === '') {
                    break;
                }
                $pem = $begin . "\n" . chunk_split($body, 64, "\n") . $end . "\n";
            }
            break;
        }

        $privateKey = openssl_pkey_get_private($pem);
        if ($privateKey === false) {
            throw new RuntimeException('UPDATE_PRIVATE_KEY is not a valid RSA private key.');
        }

        $details = openssl_pkey_get_details($privateKey);
        $publicPem = is_array($details) ? (string) ($details['key'] ?? '') : '';
        if ($publicPem === '' || !hash_equals(self::expectedPublicFingerprint(), hash('sha256', $publicPem))) {
            throw new RuntimeException('UPDATE_PRIVATE_KEY does not match the desktop update trust key.');
        }

        return $pem;
    }

    /**
     * Keep insertion order exactly aligned with the desktop canonical payload.
     * @return array<string,mixed>
     */
    public static function canonicalPayload(array $input): array
    {
        return [
            'release_id' => (int) ($input['release_id'] ?? 0),
            'version' => (string) ($input['version'] ?? ''),
            'channel' => (string) ($input['channel'] ?? 'stable'),
            'minimum_supported_version' => ($input['minimum_supported_version'] ?? null) === null
                ? null
                : (string) $input['minimum_supported_version'],
            'mandatory' => (bool) ($input['mandatory'] ?? false),
            'below_minimum_supported' => (bool) ($input['below_minimum_supported'] ?? false),
            'installer_file' => (string) ($input['installer_file'] ?? ''),
            'installer_size' => (int) ($input['installer_size'] ?? 0),
            'installer_sha256' => strtolower((string) ($input['installer_sha256'] ?? '')),
            'installer_sha512' => strtolower((string) ($input['installer_sha512'] ?? '')),
            'published_at' => ($input['published_at'] ?? null) === null
                ? null
                : (string) $input['published_at'],
        ];
    }

    /** @return array{alg:string,key_id:string,payload:array<string,mixed>,signature:string} */
    public static function sign(array $input): array
    {
        $payload = self::canonicalPayload($input);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Could not encode update manifest.');
        }

        $key = openssl_pkey_get_private(self::privateKeyPem());
        if ($key === false) {
            throw new RuntimeException('Could not load update signing key.');
        }

        $signature = '';
        if (!openssl_sign($json, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign update manifest.');
        }

        return [
            'alg' => self::ALGORITHM,
            'key_id' => self::KEY_ID,
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }
}
