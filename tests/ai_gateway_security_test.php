<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$agent = file_get_contents($root . '/public/api/ai_agent.php');
$chat = file_get_contents($root . '/public/api/ai_chat.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($agent) || !is_string($chat)) {
    $fail('AI gateway sources could not be read');
}

$modeValidation = strpos($agent, "if (!in_array(\$mode, ['plan','synthesize'], true))");
$ipLimit = strpos($agent, "RateLimiter::check(client_ip(),'ai_agent_ip'");
$deviceCheck = strpos($agent, 'DeviceManager::isBlocked(');
$licenseCheck = strpos($agent, 'License::validate(');
$modeBucket = strpos($agent, "'ai_agent_'.\$mode");

if ($modeValidation === false) {
    $fail('AI agent mode is not allow-listed');
}
if ($ipLimit === false) {
    $fail('AI agent has no pre-license IP rate limit');
}
if ($deviceCheck === false || $licenseCheck === false || $modeBucket === false) {
    $fail('AI agent security ordering anchors are missing');
}
if ($modeValidation > $ipLimit || $modeValidation > $modeBucket) {
    $fail('AI agent uses mode before allow-list validation');
}
if ($ipLimit > $deviceCheck || $ipLimit > $licenseCheck) {
    $fail('AI agent performs device/license work before IP throttling');
}

if (str_contains($agent, "'providers'=>\$res['errors']")
    || str_contains($agent, "'providers' => \$res['errors']")) {
    $fail('AI agent exposes provider diagnostics to desktop clients');
}
if (str_contains($chat, "'providers' => \$errors")
    || str_contains($chat, "'providers'=>\$errors")) {
    $fail('AI chat exposes provider diagnostics to desktop clients');
}

if (!str_contains($agent, 'ai_log_provider_failure(')) {
    $fail('AI agent provider failures are not logged server-side');
}
if (!str_contains($chat, "'ai_chat_providers_unavailable'")) {
    $fail('AI chat provider failures are not logged server-side');
}

if (str_contains($agent, "ai_cut((string)(\$res['error']")
    || str_contains($chat, 'substr($error, 0, 120)')) {
    $fail('AI gateway still retains raw upstream provider error text in failure summaries');
}

if (!str_contains($agent, "'error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'")) {
    $fail('AI agent does not return a generic provider failure message');
}
if (!str_contains($chat, "'error' => 'تعذر الوصول إلى مزودي الذكاء حالياً.'")) {
    $fail('AI chat does not return a generic provider failure message');
}

echo "PASS AI gateway security hardening\n";
