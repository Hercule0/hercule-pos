<?php
/**
 * RSA signing and verification for license responses.
 *
 * Production:
 * - Private key MUST come from LICENSE_PRIVATE_KEY environment variable.
 * - The private key is never read from or written to disk.
 *
 * The desktop application only needs the public key.
 */

final class RsaSigner
{
    /**
     * Get the RSA private key from the environment.
     *
     * Azure App Service may provide multiline values normally, but this also
     * handles environments where newline characters were stored as literal
     * "\n".
     */
    private static function getPrivateKey(): string
    {
        $privateKeyPem = getenv('LICENSE_PRIVATE_KEY');

        if ($privateKeyPem === false || trim($privateKeyPem) === '') {
            $privateKeyPem = $_ENV['LICENSE_PRIVATE_KEY'] ?? '';
        }

        if (!is_string($privateKeyPem) || trim($privateKeyPem) === '') {
            throw new RuntimeException(
                'LICENSE_PRIVATE_KEY environment variable is not configured.'
            );
        }

        // Handle escaped newlines if the environment variable contains \n.
        if (strpos($privateKeyPem, '\n') !== false) {
            $privateKeyPem = str_replace('\n', "\n", $privateKeyPem);
        }

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException(
                'LICENSE_PRIVATE_KEY is not a valid RSA private key.'
            );
        }

        return $privateKeyPem;
    }

    /**
     * Signs a payload array.
     *
     * Returns:
     * [
     *     'payload' => [...],
     *     'signature' => 'base64...'
     * ]
     */
    public static function sign(array $payload): array
    {
        $privateKeyPem = self::getPrivateKey();

        $privateKey = openssl_pkey_get_private($privateKeyPem);

        if ($privateKey === false) {
            throw new RuntimeException(
                'Failed to load RSA private key.'
            );
        }

        $canonicalJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        if ($canonicalJson === false) {
            throw new RuntimeException(
                'Failed to encode license payload.'
            );
        }

        $signature = '';

        $success = openssl_sign(
            $canonicalJson,
            $signature,
            $privateKey,
            OPENSSL_ALGO_SHA256
        );

        if (!$success) {
            throw new RuntimeException(
                'Failed to generate RSA signature: ' .
                (openssl_error_string() ?: 'Unknown OpenSSL error')
            );
        }

        return [
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }

    /**
     * Verifies a signed payload using the PUBLIC key.
     */
    public static function verify(
        array $payload,
        string $signatureB64,
        string $publicKeyPem
    ): bool {
        $publicKey = openssl_pkey_get_public($publicKeyPem);

        if ($publicKey === false) {
            return false;
        }

        $canonicalJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        if ($canonicalJson === false) {
            return false;
        }

        $signature = base64_decode($signatureB64, true);

        if ($signature === false) {
            return false;
        }

        $result = openssl_verify(
            $canonicalJson,
            $signature,
            $publicKey,
            OPENSSL_ALGO_SHA256
        );

        return $result === 1;
    }
}
