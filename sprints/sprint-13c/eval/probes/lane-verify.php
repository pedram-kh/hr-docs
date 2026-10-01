<?php

/**
 * Slice 13c, stage S0 (plan.md §3.1) — V1 + V2 verification of the positive-candidate pool. READ-ONLY: nothing is persisted
 * (no session / message / card / trace row); the only outbound calls are the corpus path's own (/retrieve, /synthesise,
 * /ground) — exactly what the agent's `convenio_search` spends on the same question.
 *
 * Per candidate, on the employee of `V_EMAIL` (default test-gipuzkoa = profile `cov`):
 *   pre-corpus gates  guardrail / salary router / pay intent      (static, $0)
 *   v1 + v2 pre-screen                                            (static, $0; v2 only if the method exists on this tree)
 *   fact route        ReferenceFactRouter::detectTopic            ($0; a hit means round 0 takes the question)
 *   corpus attempt    ProsePath::handle — the REAL path, with the literal question and the deterministic split the tool uses
 *                     → Check A (V1) and synthesis / Check B / figure guard / entailment (V2)
 * and prints `V {json}` with the derived `corpus_shape`:
 *   check_a_miss | synthesis_abstention | entailment_only | figure_guard | provider_error | fallback_* | corpus_answers | other
 * `CorpusMiss::classify` (the lane's CURRENT whitelist) is recorded next to it so the S0 report can show which candidates the
 * unmodified lane would already open on and which need the new `synthesis_abstention` shape.
 *
 * Env: V_EMAIL, V_SET (default the frozen pool), V_IDS (comma list of ids), V_BUDGET (USD, default 3.5 — stops when reached),
 *      V_DRY=1 (print the plan and exit; no calls).
 * Output: `V {json}` per candidate, then `VSUM {json}`. Cost is list price ($3/$15 per MTok) summed from every trace_fragment.
 */

use App\Models\AnswerModelSetting;
use App\Models\Employee;
use App\Services\Agent\Rules\CorpusMiss;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Services\Answer\ProsePath;
use App\Services\GuardrailService;
use App\Services\ReferenceFactRouter;
use App\Services\RouterService;
use Illuminate\Support\Carbon;

$email = getenv('V_EMAIL') ?: 'test-gipuzkoa@example.com';
if (! str_starts_with($email, 'test-') || ! str_ends_with($email, '@example.com')) {
    exit("refusing: not a test employee\n");
}
$set = getenv('V_SET') ?: '/var/hr-docs/sprints/sprint-13c/eval/lane-positives-pool.json';
$budget = (float) (getenv('V_BUDGET') ?: 3.5);
$ids = array_filter(explode(',', (string) getenv('V_IDS')));

$cases = json_decode((string) file_get_contents($set), true, 512, JSON_THROW_ON_ERROR)['cases'];
if ($ids !== []) {
    $cases = array_values(array_filter($cases, fn ($c) => in_array($c['id'], $ids, true)));
}
if (getenv('V_DRY')) {
    echo 'VDRY '.json_encode(['email' => $email, 'candidates' => count($cases), 'budget' => $budget, 'worst_case_usd' => round(count($cases) * 0.12, 2)])."\n";

    return;
}

$employee = Employee::where('email', $email)->firstOrFail();
$guard = app(GuardrailService::class);
$router = app(RouterService::class);
$payRule = new SalaryIntentPreCallRule($router);
$facts = app(ReferenceFactRouter::class);
$prose = app(ProsePath::class);
$settings = AnswerModelSetting::current();
$asOf = Carbon::today();

$tokens = static function (mixed $node) use (&$tokens): array {
    $in = 0;
    $out = 0;
    if (is_array($node)) {
        if (isset($node['prompt_tokens']) || isset($node['completion_tokens'])) {
            $in += (int) ($node['prompt_tokens'] ?? 0);
            $out += (int) ($node['completion_tokens'] ?? 0);
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                [$i, $o] = $tokens($v);
                $in += $i;
                $out += $o;
            }
        }
    }

    return [$in, $out];
};

$spent = 0.0;
$sum = ['candidates' => 0, 'gated_pre_corpus' => 0, 'fact_route' => 0, 'attempted' => 0, 'shapes' => [], 'prescreen_v2_refused' => 0];

