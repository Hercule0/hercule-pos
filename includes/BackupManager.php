<?php

final class BackupManager
{
    private const CHECKSUM_MAX_BYTES = 268435456; // 256 MiB per archive in web request

    public static function directory(): ?string
    {
        $dir = trim((string) (getenv('BACKUP_DIR') ?: ''));
        return $dir !== '' ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
    }

    public static function status(): array
    {
        $dir = self::directory();
        if ($dir === null) {
            return [
                'configured' => false,
                'readable' => false,
                'writable' => false,
                'directory' => null,
                'latest_at' => null,
                'latest_age_hours' => null,
                'count' => 0,
                'files' => [],
            ];
        }

        $readable = is_dir($dir) && is_readable($dir);
        $writable = is_dir($dir) && is_writable($dir);
        $files = $readable ? self::listBackups($dir, 20) : [];
        $latestAt = $files[0]['modified_at'] ?? null;

        return [
            'configured' => true,
            'readable' => $readable,
            'writable' => $writable,
            'directory' => $dir,
            'latest_at' => $latestAt,
            'latest_age_hours' => $latestAt ? max(0, round((time() - strtotime($latestAt)) / 3600, 1)) : null,
            'count' => count($files),
            'files' => $files,
        ];
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
            $checksum = null;
            $checksumStatus = 'missing';

            if (is_readable($checksumPath)) {
                $raw = trim((string) file_get_contents($checksumPath));
                if ($raw !== '') {
                    $checksum = preg_split('/\s+/', $raw)[0] ?? null;
                }
            }

            $checksumOk = null;
            if ($checksum) {
                if ($size <= self::CHECKSUM_MAX_BYTES) {
                    $actual = hash_file('sha256', $path);
                    $checksumOk = is_string($actual)
                        ? hash_equals(strtolower($checksum), strtolower($actual))
                        : false;
                    $checksumStatus = $checksumOk ? 'verified' : 'mismatch';
                } else {
                    // Avoid hashing very large archives synchronously inside an HTTP
                    // request. The server-side verify script remains the authoritative
                    // path for full-size restore-readiness checks.
                    $checksumStatus = 'deferred';
                }
            }

            $rows[] = [
                'name' => basename($path),
                'size_bytes' => $size,
                'modified_at' => gmdate('Y-m-d H:i:s', (int) filemtime($path)),
                'checksum' => $checksum,
                'checksum_ok' => $checksumOk,
                'checksum_status' => $checksumStatus,
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
