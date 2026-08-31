<?php

final class BackupManager
{
    private const CHECKSUM_MAX_BYTES = 268435456;
    private const AZURE_DEFAULT_DIR = '/home/backups/hercule-pos';

    public static function directory(): ?string
    {
        $configured = trim((string) (getenv('BACKUP_DIR') ?: ''));
        if ($configured !== '') return rtrim($configured, DIRECTORY_SEPARATOR);
        if (is_dir('/home') && is_writable('/home')) return self::AZURE_DEFAULT_DIR;
        return null;
    }

    public static function encryptionConfigured(): bool
    {
        return strlen((string)(getenv('BACKUP_ENCRYPTION_KEY') ?: '')) >= 32;
    }

    public static function status(): array
    {
        $dir = self::directory();
        $encryptionConfigured = self::encryptionConfigured();
        if ($dir === null) {
            return [
                'configured' => false,
                'encryption_configured' => $encryptionConfigured,
                'operational' => false,
                'readable' => false,
                'writable' => false,
                'directory' => null,
                'latest_at' => null,
                'latest_age_hours' => null,
                'latest_authenticated' => false,
                'count' => 0,
                'files' => [],
            ];
        }

        $exists = is_dir($dir);
        $readable = $exists && is_readable($dir);
        $writable = $exists && is_writable($dir);
        $files = $readable ? self::listBackups($dir, 20) : [];
        $latestAt = $files[0]['modified_at'] ?? null;
        $latestAuthenticated = !empty($files)
            && ($files[0]['checksum_status'] ?? '') === 'verified'
            && ($files[0]['hmac_status'] ?? '') === 'verified';

        return [
            'configured' => true,
            'encryption_configured' => $encryptionConfigured,
            'operational' => $encryptionConfigured && $readable && $writable,
            'readable' => $readable,
            'writable' => $writable,
            'directory' => $dir,
            'latest_at' => $latestAt,
            'latest_age_hours' => $latestAt ? max(0, round((time() - strtotime($latestAt)) / 3600, 1)) : null,
            'latest_authenticated' => $latestAuthenticated,
            'count' => count($files),
            'files' => $files,
        ];
    }

    private static function expectedHmac(string $path): ?string
    {
        $key = (string)(getenv('BACKUP_ENCRYPTION_KEY') ?: '');
        if (strlen($key) < 32) return null;
        $macKey = hash('sha256', "hercule-backup-hmac-v2\0" . $key, true);
        $ctx = hash_init('sha256', HASH_HMAC, $macKey);
        if (!hash_update_file($ctx, $path)) return null;
        return hash_final($ctx);
    }

    public static function listBackups(string $dir, int $limit = 20): array
    {
        $paths = glob($dir . DIRECTORY_SEPARATOR . '*.sql.enc') ?: [];
        usort($paths, static fn(string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $paths = array_slice($paths, 0, max(1, $limit));

        $rows = [];
        foreach ($paths as $path) {
            $size = (int) filesize($path);
            $checksumPath = $path . '.sha256';
            $hmacPath = $path . '.hmac';
            $checksum = null;
            $checksumStatus = 'missing';
            $hmacStatus = 'missing';

            if (is_readable($checksumPath)) {
                $raw = trim((string) file_get_contents($checksumPath));
                if ($raw !== '') $checksum = preg_split('/\s+/', $raw)[0] ?? null;
            }

            $checksumOk = null;
            if ($checksum) {
                if ($size <= self::CHECKSUM_MAX_BYTES) {
                    $actual = hash_file('sha256', $path);
                    $checksumOk = is_string($actual) ? hash_equals(strtolower($checksum), strtolower($actual)) : false;
                    $checksumStatus = $checksumOk ? 'verified' : 'mismatch';
                } else {
                    $checksumStatus = 'deferred';
                }
            }

            $hmacOk = null;
            if (is_readable($hmacPath)) {
                $storedHmac = strtolower(trim((string)file_get_contents($hmacPath)));
                if (!preg_match('/^[a-f0-9]{64}$/', $storedHmac)) {
                    $hmacStatus = 'invalid';
                    $hmacOk = false;
                } elseif (!self::encryptionConfigured()) {
                    $hmacStatus = 'key-unavailable';
                } elseif ($size > self::CHECKSUM_MAX_BYTES) {
                    $hmacStatus = 'deferred';
                } else {
                    $expectedHmac = self::expectedHmac($path);
                    $hmacOk = is_string($expectedHmac) && hash_equals($storedHmac, strtolower($expectedHmac));
                    $hmacStatus = $hmacOk ? 'verified' : 'mismatch';
                }
            }

            $rows[] = [
                'name' => basename($path),
                'size_bytes' => $size,
                'modified_at' => gmdate('Y-m-d H:i:s', (int) filemtime($path)),
                'checksum' => $checksum,
                'checksum_ok' => $checksumOk,
                'checksum_status' => $checksumStatus,
                'hmac_ok' => $hmacOk,
                'hmac_status' => $hmacStatus,
            ];
        }
        return $rows;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes . ' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
        if ($bytes < 1024 * 1024 * 1024) return round($bytes / 1024 / 1024, 1) . ' MB';
        return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
    }
}
