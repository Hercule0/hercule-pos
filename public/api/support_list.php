<?php
/** GET /api/support_list.php?license_key=...&hwid=... */

require_once __DIR__ . '/_support_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$input = $_GET;
[$licenseKey, $hwid] = support_credentials($input);
support_rate_guard($licenseKey, 'support_list');

$limit = (int)($input['limit'] ?? 100);
$result = SupportTicket::listForClient($licenseKey, $hwid, client_ip(), $limit);
support_json_result($result);
