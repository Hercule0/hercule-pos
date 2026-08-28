<?php
/** POST /api/support_create.php */

require_once __DIR__ . '/_support_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_input(32768);
[$licenseKey, $hwid] = support_credentials($input);
support_rate_guard($licenseKey, 'support_create');

$result = SupportTicket::create($licenseKey, $hwid, $input, client_ip());
support_json_result($result, !empty($result['idempotent']) ? 200 : 201);
