<?php
declare(strict_types=1);

/**
 * Hercule POS Cloud AI Router
 *
 * POST /public/api/ai_chat.php
 * Body: {
 *   "license_key": "...",
 *   "hwid": "...",
 *   "question": "..."
 * }
 *
 * The endpoint only classifies a POS question into a fixed, allow-listed
 * intent. Provider credentials live in Azure App Service environment
 * variables and are never returned to the desktop client.
 */

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';

function ai_env(string $name, string $default = ''): string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return trim((string) $value);
}

function ai_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_utf8_len(string $text): int
{
    if (function_exists('mb_strlen')) {
        return (int) mb_strlen($text, 'UTF-8');
    }
    $count = preg_match_all('/./us', $text, $matches);
    return $count === false ? strlen($text) : $count;
}

function ai_utf8_sub(string $text, int $start, int $length): string
{
    if (function_exists('mb_substr')) {
        return (string) mb_substr($text, $start, $length, 'UTF-8');
    }
    $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
    if (!is_array($chars)) {
        return substr($text, $start, $length);
    }
    return implode('', array_slice($chars, $start, $length));
}

function ai_post_json(string $url, array $headers, array $payload, int $timeout = 12): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return ['status' => 0, 'json' => null, 'raw' => '', 'error' => 'JSON encode failed'];
    }

    $allHeaders = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: HerculePOS-AI-Gateway/1.0',
    ], $headers);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $raw = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'status' => $status,
            'json' => is_array($json) ? $json : null,
            'raw' => is_string($raw) ? $raw : '',
            'error' => $error,
        ];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $allHeaders),
            'content' => $body,
        ],
    ]);
    $raw = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ((array) ($http_response_header ?? []) as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches)) {
            $status = (int) $matches[1];
            break;
        }
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'status' => $status,
        'json' => is_array($json) ? $json : null,
        'raw' => is_string($raw) ? $raw : '',
        'error' => $raw === false ? 'HTTP request failed' : '',
    ];
}

function ai_provider_health(string $provider, ?bool $success = null, int $status = 0): int
{
    $path = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . 'hercule_ai_provider_health_v2.json';
    $fp = @fopen($path, 'c+');
    if (!$fp) {
        return 0;
    }

    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $state = json_decode($raw ?: '{}', true);
    if (!is_array($state)) {
        $state = [];
    }

    $now = time();
    if ($success === true) {
        unset($state[$provider]);
    } elseif ($success === false && ($status === 429 || $status === 408 || $status >= 500 || $status === 0)) {
        $cooldown = $status === 429
            ? max(15, (int) ai_env('HERCULE_AI_429_COOLDOWN_SEC', '45'))
            : max(5, (int) ai_env('HERCULE_AI_FAILURE_COOLDOWN_SEC', '20'));
        $state[$provider] = $now + min(300, $cooldown);
    }

    foreach ($state as $name => $until) {
        if ((int) $until <= $now) {
            unset($state[$name]);
        }
    }
    $until = (int) ($state[$provider] ?? 0);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $until;
}

function ai_clean_json_text(string $text): ?array
{
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $first = strpos($text, '{');
    $last = strrpos($text, '}');
    if ($first !== false && $last !== false && $last >= $first) {
        $text = substr($text, $first, $last - $first + 1);
    }
    $decoded = json_decode($text, true);
    return is_array($decoded) ? $decoded : null;
}

function ai_normalize_route(?array $route, array $catalog): ?array
{
    if (!$route) {
        return null;
    }

    $intent = trim((string) ($route['intent'] ?? ''));
    if (!array_key_exists($intent, $catalog)) {
        return null;
    }

    $entityType = strtolower(trim((string) (
        $route['entity_type'] ?? ($route['entity']['type'] ?? 'none')
    )));
    $entityQuery = trim((string) (
        $route['entity_query'] ?? ($route['entity']['query'] ?? '')
    ));

    $allowedEntityTypes = ['none', 'product', 'customer', 'supplier', 'category', 'cashier'];
    if (!in_array($entityType, $allowedEntityTypes, true)) {
        $entityType = 'none';
    }
    if (ai_utf8_len($entityQuery) > 120) {
        $entityQuery = ai_utf8_sub($entityQuery, 0, 120);
    }

    $requiredEntity = (string) ($catalog[$intent]['entity'] ?? '');
    if ($requiredEntity !== '' && ($entityType !== $requiredEntity || $entityQuery === '')) {
        return null;
    }
    if ($requiredEntity === '' && $entityQuery === '') {
        $entityType = 'none';
    }

    return [
        'intent' => $intent,
        'entity_type' => $entityType,
        'entity_query' => $entityQuery,
    ];
}

