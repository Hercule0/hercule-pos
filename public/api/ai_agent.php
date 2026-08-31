<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';
require_once __DIR__ . '/model_router.php';
require_once __DIR__ . '/quota_manager.php';

function ai_env(string $key, string $default = ''): string {
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : trim((string)$value);
}
function ai_json(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function ai_cut(string $text, int $limit): string {
    return function_exists('mb_substr') ? (string)mb_substr($text, 0, $limit, 'UTF-8') : substr($text, 0, $limit);
}
function ai_prompt_json($value, int $maxChars): string {
    $shrink = function ($v, int $depth = 0) use (&$shrink) {
        if ($depth > 6) return null;
        if ($v === null || is_bool($v) || is_int($v) || is_float($v)) return $v;
        if (is_string($v)) return ai_cut($v, $depth <= 1 ? 1200 : 500);
        if (!is_array($v)) return ai_cut((string)$v, 200);
        $out = [];
        $n = 0;
        foreach ($v as $k => $item) {
            if ($n++ >= ($depth <= 1 ? 30 : 18)) break;
            $out[$k] = $shrink($item, $depth + 1);
        }
        return $out;
    };
    $candidate = $shrink($value);
    $text = json_encode($candidate, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($text)) return '{}';
    if (strlen($text) <= $maxChars) return $text;
    return json_encode(['truncated' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
}
function ai_post_json(string $url, array $headers, array $payload, int $timeout = 15): array {
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headers = array_merge(['Content-Type: application/json', 'Accept: application/json', 'User-Agent: HerculePOS-Agent/1.1'], $headers);
    if (!function_exists('curl_init')) return ['status'=>0,'json'=>null,'error'=>'cURL extension unavailable'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return ['status'=>$status,'json'=>is_array($json)?$json:null,'error'=>$error];
}
function ai_clean_json(string $text): ?array {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text) ?? $text;
    $text = preg_replace('/\s*```$/', '', $text) ?? $text;
    $first = strpos($text, '{');
    $last = strrpos($text, '}');
    if ($first !== false && $last !== false && $last >= $first) $text = substr($text, $first, $last - $first + 1);
    $json = json_decode($text, true);
    return is_array($json) ? $json : null;
}
function ai_call_provider(string $provider, string $prompt, int $maxTokens, string $tier = 'standard'): array {
    $tier = ai_model_router_tier($tier);
    $timeout = ai_model_router_timeout($tier);
    if ($provider === 'gemini') {
        $key = ai_env('GEMINI_API_KEY'); if ($key === '') return ['skip'=>true];
        $model = ai_model_router_model('gemini', $tier);
        $base = rtrim(ai_env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $res = ai_post_json($base.'/models/'.rawurlencode($model).':generateContent', ['x-goog-api-key: '.$key], [
            'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
            'generationConfig'=>['temperature'=>0.12,'maxOutputTokens'=>$maxTokens,'responseMimeType'=>'application/json'],
        ], $timeout);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['candidates'][0]['content']['parts'][0]['text']??''),'error'=>$res['error'] ?: (string)($res['json']['error']['message']??''),'model'=>$model,'tier'=>$tier];
    }
    if ($provider === 'groq') {
        $key = ai_env('GROQ_API_KEY'); if ($key === '') return ['skip'=>true];
        $model = ai_model_router_model('groq', $tier);
        $base = rtrim(ai_env('GROQ_API_BASE', 'https://api.groq.com/openai/v1'), '/');
        $res = ai_post_json($base.'/chat/completions', ['Authorization: Bearer '.$key], [
            'model'=>$model,
            'messages'=>[['role'=>'system','content'=>'Return one strict JSON object only.'],['role'=>'user','content'=>$prompt]],
            'temperature'=>0.12,
            'max_completion_tokens'=>$maxTokens,
            'response_format'=>['type'=>'json_object'],
        ], $timeout);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['choices'][0]['message']['content']??''),'error'=>$res['error'] ?: (string)($res['json']['error']['message']??''),'model'=>$model,'tier'=>$tier];
    }
    if ($provider === 'cloudflare') {
        $token = ai_env('CLOUDFLARE_AI_TOKEN'); $account = ai_env('CLOUDFLARE_ACCOUNT_ID');
        if ($token === '' || $account === '') return ['skip'=>true];
        $model = ai_model_router_model('cloudflare', $tier);
        $base = rtrim(ai_env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'), '/');
        $url = $base.'/accounts/'.rawurlencode($account).'/ai/run/'.str_replace('%2F','/',rawurlencode($model));
        $res = ai_post_json($url, ['Authorization: Bearer '.$token], ['prompt'=>$prompt,'max_tokens'=>$maxTokens,'temperature'=>0.12], $timeout);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['result']['response']??''),'error'=>$res['error'] ?: (string)($res['json']['errors'][0]['message']??''),'model'=>$model,'tier'=>$tier];
    }
    return ['skip'=>true];
}
function ai_providers(string $prompt, int $maxTokens, array $route = []): array {
    $errors = [];
    $requestedTier = ai_model_router_tier((string)($route['tier'] ?? 'standard'));
    $mode = strtolower(trim((string)($route['mode'] ?? 'synthesize')));
    $licenseHash = trim((string)($route['license_hash'] ?? ''));

    // Fix 277: a privacy-safe per-license response cache is checked before any quota reservation/provider call.
    if ($licenseHash !== '') {
        $cached = ai_quota_cache_get($licenseHash, $mode, $prompt);
        if (is_array($cached) && is_array($cached['json'] ?? null)) {
            return [
                'ok'=>true,
                'provider'=>'cache', // internal only
                'model'=>'cache',
                'tier'=>ai_model_router_tier((string)($cached['tier'] ?? $requestedTier)),
                'requested_tier'=>$requestedTier,
                'fallback_used'=>false,
                'cache_hit'=>true,
                'service_mode'=>'cached',
                'json'=>$cached['json'],
            ];
        }
    }

    $reservation = $licenseHash !== ''
        ? ai_quota_reserve_request($licenseHash, $mode, $requestedTier)
        : ['ok'=>true,'effective_tier'=>$requestedTier,'requested_tier'=>$requestedTier,'mode'=>'normal','units'=>0];
    if (empty($reservation['ok'])) {
        return [
            'ok'=>false,
            'code'=>(string)($reservation['code'] ?? 'AI_FAIR_USE_LIMIT'),
            'requested_tier'=>$requestedTier,
            'service_mode'=>ai_quota_public_mode($reservation),
            'errors'=>['fair_use_gate'],
        ];
    }

    $effectiveTier = ai_model_router_tier((string)($reservation['effective_tier'] ?? $requestedTier));
    $attempted = [];
    $successful = false;
    $successfulTokens = 0;
    try {
        foreach (ai_model_router_tier_sequence($effectiveTier) as $tier) {
            $budget = ai_model_router_token_budget($mode, $tier, $maxTokens);
            $configuredOrder = ai_model_router_provider_order($tier);
            $providerOrder = ai_quota_rank_providers($configuredOrder, $tier);
            if (!$providerOrder) { $errors[] = 'provider_budget_exhausted:'.$tier; continue; }
            foreach ($providerOrder as $provider) {
                $model = ai_model_router_model($provider, $tier);
                $attemptKey = $provider.'|'.$model;
                if (isset($attempted[$attemptKey])) continue;
                $attempted[$attemptKey] = true;
                $estimatedTokens = max(1, (int)ceil(strlen($prompt) / 4) + $budget);
                $providerAttempt = ai_quota_provider_reserve_attempt($provider, $tier, $estimatedTokens);
                if (empty($providerAttempt['ok'])) { $errors[] = $provider.':budget_or_cooldown'; continue; }

                $res = ai_call_provider($provider, $prompt, $budget, $tier);
                if (!empty($res['skip'])) {
                    ai_quota_provider_refund_attempt($providerAttempt);
                    $errors[] = $provider.':not_configured';
                    continue;
                }
                $status = (int)($res['status'] ?? 0);
                if ($status === 0) ai_quota_provider_refund_attempt($providerAttempt);
                ai_quota_provider_record_status($provider, $status);
                $json = ai_clean_json((string)($res['text'] ?? ''));
                if ($json) {
                    $successful = true;
                    $successfulTokens = $estimatedTokens;
                    $out = [
                        'ok'=>true,
                        'provider'=>$provider, // internal only; never returned to the desktop client
                        'model'=>$model,
                        'tier'=>$tier,
                        'requested_tier'=>$requestedTier,
                        'effective_tier'=>$effectiveTier,
                        'fallback_used'=>$tier !== $requestedTier || $effectiveTier !== $requestedTier,
                        'cache_hit'=>false,
                        'service_mode'=>ai_quota_public_mode($reservation),
                        'json'=>$json,
                    ];
                    if ($licenseHash !== '') ai_quota_cache_put($licenseHash, $mode, $prompt, $out);
                    return $out;
                }
                $errors[] = $provider.':'.$status.':'.$tier;
            }
        }
        return ['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','requested_tier'=>$requestedTier,'effective_tier'=>$effectiveTier,'service_mode'=>ai_quota_public_mode($reservation),'errors'=>$errors];
    } finally {
        if ($licenseHash !== '') ai_quota_release_request($reservation, $successful, $successfulTokens);
    }
}
function ai_log_provider_failure(string $event, array $errors): void {
    $safe = [];
    foreach (array_slice($errors, 0, 8) as $error) {
        $safe[] = ai_cut((string)$error, 80);
    }
    ErrorHandler::report(new RuntimeException('AI provider routing failed.'), $event, ['providers'=>$safe]);
}
function ai_decode_args($value): array {
    if (is_array($value)) return $value;
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}
function ai_flatten_call(array $call): array {
    $fn = is_array($call['function'] ?? null) ? $call['function'] : [];
    $args = ai_decode_args($call['arguments'] ?? $call['args'] ?? $call['parameters'] ?? $fn['arguments'] ?? []);
    return array_merge($args, $call, [
        'tool' => (string)($call['tool'] ?? $call['name'] ?? $fn['name'] ?? $args['tool'] ?? $args['name'] ?? ''),
    ]);
}
function ai_normalize_calls(array $model, array $catalog, string $question): array {
    $rawCalls = $model['tool_calls'] ?? $model['tools'] ?? $model['calls'] ?? $model['actions'] ?? [];
    if (!is_array($rawCalls)) $rawCalls = [];
    $calls = [];
    $toolAliases = [
        'sales_forecast'=>'forecast_sales','forecast_sales_data'=>'forecast_sales',
        'inventory_forecast'=>'forecast_inventory','stock_forecast'=>'forecast_inventory',
        'product_forecast'=>'forecast_product','product_demand_forecast'=>'forecast_product',
        'what_if'=>'simulate_what_if','simulate'=>'simulate_what_if','counterfactual'=>'simulate_what_if',
        'supplier_analysis'=>'analyze_suppliers','supplier_intelligence'=>'analyze_suppliers','compare_suppliers'=>'analyze_suppliers',
        'basket_analysis'=>'analyze_baskets','market_basket'=>'analyze_baskets','cross_sell'=>'analyze_baskets','bundle_analysis'=>'analyze_baskets',
        'app_help'=>'help_search','help'=>'help_search','settings_help'=>'help_search',
        'get_report'=>'report','query_report'=>'report','run_report'=>'report',
        'store_query'=>'store_read','read_store'=>'store_read','store_read'=>'store_read',
        'analytical_query'=>'analyze_query','universal_query'=>'analyze_query','analyze_query'=>'analyze_query',
    ];
    foreach (array_slice($rawCalls, 0, 12) as $raw) {
        if (!is_array($raw)) continue;
        $c = ai_flatten_call($raw);
        $tool = strtolower(trim((string)($c['tool'] ?? '')));
        if (isset($toolAliases[$tool])) $tool = $toolAliases[$tool];
        if (isset($catalog[$tool]) && $tool !== 'none') { $c['intent'] = $tool; $tool = 'report'; }
        if ($tool === 'store_read') {
            $allowedViews = ['products','product_prices','inventory','inventory_batches','inventory_locations','location_inventory','inventory_transfers','inventory_counts','product_movements','invoices','invoice_detail','customers','customer_debts','suppliers','purchase_orders','supplier_payables','supplier_documents','expenses','promotions','cash_drawer','cash_shifts','users','price_audit','activity_log','held_sales','feature_settings','categories'];
            $view = strtolower(trim((string)($c['view'] ?? $c['domain'] ?? '')));
            if (!in_array($view,$allowedViews,true)) continue;
            $allowedSorts = ['newest','name_asc','name_desc','stock_desc','stock_asc','price_desc','price_asc','cost_desc','cost_asc','balance_desc'];
            $sort = strtolower(trim((string)($c['sort'] ?? ''))); if (!in_array($sort,$allowedSorts,true)) $sort='';
            $calls[] = ['tool'=>'store_read','view'=>$view,
                'query'=>ai_cut(trim((string)($c['query'] ?? '')),120),'sort'=>$sort,'status'=>ai_cut(trim((string)($c['status'] ?? '')),40),
                'category'=>ai_cut(trim((string)($c['category'] ?? '')),100),'limit'=>max(1,min(50,(int)($c['limit']??10))),
                'min_price'=>isset($c['min_price'])?(float)$c['min_price']:null,'max_price'=>isset($c['max_price'])?(float)$c['max_price']:null,
                'min_cost'=>isset($c['min_cost'])?(float)$c['min_cost']:null,'max_cost'=>isset($c['max_cost'])?(float)$c['max_cost']:null,
                'product_id'=>isset($c['product_id'])?(int)$c['product_id']:null,'customer_id'=>isset($c['customer_id'])?(int)$c['customer_id']:null,
                'supplier_id'=>isset($c['supplier_id'])?(int)$c['supplier_id']:null,'invoice_id'=>isset($c['invoice_id'])?(int)$c['invoice_id']:null,
                'location_id'=>isset($c['location_id'])?(int)$c['location_id']:null,'entity_id'=>isset($c['entity_id'])?(int)$c['entity_id']:null,
                'entity_type'=>ai_cut(trim((string)($c['entity_type'] ?? '')),40),'entity_query'=>ai_cut(trim((string)($c['entity_query'] ?? '')),120),
                'supplier_query'=>ai_cut(trim((string)($c['supplier_query'] ?? '')),120),'product_query'=>ai_cut(trim((string)($c['product_query'] ?? '')),120),'location_query'=>ai_cut(trim((string)($c['location_query'] ?? '')),120),
                'start_date'=>ai_cut(trim((string)($c['start_date'] ?? '')),20),'end_date'=>ai_cut(trim((string)($c['end_date'] ?? '')),20),
                'payment_method'=>ai_cut(trim((string)($c['payment_method'] ?? '')),20),'action'=>ai_cut(trim((string)($c['action'] ?? '')),80),
                'username'=>ai_cut(trim((string)($c['username'] ?? '')),80)];
        } elseif ($tool === 'analyze_query') {
            $metrics=['sales','revenue','profit','margin','transactions','avg_basket','expenses','returns','discounts','cogs','quantity','debts','purchases'];
            $dimensions=['none','category','product','customer','supplier','cashier','payment_method','day','hour'];
            $comparisons=['none','previous_equal_period','previous_month','previous_year'];
            $rankings=['none','desc','asc','growth','decline'];
            $primary=strtolower(trim((string)($c['primary_metric']??$c['metric']??'sales')));if(!in_array($primary,$metrics,true))$primary='sales';
            $dimension=strtolower(trim((string)($c['dimension']??'none')));if(!in_array($dimension,$dimensions,true))$dimension='none';
            $comparison=strtolower(trim((string)($c['comparison_mode']??'none')));if(!in_array($comparison,$comparisons,true))$comparison='none';
            $ranking=strtolower(trim((string)($c['ranking']??'desc')));if(!in_array($ranking,$rankings,true))$ranking='desc';
            $secondary=[];foreach((array)($c['secondary_metrics']??[]) as $m){$m=strtolower(trim((string)$m));if(in_array($m,$metrics,true)&&$m!==$primary&&!in_array($m,$secondary,true))$secondary[]=$m;if(count($secondary)>=3)break;}
            $conditions=[];foreach(array_slice((array)($c['conditions']??[]),0,4) as $cond){if(!is_array($cond))continue;$m=strtolower(trim((string)($cond['metric']??$primary)));$op=strtolower(trim((string)($cond['operator']??'')));if(!in_array($m,$metrics,true)||!in_array($op,['lt_previous','gt_previous','lt','gt','lte','gte'],true))continue;$row=['metric'=>$m,'operator'=>$op];if(in_array($op,['lt','gt','lte','gte'],true))$row['value']=(float)($cond['value']??0);$conditions[]=$row;}
            $dateRange=$c['date_range']??$c['time_scope']??'this_month';if(is_array($dateRange))$dateRange=['start'=>ai_cut((string)($dateRange['start']??''),20),'end'=>ai_cut((string)($dateRange['end']??''),20)];else $dateRange=ai_cut((string)$dateRange,30);
            $filters=is_array($c['filters']??null)?$c['filters']:[];
            $calls[]=['tool'=>'analyze_query','primary_metric'=>$primary,'secondary_metrics'=>$secondary,'dimension'=>$dimension,'date_range'=>$dateRange,'comparison_mode'=>$comparison,'ranking'=>$ranking,'ranking_metric'=>in_array(strtolower(trim((string)($c['ranking_metric']??$primary))),$metrics,true)?strtolower(trim((string)($c['ranking_metric']??$primary))):$primary,'limit'=>max(1,min(30,(int)($c['limit']??10))),'conditions'=>$conditions,'filters'=>['category'=>ai_cut((string)($filters['category']??''),120),'product'=>ai_cut((string)($filters['product']??''),120),'customer'=>ai_cut((string)($filters['customer']??''),120),'cashier'=>ai_cut((string)($filters['cashier']??''),120),'payment_method'=>ai_cut((string)($filters['payment_method']??''),40)]];
        } elseif ($tool === 'report') {
            $intent = trim((string)($c['intent'] ?? $c['report'] ?? $c['report_intent'] ?? ''));
            if (!isset($catalog[$intent]) || $intent === 'none') continue;
            $required = (string)($catalog[$intent]['entity'] ?? '');
            $entityType = trim((string)($c['entity_type'] ?? $c['entity']['type'] ?? ($required ?: 'none')));
            $entityQuery = ai_cut(trim((string)($c['entity_query'] ?? $c['entity']['query'] ?? $c['query'] ?? '')), 120);
            if ($required !== '' && ($entityType !== $required || $entityQuery === '')) continue;
            $calls[] = ['tool'=>'report','intent'=>$intent,'entity_type'=>$entityType ?: 'none','entity_query'=>$entityQuery,
                'limit'=>max(1,min(50,(int)($c['limit']??10))),'window_days'=>max(1,min(3650,(int)($c['window_days']??$c['days']??30)))];
        } elseif ($tool === 'forecast_product') {
            $entityQuery = ai_cut(trim((string)($c['entity_query'] ?? $c['product'] ?? $c['query'] ?? '')), 120);
            if ($entityQuery === '') continue;
            $calls[] = ['tool'=>$tool,'entity_query'=>$entityQuery,'horizon_days'=>max(1,min(90,(int)($c['horizon_days']??$c['days']??14))),
                'lookback_days'=>max(7,min(120,(int)($c['lookback_days']??30)))];
        } elseif ($tool === 'simulate_what_if') {
            $entityQuery = ai_cut(trim((string)($c['entity_query'] ?? $c['product'] ?? $c['query'] ?? '')), 120);
            if ($entityQuery === '') continue;
            $scenario = strtolower(trim((string)($c['scenario_type'] ?? $c['type'] ?? 'price')));
            if (!in_array($scenario,['price','discount','order','reorder','supplier'],true)) $scenario='price';
            $calls[] = ['tool'=>$tool,'scenario_type'=>$scenario,'entity_query'=>$entityQuery,
                'supplier_query'=>ai_cut(trim((string)($c['supplier_query'] ?? $c['supplier'] ?? '')),120),
                'target_price'=>isset($c['target_price'])?(float)$c['target_price']:null,
                'price_change_percent'=>isset($c['price_change_percent'])?(float)$c['price_change_percent']:null,
                'target_discount_percent'=>isset($c['target_discount_percent'])?(float)$c['target_discount_percent']:null,
                'order_quantity'=>isset($c['order_quantity'])?(float)$c['order_quantity']:null,
                'horizon_days'=>max(1,min(90,(int)($c['horizon_days']??$c['days']??14))),
                'lookback_days'=>max(60,min(365,(int)($c['lookback_days']??180)))];
        } elseif ($tool === 'analyze_baskets') {
            $calls[] = ['tool'=>$tool,
                'entity_query'=>ai_cut(trim((string)($c['entity_query'] ?? $c['product'] ?? '')),120),
                'lookback_days'=>max(30,min(365,(int)($c['lookback_days']??90))),
                'min_pair_count'=>max(2,min(100,(int)($c['min_pair_count']??3))),
                'min_support_percent'=>max(.1,min(50,(float)($c['min_support_percent']??1))),
                'min_confidence_percent'=>max(1,min(95,(float)($c['min_confidence_percent']??15))),
                'min_lift'=>max(.5,min(10,(float)($c['min_lift']??1.05))),
                'limit'=>max(5,min(40,(int)($c['limit']??20)))];
        } elseif ($tool === 'analyze_suppliers') {
            $calls[] = ['tool'=>$tool,
                'supplier_query'=>ai_cut(trim((string)($c['supplier_query'] ?? $c['supplier'] ?? '')),120),
                'compare_supplier_query'=>ai_cut(trim((string)($c['compare_supplier_query'] ?? $c['compare_supplier'] ?? '')),120),
                'lookback_days'=>max(30,min(365,(int)($c['lookback_days']??180))),
                'limit'=>max(2,min(30,(int)($c['limit']??12)))];
        } elseif ($tool === 'help_search') {
            $calls[] = ['tool'=>$tool,'query'=>ai_cut(trim((string)($c['query']??$question)),500),'limit'=>max(1,min(8,(int)($c['limit']??5)))];
        } elseif (in_array($tool, ['forecast_sales','forecast_inventory'], true)) {
            $calls[] = ['tool'=>$tool,'horizon_days'=>max(1,min(90,(int)($c['horizon_days']??$c['days']??($tool==='forecast_sales'?7:14)))),
                'history_days'=>max(28,min(365,(int)($c['history_days']??84))),
                'lookback_days'=>max(7,min(180,(int)($c['lookback_days']??30))),
                'limit'=>max(1,min(50,(int)($c['limit']??20)))];
        }
        if (count($calls) >= 8) break;
    }
    return $calls;
}
function ai_horizon(string $q): int {
    if (preg_match('/شهر|month/iu',$q)) return 30;
    if (preg_match('/14|اسبوعين|أسبوعين|2\s*weeks?/iu',$q)) return 14;
    return 7;
}
function ai_fallback_plan(string $q): ?array {
    $report = fn(string $intent) => ['tool'=>'report','intent'=>$intent,'entity_type'=>'none','entity_query'=>'','limit'=>10,'window_days'=>30];
    $h = ai_horizon($q);
    if (preg_match('/(?:ما\s*)?(?:توقعك|تتوقع|توقع|تنبؤ|forecast|predict).*?(?:الاسبوع|الأسبوع|اسبوع|أسبوع|الشهر|الجاي|القادم|المقبل)|(?:الاسبوع|الأسبوع|الشهر).*?(?:توقع|تنبؤ|forecast)/iu',$q)) {
        return ['kind'=>'tools','goal'=>'توقع أداء المتجر للفترة القادمة','tool_calls'=>[
            ['tool'=>'forecast_sales','horizon_days'=>$h,'history_days'=>84], $report('sales_comparison'), $report('operational_alerts')
        ]];
    }
    if (preg_match('/(?:ينباع|ينشرى|يشتري|شراء|سلة|basket|cross[- ]?sell|bundle|مع بعض|سوية).*?(?:ويا|مع|منتج|منتجات|بعض|basket|bundle)|(?:شنو|ما|what).*?(?:ينباع|ينشرى|يشتري).*?(?:ويا|مع)/iu',$q)) {
        return ['kind'=>'tools','goal'=>'تحليل علاقات الشراء المشترك وفرص Cross-sell','tool_calls'=>[['tool'=>'analyze_baskets','entity_query'=>'','lookback_days'=>90,'min_pair_count'=>3,'min_support_percent'=>1,'min_confidence_percent'=>15,'min_lift'=>1.05,'limit'=>20]]];
    }
    if (preg_match('/(?:حلل|تحليل|قيّم|قيم|تقييم).*?(?:المتجر|المحل|الأداء|اداء|الوضع|الشغل|store|performance)/iu',$q)) {
        return ['kind'=>'tools','goal'=>'تحليل شامل لأداء المتجر','tool_calls'=>array_map($report,['manager_brief','sales_comparison','net_profit','expenses_summary','discounts_summary','returns_summary','operational_alerts','top_sellers'])];
    }
    if (preg_match('/(?:ليش|لماذا|سبب|حلل).*?(?:ربح|ارباح|أرباح|profit)/iu',$q)) {
        return ['kind'=>'tools','goal'=>'تحليل أسباب تغير الربح','tool_calls'=>array_map($report,['net_profit','sales_comparison','expenses_summary','discounts_summary','returns_summary','gross_profit'])];
    }
    if (preg_match('/(?:شلون|كيف|وين|أين|اين|ساعدني|شرح|اشرح|فعل|فعّل|تفعيل|إعداد|اعداد|setting|configure)/iu',$q)) {
        return ['kind'=>'tools','goal'=>'شرح استخدام أو إعداد داخل Hercule','tool_calls'=>[['tool'=>'help_search','query'=>ai_cut($q,500),'limit'=>5]]];
    }
    return null;
}
function ai_answer_object($value): array {
    if (is_array($value)) return $value;
    if (is_string($value) && trim($value) !== '') return ['answer'=>trim($value)];
    return [];
}
function ai_enforce_hercule_currency_text(string $text): string {
    $text = preg_replace('/\bSaudi\s+Riyals?\b/iu', 'Iraqi dinar (IQD)', $text) ?? $text;
    $text = preg_replace('/\bSAR\b/iu', 'IQD', $text) ?? $text;
    $text = preg_replace('/(?:الريال\s+السعودي|ريال\s+سعودي|ريالات\s+سعودية|ريالات\s+سعوديه|الريال|ريالات|ريال)/u', 'الدينار العراقي', $text) ?? $text;
    $text = preg_replace('/ر\.?\s*س\.?/u', 'د.ع', $text) ?? $text;
    return $text;
}
function ai_apply_product_output_policy(array $answer): array {
    foreach (['answer','caveat'] as $key) {
        if (isset($answer[$key]) && is_string($answer[$key])) $answer[$key] = ai_enforce_hercule_currency_text($answer[$key]);
    }
    foreach (['key_points','recommendations','followups','suggested_questions'] as $key) {
        if (!isset($answer[$key]) || !is_array($answer[$key])) continue;
        foreach ($answer[$key] as $i=>$item) {
            if (is_string($item)) $answer[$key][$i] = ai_enforce_hercule_currency_text($item);
        }
    }
    return $answer;
}
function ai_followup_questions($value, int $max = 4): array {
    if (!is_array($value)) return [];
    $out = []; $seen = [];
    foreach ($value as $item) {
        if (is_array($item)) $item = $item['question'] ?? $item['text'] ?? $item['title'] ?? $item['label'] ?? '';
        $text = trim(preg_replace('/\s+/u', ' ', (string)$item) ?? (string)$item);
        $text = ai_cut($text, 180);
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($text === '' || $length < 4 || $length > 160) continue;
        $isQuestion = (bool)preg_match('/[؟?]\s*$/u', $text)
            || (bool)preg_match('/^(?:شنو|شلون|كيف|شكد|كم|هل|متى|وين|أين|اين|ليش|لماذا|ما\s|من\s|أي\s|اريد|أريد|حلل|حلّل|قارن|اعرض|أعرض|اظهر|أظهر|توقع|توقّع|تنبأ|تنبّأ|اشرح|إشرح|ساعدني|what|how|why|when|where|which|can\s|could\s|show\s|analy[sz]e|compare|forecast|explain)/iu', $text);
        if (!$isQuestion) continue;
        $baseKey = preg_replace('/[؟?!.،,]+$/u', '', $text) ?? $text;
        $key = function_exists('mb_strtolower') ? mb_strtolower($baseKey, 'UTF-8') : strtolower($baseKey);
        if (isset($seen[$key])) continue;
        $seen[$key] = true; $out[] = $text;
        if (count($out) >= $max) break;
    }
    return $out;
}
function ai_sanitize_answer_payload(array $answer): array {
    $answer = ai_apply_product_output_policy($answer);
    $answer['followups'] = ai_followup_questions($answer['followups'] ?? $answer['suggested_questions'] ?? [], 4);
    unset($answer['suggested_questions']);
    return $answer;
}

