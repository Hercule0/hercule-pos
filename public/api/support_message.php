<?php
/** POST /api/support_message.php */

require_once __DIR__ . '/_support_common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$input = json_input(16384);
[$licenseKey, $hwid] = support_credentials($input);
support_rate_guard($licenseKey, 'support_message');

$ticketNumber = strtoupper(trim((string)($input['ticket_number'] ?? $input['ticketNumber'] ?? '')));
if ($ticketNumber === '' || strlen($ticketNumber) > 32 || !preg_match('/^HRC-[0-9]{4}-[0-9]{8}$/', $ticketNumber)) {
    json_response(['ok' => false, 'error' => 'Invalid ticket_number.'], 400);
}

$message = trim((string)($input['message'] ?? ''));
$result = SupportTicket::addClientMessage($licenseKey, $hwid, $ticketNumber, $message, client_ip());
support_json_result($result, 201);
