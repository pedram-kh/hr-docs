<?php
// Sprint 13b S1 — the 28 frozen over-reach canonicals through the REAL validator against STAGING's real
// topics/territories/convenios. Read-only (rolled back); no model call.
use Illuminate\Support\Facades\DB;
$neg = json_decode(file_get_contents('/tmp/eval13b/normalization-negatives.json'), true)['cases'];
$emp = App\Models\Employee::where('email', 'test-navarra@example.com')->first();
$rule = app(App\Services\Agent\Rules\NormalizationValidationRule::class);
$topics = App\Models\Topic::where('status', 'approved')->pluck('id', 'name');
$out = ['n' => 0, 'accepted' => [], 'wrong_rule' => [], 'rejected_by' => []];
DB::beginTransaction();
try {
    $session = App\Models\ChatSession::create(['employee_id' => $emp->id, 'started_at' => now(), 'last_activity_at' => now()]);
    foreach ($neg as $c) {
        foreach ([null, 'first'] as $mode) {
            $topicId = $mode === null ? null : ($topics->first());
            $state = new App\Services\Agent\TurnState($emp, $c['literal'], now(), $session, []);
            $rule->evaluate($state, ['id' => 'n', 'tool' => 'normalize_question', 'input' => ['topic_id' => $topicId, 'canonical_query' => $c['over_reach_canonical'], 'confidence' => 0.9, 'reason' => 'inject']], null);
            $n = $state->normalization;
            $rules = array_column($n['rejections'] ?? [], 'rule');
            $out['n']++;
            if ($n['verdict'] !== 'rejected') { $out['accepted'][] = $c['id'].($mode ? '+topic' : ''); continue; }
            if (! array_intersect($c['expect_rules_any'], $rules)) { $out['wrong_rule'][] = [$c['id'], $rules, $c['expect_rules_any']]; }
            foreach ($rules as $r) { $out['rejected_by'][$r] = ($out['rejected_by'][$r] ?? 0) + 1; }
        }
    }
} finally { DB::rollBack(); }
ksort($out['rejected_by']);
echo json_encode($out, JSON_UNESCAPED_UNICODE), "\n";
