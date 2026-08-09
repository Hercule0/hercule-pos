<?php
/**
 * Signs license validation responses with RSA so the desktop app (Phase 5)
 * can verify authenticity using only the embedded public key — no need to
 * trust the transport, and a modified/replayed response is detectable.
 */

final class RsaSigner
{
    /** Generates a new keypair and writes both files. Run once during setup. */
    public static function generateKeypair(): void
    {
        $config = require __DIR__ . '/../config/config.php';
        $paths = $config['rsa'];

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($res === false) {
            throw new RuntimeException('Failed to generate RSA keypair: ' . openssl_error_string());
        }

        openssl_pkey_export($res, $privateKeyPem);
        $details = openssl_pkey_get_details($res);
        $publicKeyPem = $details['key'];

        file_put_contents($paths['private_key_path'], $privateKeyPem);
        chmod($paths['private_key_path'], 0600);
        file_put_contents($paths['public_key_path'], $publicKeyPem);
    }

    /**
     * Signs a payload array. Returns the payload plus a base64 signature
     * of its canonical JSON encoding.
     */
    public static function sign(array $payload): array
    {
        $config = require __DIR__ . '/../config/config.php';
        $privateKeyPath = $config['rsa']['private_key_path'];

        if (!file_exists($privateKeyPath)) {
            throw new RuntimeException('RSA private key not found. Run RsaSigner::generateKeypair() during setup.');
        }

        $privateKey = openssl_pkey_get_private(file_get_contents($privateKeyPath));
        $canonicalJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        openssl_sign($canonicalJson, $signature, $privateKey, OPENSSL_ALGO_SHA256);

        return [
            'payload' => $payload,
            'signature' => base64_encode($signature),
        ];
    }

    /**
     * Verifies a signed payload using the PUBLIC key. This is what the
     * desktop app does — included here so it can be tested server-side too,
     * but the actual desktop implementation (Phase 5) only ships the
     * public key, never this whole class.
     */
    public static function verify(array $payload, string $signatureB64, string $publicKeyPem): bool
    {
        $publicKey = openssl_pkey_get_public($publicKeyPem);
        if ($publicKey === false) {
            return false;
        }
        $canonicalJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = base64_decode($signatureB64);

        $result = openssl_verify($canonicalJson, $signature, $publicKey, OPENSSL_ALGO_SHA256);
        return $result === 1;
    }
}
