<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/DeviceManager.php';

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
function ai_call_provider(string $provider, string $prompt, int $maxTokens): array {
    if ($provider === 'gemini') {
        $key = ai_env('GEMINI_API_KEY'); if ($key === '') return ['skip'=>true];
        $model = ai_env('GEMINI_MODEL', 'gemini-2.5-flash-lite');
        $base = rtrim(ai_env('GEMINI_API_BASE', 'https://generativelanguage.googleapis.com/v1beta'), '/');
        $res = ai_post_json($base.'/models/'.rawurlencode($model).':generateContent', ['x-goog-api-key: '.$key], [
            'contents'=>[['role'=>'user','parts'=>[['text'=>$prompt]]]],
            'generationConfig'=>['temperature'=>0.12,'maxOutputTokens'=>$maxTokens,'responseMimeType'=>'application/json'],
        ]);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['candidates'][0]['content']['parts'][0]['text']??''),'error'=>$res['error'] ?: (string)($res['json']['error']['message']??'')];
    }
    if ($provider === 'groq') {
        $key = ai_env('GROQ_API_KEY'); if ($key === '') return ['skip'=>true];
        $model = ai_env('GROQ_MODEL', 'openai/gpt-oss-20b');
        $base = rtrim(ai_env('GROQ_API_BASE', 'https://api.groq.com/openai/v1'), '/');
        $res = ai_post_json($base.'/chat/completions', ['Authorization: Bearer '.$key], [
            'model'=>$model,
            'messages'=>[['role'=>'system','content'=>'Return one strict JSON object only.'],['role'=>'user','content'=>$prompt]],
            'temperature'=>0.12,
            'max_completion_tokens'=>$maxTokens,
            'response_format'=>['type'=>'json_object'],
        ]);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['choices'][0]['message']['content']??''),'error'=>$res['error'] ?: (string)($res['json']['error']['message']??'')];
    }
    if ($provider === 'cloudflare') {
        $token = ai_env('CLOUDFLARE_AI_TOKEN'); $account = ai_env('CLOUDFLARE_ACCOUNT_ID');
        if ($token === '' || $account === '') return ['skip'=>true];
        $model = ai_env('CLOUDFLARE_AI_MODEL', '@cf/meta/llama-3.1-8b-instruct-fast');
        $base = rtrim(ai_env('CLOUDFLARE_API_BASE', 'https://api.cloudflare.com/client/v4'), '/');
        $url = $base.'/accounts/'.rawurlencode($account).'/ai/run/'.str_replace('%2F','/',rawurlencode($model));
        $res = ai_post_json($url, ['Authorization: Bearer '.$token], ['prompt'=>$prompt,'max_tokens'=>$maxTokens,'temperature'=>0.12]);
        return ['status'=>$res['status'],'text'=>(string)($res['json']['result']['response']??''),'error'=>$res['error'] ?: (string)($res['json']['errors'][0]['message']??'')];
    }
    return ['skip'=>true];
}
function ai_providers(string $prompt, int $maxTokens): array {
    $errors = [];
    $order = array_filter(array_map('trim', explode(',', strtolower(ai_env('HERCULE_AI_PROVIDER_ORDER', 'gemini,groq,cloudflare')))));
    foreach ($order as $provider) {
        $res = ai_call_provider($provider, $prompt, $maxTokens);
        if (!empty($res['skip'])) { $errors[] = $provider.':not_configured'; continue; }
        $json = ai_clean_json((string)($res['text'] ?? ''));
        if ($json) return ['ok'=>true,'provider'=>$provider,'json'=>$json];
        $errors[] = $provider.':'.(int)($res['status']??0);
    }
    return ['ok'=>false,'errors'=>$errors];
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
        'app_help'=>'help_search','help'=>'help_search','settings_help'=>'help_search',
        'get_report'=>'report','query_report'=>'report','run_report'=>'report',
    ];
    foreach (array_slice($rawCalls, 0, 12) as $raw) {
        if (!is_array($raw)) continue;
        $c = ai_flatten_call($raw);
        $tool = strtolower(trim((string)($c['tool'] ?? '')));
        if (isset($toolAliases[$tool])) $tool = $toolAliases[$tool];
        if (isset($catalog[$tool]) && $tool !== 'none') { $c['intent'] = $tool; $tool = 'report'; }
        if ($tool === 'report') {
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
    $answer['followups'] = ai_followup_questions($answer['followups'] ?? $answer['suggested_questions'] ?? [], 4);
    unset($answer['suggested_questions']);
    return $answer;
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
    if ($scope === 'minimal' && preg_match('/(?:^|_)(?:id|name|title|sku|barcode|code|serial|lot|note|customer|supplier|product|category|invoice|receipt|document|reference)(?:$|_)/i', $key)) return true;
    if ($scope === 'minimal' && preg_match('/^(?:summary|message|description|label)$/i', $key)) return true;
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
if (!in_array($mode, ['plan','synthesize'], true)) ai_json(['ok'=>false,'code'=>'INVALID_MODE'],400);
$privacy = ai_privacy_policy($body);
$question = trim(ai_privacy_redact_text($question, 6000));

$ipLimit = max(10,(int)ai_env('HERCULE_AI_IP_RPM','60'));
if (!RateLimiter::check(client_ip(),'ai_agent_ip',$ipLimit,1)) {
    ai_json(['ok'=>false,'code'=>'AI_RATE_LIMIT','error'=>'طلبات كثيرة جداً. جرّب بعد قليل.'],429);
}

if (DeviceManager::isBlocked($licenseKey,$hwid)) ai_json(['ok'=>false,'code'=>'DEVICE_BLOCKED'],403);
$validation = License::validate($licenseKey,$hwid,client_ip());
if (!($validation['ok'] ?? false)) ai_json(['ok'=>false,'code'=>'LICENSE_INVALID'],403);
$perLicense = max(1,(int)ai_env('HERCULE_AI_PER_LICENSE_RPM','12'));
$global = max($perLicense,(int)ai_env('HERCULE_AI_GLOBAL_RPM','30'));
$bucket = 'lic:'.substr(hash('sha256',$licenseKey),0,40);
if (!RateLimiter::check($bucket,'ai_agent_'.$mode,$perLicense,1) || !RateLimiter::check('global','ai_agent_global_'.$mode,$global,1)) {
    ai_json(['ok'=>false,'code'=>'AI_RATE_LIMIT','error'=>'التحليل الذكي مشغول حالياً.'],429);
}
$catalog = json_decode((string)@file_get_contents(__DIR__.'/intent_catalog.json'),true);
if (!is_array($catalog)) ai_json(['ok'=>false,'code'=>'CATALOG_ERROR'],500);
$context = ai_privacy_sanitize(is_array($body['context'] ?? null) ? $body['context'] : [], $privacy['scope']);

if ($mode === 'plan') {
    $intentNames = [];
    foreach ($catalog as $name=>$meta) if ($name !== 'none') $intentNames[] = $name.(!empty($meta['entity'])?'['.$meta['entity'].']':'');
    $prompt = "You are the planning brain for Hercule POS. The user may write Iraqi Arabic, Arabic, or English. Never invent store facts. Store facts MUST come from local read-only tools. General knowledge/chat can be answered directly. For app settings or usage, use help_search. For analysis, request multiple relevant reports. For future predictions, always use forecast tools and never guess numbers.\n"
        ."Allowed tools: report, forecast_sales, forecast_inventory, forecast_product, help_search. Use exactly the key tool in every tool call. Report intents: ".implode(',',$intentNames).".\n"
        ."Examples:\n- 'حلل أداء المتجر هذا الشهر' => tools: manager_brief,sales_comparison,net_profit,expenses_summary,discounts_summary,returns_summary,operational_alerts.\n"
        ."- 'ما توقعك للاسبوع الجاي' => forecast_sales + sales_comparison + operational_alerts.\n"
        ."- 'شلون أفعل درج النقد' => help_search.\n"
        ."Context: ".ai_prompt_json($context,14000)."\nQuestion: ".$question
        ."\nThe followups field is OPTIONAL. If used, it must contain only 1-4 short next QUESTIONS/REQUESTS the user could send, never advice, conclusions, instructions, or parts of the answer.\nReturn JSON only. Either {\"kind\":\"answer\",\"answer\":{\"answer\":\"useful complete answer\",\"key_points\":[],\"recommendations\":[],\"confidence\":\"high|medium|low\",\"followups\":[],\"navigation\":[]},\"tool_calls\":[]} OR {\"kind\":\"tools\",\"goal\":\"...\",\"tool_calls\":[{\"tool\":\"report\",\"intent\":\"sales_summary\",...}]}. Never return an empty answer.";
    $res = ai_providers($prompt,1000);
    if (!$res['ok']) {
        ai_log_provider_failure('ai_agent_plan_providers_unavailable', $res['errors'] ?? []);
        ai_json(['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'],503);
    }
    $model = $res['json'];
    $calls = ai_normalize_calls($model,$catalog,$question);
    if ($calls) {
        ai_json(['ok'=>true,'provider'=>$res['provider'],'plan'=>['kind'=>'tools','goal'=>ai_cut((string)($model['goal']??''),300),'tool_calls'=>$calls]]);
    }
    $answer = ai_sanitize_answer_payload(ai_answer_object($model['answer'] ?? $model['response'] ?? $model['message'] ?? $model['text'] ?? null));
    if (trim((string)($answer['answer'] ?? '')) !== '') {
        ai_json(['ok'=>true,'provider'=>$res['provider'],'plan'=>['kind'=>'answer','answer'=>$answer,'tool_calls'=>[]]]);
    }
    $fallback = ai_fallback_plan($question);
    if ($fallback) ai_json(['ok'=>true,'provider'=>$res['provider'],'plan'=>$fallback,'repaired'=>true]);
    ai_json(['ok'=>false,'code'=>'AI_INVALID_PLAN','error'=>'AI provider returned an empty or unusable plan'],502);
}

$plan = ai_privacy_sanitize(is_array($body['plan'] ?? null) ? $body['plan'] : [], $privacy['scope']);
$tools = ai_privacy_sanitize(is_array($body['tool_results'] ?? null) ? array_slice($body['tool_results'],0,8) : [], $privacy['scope']);
$prompt = "You are the Hercule POS smart assistant. Use ONLY supplied local tool results for store-specific facts. Never invent numbers, causes, product names, customer names, or settings state. Forecasts are probabilistic: mention confidence/range and never guarantee. App-help must follow supplied help steps/settings. If some tools failed, clearly say what could not be read and still use the successful evidence. Answer in the user's language; use natural Iraqi Arabic when the user uses Iraqi Arabic.\n"
    ."The followups field is OPTIONAL. If used, it must contain only 1-4 short next QUESTIONS/REQUESTS the user could send, never advice, conclusions, instructions, or parts of the answer.\nReturn JSON only: {\"answer\":\"complete useful answer\",\"key_points\":[],\"recommendations\":[],\"confidence\":\"high|medium|low\",\"followups\":[],\"navigation\":[{\"view\":\"dashboard|sell|shifts|inventory|customers|expenses|reports|ask|settings|invoices|promotions|purchasing\",\"label\":\"...\"}],\"caveat\":\"\"}. Never return an empty answer.\n"
    ."Question: ".$question."\nPlan: ".ai_prompt_json($plan,5000)."\nTool results: ".ai_prompt_json($tools,24000)."\nContext: ".ai_prompt_json($context,7000);
$res = ai_providers($prompt,1400);
if (!$res['ok']) {
    ai_log_provider_failure('ai_agent_synthesize_providers_unavailable', $res['errors'] ?? []);
    ai_json(['ok'=>false,'code'=>'AI_PROVIDERS_UNAVAILABLE','error'=>'تعذر الوصول إلى مزودي الذكاء حالياً.'],503);
}
$answer = ai_sanitize_answer_payload($res['json']);
if (!trim((string)($answer['answer'] ?? ''))) ai_json(['ok'=>false,'code'=>'AI_INVALID_RESPONSE','error'=>'AI provider returned an empty answer'],502);
$answer['answer'] = ai_cut((string)$answer['answer'],5000);
ai_json(['ok'=>true,'provider'=>$res['provider'],'answer'=>$answer]);
