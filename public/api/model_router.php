<?php
declare(strict_types=1);

/*
 * Hercule Fix 264 — server-side model router.
 * This file contains no provider secrets and never emits provider/model names to clients.
 */

function ai_model_router_tier(string $value): string {
    $tier = strtolower(trim($value));
    return in_array($tier, ['fast','standard','strongest'], true) ? $tier : 'standard';
}

function ai_model_router_json_size($value): int {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return is_string($json) ? strlen($json) : 0;
}

function ai_model_router_tool_count(array $tools): int {
    $count = 0;
    foreach ($tools as $row) {
        if (!is_array($row)) continue;
        $count++;
        $result = is_array($row['result'] ?? null) ? $row['result'] : [];
        if (($row['tool'] ?? '') === 'privacy_evidence_pack' && is_array($result['tools'] ?? null)) {
            $count += max(0, count($result['tools']) - 1);
        }
    }
    return $count;
}

function ai_model_router_evidence_units(array $tools): int {
    $units = 0;
    foreach ($tools as $row) {
        if (!is_array($row)) continue;
        $result = is_array($row['result'] ?? null) ? $row['result'] : [];
        $units += max(0, (int)($result['evidence_units'] ?? 0));
        if (($row['tool'] ?? '') === 'privacy_evidence_pack' && is_array($result['tools'] ?? null)) {
            foreach ($result['tools'] as $packed) {
                if (!is_array($packed)) continue;
                $features = is_array($packed['features'] ?? null) ? $packed['features'] : [];
                $units += max(0, (int)($features['evidence_units'] ?? 0));
            }
        }
    }
    return $units;
}

function ai_model_router_classify(string $mode, string $question, array $context = [], array $tools = []): array {
    $mode = strtolower(trim($mode));
    $question = trim($question);
    $score = 0;
    $reasons = [];
    $qLen = function_exists('mb_strlen') ? mb_strlen($question, 'UTF-8') : strlen($question);
    $toolCount = ai_model_router_tool_count($tools);
    $evidenceUnits = ai_model_router_evidence_units($tools);
    $contextBytes = ai_model_router_json_size($context);

    // Very small direct/general requests should use the fastest configured model.
    if ($qLen <= 120) { $score -= 1; $reasons[] = 'short_question'; }
    if ($mode === 'understand') { $score -= 3; $reasons[] = 'semantic_understanding'; }
    if ($mode === 'plan') { $score += 1; $reasons[] = 'planning'; }
    if ($mode === 'synthesize') { $score += 1; $reasons[] = 'synthesis'; }

    $complexPattern = '/(?:استراتيجي|استراتيجية|خطة\s+(?:تحسين|شهر|الشهر|القادم)|تحليل\s+شامل|سبب\s+جذري|root\s*cause|why\s+.*(?:profit|sales)|ليش\s+.*(?:الربح|المبيعات|الهامش)|مقارنة\s+شاملة|أولويات\s+المتجر|اولويات\s+المتجر|سيناريوهات|what[-\s]?if|counterfactual|مجلس\s+تحليلي)/iu';
    $mediumPattern = '/(?:حلل|تحليل|قارن|مقارنة|توقع|forecast|مخزون|ربح|هامش|مورد|زبائن|عملاء|خصم|مرتجعات|مصروف|تقرير)/iu';
    if (preg_match($complexPattern, $question)) { $score += 5; $reasons[] = 'complex_intent'; }
    elseif (preg_match($mediumPattern, $question)) { $score += 2; $reasons[] = 'analytical_intent'; }

    if ($toolCount >= 5) { $score += 4; $reasons[] = 'many_tools'; }
    elseif ($toolCount >= 2) { $score += 2; $reasons[] = 'multi_tool'; }
    if ($evidenceUnits >= 10) { $score += 3; $reasons[] = 'dense_evidence'; }
    elseif ($evidenceUnits >= 4) { $score += 1; $reasons[] = 'multi_evidence'; }
    if ($contextBytes >= 10000) { $score += 2; $reasons[] = 'large_context'; }
    elseif ($contextBytes >= 4000) { $score += 1; $reasons[] = 'medium_context'; }
    if ($qLen >= 500) { $score += 2; $reasons[] = 'long_question'; }
    elseif ($qLen >= 220) { $score += 1; $reasons[] = 'medium_question'; }

    $tier = $score >= 5 ? 'strongest' : ($score >= 2 ? 'standard' : 'fast');
    return [
        'tier' => $tier,
        'score' => $score,
        'reasons' => array_values(array_unique($reasons)),
        'tool_count' => $toolCount,
        'evidence_units' => $evidenceUnits,
        'context_bytes' => $contextBytes,
    ];
}

