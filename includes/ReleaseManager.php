<?php

require_once __DIR__ . '/Database.php';

final class ReleaseManager
{
    public static function latestPublished(): ?array
    {
        $stmt = Database::pdo()->query(
            "SELECT * FROM app_releases
             WHERE is_published = 1
             ORDER BY published_at DESC, id DESC
             LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all(): array
    {
        return Database::pdo()->query(
            'SELECT * FROM app_releases ORDER BY is_published DESC, published_at DESC, id DESC'
        )->fetchAll();
    }

    public static function create(array $data, string $adminUsername): int
    {
        $version = self::cleanVersion($data['version'] ?? '');
        $minimum = self::optionalVersion($data['minimum_supported_version'] ?? null);
        $downloadUrl = self::cleanUrl($data['download_url'] ?? null);
        $notes = self::cleanNotes($data['release_notes'] ?? null);
        $mandatory = !empty($data['is_mandatory']) ? 1 : 0;
        $published = !empty($data['is_published']) ? 1 : 0;

        if ($minimum !== null && self::compare($minimum, $version) > 0) {
            throw new InvalidArgumentException('Minimum supported version cannot be newer than the release version.');
        }
        if ($published && $downloadUrl === null) {
            throw new InvalidArgumentException('A published release must have an HTTPS download URL.');
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO app_releases
             (version, minimum_supported_version, download_url, release_notes, is_mandatory, is_published, published_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $version,
            $minimum,
            $downloadUrl,
            $notes,
            $mandatory,
            $published,
            $published ? date('Y-m-d H:i:s') : null,
            $adminUsername,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    public static function setPublished(int $releaseId, bool $published): void
    {
        $pdo = Database::pdo();
        if ($published) {
            $check = $pdo->prepare('SELECT download_url FROM app_releases WHERE id = ?');
            $check->execute([$releaseId]);
            $downloadUrl = $check->fetchColumn();
            if ($downloadUrl === false) {
                throw new InvalidArgumentException('Release not found.');
            }
            if (trim((string) $downloadUrl) === '') {
                throw new InvalidArgumentException('Add an HTTPS download URL before publishing this release.');
            }
        }

        $stmt = $pdo->prepare(
            'UPDATE app_releases SET is_published = ?, published_at = ? WHERE id = ?'
        );
        $stmt->execute([$published ? 1 : 0, $published ? date('Y-m-d H:i:s') : null, $releaseId]);
        if ($stmt->rowCount() === 0 && !$published) {
            $exists = $pdo->prepare('SELECT 1 FROM app_releases WHERE id = ?');
            $exists->execute([$releaseId]);
            if (!$exists->fetchColumn()) {
                throw new InvalidArgumentException('Release not found.');
            }
        }
    }

    public static function setMandatory(int $releaseId, bool $mandatory): void
    {
        $stmt = Database::pdo()->prepare('UPDATE app_releases SET is_mandatory = ? WHERE id = ?');
        $stmt->execute([$mandatory ? 1 : 0, $releaseId]);
    }

    public static function delete(int $releaseId): void
    {
        $stmt = Database::pdo()->prepare('DELETE FROM app_releases WHERE id = ?');
        $stmt->execute([$releaseId]);
    }

    public static function compare(string $clientVersion, string $serverVersion): int
    {
        $client = self::normalizeComparableVersion($clientVersion);
        $server = self::normalizeComparableVersion($serverVersion);
        return version_compare($client, $server);
    }

    private static function cleanVersion(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || mb_strlen($value) > 50 || !preg_match('/^[0-9A-Za-z._+-]+$/', $value)) {
            throw new InvalidArgumentException('Enter a valid version (letters, numbers, dots, dashes, plus or underscore).');
        }
        return $value;
    }

    private static function optionalVersion(?string $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : self::cleanVersion($value);
    }

    private static function cleanUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (mb_strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Download URL is invalid.');
        }
        if (!in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https'], true)) {
            throw new InvalidArgumentException('Download URL must use HTTPS.');
        }
        return $value;
    }

    private static function cleanNotes(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (mb_strlen($value) > 10000) {
            throw new InvalidArgumentException('Release notes cannot exceed 10,000 characters.');
        }
        return $value;
    }

    private static function normalizeComparableVersion(string $version): string
    {
        $version = trim($version);
        if ($version === '') return '0.0.0';
        return ltrim($version, 'vV');
    }
}
