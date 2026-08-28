<?php
/**
 * Support / feedback service for Hercule POS desktop clients and admins.
 *
 * Desktop-facing methods authenticate through the existing license + HWID
 * validation path. Admin-facing methods deliberately contain no auth checks;
 * callers in public/admin must enforce Auth::require()/Auth::can().
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/License.php';
require_once __DIR__ . '/AuditLog.php';

final class SupportTicket
{
    private const TYPES = ['problem', 'suggestion', 'feature_request'];
    private const CATEGORIES = [
        'pos', 'inventory', 'invoices', 'printing', 'reports', 'suppliers',
        'customers', 'updates', 'account', 'settings', 'ai', 'other',
    ];
    private const PRIORITIES = ['normal', 'important', 'very_important'];
    private const STATUSES = [
        'new', 'reviewed', 'in_progress', 'resolved', 'closed', 'under_review',
        'accepted', 'planned', 'implemented', 'rejected', 'duplicate',
    ];

    public static function create(
        string $licenseKey,
        string $hwid,
        array $payload,
        ?string $ip = null
    ): array {
        $ctx = self::authorizeClient($licenseKey, $hwid, $ip);
        if (!$ctx['ok']) {
            return $ctx;
        }

        $validated = self::validateCreatePayload($payload);
        if (!$validated['ok']) {
            return $validated;
        }

        $data = $validated['data'];
        $pdo = Database::pdo();

        if ($data['client_request_id'] !== null) {
            $existing = self::findByClientRequestId((int)$ctx['license_id'], $data['client_request_id']);
            if ($existing) {
                return [
                    'ok' => true,
                    'idempotent' => true,
                    'ticket' => self::publicTicket($existing),
                ];
            }
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO support_tickets
                 (ticket_number, license_id, activation_id, client_request_id, type, category, title, description,
                  priority, status, app_version, build, os, current_page, error_code, error_message, last_client_reply_at)
                 VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)'
            );
            $stmt->execute([
                $ctx['license_id'],
                $ctx['activation_id'],
                $data['client_request_id'],
                $data['type'],
                $data['category'],
                $data['title'],
                $data['description'],
                $data['priority'],
                'new',
                $data['app_version'],
                $data['build'],
                $data['os'],
                $data['current_page'],
                $data['error_code'],
                $data['error_message'],
            ]);

            $ticketId = (int)$pdo->lastInsertId();
            $ticketNumber = sprintf('HRC-%s-%08d', date('Y'), $ticketId);

            $pdo->prepare('UPDATE support_tickets SET ticket_number = ? WHERE id = ?')
                ->execute([$ticketNumber, $ticketId]);

            $pdo->prepare(
                "INSERT INTO support_status_history
                 (ticket_id, from_status, to_status, changed_by_type, changed_by, note, is_internal)
                 VALUES (?, NULL, 'new', 'client', NULL, NULL, 0)"
            )->execute([$ticketId]);

            $pdo->commit();
            $ticket = self::findById($ticketId);

            return [
                'ok' => true,
                'idempotent' => false,
                'ticket' => self::publicTicket($ticket ?: []),
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Concurrent retry with the same offline queue request id: return
            // the row that won the unique (license_id, client_request_id) race.
            if ($data['client_request_id'] !== null) {
                $existing = self::findByClientRequestId((int)$ctx['license_id'], $data['client_request_id']);
                if ($existing) {
                    return [
                        'ok' => true,
                        'idempotent' => true,
                        'ticket' => self::publicTicket($existing),
                    ];
                }
            }
            throw $e;
        }
    }

    public static function listForClient(
        string $licenseKey,
        string $hwid,
        ?string $ip = null,
        int $limit = 100
    ): array {
        $ctx = self::authorizeClient($licenseKey, $hwid, $ip);
        if (!$ctx['ok']) {
            return $ctx;
        }

        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->prepare(
            "SELECT t.*,
                    (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id AND m.sender_type = 'admin' AND m.is_internal = 0) AS admin_reply_count
             FROM support_tickets t
             WHERE t.license_id = ?
             ORDER BY t.updated_at DESC, t.id DESC
             LIMIT {$limit}"
        );
        $stmt->execute([$ctx['license_id']]);

        return [
            'ok' => true,
            'tickets' => array_map([self::class, 'publicTicket'], $stmt->fetchAll()),
        ];
    }

    public static function detailForClient(
        string $licenseKey,
        string $hwid,
        string $ticketNumber,
        ?string $ip = null
    ): array {
        $ctx = self::authorizeClient($licenseKey, $hwid, $ip);
        if (!$ctx['ok']) {
            return $ctx;
        }

        $ticket = self::findByNumberForLicense($ticketNumber, (int)$ctx['license_id']);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.', 'status' => 404];
        }

        $messages = Database::pdo()->prepare(
            'SELECT id, sender_type, sender_name, message, created_at
             FROM support_messages
             WHERE ticket_id = ? AND is_internal = 0
             ORDER BY created_at ASC, id ASC'
        );
        $messages->execute([$ticket['id']]);

        $history = Database::pdo()->prepare(
            'SELECT from_status, to_status, changed_by_type, changed_by, note, created_at
             FROM support_status_history
             WHERE ticket_id = ? AND is_internal = 0
             ORDER BY created_at ASC, id ASC'
        );
        $history->execute([$ticket['id']]);

        return [
            'ok' => true,
            'ticket' => self::publicTicket($ticket),
            'messages' => $messages->fetchAll(),
            'history' => $history->fetchAll(),
        ];
    }

    public static function addClientMessage(
        string $licenseKey,
        string $hwid,
        string $ticketNumber,
        string $message,
        ?string $ip = null
    ): array {
        $ctx = self::authorizeClient($licenseKey, $hwid, $ip);
        if (!$ctx['ok']) {
            return $ctx;
        }

        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 8000) {
            return ['ok' => false, 'error' => 'Message must be between 1 and 8000 characters.', 'status' => 400];
        }

        $ticket = self::findByNumberForLicense($ticketNumber, (int)$ctx['license_id']);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.', 'status' => 404];
        }
        if ($ticket['status'] === 'closed') {
            return ['ok' => false, 'error' => 'This ticket is closed.', 'status' => 409];
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_type, sender_name, message, is_internal)
                 VALUES (?, 'client', NULL, ?, 0)"
            );
            $stmt->execute([$ticket['id'], $message]);
            $messageId = (int)$pdo->lastInsertId();

            $pdo->prepare(
                'UPDATE support_tickets SET last_client_reply_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$ticket['id']]);

            $pdo->commit();
            return ['ok' => true, 'message_id' => $messageId];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function adminList(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '' && in_array($status, self::STATUSES, true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $type = trim((string)($filters['type'] ?? ''));
        if ($type !== '' && in_array($type, self::TYPES, true)) {
            $where[] = 't.type = ?';
            $params[] = $type;
        }

        $category = trim((string)($filters['category'] ?? ''));
        if ($category !== '' && in_array($category, self::CATEGORIES, true)) {
            $where[] = 't.category = ?';
            $params[] = $category;
        }

        $search = trim((string)($filters['search'] ?? ''));
        if ($search !== '') {
            $search = mb_substr($search, 0, 100);
            $where[] = '(t.ticket_number LIKE ? OR t.title LIKE ? OR c.name LIKE ? OR l.license_key LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle, $needle);
        }

        $limit = max(1, min(500, $limit));
        $sql = "SELECT t.*, l.license_key, c.name AS customer_name,
                       la.device_name, la.hwid,
                       (SELECT COUNT(*) FROM support_messages sm WHERE sm.ticket_id = t.id AND sm.is_internal = 0) AS public_message_count
                FROM support_tickets t
                JOIN licenses l ON l.id = t.license_id
                JOIN customers c ON c.id = l.customer_id
                LEFT JOIN license_activations la ON la.id = t.activation_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY FIELD(t.status, 'new','reviewed','in_progress','under_review','accepted','planned','resolved','implemented','closed','rejected','duplicate'), t.updated_at DESC, t.id DESC LIMIT {$limit}";

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public static function adminDetail(string $ticketNumber): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT t.*, l.license_key, c.name AS customer_name, c.phone AS customer_phone, c.email AS customer_email,
                    la.device_name, la.hwid
             FROM support_tickets t
             JOIN licenses l ON l.id = t.license_id
             JOIN customers c ON c.id = l.customer_id
             LEFT JOIN license_activations la ON la.id = t.activation_id
             WHERE t.ticket_number = ? LIMIT 1'
        );
        $stmt->execute([self::normalizeTicketNumber($ticketNumber)]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            return null;
        }

        $messages = Database::pdo()->prepare(
            'SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $messages->execute([$ticket['id']]);

        $history = Database::pdo()->prepare(
            'SELECT * FROM support_status_history WHERE ticket_id = ? ORDER BY created_at ASC, id ASC'
        );
        $history->execute([$ticket['id']]);

        $ticket['messages'] = $messages->fetchAll();
        $ticket['history'] = $history->fetchAll();
        return $ticket;
    }

    public static function adminReply(string $ticketNumber, string $adminUsername, string $message, bool $internal = false): array
    {
        $ticket = self::findByNumber($ticketNumber);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }

        $message = trim($message);
        if ($message === '' || mb_strlen($message) > 8000) {
            return ['ok' => false, 'error' => 'Reply must be between 1 and 8000 characters.'];
        }

        $adminUsername = mb_substr(trim($adminUsername), 0, 64);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO support_messages (ticket_id, sender_type, sender_name, message, is_internal)
                 VALUES (?, 'admin', ?, ?, ?)"
            );
            $stmt->execute([$ticket['id'], $adminUsername ?: null, $message, $internal ? 1 : 0]);

            if (!$internal) {
                $pdo->prepare(
                    'UPDATE support_tickets SET last_admin_reply_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
                )->execute([$ticket['id']]);
            }

            $pdo->commit();
            AuditLog::adminAction('support.reply', (int)$ticket['license_id'], 'Ticket ' . $ticket['ticket_number']);
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function adminChangeStatus(
        string $ticketNumber,
        string $newStatus,
        string $adminUsername,
        ?string $note = null,
        bool $internalNote = false,
        ?string $resolvedInVersion = null
    ): array {
        if (!in_array($newStatus, self::STATUSES, true)) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }

        $ticket = self::findByNumber($ticketNumber);
        if (!$ticket) {
            return ['ok' => false, 'error' => 'Ticket not found.'];
        }

        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null && mb_strlen($note) > 255) {
            return ['ok' => false, 'error' => 'Status note is too long.'];
        }

        $resolvedInVersion = $resolvedInVersion !== null ? trim($resolvedInVersion) : null;
        if ($resolvedInVersion === '') {
            $resolvedInVersion = null;
        }
        if ($resolvedInVersion !== null && mb_strlen($resolvedInVersion) > 50) {
            return ['ok' => false, 'error' => 'Resolved version is too long.'];
        }

        $adminUsername = mb_substr(trim($adminUsername), 0, 64);
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE support_tickets SET status = ?, resolved_in_version = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?'
            )->execute([$newStatus, $resolvedInVersion, $ticket['id']]);

            $pdo->prepare(
                "INSERT INTO support_status_history
                 (ticket_id, from_status, to_status, changed_by_type, changed_by, note, is_internal)
                 VALUES (?, ?, ?, 'admin', ?, ?, ?)"
            )->execute([
                $ticket['id'],
                $ticket['status'],
                $newStatus,
                $adminUsername ?: null,
                $note,
                $internalNote ? 1 : 0,
            ]);

            $pdo->commit();
            AuditLog::adminAction(
                'support.status',
                (int)$ticket['license_id'],
                $ticket['ticket_number'] . ': ' . $ticket['status'] . ' -> ' . $newStatus
            );
            return ['ok' => true];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function dashboardCounts(): array
    {
        $rows = Database::pdo()->query(
            'SELECT status, COUNT(*) AS total FROM support_tickets GROUP BY status'
        )->fetchAll();

        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($rows as $row) {
            $status = (string)$row['status'];
            if (array_key_exists($status, $counts)) {
                $counts[$status] = (int)$row['total'];
            }
        }
        return $counts;
    }

    public static function allowedTypes(): array
    {
        return self::TYPES;
    }

    public static function allowedCategories(): array
    {
        return self::CATEGORIES;
    }

    public static function allowedStatuses(): array
    {
        return self::STATUSES;
    }

    private static function authorizeClient(string $licenseKey, string $hwid, ?string $ip): array
    {
        $licenseKey = strtoupper(trim($licenseKey));
        $hwid = trim($hwid);

        if ($licenseKey === '' || strlen($licenseKey) > 29 || !preg_match('/^[A-Z0-9-]+$/', $licenseKey)) {
            return ['ok' => false, 'error' => 'Invalid license key.', 'status' => 400];
        }
        if ($hwid === '' || strlen($hwid) > 128 || preg_match('/[\x00-\x1F\x7F]/', $hwid)) {
            return ['ok' => false, 'error' => 'Invalid device id.', 'status' => 400];
        }

        $validation = License::validate($licenseKey, $hwid, $ip);
        if (empty($validation['ok']) || empty($validation['license']['id'])) {
            return ['ok' => false, 'error' => 'License or device is not authorized.', 'status' => 403];
        }

        $licenseId = (int)$validation['license']['id'];
        $stmt = Database::pdo()->prepare(
            'SELECT id FROM license_activations WHERE license_id = ? AND hwid = ? AND is_active = 1 LIMIT 1'
        );
        $stmt->execute([$licenseId, $hwid]);
        $activationId = $stmt->fetchColumn();
        if ($activationId === false) {
            return ['ok' => false, 'error' => 'Device activation is not available.', 'status' => 403];
        }

        return [
            'ok' => true,
            'license_id' => $licenseId,
            'activation_id' => (int)$activationId,
        ];
    }

    private static function validateCreatePayload(array $payload): array
    {
        $type = strtolower(trim((string)($payload['type'] ?? '')));
        $category = strtolower(trim((string)($payload['category'] ?? 'other')));
        $priority = strtolower(trim((string)($payload['priority'] ?? 'normal')));
        $title = trim((string)($payload['title'] ?? ''));
        $description = trim((string)($payload['description'] ?? ''));

        if (!in_array($type, self::TYPES, true)) {
            return ['ok' => false, 'error' => 'Invalid support ticket type.', 'status' => 400];
        }
        if (!in_array($category, self::CATEGORIES, true)) {
            return ['ok' => false, 'error' => 'Invalid support category.', 'status' => 400];
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            return ['ok' => false, 'error' => 'Invalid priority.', 'status' => 400];
        }
        if ($title === '' || mb_strlen($title) > 160) {
            return ['ok' => false, 'error' => 'Title must be between 1 and 160 characters.', 'status' => 400];
        }
        if ($description === '' || mb_strlen($description) > 10000) {
            return ['ok' => false, 'error' => 'Description must be between 1 and 10000 characters.', 'status' => 400];
        }

        $clientRequestId = self::nullableString($payload['client_request_id'] ?? $payload['clientRequestId'] ?? null, 64);
        if ($clientRequestId !== null && !preg_match('/^[A-Za-z0-9._:-]+$/', $clientRequestId)) {
            return ['ok' => false, 'error' => 'Invalid client_request_id.', 'status' => 400];
        }

        return [
            'ok' => true,
            'data' => [
                'type' => $type,
                'category' => $category,
                'priority' => $priority,
                'title' => $title,
                'description' => $description,
                'client_request_id' => $clientRequestId,
                'app_version' => self::nullableString($payload['app_version'] ?? $payload['appVersion'] ?? null, 50),
                'build' => self::nullableString($payload['build'] ?? null, 50),
                'os' => self::nullableString($payload['os'] ?? null, 120),
                'current_page' => self::nullableString($payload['current_page'] ?? $payload['currentPage'] ?? null, 100),
                'error_code' => self::nullableString($payload['error_code'] ?? $payload['errorCode'] ?? null, 100),
                'error_message' => self::nullableString($payload['error_message'] ?? $payload['errorMessage'] ?? null, 4000),
            ],
        ];
    }

    private static function nullableString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        return mb_substr($value, 0, $maxLength);
    }

    private static function normalizeTicketNumber(string $ticketNumber): string
    {
        return strtoupper(trim($ticketNumber));
    }

    private static function findById(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM support_tickets WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function findByNumber(string $ticketNumber): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM support_tickets WHERE ticket_number = ? LIMIT 1');
        $stmt->execute([self::normalizeTicketNumber($ticketNumber)]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function findByNumberForLicense(string $ticketNumber, int $licenseId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_tickets WHERE ticket_number = ? AND license_id = ? LIMIT 1'
        );
        $stmt->execute([self::normalizeTicketNumber($ticketNumber), $licenseId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function findByClientRequestId(int $licenseId, string $clientRequestId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_tickets WHERE license_id = ? AND client_request_id = ? LIMIT 1'
        );
        $stmt->execute([$licenseId, $clientRequestId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private static function publicTicket(array $ticket): array
    {
        $fields = [
            'ticket_number', 'type', 'category', 'title', 'description', 'priority', 'status',
            'app_version', 'build', 'os', 'current_page', 'error_code', 'error_message',
            'resolved_in_version', 'last_admin_reply_at', 'last_client_reply_at', 'created_at', 'updated_at',
            'admin_reply_count',
        ];
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $ticket)) {
                $out[$field] = $ticket[$field];
            }
        }
        return $out;
    }
}
