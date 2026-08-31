<?php
declare(strict_types=1);

/*
 * Hercule Fix 277 — AI Quota, Fair-Use & Model Budget Manager.
 * Server-only accounting. Never exposes provider identities or secrets to clients.
 * State is daily, lock-protected, privacy-safe (license hashes only; no questions/prompts stored).
 */

function ai_quota_env_int(string $key, int $default, int $min = 0, int $max = PHP_INT_MAX): int {
    $raw = function_exists('ai_env') ? ai_env($key, (string)$default) : (string)$default;
    $value = is_numeric($raw) ? (int)$raw : $default;
    return max($min, min($max, $value));
}
function ai_quota_env_float(string $key, float $default, float $min = 0.0, float $max = 1.0): float {
    $raw = function_exists('ai_env') ? ai_env($key, (string)$default) : (string)$default;
    $value = is_numeric($raw) ? (float)$raw : $default;
    return max($min, min($max, $value));
}
function ai_quota_license_hash(string $licenseKey): string {
    return substr(hash('sha256', trim($licenseKey)), 0, 40);
}
function ai_quota_cost_units(string $tier, string $mode = ''): int {
    $tier = function_exists('ai_model_router_tier') ? ai_model_router_tier($tier) : strtolower(trim($tier));
    $mode = strtolower(trim($mode));
    if ($mode === 'understand') return 1;
    if ($mode === 'plan') return $tier === 'strongest' ? 4 : ($tier === 'standard' ? 2 : 1);
    return $tier === 'strongest' ? 7 : ($tier === 'standard' ? 3 : 1);
}
function ai_quota_state_dir(): string {
    $configured = function_exists('ai_env') ? ai_env('HERCULE_AI_QUOTA_DIR', '') : '';
    $dir = trim($configured) !== '' ? trim($configured) : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'hercule-ai-quota-v1';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir;
}
function ai_quota_day(): string { return gmdate('Y-m-d'); }
function ai_quota_state_file(): string { return ai_quota_state_dir().DIRECTORY_SEPARATOR.'quota-'.str_replace('-', '', ai_quota_day()).'.json'; }
function ai_quota_default_state(): array {
    return [
        'version'=>1,
        'day'=>ai_quota_day(),
        'global'=>['used_units'=>0,'requests'=>0,'estimated_tokens'=>0],
        'licenses'=>[],
        'providers'=>[],
    ];
}
function ai_quota_transaction(callable $callback) {
    $path = ai_quota_state_file();
    $fh = @fopen($path, 'c+');
    if (!$fh) { $fallback = ai_quota_default_state(); return $callback($fallback); }
    try {
        if (!flock($fh, LOCK_EX)) { $fallback = ai_quota_default_state(); return $callback($fallback); }
        rewind($fh);
        $raw = stream_get_contents($fh);
        $state = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($state) || ($state['day'] ?? '') !== ai_quota_day()) $state = ai_quota_default_state();
        $result = $callback($state);
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        fflush($fh);
        @chmod($path, 0600);
        flock($fh, LOCK_UN);
        return $result;
    } finally { fclose($fh); }
}
function ai_quota_prune_inflight(array &$license): void {
    $now = time();
    $inflight = is_array($license['inflight'] ?? null) ? $license['inflight'] : [];
    foreach ($inflight as $id=>$expiry) if ((int)$expiry <= $now) unset($inflight[$id]);
    $license['inflight'] = $inflight;
}
function ai_quota_global_budget(): int { return ai_quota_env_int('HERCULE_AI_GLOBAL_DAILY_UNITS', 6000, 60, 10000000); }
function ai_quota_license_base_budget(): int { return ai_quota_env_int('HERCULE_AI_PER_LICENSE_DAILY_UNITS', 80, 10, 100000); }
function ai_quota_expected_active_licenses(): int { return ai_quota_env_int('HERCULE_AI_EXPECTED_ACTIVE_LICENSES', 100, 1, 100000); }
function ai_quota_license_budget(array $state): int {
    $global = ai_quota_global_budget();
    $activeToday = max(1, count(is_array($state['licenses'] ?? null) ? $state['licenses'] : []));
    $denominator = max(ai_quota_expected_active_licenses(), $activeToday);
    $fairShare = max(10, (int)floor($global / $denominator));
    return min(ai_quota_license_base_budget(), $fairShare);
}
function ai_quota_reserve_ratio(): float { return ai_quota_env_float('HERCULE_AI_GLOBAL_RESERVE_RATIO', 0.05, 0.0, 0.30); }
function ai_quota_usage_mode(float $ratio): string {
    if ($ratio >= 0.95) return 'local_only';
    if ($ratio >= 0.85) return 'economy';
    if ($ratio >= 0.70) return 'conservative';
    return 'normal';
}
function ai_quota_effective_tier_for_usage(string $requestedTier, float $licenseRatio, float $globalRatio, int $heavyUsed): string {
    $tier = function_exists('ai_model_router_tier') ? ai_model_router_tier($requestedTier) : strtolower(trim($requestedTier));
    $ratio = max($licenseRatio, $globalRatio);
    $heavyMax = ai_quota_env_int('HERCULE_AI_PER_LICENSE_HEAVY_DAILY', 5, 0, 10000);
    if ($ratio >= 0.85) return 'fast';
    if ($ratio >= 0.70 && $tier === 'strongest') return 'standard';
    if ($tier === 'strongest' && $heavyMax >= 0 && $heavyUsed >= $heavyMax) return 'standard';
    return $tier;
}
function ai_quota_reserve_request(string $licenseHash, string $mode, string $requestedTier): array {
    return ai_quota_transaction(function (&$state) use ($licenseHash, $mode, $requestedTier) {
        $license = is_array($state['licenses'][$licenseHash] ?? null) ? $state['licenses'][$licenseHash] : [
            'used_units'=>0,'requests'=>0,'heavy_requests'=>0,'estimated_tokens'=>0,'last_seen'=>0,'inflight'=>[]
        ];
        ai_quota_prune_inflight($license);
        $maxConcurrent = ai_quota_env_int('HERCULE_AI_PER_LICENSE_CONCURRENT', 2, 1, 20);
        if (count($license['inflight']) >= $maxConcurrent) {
            $state['licenses'][$licenseHash] = $license;
            return ['ok'=>false,'code'=>'AI_FAIR_USE_CONCURRENT','mode'=>'busy'];
        }
        $licenseBudget = ai_quota_license_budget($state);
        $globalBudget = ai_quota_global_budget();
        $usableGlobal = max(1, (int)floor($globalBudget * (1.0 - ai_quota_reserve_ratio())));
        $licenseRatio = ((int)$license['used_units']) / max(1, $licenseBudget);
        $globalUsed = (int)($state['global']['used_units'] ?? 0);
        $globalRatio = $globalUsed / max(1, $usableGlobal);
        if ($licenseRatio >= 1.0 || $globalRatio >= 1.0) {
            $state['licenses'][$licenseHash] = $license;
            return ['ok'=>false,'code'=>'AI_FAIR_USE_LIMIT','mode'=>'local_only','license_ratio'=>$licenseRatio,'global_ratio'=>$globalRatio];
        }
        $effectiveTier = ai_quota_effective_tier_for_usage($requestedTier, $licenseRatio, $globalRatio, (int)$license['heavy_requests']);
        $units = ai_quota_cost_units($effectiveTier, $mode);
        if (((int)$license['used_units'] + $units) > $licenseBudget || ($globalUsed + $units) > $usableGlobal) {
            $state['licenses'][$licenseHash] = $license;
            return ['ok'=>false,'code'=>'AI_FAIR_USE_LIMIT','mode'=>'local_only','license_ratio'=>$licenseRatio,'global_ratio'=>$globalRatio];
        }
        $id = bin2hex(random_bytes(8));
        $expiry = time() + ai_quota_env_int('HERCULE_AI_INFLIGHT_TTL_SEC', 75, 20, 300);
        $license['inflight'][$id] = $expiry;
        $license['used_units'] = (int)$license['used_units'] + $units;
        $license['requests'] = (int)$license['requests'] + 1;
        if ($effectiveTier === 'strongest' && $mode === 'synthesize') $license['heavy_requests'] = (int)$license['heavy_requests'] + 1;
        $license['last_seen'] = time();
        $state['licenses'][$licenseHash] = $license;
        $state['global']['used_units'] = $globalUsed + $units;
        $state['global']['requests'] = (int)($state['global']['requests'] ?? 0) + 1;
        return [
            'ok'=>true,'id'=>$id,'license_hash'=>$licenseHash,'mode'=>ai_quota_usage_mode(max($licenseRatio,$globalRatio)),
            'requested_tier'=>$requestedTier,'effective_tier'=>$effectiveTier,'request_mode'=>$mode,'units'=>$units,
            'license_budget'=>$licenseBudget,'global_budget'=>$usableGlobal,
        ];
    });
}
function ai_quota_release_request(array $reservation, bool $success, int $estimatedTokens = 0): void {
    if (empty($reservation['ok']) || empty($reservation['id']) || empty($reservation['license_hash'])) return;
    ai_quota_transaction(function (&$state) use ($reservation, $success, $estimatedTokens) {
        $hash = (string)$reservation['license_hash'];
        $license = is_array($state['licenses'][$hash] ?? null) ? $state['licenses'][$hash] : null;
        if (!$license) return null;
        unset($license['inflight'][(string)$reservation['id']]);
        if (!$success) {
            $units = max(0, (int)($reservation['units'] ?? 0));
            $license['used_units'] = max(0, (int)$license['used_units'] - $units);
            $license['requests'] = max(0, (int)$license['requests'] - 1);
            if (($reservation['effective_tier'] ?? '') === 'strongest' && ($reservation['request_mode'] ?? '') === 'synthesize') $license['heavy_requests'] = max(0, (int)$license['heavy_requests'] - 1);
            $state['global']['used_units'] = max(0, (int)($state['global']['used_units'] ?? 0) - $units);
            $state['global']['requests'] = max(0, (int)($state['global']['requests'] ?? 0) - 1);
        } else {
            $tokens = max(0, $estimatedTokens);
            $license['estimated_tokens'] = (int)($license['estimated_tokens'] ?? 0) + $tokens;
            $state['global']['estimated_tokens'] = (int)($state['global']['estimated_tokens'] ?? 0) + $tokens;
        }
        $state['licenses'][$hash] = $license;
        return null;
    });
}
function ai_quota_provider_budget(string $provider): int {
    $provider = strtoupper(trim($provider));
    $fallback = max(20, (int)floor(ai_quota_global_budget() / 3));
    return ai_quota_env_int('HERCULE_AI_PROVIDER_DAILY_UNITS_'.$provider, $fallback, 10, 10000000);
}
function ai_quota_provider_snapshot(): array {
    return ai_quota_transaction(function (&$state) { return is_array($state['providers'] ?? null) ? $state['providers'] : []; });
}
function ai_quota_rank_providers(array $providers, string $tier): array {
    $providers = array_values(array_unique(array_filter(array_map(fn($x)=>strtolower(trim((string)$x)), $providers))));
    $snapshot = ai_quota_provider_snapshot();
    $reserve = ai_quota_env_float('HERCULE_AI_PROVIDER_RESERVE_RATIO', 0.05, 0.0, 0.30);
    $now = time();
    $rows = [];
    foreach ($providers as $index=>$provider) {
        $p = is_array($snapshot[$provider] ?? null) ? $snapshot[$provider] : [];
        if ((int)($p['cooldown_until'] ?? 0) > $now) continue;
        $budget = ai_quota_provider_budget($provider);
        $usable = max(1, (int)floor($budget * (1.0 - $reserve)));
        $used = (int)($p['used_units'] ?? 0);
        if (($used + ai_quota_cost_units($tier)) > $usable) continue;
        $ratio = $used / $usable;
        $rows[] = ['provider'=>$provider,'score'=>$ratio + ($index * 0.0001)];
    }
    usort($rows, fn($a,$b)=>$a['score'] <=> $b['score']);
    return array_map(fn($r)=>$r['provider'], $rows);
}
function ai_quota_provider_reserve_attempt(string $provider, string $tier, int $estimatedTokens): array {
    return ai_quota_transaction(function (&$state) use ($provider, $tier, $estimatedTokens) {
        $provider = strtolower(trim($provider));
        $p = is_array($state['providers'][$provider] ?? null) ? $state['providers'][$provider] : ['used_units'=>0,'requests'=>0,'estimated_tokens'=>0,'cooldown_until'=>0,'rate_limits'=>0];
        if ((int)$p['cooldown_until'] > time()) return ['ok'=>false,'code'=>'provider_cooldown'];
        $units = ai_quota_cost_units($tier);
        $budget = ai_quota_provider_budget($provider);
        $usable = max(1, (int)floor($budget * (1.0 - ai_quota_env_float('HERCULE_AI_PROVIDER_RESERVE_RATIO', 0.05, 0.0, 0.30))));
        if (((int)$p['used_units'] + $units) > $usable) return ['ok'=>false,'code'=>'provider_budget'];
        $p['used_units'] = (int)$p['used_units'] + $units;
        $p['requests'] = (int)$p['requests'] + 1;
        $p['estimated_tokens'] = (int)$p['estimated_tokens'] + max(0,$estimatedTokens);
        $p['last_attempt_at'] = time();
        $state['providers'][$provider] = $p;
        return ['ok'=>true,'provider'=>$provider,'units'=>$units,'estimated_tokens'=>max(0,$estimatedTokens)];
    });
}
function ai_quota_provider_refund_attempt(array $attempt): void {
    if (empty($attempt['ok']) || empty($attempt['provider'])) return;
    ai_quota_transaction(function (&$state) use ($attempt) {
        $provider = (string)$attempt['provider'];
        $p = is_array($state['providers'][$provider] ?? null) ? $state['providers'][$provider] : null;
        if (!$p) return null;
        $p['used_units'] = max(0, (int)$p['used_units'] - max(0,(int)$attempt['units']));
        $p['requests'] = max(0, (int)$p['requests'] - 1);
        $p['estimated_tokens'] = max(0, (int)$p['estimated_tokens'] - max(0,(int)$attempt['estimated_tokens']));
        $state['providers'][$provider] = $p;
        return null;
    });
}
function ai_quota_provider_record_status(string $provider, int $status): void {
    if ($status !== 429) return;
    ai_quota_transaction(function (&$state) use ($provider) {
        $provider = strtolower(trim($provider));
        $p = is_array($state['providers'][$provider] ?? null) ? $state['providers'][$provider] : ['used_units'=>0,'requests'=>0,'estimated_tokens'=>0,'cooldown_until'=>0,'rate_limits'=>0];
        $p['rate_limits'] = (int)($p['rate_limits'] ?? 0) + 1;
        $p['cooldown_until'] = time() + ai_quota_env_int('HERCULE_AI_PROVIDER_429_COOLDOWN_SEC', 1800, 60, 86400);
        $state['providers'][$provider] = $p;
        return null;
    });
}
function ai_quota_cache_ttl(string $mode): int {
    $mode = strtolower(trim($mode));
    $key = $mode === 'understand' ? 'HERCULE_AI_CACHE_UNDERSTAND_TTL_SEC' : ($mode === 'plan' ? 'HERCULE_AI_CACHE_PLAN_TTL_SEC' : 'HERCULE_AI_CACHE_SYNTH_TTL_SEC');
    $default = $mode === 'understand' ? 900 : ($mode === 'plan' ? 300 : 600);
    return ai_quota_env_int($key, $default, 0, 86400);
}
function ai_quota_cache_path(string $licenseHash, string $mode, string $prompt): string {
    $key = hash('sha256', $licenseHash.'|'.strtolower(trim($mode)).'|'.hash('sha256',$prompt));
    return ai_quota_state_dir().DIRECTORY_SEPARATOR.'cache-'.$key.'.json';
}
function ai_quota_cache_get(string $licenseHash, string $mode, string $prompt): ?array {
    $ttl = ai_quota_cache_ttl($mode);
    if ($ttl <= 0) return null;
    $path = ai_quota_cache_path($licenseHash,$mode,$prompt);
    if (!is_file($path) || (time() - (int)@filemtime($path)) > $ttl) { if (is_file($path)) @unlink($path); return null; }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw,true) : null;
    return is_array($data) && is_array($data['json'] ?? null) ? $data : null;
}
function ai_quota_cache_prune(): void {
    $files = glob(ai_quota_state_dir().DIRECTORY_SEPARATOR.'cache-*.json') ?: [];
    if (!$files) return;
    $maxTtl = max(ai_quota_cache_ttl('understand'), ai_quota_cache_ttl('plan'), ai_quota_cache_ttl('synthesize'));
    $cutoff = time() - max(60, $maxTtl + 60);
    foreach ($files as $file) if ((int)@filemtime($file) < $cutoff) @unlink($file);
    $files = glob(ai_quota_state_dir().DIRECTORY_SEPARATOR.'cache-*.json') ?: [];
    $maxFiles = ai_quota_env_int('HERCULE_AI_CACHE_MAX_FILES', 2000, 100, 50000);
    if (count($files) <= $maxFiles) return;
    usort($files, fn($a,$b)=>((int)@filemtime($a)) <=> ((int)@filemtime($b)));
    $remove = count($files) - max(100, (int)floor($maxFiles * 0.80));
    for ($i=0; $i<$remove; $i++) @unlink($files[$i]);
}
function ai_quota_cache_put(string $licenseHash, string $mode, string $prompt, array $result): void {
    if (ai_quota_cache_ttl($mode) <= 0) return;
    ai_quota_cache_prune();
    $path = ai_quota_cache_path($licenseHash,$mode,$prompt);
    $payload = [
        'created_at'=>time(),
        'tier'=>(string)($result['tier'] ?? 'fast'),
        'json'=>is_array($result['json'] ?? null) ? $result['json'] : [],
    ];
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    @chmod($path,0600);
}
function ai_quota_state_snapshot(): array {
    return ai_quota_transaction(function (&$state) {
        // Internal/server-side operational metrics only. License identifiers are already SHA-256 prefixes.
        return $state;
    });
}
function ai_quota_public_mode(array $reservation = [], bool $cacheHit = false): string {
    if ($cacheHit) return 'cached';
    $mode = (string)($reservation['mode'] ?? 'normal');
    return in_array($mode,['normal','conservative','economy','local_only','busy'],true) ? $mode : 'normal';
}
