<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manager = file_get_contents($root . '/includes/ReleaseManager.php');
$eventEndpoint = file_get_contents($root . '/public/api/release_event.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};

if (!is_string($manager) || !is_string($eventEndpoint)) {
    $fail('release entitlement sources could not be read');
}

foreach ([
    'l.expires_at AS license_expires_at' => 'update eligibility does not load license expiry',
    "['ok'=>false,'code'=>'LICENSE_EXPIRED']" => 'expired licenses are not rejected during update eligibility',
    "(l.expires_at IS NULL OR l.expires_at > NOW())" => 'download grants do not re-check license expiry',
    'a.id=g.activation_id AND a.license_id=g.license_id' => 'download grant does not bind activation back to the grant license',
    "AND (expires_at IS NULL OR expires_at > NOW())" => 'targeted release selection still accepts expired licenses',
] as $needle => $message) {
    if (!str_contains($manager, $needle)) {
        $fail($message);
    }
}

foreach ([
    'l.expires_at AS license_expires_at' => 'update event endpoint does not load license expiry',
    '$licenseExpired = !empty($client[\'license_expires_at\'])' => 'update event endpoint does not calculate entitlement expiry',
    '|| $licenseExpired ||' => 'expired license can still submit update telemetry',
] as $needle => $message) {
    if (!str_contains($eventEndpoint, $needle)) {
        $fail($message);
    }
}

$expiryCheck = strpos($manager, "code'=>'LICENSE_EXPIRED");
$releaseQuery = strpos($manager, 'SELECT DISTINCT r.* FROM app_releases r');
if ($expiryCheck === false || $releaseQuery === false || $expiryCheck > $releaseQuery) {
    $fail('license expiry is checked only after release selection');
}

$grantExpiry = strpos($manager, '(l.expires_at IS NULL OR l.expires_at > NOW())');
$grantReturn = strpos($manager, 'return $row ?: null;', $grantExpiry === false ? 0 : $grantExpiry);
if ($grantExpiry === false || $grantReturn === false || $grantExpiry > $grantReturn) {
    $fail('download grant can resolve without a current license entitlement');
}

echo "PASS release entitlement expiry hardening\n";