function ai_provider_prompt(string $question, array $catalog): string
{
    $intents = [];
    foreach ($catalog as $name => $meta) {
        $entity = (string) ($meta['entity'] ?? '');
        $intents[] = $name . ($entity !== '' ? '[' . $entity . ']' : '');
    }

    return "Hercule POS intent router. User may write Iraqi Arabic, Arabic, or English. Do not answer or calculate.\n"
        . "Return ONLY JSON: {\"intent\":\"...\",\"entity_type\":\"none|product|customer|supplier|category|cashier\",\"entity_query\":\"\"}.\n"
        . "Copy entity_query only for an explicitly named record; never invent names. Use none only outside store/POS data.\n"
        . "Examples: net after expenses=net_profit; customer debt=customer_account; named customer sales=customer_sales; named product stock=product_status; supplier balance=supplier_balances; drawer difference=cash_reconciliation; invoice number=invoice_lookup.\n"
        . 'Intents: ' . implode(',', $intents) . "\n"
        . 'Question: ' . $question;
}

function ai_call_gemini(string $prompt): array
{
    $key = ai_env('GEMINI_API_KEY');
    if ($key === '') {
        return ['skip' => true];
    }

    $model = ai_env('GEMINI_MODEL', 'gemini-2.5-flash-lite');
    $base = rtrim(ai_env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'), '/');
    $url = $base . '/models/' . rawurlencode($model) . ':generateContent';
    $res = ai_post_json($url, ['x-goog-api-key: ' . $key], [
        'contents' => [[
            'role' => 'user',
            'parts' => [['text' => $prompt]],
        ]],
        'generationConfig' => [
            'temperature' => 0.1,
            'maxOutputTokens' => 180,
            'responseMimeType' => 'application/json',
        ],
    ]);

    return [
        'status' => $res['status'],
        'text' => (string) ($res['json']['candidates'][0]['content']['parts'][0]['text'] ?? ''),
        'error' => $res['error'] ?: (string) ($res['json']['error']['message'] ?? ''),
    ];
}

function ai_call_groq(string $prompt): array
{
    $key = ai_env('GROQ_API_KEY');
    if ($key === '') {
        return ['skip' => true];
    }

    $model = ai_env('GROQ_MODEL', 'openai/gpt-oss-20b');
    $base = rtrim(ai_env('GROQ_API_BASE', 'https://api.groq.com/openai/v1'), '/');
    $res = ai_post_json($base . '/chat/completions', ['Authorization: Bearer ' . $key], [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => 'Return strict JSON only.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.1,
        'max_completion_tokens' => 180,
        'response_format' => ['type' => 'json_object'],
    ]);

    return [
        'status' => $res['status'],
        'text' => (string) ($res['json']['choices'][0]['message']['content'] ?? ''),
        'error' => $res['error'] ?: (string) ($res['json']['error']['message'] ?? ''),
    ];
}

function ai_call_cloudflare(string $prompt): array
{
    $token = ai_env('CLOUDFLARE_AI_TOKEN');
    $accountId = ai_env('CLOUDFLARE_ACCOUNT_ID');
    if ($token === '' || $accountId === '') {
        return ['skip' => true];
    }

    $model = ai_env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3.1-8b-instruct-fast');
    $base = rtrim(ai_env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'), '/');
    $url = $base . '/accounts/' . rawurlencode($accountId)
        . '/ai/run/' . str_replace('%2F', '/', rawurlencode($model));

    $res = ai_post_json($url, ['Authorization: Bearer ' . $token], [
        'prompt' => $prompt,
        'max_tokens' => 180,
        'temperature' => 0.1,
    ]);

    return [
        'status' => $res['status'],
        'text' => (string) ($res['json']['result']['response'] ?? ''),
        'error' => $res['error'] ?: (string) ($res['json']['errors'][0]['message'] ?? ''),
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    ai_json(['ok' => false, 'code' => 'METHOD_NOT_ALLOWED', 'error' => 'POST only'], 405);
}

$body = json_input();
$licenseKey = trim((string) ($body['license_key'] ?? $body['licenseKey'] ?? ''));
$hwid = trim((string) ($body['hwid'] ?? $body['hardware_id'] ?? $body['hardwareId'] ?? ''));
$question = trim((string) ($body['question'] ?? ''));

if ($licenseKey === '' || $hwid === '') {
    ai_json(['ok' => false, 'code' => 'LICENSE_REQUIRED', 'error' => 'License identity required'], 401);
}
if ($question === '' || ai_utf8_len($question) > 700) {
    ai_json(['ok' => false, 'code' => 'INVALID_QUESTION', 'error' => 'Invalid question'], 400);
}

$ipLimit = max(10, (int) ai_env('HERCULE_AI_IP_RPM', '60'));
if (!RateLimiter::check(client_ip(), 'ai_ip', $ipLimit, 1)) {
    ai_json(['ok' => false, 'code' => 'AI_RATE_LIMIT', 'error' => 'طلبات كثيرة جداً. جرّب بعد قليل.'], 429);
}

if (DeviceManager::isBlocked($licenseKey, $hwid)) {
    ai_json(['ok' => false, 'code' => 'DEVICE_BLOCKED', 'error' => 'This device is blocked.'], 403);
}

$validation = License::validate($licenseKey, $hwid, client_ip());
if (!($validation['ok'] ?? false)) {
    ai_json(['ok' => false, 'code' => 'LICENSE_INVALID', 'error' => 'License is not valid for AI service'], 403);
}

$perLicenseRpm = max(1, (int) ai_env('HERCULE_AI_PER_LICENSE_RPM', '12'));
$globalRpm = max($perLicenseRpm, (int) ai_env('HERCULE_AI_GLOBAL_RPM', '30'));
$licenseBucket = 'lic:' . substr(hash('sha256', $licenseKey), 0, 40);
if (!RateLimiter::check($licenseBucket, 'ai_license', $perLicenseRpm, 1)
    || !RateLimiter::check('global', 'ai_global', $globalRpm, 1)) {
    ai_json([
        'ok' => false,
        'code' => 'AI_RATE_LIMIT',
        'error' => 'التحليل الذكي مشغول حالياً. جرّب بعد قليل.',
    ], 429);
}

$catalogPath = __DIR__ . DIRECTORY_SEPARATOR . 'intent_catalog.json';
$catalog = json_decode((string) @file_get_contents($catalogPath), true);
if (!is_array($catalog) || !$catalog) {
    ai_json(['ok' => false, 'code' => 'CATALOG_ERROR', 'error' => 'AI routing catalog unavailable'], 500);
}

$prompt = ai_provider_prompt($question, $catalog);
$order = array_values(array_filter(array_map(
    'trim',
    explode(',', strtolower(ai_env('HERCULE_AI_PROVIDER_ORDER', 'gemini,groq,cloudflare')))
)));
$providers = [
    'gemini' => 'ai_call_gemini',
    'groq' => 'ai_call_groq',
    'cloudflare' => 'ai_call_cloudflare',
];
$errors = [];

foreach ($order as $name) {
    if (!isset($providers[$name])) {
        continue;
    }

    $coolUntil = ai_provider_health($name);
    if ($coolUntil > time()) {
        $errors[] = $name . ':cooldown';
        continue;
    }

    $result = $providers[$name]($prompt);
    if (!empty($result['skip'])) {
        $errors[] = $name . ':not_configured';
        continue;
    }

    $status = (int) ($result['status'] ?? 0);
    $route = ai_normalize_route(
        ai_clean_json_text((string) ($result['text'] ?? '')),
        $catalog
    );

    if ($route) {
        ai_provider_health($name, true, $status);
        ai_json([
            'ok' => true,
            'provider' => $name,
            'route' => $route,
        ]);
    }

    ai_provider_health($name, false, $status);
    $errors[] = $name . ':' . $status;
}

ErrorHandler::report(
    new RuntimeException('AI provider routing failed.'),
    'ai_chat_providers_unavailable',
    ['providers' => array_slice($errors, 0, 8)]
);
ai_json([
    'ok' => false,
    'code' => 'AI_PROVIDERS_UNAVAILABLE',
    'error' => 'تعذر الوصول إلى مزودي الذكاء حالياً.',
], 503);
