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

    $privateKeyPem = trim($privateKeyPem);

    /*
     * Normalize escaped newlines.
     */
    $privateKeyPem = str_replace(
        ["\\r\\n", "\\n", "\\r"],
        "\n",
        $privateKeyPem
    );

    /*
     * Normalize PEM when Azure stores the entire key in one line.
     *
     * Example:
     * -----BEGIN PRIVATE KEY----- MIIE... -----END PRIVATE KEY-----
     *
     * becomes:
     *
     * -----BEGIN PRIVATE KEY-----
     * MIIE...
     * -----END PRIVATE KEY-----
     */
    if (
        strpos($privateKeyPem, '-----BEGIN PRIVATE KEY-----') !== false &&
        strpos($privateKeyPem, '-----END PRIVATE KEY-----') !== false
    ) {
        $privateKeyPem = preg_replace(
            '/\s*-----BEGIN PRIVATE KEY-----\s*/',
            "-----BEGIN PRIVATE KEY-----\n",
            $privateKeyPem
        );

        $privateKeyPem = preg_replace(
            '/\s*-----END PRIVATE KEY-----\s*/',
            "\n-----END PRIVATE KEY-----\n",
            $privateKeyPem
        );
    }

    /*
     * Also support traditional RSA PRIVATE KEY format.
     */
    if (
        strpos($privateKeyPem, '-----BEGIN RSA PRIVATE KEY-----') !== false &&
        strpos($privateKeyPem, '-----END RSA PRIVATE KEY-----') !== false
    ) {
        $privateKeyPem = preg_replace(
            '/\s*-----BEGIN RSA PRIVATE KEY-----\s*/',
            "-----BEGIN RSA PRIVATE KEY-----\n",
            $privateKeyPem
        );

        $privateKeyPem = preg_replace(
            '/\s*-----END RSA PRIVATE KEY-----\s*/',
            "\n-----END RSA PRIVATE KEY-----\n",
            $privateKeyPem
        );
    }

    /*
     * Remove accidental spaces/newlines from the Base64 body,
     * then wrap it at 64 characters per line.
     */
    if (
        preg_match(
            '/-----BEGIN PRIVATE KEY-----(.*?)-----END PRIVATE KEY-----/s',
            $privateKeyPem,
            $matches
        )
    ) {
        $body = preg_replace('/\s+/', '', $matches[1]);
        $body = chunk_split($body, 64, "\n");

        $privateKeyPem =
            "-----BEGIN PRIVATE KEY-----\n" .
            $body .
            "-----END PRIVATE KEY-----\n";
    } elseif (
        preg_match(
            '/-----BEGIN RSA PRIVATE KEY-----(.*?)-----END RSA PRIVATE KEY-----/s',
            $privateKeyPem,
            $matches
        )
    ) {
        $body = preg_replace('/\s+/', '', $matches[1]);
        $body = chunk_split($body, 64, "\n");

        $privateKeyPem =
            "-----BEGIN RSA PRIVATE KEY-----\n" .
            $body .
            "-----END RSA PRIVATE KEY-----\n";
    }

    /*
     * Validate the normalized key.
     */
    $privateKey = openssl_pkey_get_private($privateKeyPem);

    if ($privateKey === false) {
        $opensslError = openssl_error_string();

        throw new RuntimeException(
            'LICENSE_PRIVATE_KEY is not a valid RSA private key.' .
            ($opensslError ? ' OpenSSL: ' . $opensslError : '')
        );
    }

    return $privateKeyPem;
}
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
