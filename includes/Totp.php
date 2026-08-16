<?php
/**
 * RFC 6238 TOTP helpers and authenticated encryption for administrator secrets.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function verify(string $secret, string $code, ?int $time = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== 6) {
            return false;
        }

        $counter = intdiv($time ?? time(), 30);
        for ($offset = -1; $offset <= 1; $offset++) {
            if (hash_equals(self::code($secret, $counter + $offset), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function code(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        $binaryCounter = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0f;
        $value = ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public static function provisioningUri(string $secret, string $username): string
    {
        $issuer = 'Hercule License Admin';
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
            . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=6&period=30';
    }

    public static function encrypt(string $secret): string
    {
        $key = self::encryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Unable to protect the MFA secret.');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Invalid protected MFA secret.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, 'aes-256-gcm', self::encryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt the MFA secret.');
        }
        return $plain;
    }

    private static function encryptionKey(): string
    {
        $configured = $_ENV['MFA_ENCRYPTION_KEY'] ?? $_SERVER['MFA_ENCRYPTION_KEY'] ?? getenv('MFA_ENCRYPTION_KEY');
        if (!is_string($configured) || strlen($configured) < 32) {
            throw new RuntimeException('MFA_ENCRYPTION_KEY must be configured with at least 32 random characters.');
        }
        return hash('sha256', $configured, true);
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }
        return $output;
    }

    private static function base32Decode(string $data): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($data, '='))) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                throw new InvalidArgumentException('Invalid Base32 secret.');
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $output .= chr(bindec($chunk));
            }
        }
        return $output;
    }
}
