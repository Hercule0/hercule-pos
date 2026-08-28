<?php
/** GET /api/support_detail.php?license_key=...&hwid=...&ticket_number=HRC-... */

require_once __DIR__ . '/_support_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$input = $_GET;
[$licenseKey, $hwid] = support_credentials($input);
support_rate_guard($licenseKey, 'support_detail');

$ticketNumber = strtoupper(trim((string)($input['ticket_number'] ?? $input['ticketNumber'] ?? '')));
if ($ticketNumber === '' || strlen($ticketNumber) > 32 || !preg_match('/^HRC-[0-9]{4}-[0-9]{8}$/', $ticketNumber)) {
    json_response(['ok' => false, 'error' => 'Invalid ticket_number.'], 400);
}

$result = SupportTicket::detailForClient($licenseKey, $hwid, $ticketNumber, client_ip());
support_json_result($result);
