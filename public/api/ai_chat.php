<?php
declare(strict_types=1);

/**
 * Legacy Hercule AI router retired by Fix 134.
 *
 * Older clients used this endpoint without the explicit cloud-privacy consent
 * contract. Keeping it provider-capable would create a bypass around the new
 * desktop + server opt-in gate. New clients use /public/api/ai_agent.php and
 * send privacy.consent_version + privacy.scope on every cloud request.
 */
require_once __DIR__ . '/_bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(426);
echo json_encode([
    'ok'=>false,
    'code'=>'AI_LEGACY_ENDPOINT_RETIRED',
    'error'=>'هذا المسار القديم متوقف لحماية الخصوصية. حدّث Hercule POS إلى إصدار يدعم موافقة الذكاء السحابي.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