function ai_user_prefers_arabic(string $question): bool {
    return (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $question);
}
function ai_user_facing_text(string $text): string {
    $map = [
        'sales_summary'=>'ملخص المبيعات','top_sellers'=>'أعلى المنتجات مبيعاً','bottom_sellers'=>'أقل المنتجات مبيعاً',
        'manager_brief'=>'ملخص المدير','sales_comparison'=>'مقارنة المبيعات','net_profit'=>'صافي الربح','gross_profit'=>'إجمالي الربح',
        'expenses_summary'=>'ملخص المصروفات','discounts_summary'=>'ملخص الخصومات','returns_summary'=>'ملخص المرتجعات',
        'operational_alerts'=>'التنبيهات التشغيلية','inventory_summary'=>'ملخص المخزون','inventory_value'=>'قيمة المخزون',
        'reorder_suggestions'=>'اقتراحات إعادة الطلب','store_read'=>'قراءة بيانات المتجر','forecast_sales'=>'توقع المبيعات',
        'forecast_inventory'=>'توقع المخزون','forecast_product'=>'توقع المنتج','simulate_what_if'=>'محاكاة السيناريو',
        'analyze_suppliers'=>'تحليل الموردين','analyze_baskets'=>'تحليل سلة المشتريات','daily_executive_brief'=>'الملخص التنفيذي اليومي',
        'analyze_proactive_notifications'=>'التنبيهات الذكية الاستباقية','help_search'=>'مساعدة Hercule'
    ];
    foreach ($map as $key=>$label) $text = preg_replace('/\b'.preg_quote($key,'/').'\b/iu', $label, $text) ?? $text;
    $text = preg_replace('/\bEvidence\s*ID\b/iu','دليل',$text) ?? $text;
    $text = preg_replace('/\bClaim\b/iu','استنتاج',$text) ?? $text;
    $text = preg_replace('/\btool[_ -]?calls?\b/iu','خطوات التحليل',$text) ?? $text;
    $text = preg_replace('/\bintent\b/iu','نوع التحليل',$text) ?? $text;
    return trim(preg_replace('/\s{2,}/u',' ',$text) ?? $text);
}
function ai_apply_user_facing_policy(array $answer): array {
    foreach (['answer','caveat'] as $key) if (isset($answer[$key]) && is_string($answer[$key])) $answer[$key] = ai_user_facing_text($answer[$key]);
    foreach (['key_points','recommendations','followups'] as $key) {
        if (!is_array($answer[$key] ?? null)) continue;
        foreach ($answer[$key] as $i=>$item) if (is_string($item)) $answer[$key][$i] = ai_user_facing_text($item);
    }
    return $answer;
}
function ai_answer_language_ok(array $answer, string $question): bool {
    if (!ai_user_prefers_arabic($question)) return true;
    $parts = [trim((string)($answer['answer'] ?? ''))];
    foreach (['key_points','recommendations','followups'] as $key) if (is_array($answer[$key] ?? null)) foreach ($answer[$key] as $v) if (is_string($v)) $parts[] = trim($v);
    if (is_string($answer['caveat'] ?? null)) $parts[] = trim((string)$answer['caveat']);
    if ($parts[0] === '') return false;
    foreach ($parts as $text) {
        if ($text === '') continue;
        preg_match_all('/[\x{0621}-\x{064A}]/u',$text,$ar); preg_match_all('/[A-Za-z]/',$text,$la);
        $a=count($ar[0]??[]);$l=count($la[0]??[]);$letters=$a+$l;
        if ($letters < 8) continue;
        if ($a < 6 || ($a / max(1,$letters)) < 0.28) return false;
    }
    return true;
}


