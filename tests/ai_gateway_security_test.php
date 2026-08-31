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

$modeValidation = strpos($agent, "if (!in_array(\$mode, ['understand','plan','synthesize'], true))");
$privacyGate = strpos($agent, '$privacy = ai_privacy_policy($body);');
$questionRedaction = strpos($agent, 'ai_privacy_redact_text($question, 6000)');
$ipLimit = strpos($agent, "RateLimiter::check(client_ip(),'ai_agent_ip'");
$deviceCheck = strpos($agent, 'DeviceManager::isBlocked(');
$licenseCheck = strpos($agent, 'License::validate(');
$modeBucket = strpos($agent, "'ai_agent_'.\$mode");
$providerCall = strpos($agent, '$res = ai_providers($prompt');

if ($modeValidation === false) {
    $fail('AI agent mode is not allow-listed');
}
if ($privacyGate === false || !str_contains($agent, 'HERCULE_AI_PRIVACY_CONSENT_VERSION = 1')
    || !str_contains($agent, 'AI_PRIVACY_CONSENT_REQUIRED')) {
    $fail('AI agent does not enforce the cloud privacy consent contract');
}
if ($questionRedaction === false
    || !str_contains($agent, "ai_privacy_sanitize(is_array(\$body['context']")
    || !str_contains($agent, "ai_privacy_sanitize(is_array(\$body['tool_results']")) {
    $fail('AI agent does not apply server-side redaction to cloud-bound content');
}
if ($ipLimit === false) {
    $fail('AI agent has no pre-license IP rate limit');
}
if ($deviceCheck === false || $licenseCheck === false || $modeBucket === false) {
    $fail('AI agent security ordering anchors are missing');
}
if ($modeValidation > $privacyGate || $privacyGate > $ipLimit || $privacyGate > $providerCall) {
    $fail('AI privacy consent must be validated before rate-limited/provider work');
}
if ($modeValidation > $modeBucket) {
    $fail('AI agent uses mode before allow-list validation');
}
if ($ipLimit > $deviceCheck || $ipLimit > $licenseCheck) {
    $fail('AI agent performs device/license work before IP throttling');
}

if (str_contains($agent, "'providers'=>\$res['errors']")
    || str_contains($agent, "'providers' => \$res['errors']")) {
    $fail('AI agent exposes provider diagnostics to desktop clients');
}
if (!str_contains($agent, 'ai_log_provider_failure(')) {
    $fail('AI agent provider failures are not logged server-side');
}
if (str_contains($agent, "ai_cut((string)(\$res['error']")) {
    $fail('AI agent still retains raw upstream provider error text in failure summaries');
}
if (!str_contains($agent, "'error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'")) {
    $fail('AI agent does not return a generic provider failure message');
}

if (!str_contains($chat, 'AI_LEGACY_ENDPOINT_RETIRED')) {
    $fail('legacy AI endpoint is still enabled without the privacy consent contract');
}
if (str_contains($chat, 'ai_call_gemini(')
    || str_contains($chat, 'ai_call_groq(')
    || str_contains($chat, 'ai_call_cloudflare(')) {
    $fail('legacy AI endpoint can still call an upstream provider');
}

echo "PASS AI gateway security + privacy hardening\n";
