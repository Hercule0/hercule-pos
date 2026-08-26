<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/public/api/_bootstrap.php');
$agent = file_get_contents($root . '/public/api/ai_agent.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($bootstrap) || !is_string($agent)) {
    $fail('public API security sources could not be read');
}

if (!str_contains($bootstrap, 'function json_input(int $maxBytes = 16384): array')) {
    $fail('normal public API request bodies do not retain the 16 KiB default limit');
}
if (!str_contains($bootstrap, '$maxBytes = max(1024, min(262144, $maxBytes));')) {
    $fail('custom JSON body limits are not globally bounded');
}
if (!str_contains($bootstrap, "file_get_contents('php://input', false, null, 0, $maxBytes + 1)")) {
    $fail('JSON parser does not stop reading after the bounded request size');
}
if (!str_contains($bootstrap, "Strict-Transport-Security: max-age=31536000")) {
    $fail('public API HTTPS responses are missing HSTS');
}
if (!str_contains($agent, '$body = json_input(65536);')) {
    $fail('AI agent does not opt into the bounded 64 KiB tool-result envelope');
}
if (str_contains($agent, 'json_input(262144)')) {
    $fail('AI agent unnecessarily uses the maximum public API body allowance');
}

echo "PASS public API bootstrap security and payload bounds\n";