const HERCULE_AI_PRIVACY_CONSENT_VERSION = 1;
function ai_privacy_policy(array $body): array {
    $privacy = is_array($body['privacy'] ?? null) ? $body['privacy'] : [];
    $version = max(0, (int)($privacy['consent_version'] ?? 0));
    $scope = strtolower(trim((string)($privacy['scope'] ?? 'minimal')));
    if ($version < HERCULE_AI_PRIVACY_CONSENT_VERSION || !in_array($scope, ['minimal','operational'], true)) {
        ai_json(['ok'=>false,'code'=>'AI_PRIVACY_CONSENT_REQUIRED','error'=>'يلزم تفعيل موافقة الذكاء السحابي من إعدادات Hercule.'],403);
    }
    return ['consent_version'=>$version,'scope'=>$scope];
}
function ai_privacy_redact_text(string $text, int $limit = 1200): string {
    $text = ai_cut($text, $limit);
    $text = preg_replace('/\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b/u', '[EMAIL]', $text) ?? $text;
    $text = preg_replace('/(?:\+?964|00964|0)?7(?:[\s-]?\d){9}\b/u', '[PHONE]', $text) ?? $text;
    $text = preg_replace('/\b(?:sk-[A-Za-z0-9_-]{16,}|AIza[A-Za-z0-9_-]{20,}|ghp_[A-Za-z0-9]{20,})\b/u', '[SECRET]', $text) ?? $text;
    $text = preg_replace('/\b(?:license|licence|hwid|hardware\s*id)\s*[:=#-]?\s*[A-Za-z0-9_-]{8,}\b/iu', '[REDACTED]', $text) ?? $text;
    return $text;
}
function ai_privacy_block_key(string $key, string $scope): bool {
    $key = strtolower(trim($key));
    if (preg_match('/(?:password|pass_hash|passcode|token|secret|api[_-]?key|private[_-]?key|license[_-]?key|hwid|hardware[_-]?id|recovery|email|phone|whatsapp|address|history|conversation[_-]?history|customer[_-]?name|customer[_-]?full|cashier|username|full[_-]?name|employee[_-]?name|user[_-]?name|contact[_-]?name|supplier[_-]?phone)/i', $key)) return true;
    if ($scope === 'minimal' && preg_match('/(?:^|_)(?:id|name|title|sku|barcode|code|serial|lot|note|customer|supplier|product|category|invoice|receipt|document|reference|fingerprint)(?:$|_)/i', $key)) return true;
    if ($scope === 'minimal' && preg_match('/^(?:summary|message|description|label|statement|findings|key_findings|recommendations?|recommended_action|recommended_actions|positive_signals|interpretation_guard|decision_guard|conclusion|detail|action)$/i', $key)) return true;
    return false;
}
function ai_privacy_sanitize($value, string $scope, int $depth = 0) {
    if ($depth > 7) return null;
    if ($value === null || is_bool($value) || is_int($value) || is_float($value)) return $value;
    if (is_string($value)) return ai_privacy_redact_text($value, $depth <= 1 ? 1200 : 500);
    if (!is_array($value)) return ai_privacy_redact_text((string)$value, 200);

    $isList = array_is_list($value);
    $out = [];
    $limit = $depth <= 1 ? 80 : 50;
    $count = 0;
    foreach ($value as $key=>$item) {
        if ($count++ >= $limit) break;
        if (!$isList && ai_privacy_block_key((string)$key, $scope)) continue;
        // Fix 262 defense-in-depth: raw row/list containers never reach provider prompts.
        if (!$isList && is_array($item) && array_is_list($item) && preg_match('/^(?:rows?|records?|invoices?|receipts?|transactions?|customers?|orders?|purchase_orders?|purchases?|sales_lines?|invoice_items?|line_items?|items?|batches?|movements?|stock_movements?|events?|snapshots?|documents?|attachments?)$/i', (string)$key)) continue;
        $clean = ai_privacy_sanitize($item, $scope, $depth + 1);
        if ($isList) $out[] = $clean; else $out[$key] = $clean;
    }
    return $out;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') ai_json(['ok'=>false,'code'=>'METHOD_NOT_ALLOWED'],405);
$body = json_input(65536);
$licenseKey = trim((string)($body['license_key'] ?? ''));
$hwid = trim((string)($body['hwid'] ?? ''));
$question = trim((string)($body['question'] ?? ''));
$mode = strtolower(trim((string)($body['mode'] ?? 'plan')));
if ($licenseKey === '' || $hwid === '') ai_json(['ok'=>false,'code'=>'LICENSE_REQUIRED'],401);
if ($question === '' || strlen($question) > 6000) ai_json(['ok'=>false,'code'=>'INVALID_QUESTION'],400);
if (!in_array($mode, ['understand','plan','synthesize'], true)) ai_json(['ok'=>false,'code'=>'INVALID_MODE'],400);
$privacy = ai_privacy_policy($body);
$privacyManifest = is_array($body['privacy_manifest'] ?? null) ? $body['privacy_manifest'] : [];
if ($privacyManifest) {
    $policyVersion = max(0, (int)($privacyManifest['policy_version'] ?? 0));
    $rawRows = max(0, (int)($privacyManifest['raw_rows_forwarded'] ?? 0));
    $transform = trim((string)($privacyManifest['transformation'] ?? ''));
    if ($policyVersion < 2 || $rawRows !== 0 || $transform !== 'feature_extraction_aggregation_anonymization') {
        ai_json(['ok'=>false,'code'=>'AI_PRIVACY_MANIFEST_INVALID','error'=>'تعذر قبول حزمة الذكاء بسبب سياسة الخصوصية.'],400);
    }
}
$question = trim(ai_privacy_redact_text($question, 6000));

$ipLimit = max(10,(int)ai_env('HERCULE_AI_IP_RPM','60'));
if (!RateLimiter::check(client_ip(),'ai_agent_ip',$ipLimit,1)) {
    ai_json(['ok'=>false,'code'=>'AI_RATE_LIMIT','error'=>'طلبات كثيرة جداً. جرّب بعد قليل.'],429);
}

if (DeviceManager::isBlocked($licenseKey,$hwid)) ai_json(['ok'=>false,'code'=>'DEVICE_BLOCKED'],403);
$validation = License::validate($licenseKey,$hwid,client_ip());
if (!($validation['ok'] ?? false)) ai_json(['ok'=>false,'code'=>'LICENSE_INVALID'],403);
$licenseHash = ai_quota_license_hash($licenseKey);
$perLicense = max(1,(int)ai_env('HERCULE_AI_PER_LICENSE_RPM','12'));
$global = max($perLicense,(int)ai_env('HERCULE_AI_GLOBAL_RPM','30'));
$bucket = 'lic:'.substr(hash('sha256',$licenseKey),0,40);
if (!RateLimiter::check($bucket,'ai_agent_'.$mode,$perLicense,1) || !RateLimiter::check('global','ai_agent_global_'.$mode,$global,1)) {
    ai_json(['ok'=>false,'code'=>'AI_RATE_LIMIT','error'=>'التحليل الذكي مشغول حالياً.'],429);
}
$catalog = json_decode((string)@file_get_contents(__DIR__.'/intent_catalog.json'),true);
if (!is_array($catalog)) ai_json(['ok'=>false,'code'=>'CATALOG_ERROR'],500);
$context = ai_privacy_sanitize(is_array($body['context'] ?? null) ? $body['context'] : [], $privacy['scope']);

function ai_repair_answer_language(array $answer, string $question, string $licenseHash): array {
    $answer = ai_apply_user_facing_policy($answer);
    if (ai_answer_language_ok($answer,$question)) return $answer;
    $safe = json_encode($answer, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($safe) || $safe === '') return $answer;
    $prompt = "Rewrite the following Hercule response into natural Iraqi Arabic only. Preserve every number and fact exactly; add no new facts. Do not mention provider/model/tool/intent/report identifiers or Evidence IDs. Recommendations must be genuine business recommendations only. followups must be 1-4 short questions/requests written as things the USER can ask next. Return the same JSON object shape only.\nResponse: ".ai_cut($safe,9000);
    $res = ai_providers($prompt,700,['tier'=>'fast','mode'=>'language_repair','license_hash'=>$licenseHash]);
    if (!($res['ok'] ?? false) || !is_array($res['json'] ?? null)) return $answer;
    $repaired = ai_sanitize_answer_payload(ai_answer_object($res['json']));
    $repaired = ai_apply_user_facing_policy($repaired);
    return ai_answer_language_ok($repaired,$question) ? $repaired : $answer;
}



if ($mode === 'understand') {
    // Fix 284: semantic rescue receives only the privacy-redacted user sentence and tiny non-store context.
    // It classifies meaning; it MUST NOT answer the business question or invent any store fact.
    $prompt = "You are Hercule POS semantic understanding only. Understand Iraqi Arabic, Arabic typos, colloquial wording, and English. Do NOT answer the question. Do NOT invent store facts. Return one strict JSON frame only.\n"
        ."Allowed operation: read, aggregate, compare, explain, forecast, simulate, recommend, help, automate, unknown.\n"
        ."Allowed metric: sales, revenue, profit, margin, transactions, avg_basket, discounts, cogs, quantity, inventory, stockout, expenses, purchases, returns, debts, customers, suppliers, cash, store, unknown.\n"
        ."Allowed time_scope: current, today, yesterday, tomorrow, next_week, next_month, this_week, last_week, this_month, last_month, custom, unknown.\n"
        ."If the user asks about tomorrow/bajer/غدا or asks what you expect will happen, operation MUST be forecast. Examples: 'تكدر تنبئني بمبيعات باجر؟', 'شتتوقع نبيع باجر؟', 'باجر شكد تتوقع دخلنا؟' => operation=forecast, metric=sales (or revenue when explicitly revenue), time_scope=tomorrow, horizon_days=1.\n"
        ."A typo in a prediction verb must never turn a future question into a current sales summary. If ambiguous, set confidence below 0.65 and explain ambiguity without guessing.\n"
        ."For compound analytical questions, also return analytical_query. It is a SAFE semantic frame only; NEVER SQL. Allowed analytical dimensions: none, category, product, customer, supplier, cashier, payment_method, day, hour. Allowed comparison_mode: none, previous_equal_period, previous_month, previous_year. Allowed ranking: none, desc, asc, growth, decline. Conditions operators: lt_previous, gt_previous, lt, gt, lte, gte. Example 'شنو أكثر فئة باعت هذا الشهر بس ربحها أقل من الشهر الماضي؟' => primary_metric=sales, secondary_metrics=[profit], dimension=category, date_range=this_month, comparison_mode=previous_month, ranking=desc, ranking_metric=sales, condition profit lt_previous.\n"
        ."Return exactly: {\"operation\":\"...\",\"metric\":\"...\",\"time_scope\":\"...\",\"horizon_days\":null,\"entity_type\":\"none|product|customer|supplier|category|cashier\",\"entity_query\":\"\",\"dimension\":\"none|category|product|customer|supplier|cashier|payment_method|day|hour\",\"comparison_mode\":\"none|previous_equal_period|previous_month|previous_year\",\"analytical_query\":{\"primary_metric\":\"sales\",\"secondary_metrics\":[],\"dimension\":\"none\",\"date_range\":\"this_month\",\"comparison_mode\":\"none\",\"ranking\":\"none\",\"ranking_metric\":\"sales\",\"limit\":10,\"conditions\":[],\"filters\":{},\"confidence\":0.0},\"needs_store_data\":true,\"confidence\":0.0,\"ambiguity\":\"\"}.\n"
        ."Question: ".$question;
    $res = ai_providers($prompt,420,['tier'=>'fast','mode'=>'understand','license_hash'=>$licenseHash]);
    if (!$res['ok']) {
        if (str_starts_with((string)($res['code'] ?? ''), 'AI_FAIR_USE_')) ai_json(['ok'=>false,'code'=>(string)$res['code'],'service_mode'=>(string)($res['service_mode'] ?? 'local_only'),'error'=>'تم الحفاظ على الحصة اليومية للذكاء. سيكمل Hercule بالفهم المحلي حالياً.'],429);
        ai_log_provider_failure('ai_agent_understand_providers_unavailable', $res['errors'] ?? []);
        ai_json(['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','error'=>'تعذر تشغيل الفهم الدلالي السحابي حالياً.'],503);
    }
    $frame = is_array($res['json'] ?? null) ? $res['json'] : [];
    $ops=['read','aggregate','compare','explain','forecast','simulate','recommend','help','automate','unknown'];
    $metrics=['sales','revenue','profit','margin','transactions','avg_basket','discounts','cogs','quantity','inventory','stockout','expenses','purchases','returns','debts','customers','suppliers','cash','store','unknown'];
    $times=['current','today','yesterday','tomorrow','next_week','next_month','this_week','last_week','this_month','last_month','custom','unknown'];
    $entities=['none','product','customer','supplier','category','cashier'];
    $dimensions=['none','category','product','customer','supplier','cashier','payment_method','day','hour'];
    $comparisons=['none','previous_equal_period','previous_month','previous_year'];
    $op=in_array((string)($frame['operation']??''),$ops,true)?(string)$frame['operation']:'unknown';
    $metric=in_array((string)($frame['metric']??''),$metrics,true)?(string)$frame['metric']:'unknown';
    $time=in_array((string)($frame['time_scope']??''),$times,true)?(string)$frame['time_scope']:'unknown';
    $entityType=in_array((string)($frame['entity_type']??''),$entities,true)?(string)$frame['entity_type']:'none';
    $dimension=in_array((string)($frame['dimension']??''),$dimensions,true)?(string)$frame['dimension']:'none';
    $comparisonMode=in_array((string)($frame['comparison_mode']??''),$comparisons,true)?(string)$frame['comparison_mode']:'none';
    $horizon=(int)($frame['horizon_days']??0); if($horizon<1||$horizon>365)$horizon=$time==='tomorrow'?1:($time==='next_week'?7:($time==='next_month'?30:0));
    $confidence=max(0.0,min(1.0,(float)($frame['confidence']??0.0)));
    $aq=is_array($frame['analytical_query']??null)?$frame['analytical_query']:[];
    $aqMetrics=['sales','revenue','profit','margin','transactions','avg_basket','expenses','returns','discounts','cogs','quantity','debts','purchases'];
    $aqRankings=['none','desc','asc','growth','decline']; $aqOps=['lt_previous','gt_previous','lt','gt','lte','gte'];
    $aqPrimary=in_array((string)($aq['primary_metric']??''),$aqMetrics,true)?(string)$aq['primary_metric']:'unknown';
    $aqSecondary=[]; foreach((array)($aq['secondary_metrics']??[]) as $m){$m=(string)$m;if(in_array($m,$aqMetrics,true)&&$m!==$aqPrimary&&!in_array($m,$aqSecondary,true))$aqSecondary[]=$m;if(count($aqSecondary)>=3)break;}
    $aqDimension=in_array((string)($aq['dimension']??$dimension),$dimensions,true)?(string)($aq['dimension']??$dimension):'none';
    $aqComparison=in_array((string)($aq['comparison_mode']??$comparisonMode),$comparisons,true)?(string)($aq['comparison_mode']??$comparisonMode):'none';
    $aqRanking=in_array((string)($aq['ranking']??''),$aqRankings,true)?(string)$aq['ranking']:'none';
    $aqRankingMetric=in_array((string)($aq['ranking_metric']??''),$aqMetrics,true)?(string)$aq['ranking_metric']:$aqPrimary;
    $aqLimit=max(1,min(30,(int)($aq['limit']??10))); $aqConditions=[];
    foreach(array_slice((array)($aq['conditions']??[]),0,4) as $row){if(!is_array($row))continue;$cm=in_array((string)($row['metric']??''),$aqMetrics,true)?(string)$row['metric']:$aqPrimary;$co=in_array((string)($row['operator']??''),$aqOps,true)?(string)$row['operator']:'';if($cm==='unknown'||$co==='')continue;$item=['metric'=>$cm,'operator'=>$co];if(in_array($co,['lt','gt','lte','gte'],true)){$v=$row['value']??null;if(!is_numeric($v))continue;$item['value']=(float)$v;}$aqConditions[]=$item;}
    $aqFilters=[]; foreach(['category','product','customer','cashier','payment_method'] as $k){$v=ai_cut((string)(is_array($aq['filters']??null)?($aq['filters'][$k]??''):''),120);if($v!=='')$aqFilters[$k]=$v;}
    $aqDate=$aq['date_range']??($time!=='unknown'?$time:'this_month'); if(is_array($aqDate)){$as=ai_cut((string)($aqDate['start']??''),20);$ae=ai_cut((string)($aqDate['end']??''),20);$aqDate=(preg_match('/^\d{4}-\d{2}-\d{2}$/',$as)&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$ae)&&$as<=$ae)?['start'=>$as,'end'=>$ae]:'this_month';}else{$aqDate=ai_cut((string)$aqDate,30);}
    $aqConfidence=max(0.0,min(1.0,(float)($aq['confidence']??$confidence)));
    ai_json(['ok'=>true,'provider'=>'hercule-cloud','model_tier'=>'fast','service_mode'=>(string)($res['service_mode']??'normal'),'quota_cache_hit'=>!empty($res['cache_hit']),'semantic'=>[
        'operation'=>$op,'metric'=>$metric,'time_scope'=>$time,'horizon_days'=>$horizon?:null,'entity_type'=>$entityType,'entity_query'=>ai_cut((string)($frame['entity_query']??''),120),'dimension'=>$dimension,'comparison_mode'=>$comparisonMode,
        'analytical_query'=>['primary_metric'=>$aqPrimary,'secondary_metrics'=>$aqSecondary,'dimension'=>$aqDimension,'date_range'=>$aqDate,'comparison_mode'=>$aqComparison,'ranking'=>$aqRanking,'ranking_metric'=>$aqRankingMetric,'limit'=>$aqLimit,'conditions'=>$aqConditions,'filters'=>$aqFilters,'confidence'=>$aqConfidence],
        'needs_store_data'=>($frame['needs_store_data']??true)!==false,'confidence'=>$confidence,'ambiguity'=>ai_cut((string)($frame['ambiguity']??''),240)
    ]]);
}