foreach ($cases as $c) {
    if ($spent >= $budget) {
        echo 'VSTOP '.json_encode(['reason' => 'budget', 'spent' => round($spent, 4), 'at' => $c['id']])."\n";
        break;
    }
    $q = $c['question'];
    $row = ['id' => $c['id'], 'question' => $q, 'flags' => $c['flags'] ?? []];
    $sum['candidates']++;

    $g = $guard->check($q);
    $gates = [];
    if ($g['fired']) {
        $gates[] = 'guardrail:'.$g['rule'];
    }
    if ($router->matchesSalary($q)) {
        $gates[] = 'salary_router';
    }
    if ($payRule->hasPayIntent($q)) {
        $gates[] = 'pay_intent';
    }
    $row['pre_corpus_gates'] = $gates;
    $row['prescreen_v1_hit'] = GeneralLanePostCheck::questionPrescreenHit($q);
    $row['prescreen_v2_refusal'] = method_exists(GeneralLanePostCheck::class, 'questionRefusal') ? GeneralLanePostCheck::questionRefusal($q) : 'n/a';
    $sum['prescreen_v2_refused'] += $row['prescreen_v2_refusal'] !== null && $row['prescreen_v2_refusal'] !== 'n/a' ? 1 : 0;

    if ($gates !== []) {
        $row['corpus_shape'] = 'gated_pre_corpus';
        $sum['gated_pre_corpus']++;
        echo 'V '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        continue;
    }

    $fact = $facts->detectTopic($employee, $q, $asOf);
    if ($fact !== null) {
        $row['fact_route'] = $fact['topic_name'] ?? ($fact['topic_id'] ?? true);
        $row['corpus_shape'] = 'fact_route';
        $sum['fact_route']++;
        echo 'V '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        continue;
    }

    $t0 = microtime(true);
    $key = $settings->isConfigured() ? $settings->decryptKey() : null;
    $out = $prose->handle($employee, $q, $router->deterministicSplit($q), $asOf, $key, [], [], false);
    unset($key);
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $sum['attempted']++;

    $t = $out->trace;
    $fd = $t['floor_decision'] ?? [];
    [$in, $outTok] = $tokens($t);
    $cost = ($in * 3.0 + $outTok * 15.0) / 1_000_000;
    $spent += $cost;

    $hasFallback = array_key_exists('fallback', $fd);
    if ($out->outcome === 'answer') {
        $shape = 'corpus_answers';
    } elseif (($fd['check_a_retrieval'] ?? true) === false) {
        $shape = 'check_a_miss';
    } elseif (($fd['note'] ?? null) === 'provider error') {
        $shape = 'provider_error';
    } elseif (($fd['check_b_citations'] ?? null) === false) {
        $shape = $hasFallback ? 'fallback_abstention' : 'synthesis_abstention';
    } elseif (($fd['figure_grounding']['grounded'] ?? true) === false) {
        $shape = 'figure_guard';
    } elseif (($fd['grounding']['checked'] ?? false) === true && ($fd['grounding']['grounded'] ?? true) === false) {
        $shape = $hasFallback ? 'fallback_entailment' : 'entailment_only';
    } else {
        $shape = 'other';
    }

    $row += [
        'corpus_shape' => $shape,
        'corpus_miss_classify' => CorpusMiss::classify($out),
        'outcome' => $out->outcome,
        'escalation_reason' => $out->escalationReason,
        'fallback' => $hasFallback,
        'top_score' => $t['retrieval']['top_score'] ?? null,
        'retrieval_floor' => $fd['retrieval_score_floor'] ?? null,
        'check_a' => $fd['check_a_retrieval'] ?? null,
        'check_b' => $fd['check_b_citations'] ?? null,
        'confidence' => $t['synthesis']['confidence'] ?? null,
        'citation_count' => $t['synthesis']['citation_count'] ?? null,
        'authority_used' => $fd['authority_used'] ?? ($t['synthesis']['authority_used'] ?? null),
        'note' => $fd['note'] ?? null,
        'answer_excerpt' => $out->outcome === 'answer' ? mb_substr($out->answer, 0, 220) : null,
        'tokens_prompt' => $in, 'tokens_completion' => $outTok, 'cost_usd' => round($cost, 5), 'ms' => $ms,
    ];
    $sum['shapes'][$shape] = ($sum['shapes'][$shape] ?? 0) + 1;
    echo 'V '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

echo 'VSUM '.json_encode($sum + ['email' => $email, 'spent_usd' => round($spent, 4), 'budget_usd' => $budget])."\n";
