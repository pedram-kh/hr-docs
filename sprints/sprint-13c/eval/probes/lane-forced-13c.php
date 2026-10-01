<?php

/**
 * Slice 13c step 9 (plan.md §5.2) — the FORCED-LANE harness, extended. `sprint-13/eval/probes/lane-forced.php` is untouched.
 *
 * Sends each question STRAIGHT to `GeneralKnowledgeTool` (→ hr-ai `/general-knowledge`: catalogue match, real fetch, real model; web
 * drafts are also `/ground`ed inside the tool), bypassing the planner and the availability rules, then judges the draft with the
 * deterministic locks in the order the live rules run them (see `lane-forced-13c-lib.php`). Read-only: no session / message / card
 * is persisted; the only writes are the outbound fetches and model calls.
 *
 * Env:
 *   LANE_POSITIVES   lane-positives.json (default the frozen one; `none` skips them)            LANE_NEGATIVES  optional json of negatives (`cases[].question`)
 *   LANE_REPEATS_POS (default 1)   LANE_REPEATS_NEG (default 3)              LANE_GATE_ONLY=1  only `gate_set: true` positives
 *   LANE_IDS         comma list of case ids to run                           LANE_EMAIL      a test-*@example.com employee (per-case `email` wins)
 *   LANE_SUBFLAG     on (default) | off — model-knowledge sub-flag for THIS process only (off = the Sprint-13 web-only behaviour)
 *   LANE_THROUGH_PRESCREEN=1  do NOT call the tool for a question pre-screen v2 would deny; report it as `denied_by_prescreen`
 *   LANE_BUDGET      USD stop-loss (default 1.0): the run ends before the next call once spent + its worst case would exceed it
 *   LANE_DRY=1       print the cost projection and exit (no calls)
 * Output: `LANE {json}` per draft, then `LANESUM {json}` (per class/basis: verdict counts, block rate R1, rule-id histograms,
 * words/cost/latency percentiles, AUDIT bypasses, and per-lock counts: `would_catch_any` = drafts each lock alone would have stopped, `sole_catcher` =
 * drafts stopped by exactly one lock) and `LANEPASS {json}` for every negative that passed (full text, for CP-1 reading). Each LANE row also carries
 * `locks` (every lock run independently of the live short-circuit) and `fallback` (web → model-knowledge, with its reason).
 *
 * The harness flips `hr.general_lane.enabled` / `.model_knowledge` in-process so it is independent of the host's env.
 * Cost is the draft call's own list-price cost; a web draft's `/ground` call (≈ $0.01–0.02) is added as a flat estimate.
 */

use App\Models\AnswerModelSetting;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Tools\GeneralKnowledgeTool;
use App\Services\Agent\TurnState;
use App\Services\GuardrailPolicy;
use Illuminate\Support\Carbon;

require_once __DIR__.'/lane-forced-13c-lib.php';

const LANE_WORST_CASE_USD = 0.10;   // plan §7: corpus-free web-grounded draft ≈ 0.065, padded
const LANE_GROUND_ESTIMATE_USD = 0.015;

$positivesPath = getenv('LANE_POSITIVES') ?: '/var/hr-docs/sprints/sprint-13c/eval/lane-positives.json';
$negativesPath = getenv('LANE_NEGATIVES') ?: null;
$repeatsPos = max(1, (int) (getenv('LANE_REPEATS_POS') ?: 1));
$repeatsNeg = max(1, (int) (getenv('LANE_REPEATS_NEG') ?: 3));
$gateOnly = (bool) getenv('LANE_GATE_ONLY');
$ids = array_filter(explode(',', (string) getenv('LANE_IDS')));
$defaultEmail = getenv('LANE_EMAIL') ?: 'test-gipuzkoa@example.com';
$subflag = strtolower((string) (getenv('LANE_SUBFLAG') ?: 'on')) !== 'off';
$throughPrescreen = (bool) getenv('LANE_THROUGH_PRESCREEN');
$budget = (float) (getenv('LANE_BUDGET') ?: 1.0);