if ($mode === 'plan') {
    $modelRoute = ai_model_router_classify('plan', $question, $context, []);
    $intentNames = [];
    foreach ($catalog as $name=>$meta) if ($name !== 'none') $intentNames[] = $name.(!empty($meta['entity'])?'['.$meta['entity'].']':'');
    $prompt = "You are the planning brain for Hercule POS. The user may write Iraqi Arabic, Arabic, or English. Never invent store facts. Store facts MUST come from local read-only tools. General knowledge/chat can be answered directly. For app settings or usage, use help_search. For analysis, request multiple relevant reports. For future predictions, always use forecast tools and never guess numbers. For hypothetical price, discount, reorder quantity, or supplier-change questions, use simulate_what_if and never invent scenario numbers. For supplier quality, price stability, delivery reliability, expiry quality, margin impact, or supplier comparison questions, use analyze_suppliers and never rank suppliers from memory. For products bought together, market-basket, cross-sell, or bundle questions, use analyze_baskets and never invent Support, Confidence, Lift, or bundle profitability. Product facts: the software maker name is Hercule, maker phone is 07802699479, maker email is 11mosta22@gmail.com. All Hercule POS monetary values use Iraqi dinar only (IQD / د.ع / الدينار العراقي); never call the store currency riyal or SAR.\n"
        ."Allowed tools: store_read, analyze_query, report, forecast_sales, forecast_inventory, forecast_product, simulate_what_if, analyze_suppliers, analyze_baskets, help_search. analyze_query is the universal READ-ONLY analytical engine for compound metric + dimension + period + comparison + ranking questions and never accepts SQL. store_read is the universal local READ-ONLY catalog for direct factual store queries. store_read views: products, product_prices, inventory, inventory_batches, inventory_locations, location_inventory, inventory_transfers, inventory_counts, product_movements, invoices, invoice_detail, customers, customer_debts, suppliers, purchase_orders, supplier_payables, supplier_documents, expenses, promotions, cash_drawer, cash_shifts, users, price_audit, activity_log, held_sales, feature_settings, categories. Never request secrets, passwords, API keys, license keys, DB keys, raw files, or arbitrary SQL. Use exactly the key tool in every tool call. Report intents: ".implode(',',$intentNames).".\n"
        ."Examples:\n- 'آخر الفواتير' => store_read view=invoices sort=newest limit=10.\n- 'شنو أغلى منتج' => store_read view=product_prices sort=price_desc limit=1.\n- 'العروض الفعالة حاليا' => store_read view=promotions status=active limit=20.\n- 'آخر طلبية شراء' => store_read view=purchase_orders sort=newest limit=1.\n-  'حلل أداء المتجر هذا الشهر' => tools: manager_brief,sales_comparison,net_profit,expenses_summary,discounts_summary,returns_summary,operational_alerts.\n"
        ."- 'ما توقعك للاسبوع الجاي' => forecast_sales + sales_comparison + operational_alerts.\n"
        ."- 'شلون أفعل درج النقد' => help_search.\n"
        ."Context: ".ai_prompt_json($context,14000)."\nQuestion: ".$question
        ."\nThe followups field is OPTIONAL. If used, it must contain only 1-4 short next QUESTIONS/REQUESTS the user could send, never advice, conclusions, instructions, or parts of the answer.\nReturn JSON only. Either {\"kind\":\"answer\",\"answer\":{\"answer\":\"useful complete answer\",\"key_points\":[],\"recommendations\":[],\"confidence\":\"high|medium|low\",\"followups\":[],\"navigation\":[]},\"tool_calls\":[]} OR {\"kind\":\"tools\",\"goal\":\"...\",\"tool_calls\":[{\"tool\":\"report\",\"intent\":\"sales_summary\",...}]}. Never return an empty answer.";
    $res = ai_providers($prompt,1000,['tier'=>$modelRoute['tier'],'mode'=>'plan','license_hash'=>$licenseHash]);
    if (!$res['ok']) {
        if (str_starts_with((string)($res['code'] ?? ''), 'AI_FAIR_USE_')) {
            ai_json(['ok'=>false,'code'=>(string)$res['code'],'service_mode'=>(string)($res['service_mode'] ?? 'local_only'),'error'=>'تم الحفاظ على الحصة اليومية للذكاء. سيكمل Hercule بالتحليل المحلي حالياً.'],429);
        }
        ai_log_provider_failure('ai_agent_plan_providers_unavailable', $res['errors'] ?? []);
        ai_json(['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'],503);
    }
    $model = $res['json'];
    $calls = ai_normalize_calls($model,$catalog,$question);
    if ($calls) {
        ai_json(['ok'=>true,'provider'=>'hercule-cloud','model_tier'=>ai_model_router_public_label((string)$res['tier']),'route_fallback_used'=>!empty($res['fallback_used']),'service_mode'=>(string)($res['service_mode'] ?? 'normal'),'quota_cache_hit'=>!empty($res['cache_hit']),'plan'=>['kind'=>'tools','goal'=>ai_cut((string)($model['goal']??''),300),'tool_calls'=>$calls]]);
    }
    $answer = ai_sanitize_answer_payload(ai_answer_object($model['answer'] ?? $model['response'] ?? $model['message'] ?? $model['text'] ?? null));
    $answer = ai_repair_answer_language($answer, $question, $licenseHash);
    if (trim((string)($answer['answer'] ?? '')) !== '') {
        ai_json(['ok'=>true,'provider'=>'hercule-cloud','model_tier'=>ai_model_router_public_label((string)$res['tier']),'route_fallback_used'=>!empty($res['fallback_used']),'service_mode'=>(string)($res['service_mode'] ?? 'normal'),'quota_cache_hit'=>!empty($res['cache_hit']),'plan'=>['kind'=>'answer','answer'=>$answer,'tool_calls'=>[]]]);
    }
    $fallback = ai_fallback_plan($question);
    if ($fallback) ai_json(['ok'=>true,'provider'=>'hercule-cloud','model_tier'=>ai_model_router_public_label((string)$res['tier']),'route_fallback_used'=>!empty($res['fallback_used']),'service_mode'=>(string)($res['service_mode'] ?? 'normal'),'quota_cache_hit'=>!empty($res['cache_hit']),'plan'=>$fallback,'repaired'=>true]);
    ai_json(['ok'=>false,'code'=>'AI_INVALID_PLAN','error'=>'AI provider returned an empty or unusable plan'],502);
}