function ai_model_router_provider_order(string $tier): array {
    $tier = ai_model_router_tier($tier);
    $specific = 'HERCULE_AI_PROVIDER_ORDER_'.strtoupper($tier);
    $raw = function_exists('ai_env') ? ai_env($specific, '') : '';
    if ($raw === '' && function_exists('ai_env')) $raw = ai_env('HERCULE_AI_PROVIDER_ORDER', 'gemini,groq,cloudflare');
    if ($raw === '') $raw = 'gemini,groq,cloudflare';
    $allowed = ['gemini'=>true,'groq'=>true,'cloudflare'=>true];
    $out = [];
    foreach (explode(',', strtolower($raw)) as $provider) {
        $provider = trim($provider);
        if ($provider !== '' && isset($allowed[$provider]) && !in_array($provider, $out, true)) $out[] = $provider;
    }
    return $out ?: ['gemini','groq','cloudflare'];
}

function ai_model_router_model(string $provider, string $tier): string {
    $tier = ai_model_router_tier($tier);
    $map = [
        'gemini' => ['base'=>'GEMINI_MODEL','default'=>'gemini-2.5-flash-lite'],
        'groq' => ['base'=>'GROQ_MODEL','default'=>'openai/gpt-oss-20b'],
        'cloudflare' => ['base'=>'CLOUDFLARE_AI_MODEL','default'=>'@cf/meta/llama-3.1-8b-instruct-fast'],
    ];
    if (!isset($map[$provider])) return '';
    $baseKey = $map[$provider]['base'];
    $tierKey = $baseKey.'_'.strtoupper($tier);
    if (function_exists('ai_env')) {
        $specific = ai_env($tierKey, '');
        if ($specific !== '') return $specific;
        return ai_env($baseKey, $map[$provider]['default']);
    }
    return $map[$provider]['default'];
}

function ai_model_router_tier_sequence(string $tier): array {
    $tier = ai_model_router_tier($tier);
    if ($tier === 'strongest') return ['strongest','standard','fast'];
    if ($tier === 'standard') return ['standard','fast'];
    return ['fast'];
}

function ai_model_router_timeout(string $tier): int {
    $tier = ai_model_router_tier($tier);
    $defaults = ['fast'=>10,'standard'=>15,'strongest'=>22];
    $key = 'HERCULE_AI_TIMEOUT_'.strtoupper($tier).'_SEC';
    $value = function_exists('ai_env') ? (int)ai_env($key, (string)$defaults[$tier]) : $defaults[$tier];
    return max(6, min(35, $value));
}

function ai_model_router_token_budget(string $mode, string $tier, int $requested): int {
    $tier = ai_model_router_tier($tier);
    $mode = strtolower(trim($mode));
    $caps = $mode === 'understand'
        ? ['fast'=>420,'standard'=>520,'strongest'=>650]
        : ($mode === 'plan'
            ? ['fast'=>700,'standard'=>1000,'strongest'=>1400]
            : ['fast'=>900,'standard'=>1400,'strongest'=>1900]);
    $target = $caps[$tier] ?? $requested;
    return max(300, min(2400, max(min($requested, $target), $tier === 'strongest' ? min(1400, $target) : 300)));
}

function ai_model_router_public_label(string $tier): string {
    $tier = ai_model_router_tier($tier);
    return $tier === 'strongest' ? 'strong' : $tier;
}
