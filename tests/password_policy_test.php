<?php
require_once __DIR__ . '/../includes/PasswordPolicy.php';

$failures = [];
function pp_check(string $label, bool $condition): void
{
    global $failures;
    echo ($condition ? '[PASS] ' : '[FAIL] ') . $label . "\n";
    if (!$condition) $failures[] = $label;
}

pp_check('Strong password is accepted', PasswordPolicy::validate('Hercule#2026Secure')['ok'] === true);
pp_check('Password shorter than 12 chars is rejected', PasswordPolicy::validate('Aa1!short')['ok'] === false);
pp_check('Missing uppercase is rejected', PasswordPolicy::validate('hercule#2026secure')['ok'] === false);
pp_check('Missing lowercase is rejected', PasswordPolicy::validate('HERCULE#2026SECURE')['ok'] === false);
pp_check('Missing number is rejected', PasswordPolicy::validate('Hercule#SecurePass')['ok'] === false);
pp_check('Missing symbol is rejected', PasswordPolicy::validate('Hercule2026Secure')['ok'] === false);
pp_check('Reusing current password is rejected', PasswordPolicy::validate('Hercule#2026Secure', 'Hercule#2026Secure')['ok'] === false);
pp_check('Different strong password passes current-password comparison', PasswordPolicy::validate('Hercule#2027Secure', 'Hercule#2026Secure')['ok'] === true);

if ($failures) {
    echo "\n" . count($failures) . " TEST(S) FAILED\n";
    exit(1);
}

echo "\nPASSWORD POLICY TESTS PASSED\n";