$plan = [];
$load = function (string $path, string $class, int $repeats) use (&$plan, $gateOnly, $ids, $defaultEmail): void {
    $cases = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['cases'];
    foreach ($cases as $c) {
        if (! isset($c['question'])) {
            continue;
        }
        if ($ids !== [] && ! in_array($c['id'] ?? '', $ids, true)) {
            continue;
        }
        if ($class === 'lane_positive' && $gateOnly && ! ($c['gate_set'] ?? false)) {
            continue;
        }
        for ($i = 1; $i <= $repeats; $i++) {
            $plan[] = ['id' => $c['id'] ?? null, 'class' => $class === 'lane_positive' ? $class : ($c['class'] ?? $class), 'repeat' => $i, 'question' => $c['question'], 'email' => $c['email'] ?? $defaultEmail, 'family' => $c['family'] ?? null];
        }
    }
};
if ($positivesPath !== 'none') {
    $load($positivesPath, 'lane_positive', $repeatsPos);
}
if ($negativesPath !== null) {
    $load($negativesPath, 'lane_negative', $repeatsNeg);
}

if (getenv('LANE_DRY')) {
    echo 'LANEDRY '.json_encode(['drafts' => count($plan), 'projected_usd' => round(count($plan) * 0.03, 2), 'worst_case_usd' => round(count($plan) * LANE_WORST_CASE_USD, 2), 'budget' => $budget, 'subflag' => $subflag ? 'on' : 'off', 'through_prescreen' => $throughPrescreen])."\n";

    return;
}

config(['hr.general_lane.enabled' => true, 'hr.general_lane.model_knowledge' => $subflag]);
GuardrailPolicy::flush();
if (! AnswerModelSetting::current()->isConfigured()) {
    exit("refusing: no answer-model key configured on this host\n");
}

$tool = app(GeneralKnowledgeTool::class);
$employees = [];
$rows = [];
$spent = 0.0;
$stoppedForBudget = false;

foreach ($plan as $p) {
    if (! str_starts_with($p['email'], 'test-') || ! str_ends_with($p['email'], '@example.com')) {
        exit("refusing: not a test employee\n");
    }
    $employee = $employees[$p['email']] ??= Employee::where('email', $p['email'])->firstOrFail();

    $row = ['id' => $p['id'], 'class' => $p['class'], 'family' => $p['family'], 'repeat' => $p['repeat'], 'question' => $p['question']];
    $blocked = GeneralLanePostCheck::questionBlocked($p['question'], true);
    $row['prescreen_v2_blocked'] = $blocked;

    if ($throughPrescreen && $blocked) {
        $row += ['verdict' => 'denied_by_prescreen', 'basis' => null, 'answer' => null, 'word_count' => null, 'postcheck' => null, 'shape' => null, 'audit' => [], 'cost_usd' => null, 'latency_ms' => null];
        $rows[] = $row;
        echo 'LANE '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

        continue;
    }

    if ($spent + LANE_WORST_CASE_USD > $budget) {
        $stoppedForBudget = true;
        fwrite(STDERR, sprintf("BUDGET STOP before call %d/%d: spent %.4f + worst case %.2f > budget %.2f\n", count($rows) + 1, count($plan), $spent, LANE_WORST_CASE_USD, $budget));
        break;
    }

    $t0 = microtime(true);
    $res = $tool->run([], new TurnState($employee, $p['question'], Carbon::today(), new ChatSession, []));
    $wall = (int) round((microtime(true) - $t0) * 1000);

    $v = lane13c_verdict($res);
    $v['latency_ms'] ??= $wall;
    $v['wall_ms'] = $wall;
    $spent += ($v['cost_usd'] ?? 0.0) + ((($v['basis'] ?? null) === 'web' || ($v['fallback']['reason'] ?? null) === 'web_ungrounded') ? LANE_GROUND_ESTIMATE_USD : 0.0);
    $row += $v;
    $rows[] = $row;
    echo 'LANE '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

echo 'LANESUM '.json_encode([
    'summary' => lane13c_summarise($rows),
    'planned' => count($plan), 'ran' => count($rows), 'stopped_for_budget' => $stoppedForBudget,
    'spent_usd_est' => round($spent, 4), 'budget' => $budget, 'subflag' => $subflag ? 'on' : 'off', 'through_prescreen' => $throughPrescreen,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";

foreach ($rows as $r) {
    if (str_starts_with((string) $r['class'], 'lane_negative') && $r['verdict'] === 'passed_clean') {
        echo 'LANEPASS '.json_encode(['id' => $r['id'], 'question' => $r['question'], 'answer' => $r['answer']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
    }
}