$plan = ai_privacy_sanitize(is_array($body['plan'] ?? null) ? $body['plan'] : [], $privacy['scope']);
$tools = ai_privacy_sanitize(is_array($body['tool_results'] ?? null) ? array_slice($body['tool_results'],0,8) : [], $privacy['scope']);
$modelRoute = ai_model_router_classify('synthesize', $question, $context, $tools);
$prompt = "You are the Hercule POS smart assistant. Use ONLY supplied privacy-preserving aggregate evidence for store-specific facts. The client forwards no raw invoice/customer rows; do not ask for or infer missing identities. Never invent numbers, causes, product names, customer names, or settings state. Forecasts are probabilistic: mention confidence/range and never guarantee. App-help must follow supplied help steps/settings. If some tools failed, clearly say what could not be read and still use the successful evidence. Answer in the user's language; use natural Iraqi Arabic when the user uses Iraqi Arabic. Product facts: the software maker name is Hercule, maker phone is 07802699479, maker email is 11mosta22@gmail.com. All monetary values in Hercule POS are Iraqi dinar only (IQD / د.ع / الدينار العراقي). Never label Hercule amounts as riyal, Saudi riyal, or SAR.\n"
    ."The followups field is OPTIONAL. If used, it must contain only 1-4 short next QUESTIONS/REQUESTS the user could send, never advice, conclusions, instructions, or parts of the answer.\nReturn JSON only: {\"answer\":\"complete useful answer\",\"key_points\":[],\"recommendations\":[],\"confidence\":\"high|medium|low\",\"followups\":[],\"navigation\":[{\"view\":\"dashboard|sell|shifts|inventory|customers|expenses|reports|ask|settings|invoices|promotions|purchasing\",\"label\":\"...\"}],\"caveat\":\"\"}. Never return an empty answer.\n"
    ."Question: ".$question."\nPlan: ".ai_prompt_json($plan,5000)."\nTool results: ".ai_prompt_json($tools,24000)."\nContext: ".ai_prompt_json($context,7000);
