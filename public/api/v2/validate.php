<?php
require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/../../../includes/MultiEntitlementPolicy.php';

$input = v2_input();
$bootstrapRequested = filter_var($input['bootstrap_if_unbound'] ?? false, FILTER_VALIDATE_BOOLEAN);
$bootstrapStage = 'request_received';

// Fix443: server-side stage tracing for the one-time entitlement bootstrap.
// The trace intentionally records only correlation/stage/result metadata. It
// never logs the request body, license key, HWID, store UUID, or device UUID.
$traceBootstrap = static function (string $stage, array $context = []): void {
    $entry = [
        'timestamp' => gmdate('c'),
        'service' => 'hercule-license-server',
        'request_id' => ErrorHandler::requestId() ?: 'unknown',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
        'path' => parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '',
        'level' => 'info',
        'event' => 'entitlement_bootstrap_trace',
        'stage' => $stage,
    ];
    error_log(json_encode(array_merge($entry, $context), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};

if ($bootstrapRequested) {
    $traceBootstrap('request_received');
}

v2_rate_limit('validate', $input);

try {
    $bootstrapStage = 'validate_enter';
    if ($bootstrapRequested) {
        $traceBootstrap('validate_enter');
    }

    $result = EntitlementV2::validate($input, client_ip());

    if ($bootstrapRequested) {
        $bootstrapStage = 'validate_return';
        $traceBootstrap('validate_return', [
            'result_ok' => (bool) ($result['ok'] ?? false),
            'result_status' => (string) ($result['status'] ?? ''),
        ]);
    }

    // Fix438 server bridge: legacy v1 installations may not yet have a
    // store_uuid bound on the server. G6A can request a one-time bootstrap
    // through the already proven validate.php route. This path is deliberately
    // narrow: only an authenticated/signed store_mismatch result may reach the
    // activation logic. A license already bound to another store remains
    // fail-closed because EntitlementV2::activate() re-checks store_uuid before
    // any mutation.
    if ($bootstrapRequested
        && !($result['ok'] ?? false)
        && strtolower((string) ($result['status'] ?? '')) === 'store_mismatch') {
        // Apply the same key/IP activation throttles as the dedicated endpoint.
        $bootstrapStage = 'activation_rate_limit_enter';
        $traceBootstrap('activation_rate_limit_enter');
        v2_rate_limit('activate', $input);
        $bootstrapStage = 'activation_rate_limit_ok';
        $traceBootstrap('activation_rate_limit_ok');

        $bootstrapStage = 'policy_enter';
        $traceBootstrap('policy_enter');
        $policy = MultiEntitlementPolicy::preflightActivation($input);
        $bootstrapStage = 'policy_return';
        $traceBootstrap('policy_return', [
            'policy_ok' => (bool) ($policy['ok'] ?? false),
            'policy_status' => (string) ($policy['status'] ?? ''),
        ]);
        if (!($policy['ok'] ?? false)) {
            $bootstrapStage = 'policy_rejected';
            $traceBootstrap('policy_rejected', [
                'policy_status' => (string) ($policy['status'] ?? ''),
            ]);
            v2_signed_response($policy);
        }

        $bootstrapStage = 'activate_enter';
        $traceBootstrap('activate_enter');
        $result = EntitlementV2::activate($input, client_ip());
        $bootstrapStage = 'activate_return';
        $traceBootstrap('activate_return', [
            'result_ok' => (bool) ($result['ok'] ?? false),
            'result_status' => (string) ($result['status'] ?? ''),
        ]);
    }

    if ($bootstrapRequested) {
        $bootstrapStage = 'signed_response_enter';
        $traceBootstrap('signed_response_enter', [
            'result_ok' => (bool) ($result['ok'] ?? false),
            'result_status' => (string) ($result['status'] ?? ''),
        ]);
    }

    v2_signed_response($result);
} catch (Throwable $e) {
    if ($bootstrapRequested) {
        ErrorHandler::report($e, 'entitlement_bootstrap_exception', [
            'stage' => $bootstrapStage,
        ]);
    }
    v2_exception_response($e);
}
