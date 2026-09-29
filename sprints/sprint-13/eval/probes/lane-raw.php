<?php

/**
 * Sprint 13 step 11 — raw `/general-knowledge` response for ONE question
 * (env LANE_Q), to see why the lane returned nothing: which catalogue entries
 * matched, what each fetch returned (status / windows / matched terms / error),
 * and what the model answered. Prints only trace + answer + sources — never the
 * API key. Read-only (outbound fetches only).
 */

use App\Models\AnswerModelSetting;
use App\Services\ExtractionClient;

$q = getenv('LANE_Q') ?: '¿Cuánto dura mi periodo de prueba?';
$settings = AnswerModelSetting::current();
$key = $settings->decryptKey();
$config = config('hr.general_lane');
$raw = app(ExtractionClient::class)->generalKnowledge($q, $config['sources'] ?? [], $config['domains'] ?? [], $key, [
    'provider' => config('services.hr_ai.answer_provider', 'claude'),
    'model' => config('services.hr_ai.answer_model'),
    'endpoint' => config('services.hr_ai.answer_endpoint'),
]);
unset($key);

$clip = fn ($v) => is_string($v) && mb_strlen($v) > 700 ? mb_substr($v, 0, 700).'…' : $v;
$sources = array_map(fn ($s) => is_array($s) ? array_map($clip, $s) : $s, $raw['sources'] ?? []);
echo 'RAW '.json_encode([
    'error' => $raw['error'] ?? null,
    'detail' => isset($raw['detail']) ? mb_substr(preg_replace('/sk-[A-Za-z0-9_\-]+/', 'sk-***', (string) $raw['detail']), 0, 600) : null,
    'answer' => $raw['answer'] ?? null,
    'sources' => $sources,
    'trace_fragment' => $raw['trace_fragment'] ?? null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
