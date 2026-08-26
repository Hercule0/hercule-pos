<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$script = file_get_contents($root . '/scripts/check_production_health.sh');
$workflow = file_get_contents($root . '/.github/workflows/uptime-monitor.yml');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($script) || !is_string($workflow)) {
    $fail('health monitoring sources could not be read');
}

if (!str_contains($script, "--proto '=https'")) {
    $fail('health monitor can initiate non-HTTPS requests');
}
if (!str_contains($script, "--proto-redir '=https'")) {
    $fail('health monitor can follow redirects to non-HTTPS targets');
}
if (!str_contains($script, '--max-redirs 2')) {
    $fail('health monitor redirect count is not bounded');
}
if (!str_contains($script, '${#url} -gt 2048') || !str_contains($script, '[[:cntrl:]]')) {
    $fail('health URL length/control characters are not rejected');
}
if (!str_contains($workflow, 'actions/checkout@11d5960a326750d5838078e36cf38b85af677262')) {
    $fail('uptime workflow checkout is not pinned');
}
if (!str_contains($workflow, 'permissions:') || !str_contains($workflow, 'issues: write')) {
    $fail('uptime incident permissions are missing');
}

echo "PASS production health monitor transport hardening\n";
