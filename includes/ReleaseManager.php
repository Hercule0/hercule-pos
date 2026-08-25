<?php

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/ReleaseStorage.php';

final class ReleaseManager
{
    public static function schemaV2Ready(): bool
    {
        $pdo = Database::pdo();
        try {
            foreach (['channel','is_paused','target_mode','storage_key','installer_filename','bundle_sha256'] as $column) {
                if (!self::columnExists($pdo, 'app_releases', $column)) return false;
            }
            foreach (['release_targets','release_download_grants','release_events'] as $table) {
                if (!self::tableExists($pdo, $table)) return false;
            }
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function ensureSchemaV2(): void
    {
        $pdo = Database::pdo();
        if (!self::tableExists($pdo, 'app_releases')) {
            $pdo->exec("CREATE TABLE app_releases (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                version VARCHAR(50) NOT NULL,
                minimum_supported_version VARCHAR(50) NULL,
                download_url VARCHAR(2048) NULL,
                release_notes TEXT NULL,
                is_mandatory TINYINT(1) NOT NULL DEFAULT 0,
                is_published TINYINT(1) NOT NULL DEFAULT 0,
                published_at DATETIME NULL,
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_app_releases_version (version),
                INDEX idx_app_releases_published (is_published, published_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }

        $columns = [
            'channel' => "VARCHAR(20) NOT NULL DEFAULT 'stable'",
            'is_paused' => "TINYINT(1) NOT NULL DEFAULT 0",
            'paused_at' => "DATETIME NULL",
            'target_mode' => "VARCHAR(20) NOT NULL DEFAULT 'all'",
            'storage_key' => "VARCHAR(255) NULL",
            'bundle_filename' => "VARCHAR(255) NULL",
            'bundle_size' => "BIGINT UNSIGNED NULL",
            'bundle_sha256' => "CHAR(64) NULL",
            'installer_filename' => "VARCHAR(255) NULL",
            'installer_size' => "BIGINT UNSIGNED NULL",
            'installer_sha256' => "CHAR(64) NULL",
            'installer_sha512' => "TEXT NULL",
            'blockmap_filename' => "VARCHAR(255) NULL",
            'blockmap_size' => "BIGINT UNSIGNED NULL",
            'blockmap_sha256' => "CHAR(64) NULL",
            'metadata_filename' => "VARCHAR(255) NULL",
            'metadata_size' => "BIGINT UNSIGNED NULL",
            'metadata_sha256' => "CHAR(64) NULL",
            'manifest_json' => "LONGTEXT NULL",
        ];
        foreach ($columns as $name => $definition) {
            if (!self::columnExists($pdo, 'app_releases', $name)) {
                $pdo->exec("ALTER TABLE app_releases ADD COLUMN `{$name}` {$definition}");
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS release_targets (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            release_id INT UNSIGNED NOT NULL,
            license_id INT UNSIGNED NULL,
            activation_id INT UNSIGNED NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_release_target_license (release_id, license_id),
            UNIQUE KEY uq_release_target_activation (release_id, activation_id),
            INDEX idx_release_target_license (license_id, release_id),
            INDEX idx_release_target_activation (activation_id, release_id),
            CONSTRAINT fk_release_target_release FOREIGN KEY (release_id) REFERENCES app_releases(id) ON DELETE CASCADE,
            CONSTRAINT fk_release_target_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
            CONSTRAINT fk_release_target_activation FOREIGN KEY (activation_id) REFERENCES license_activations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS release_download_grants (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            token_hash CHAR(64) NOT NULL UNIQUE,
            release_id INT UNSIGNED NOT NULL,
            license_id INT UNSIGNED NOT NULL,
            activation_id INT UNSIGNED NOT NULL,
            expires_at DATETIME NOT NULL,
            used_count INT UNSIGNED NOT NULL DEFAULT 0,
            last_used_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_release_grant_expiry (expires_at),
            INDEX idx_release_grant_release (release_id, created_at),
            CONSTRAINT fk_release_grant_release FOREIGN KEY (release_id) REFERENCES app_releases(id) ON DELETE CASCADE,
            CONSTRAINT fk_release_grant_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE CASCADE,
            CONSTRAINT fk_release_grant_activation FOREIGN KEY (activation_id) REFERENCES license_activations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS release_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            release_id INT UNSIGNED NOT NULL,
            license_id INT UNSIGNED NULL,
            activation_id INT UNSIGNED NULL,
            event_type VARCHAR(40) NOT NULL,
            client_version VARCHAR(50) NULL,
            detail VARCHAR(500) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_release_event_release (release_id, event_type, created_at),
            INDEX idx_release_event_activation (activation_id, created_at),
            CONSTRAINT fk_release_event_release FOREIGN KEY (release_id) REFERENCES app_releases(id) ON DELETE CASCADE,
            CONSTRAINT fk_release_event_license FOREIGN KEY (license_id) REFERENCES licenses(id) ON DELETE SET NULL,
            CONSTRAINT fk_release_event_activation FOREIGN KEY (activation_id) REFERENCES license_activations(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("UPDATE app_releases SET channel='stable' WHERE channel IS NULL OR channel=''");
        $pdo->exec("UPDATE app_releases SET target_mode='all' WHERE target_mode IS NULL OR target_mode=''");
        ReleaseStorage::ensureWritable();
    }

    public static function latestPublished(): ?array
    {
        $pdo = Database::pdo();
        if (self::schemaV2Ready()) {
            $stmt = $pdo->query("SELECT * FROM app_releases WHERE is_published = 1 AND is_paused = 0 AND target_mode = 'all' AND channel = 'stable' ORDER BY published_at DESC, id DESC LIMIT 50");
            return self::highestVersion($stmt->fetchAll());
        }
        $stmt = $pdo->query("SELECT * FROM app_releases WHERE is_published = 1 ORDER BY published_at DESC, id DESC LIMIT 1");
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function all(): array
    {
        $pdo = Database::pdo();
        if (!self::schemaV2Ready()) return $pdo->query('SELECT * FROM app_releases ORDER BY is_published DESC, published_at DESC, id DESC')->fetchAll();
        return $pdo->query("SELECT r.*,
                    (SELECT COUNT(*) FROM release_targets t WHERE t.release_id = r.id) AS target_count,
                    (SELECT COUNT(*) FROM release_events e WHERE e.release_id = r.id AND e.event_type = 'installed') AS installed_count,
                    (SELECT COUNT(*) FROM release_events e WHERE e.release_id = r.id AND e.event_type = 'failed') AS failed_count
             FROM app_releases r
             ORDER BY r.is_published DESC, r.is_paused ASC, r.published_at DESC, r.id DESC")->fetchAll();
    }

    public static function listTargetLicenses(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = "SELECT l.id, l.license_key, l.status, l.plan, l.expires_at,
                       c.name AS customer_name,
                       COUNT(a.id) AS activation_count,
                       MAX(a.app_version) AS app_version,
                       MAX(a.last_seen_at) AS last_seen_at
                FROM licenses l
                JOIN customers c ON c.id = l.customer_id
                LEFT JOIN license_activations a ON a.license_id = l.id AND a.is_active = 1
                GROUP BY l.id, l.license_key, l.status, l.plan, l.expires_at, c.name
                ORDER BY (l.status='active') DESC, c.name ASC, l.id DESC
                LIMIT {$limit}";
        return Database::pdo()->query($sql)->fetchAll();
    }

    public static function createFromBundle(array $upload, array $data, string $adminUsername): array
    {
        self::ensureSchemaV2();
        $stored = ReleaseStorage::importBundle($upload);
        $manifest = $stored['manifest'];
        $version = self::cleanVersion((string)$manifest['version']);
        $minimum = self::optionalVersion($data['minimum_supported_version'] ?? null);
        $notes = self::cleanNotes($data['release_notes'] ?? null);
        $mandatory = !empty($data['is_mandatory']) ? 1 : 0;
        $published = !empty($data['is_published']) ? 1 : 0;
        $channel = in_array(($data['channel'] ?? 'stable'), ['stable','beta'], true) ? (string)$data['channel'] : 'stable';
        $targetMode = ($data['target_mode'] ?? 'all') === 'licenses' ? 'licenses' : 'all';
        $targetIds = self::normalizeIds($data['target_license_ids'] ?? []);

        if ($minimum !== null && self::compare($minimum, $version) > 0) {
            ReleaseStorage::deleteReleaseFiles((string)$stored['storage_key']);
            throw new InvalidArgumentException('Minimum supported version cannot be newer than the release version.');
        }
        if ($targetMode === 'licenses' && !$targetIds) {
            ReleaseStorage::deleteReleaseFiles((string)$stored['storage_key']);
            throw new InvalidArgumentException('Select at least one target license for a targeted release.');
        }

        $pdo = Database::pdo();
        try {
            $pdo->beginTransaction();
            if ($targetMode === 'licenses') self::assertLicensesExist($pdo, $targetIds);
            $stmt = $pdo->prepare('INSERT INTO app_releases
                 (version, minimum_supported_version, download_url, release_notes, is_mandatory, is_published, published_at, created_by,
                  channel, is_paused, target_mode, storage_key, bundle_filename, bundle_size, bundle_sha256,
                  installer_filename, installer_size, installer_sha256, installer_sha512,
                  blockmap_filename, blockmap_size, blockmap_sha256,
                  metadata_filename, metadata_size, metadata_sha256, manifest_json)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $version, $minimum, $notes, $mandatory, $published, $published ? date('Y-m-d H:i:s') : null, $adminUsername,
                $channel, $targetMode, $stored['storage_key'], $stored['bundle_filename'], $stored['bundle_size'], $stored['bundle_sha256'],
                $manifest['installer']['file'], $manifest['installer']['size'], strtolower($manifest['installer']['sha256']), $manifest['installer']['sha512'],
                $manifest['blockmap']['file'], $manifest['blockmap']['size'], strtolower($manifest['blockmap']['sha256']),
                $manifest['updater_metadata']['file'], $manifest['updater_metadata']['size'], strtolower($manifest['updater_metadata']['sha256']),
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $releaseId = (int)$pdo->lastInsertId();
            if ($targetMode === 'licenses') self::insertLicenseTargets($pdo, $releaseId, $targetIds);
            $pdo->commit();
            return ['id'=>$releaseId,'version'=>$version,'published'=>(bool)$published,'target_mode'=>$targetMode,'target_count'=>count($targetIds)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            ReleaseStorage::deleteReleaseFiles((string)$stored['storage_key']);
            if ($e instanceof PDOException && stripos($e->getMessage(), 'uq_app_releases_version') !== false) throw new InvalidArgumentException('This release version already exists.');
            throw $e;
        }
    }

    public static function create(array $data, string $adminUsername): int
    {
        $version = self::cleanVersion($data['version'] ?? '');
        $minimum = self::optionalVersion($data['minimum_supported_version'] ?? null);
        $downloadUrl = self::cleanUrl($data['download_url'] ?? null);
        $notes = self::cleanNotes($data['release_notes'] ?? null);
        $mandatory = !empty($data['is_mandatory']) ? 1 : 0;
        $published = !empty($data['is_published']) ? 1 : 0;
        if ($minimum !== null && self::compare($minimum, $version) > 0) throw new InvalidArgumentException('Minimum supported version cannot be newer than the release version.');
        if ($published && $downloadUrl === null) throw new InvalidArgumentException('A published legacy release must have an HTTPS download URL.');
        $stmt = Database::pdo()->prepare('INSERT INTO app_releases (version, minimum_supported_version, download_url, release_notes, is_mandatory, is_published, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$version,$minimum,$downloadUrl,$notes,$mandatory,$published,$published ? date('Y-m-d H:i:s') : null,$adminUsername]);
        return (int)Database::pdo()->lastInsertId();
    }

    public static function setPublished(int $releaseId, bool $published): void
    {
        $pdo = Database::pdo();
        $release = self::find($releaseId);
        if (!$release) throw new InvalidArgumentException('Release not found.');
        if ($published) {
            $stored = !empty($release['storage_key']) && !empty($release['installer_filename']);
            $external = trim((string)($release['download_url'] ?? '')) !== '';
            if (!$stored && !$external) throw new InvalidArgumentException('Upload a valid update bundle or add an HTTPS download URL before publishing.');
        }
        if (self::schemaV2Ready()) {
            $stmt = $pdo->prepare('UPDATE app_releases SET is_published=?, is_paused=0, paused_at=NULL, published_at=? WHERE id=?');
        } else {
            $stmt = $pdo->prepare('UPDATE app_releases SET is_published=?, published_at=? WHERE id=?');
        }
        $stmt->execute([$published ? 1 : 0, $published ? date('Y-m-d H:i:s') : null, $releaseId]);
    }

    public static function setPaused(int $releaseId, bool $paused): void
    {
        if (!self::schemaV2Ready()) throw new RuntimeException('Release Management V2 is not initialized.');
        $release = self::find($releaseId);
        if (!$release) throw new InvalidArgumentException('Release not found.');
        if ($paused && empty($release['is_published'])) throw new InvalidArgumentException('Only a published release can be paused.');
        $stmt = Database::pdo()->prepare('UPDATE app_releases SET is_paused=?, paused_at=? WHERE id=?');
        $stmt->execute([$paused ? 1 : 0, $paused ? date('Y-m-d H:i:s') : null, $releaseId]);
    }

    public static function setMandatory(int $releaseId, bool $mandatory): void
    {
        $stmt = Database::pdo()->prepare('UPDATE app_releases SET is_mandatory=? WHERE id=?');
        $stmt->execute([$mandatory ? 1 : 0, $releaseId]);
    }

    public static function setTargets(int $releaseId, string $targetMode, array $licenseIds): void
    {
        if (!self::schemaV2Ready()) throw new RuntimeException('Release Management V2 is not initialized.');
        $targetMode = $targetMode === 'licenses' ? 'licenses' : 'all';
        $ids = self::normalizeIds($licenseIds);
        if ($targetMode === 'licenses' && !$ids) throw new InvalidArgumentException('Select at least one target license.');
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if (!self::find($releaseId)) throw new InvalidArgumentException('Release not found.');
            if ($targetMode === 'licenses') self::assertLicensesExist($pdo, $ids);
            $pdo->prepare('DELETE FROM release_targets WHERE release_id=?')->execute([$releaseId]);
            if ($targetMode === 'licenses') self::insertLicenseTargets($pdo, $releaseId, $ids);
            $pdo->prepare('UPDATE app_releases SET target_mode=? WHERE id=?')->execute([$targetMode,$releaseId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function delete(int $releaseId): void
    {
        $release = self::find($releaseId);
        if (!$release) return;
        Database::pdo()->prepare('DELETE FROM app_releases WHERE id=?')->execute([$releaseId]);
        if (!empty($release['storage_key'])) ReleaseStorage::deleteReleaseFiles((string)$release['storage_key']);
    }

    public static function find(int $releaseId): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM app_releases WHERE id=? LIMIT 1');
        $stmt->execute([$releaseId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function targets(int $releaseId): array
    {
        if (!self::schemaV2Ready()) return [];
        $stmt = Database::pdo()->prepare('SELECT license_id, activation_id FROM release_targets WHERE release_id=? ORDER BY id');
        $stmt->execute([$releaseId]);
        return $stmt->fetchAll();
    }

    public static function eligibleForClient(string $clientVersion, string $licenseKey, string $hwid, string $channel = 'stable'): array
    {
        if (!self::schemaV2Ready()) return ['ok'=>false,'code'=>'RELEASE_SCHEMA_NOT_READY'];
        $clientVersion = self::normalizeComparableVersion($clientVersion);
        $channel = in_array($channel, ['stable','beta'], true) ? $channel : 'stable';
        $pdo = Database::pdo();
        $stmt = $pdo->prepare("SELECT a.id AS activation_id, a.license_id, a.is_active, a.is_blocked, l.status AS license_status
             FROM license_activations a JOIN licenses l ON l.id=a.license_id
             WHERE l.license_key=? AND a.hwid=? LIMIT 1");
        $stmt->execute([$licenseKey,$hwid]);
        $client = $stmt->fetch();
        if (!$client) return ['ok'=>false,'code'=>'DEVICE_NOT_FOUND'];
        if (($client['license_status'] ?? '') !== 'active') return ['ok'=>false,'code'=>'LICENSE_INACTIVE'];
        if (empty($client['is_active']) || !empty($client['is_blocked'])) return ['ok'=>false,'code'=>'DEVICE_BLOCKED'];

        $licenseId = (int)$client['license_id'];
        $activationId = (int)$client['activation_id'];
        $stmt = $pdo->prepare("SELECT DISTINCT r.* FROM app_releases r
             LEFT JOIN release_targets t ON t.release_id=r.id
             WHERE r.is_published=1 AND r.is_paused=0 AND r.channel=?
               AND (r.target_mode='all' OR (r.target_mode='licenses' AND t.license_id=?) OR (r.target_mode='devices' AND t.activation_id=?))
             ORDER BY r.published_at DESC, r.id DESC LIMIT 100");
        $stmt->execute([$channel,$licenseId,$activationId]);
        $rows = $stmt->fetchAll();
        usort($rows, fn($a,$b) => -self::compare((string)$a['version'], (string)$b['version']));
        $release = null;
        foreach ($rows as $candidate) {
            if (self::compare($clientVersion, (string)$candidate['version']) < 0) { $release = $candidate; break; }
        }
        if (!$release) return ['ok'=>true,'update_available'=>false,'license_id'=>$licenseId,'activation_id'=>$activationId];
        $belowMinimum = !empty($release['minimum_supported_version']) && self::compare($clientVersion, (string)$release['minimum_supported_version']) < 0;
        return ['ok'=>true,'update_available'=>true,'mandatory'=>!empty($release['is_mandatory']) || $belowMinimum,'below_minimum_supported'=>$belowMinimum,'license_id'=>$licenseId,'activation_id'=>$activationId,'release'=>$release];
    }

    public static function createDownloadGrant(int $releaseId, int $licenseId, int $activationId, int $ttlSeconds = 21600): string
    {
        if (!self::schemaV2Ready()) throw new RuntimeException('Release Management V2 is not initialized.');
        $ttlSeconds = max(900, min(86400, $ttlSeconds));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + $ttlSeconds);
        $pdo = Database::pdo();
        if (random_int(1, 20) === 1) $pdo->exec('DELETE FROM release_download_grants WHERE expires_at < NOW()');
        $stmt = $pdo->prepare('INSERT INTO release_download_grants (token_hash, release_id, license_id, activation_id, expires_at) VALUES (?,?,?,?,?)');
        $stmt->execute([$hash,$releaseId,$licenseId,$activationId,$expires]);
        return $token;
    }

    public static function resolveDownloadGrant(string $token): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/i', $token) || !self::schemaV2Ready()) return null;
        $hash = hash('sha256', strtolower($token));
        $stmt = Database::pdo()->prepare("SELECT g.id AS grant_id, g.release_id, g.license_id, g.activation_id, g.expires_at, r.*
             FROM release_download_grants g
             JOIN app_releases r ON r.id=g.release_id
             JOIN licenses l ON l.id=g.license_id
             JOIN license_activations a ON a.id=g.activation_id
             WHERE g.token_hash=? AND g.expires_at>NOW() AND r.is_published=1 AND r.is_paused=0
               AND l.status='active' AND a.is_active=1 AND a.is_blocked=0 LIMIT 1");
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function touchDownloadGrant(int $grantId): void
    {
        Database::pdo()->prepare('UPDATE release_download_grants SET used_count=used_count+1,last_used_at=NOW() WHERE id=?')->execute([$grantId]);
    }

    public static function recordEvent(int $releaseId, ?int $licenseId, ?int $activationId, string $eventType, ?string $clientVersion = null, ?string $detail = null): void
    {
        if (!self::schemaV2Ready()) return;
        $allowed = ['offered','download_started','downloaded','install_started','installed','failed','dismissed'];
        if (!in_array($eventType, $allowed, true)) return;
        $stmt = Database::pdo()->prepare('INSERT INTO release_events (release_id,license_id,activation_id,event_type,client_version,detail) VALUES (?,?,?,?,?,?)');
        $stmt->execute([$releaseId,$licenseId,$activationId,$eventType,$clientVersion ? mb_substr($clientVersion,0,50) : null,$detail ? mb_substr($detail,0,500) : null]);
    }

    public static function compare(string $clientVersion, string $serverVersion): int
    {
        return version_compare(self::normalizeComparableVersion($clientVersion), self::normalizeComparableVersion($serverVersion));
    }

    private static function highestVersion(array $rows): ?array
    {
        if (!$rows) return null;
        usort($rows, fn($a,$b) => -self::compare((string)$a['version'], (string)$b['version']));
        return $rows[0] ?? null;
    }

    private static function insertLicenseTargets(PDO $pdo, int $releaseId, array $ids): void
    {
        $stmt = $pdo->prepare('INSERT INTO release_targets (release_id,license_id,activation_id) VALUES (?,?,NULL)');
        foreach ($ids as $id) $stmt->execute([$releaseId,$id]);
    }

    private static function assertLicensesExist(PDO $pdo, array $ids): void
    {
        if (!$ids) return;
        $marks = implode(',', array_fill(0,count($ids),'?'));
        $stmt = $pdo->prepare("SELECT id FROM licenses WHERE id IN ({$marks}) AND status='active'");
        $stmt->execute($ids);
        $found = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        sort($found); $expected = $ids; sort($expected);
        if ($found !== $expected) throw new InvalidArgumentException('One or more selected target licenses are missing or inactive.');
    }

    private static function normalizeIds($values): array
    {
        if (!is_array($values)) $values = [$values];
        $ids = [];
        foreach ($values as $v) { $id=(int)$v; if ($id>0) $ids[$id]=$id; }
        return array_values($ids);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table,$column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private static function cleanVersion(?string $value): string
    {
        $value = trim((string)$value);
        if ($value === '' || mb_strlen($value)>50 || !preg_match('/^[0-9A-Za-z._+-]+$/',$value)) throw new InvalidArgumentException('Enter a valid version.');
        return $value;
    }

    private static function optionalVersion(?string $value): ?string
    {
        $value = trim((string)$value);
        return $value === '' ? null : self::cleanVersion($value);
    }

    private static function cleanUrl(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (mb_strlen($value)>2048 || !filter_var($value,FILTER_VALIDATE_URL) || strtolower((string)parse_url($value,PHP_URL_SCHEME))!=='https') throw new InvalidArgumentException('Download URL must be a valid HTTPS URL.');
        return $value;
    }

    private static function cleanNotes(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') return null;
        if (mb_strlen($value)>10000) throw new InvalidArgumentException('Release notes cannot exceed 10,000 characters.');
        return $value;
    }

    private static function normalizeComparableVersion(string $version): string
    {
        $version = trim($version);
        return $version === '' ? '0.0.0' : ltrim($version,'vV');
    }
}