$res = ai_providers($prompt,1400,['tier'=>$modelRoute['tier'],'mode'=>'synthesize','license_hash'=>$licenseHash]);
if (!$res['ok']) {
    if (str_starts_with((string)($res['code'] ?? ''), 'AI_FAIR_USE_')) {
        ai_json(['ok'=>false,'code'=>(string)$res['code'],'service_mode'=>(string)($res['service_mode'] ?? 'local_only'),'error'=>'تم الحفاظ على الحصة اليومية للذكاء. سيكمل Hercule بالتحليل المحلي حالياً.'],429);
    }
    ai_log_provider_failure('ai_agent_synthesize_providers_unavailable', $res['errors'] ?? []);
    ai_json(['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'],503);
}
$answer = ai_sanitize_answer_payload($res['json']);
$answer = ai_repair_answer_language($answer, $question, $licenseHash);
if (!trim((string)($answer['answer'] ?? ''))) ai_json(['ok'=>false,'code'=>'AI_INVALID_RESPONSE','error'=>'AI provider returned an empty answer'],502);
$answer['answer'] = ai_cut((string)$answer['answer'],5000);
ai_json(['ok'=>true,'provider'=>'hercule-cloud','model_tier'=>ai_model_router_public_label((string)$res['tier']),'route_fallback_used'=>!empty($res['fallback_used']),'service_mode'=>(string)($res['service_mode'] ?? 'normal'),'quota_cache_hit'=>!empty($res['cache_hit']),'answer'=>$answer]);
