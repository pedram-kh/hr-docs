<?php

/**
 * Sprint 13 step 11 (plan.md §E.14 set 1) — the LANE-FORCED harness.
 *
 * Sends questions STRAIGHT to the `general_knowledge` tool (→ hr-ai
 * `/general-knowledge`: catalogue match, real fetch, real model) and then runs
 * the deterministic post-check on the raw answer — bypassing the planner and
 * every availability rule. It proves the post-check against REAL model
 * output, not only fixtures: for every question the lane could be tempted to
 * answer, either there is no web material (honest NO_MATERIAL), or the answer
 * is discarded by `GeneralLanePostCheck`, or it passes and is then audited by
 * an INDEPENDENT, broader leak regex (digits, spelled numbers, entitlement
 * words) — any audit hit on a post-check-clean answer is a BYPASS (hard fail).
 *
 * Read-only: nothing is persisted (no session/message/card is created); the
 * only writes are the outbound fetches the lane always makes.
 *
 * Env: LANE_SET (fixture json, default the sprint-13 general-lane.json),
 *      LANE_CLASS (case class to run, default lane_negative),
 *      LANE_EMAIL (a test-*@example.com employee).
 * Output: `LANE {json}` per unique question, then `LANESUM {json}`.
 */

use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Tools\GeneralKnowledgeTool;
use App\Services\Agent\TurnState;
use Illuminate\Support\Carbon;

$set = getenv('LANE_SET') ?: '/var/hr-docs/sprints/sprint-13/eval/general-lane.json';
$class = getenv('LANE_CLASS') ?: 'lane_negative';
$email = getenv('LANE_EMAIL') ?: 'test-gipuzkoa@example.com';

if (! str_starts_with($email, 'test-') || ! str_ends_with($email, '@example.com')) {
    exit("refusing: not a test employee\n");
}

$employee = Employee::where('email', $email)->firstOrFail();
$fixture = json_decode((string) file_get_contents($set), true, 512, JSON_THROW_ON_ERROR);

$questions = [];
foreach ($fixture['cases'] as $case) {
    if (($case['class'] ?? null) === $class && isset($case['question'])) {
        $questions[$case['question']] = true;
    }
}
$questions = array_keys($questions);

/**
 * Independent audit: `GeneralLanePostCheck::audit()` — deliberately broader than `scan()`, but built from the SAME
 * spelled-number vocabulary as F2 (CP-2), so the two cannot drift. (Before CP-2 this was a local closure with a
 * context-free `una|uno|...` regex that flagged articles; the shared method replaces it.)
 */
$audit = static fn (string $text): array => GeneralLanePostCheck::audit($text);

$tool = app(GeneralKnowledgeTool::class);
$summary = ['questions' => 0, 'no_material' => 0, 'unavailable' => 0, 'terminal_escalate' => 0, 'answered_raw' => 0,
    'blocked_by_postcheck' => 0, 'passed_postcheck' => 0, 'audit_bypass' => 0];

foreach ($questions as $q) {
    $state = new TurnState($employee, $q, Carbon::today(), new ChatSession, []);
    $t0 = microtime(true);
    $res = $tool->run([], $state);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $row = ['question' => $q, 'status' => $res->status, 'summary' => $res->plannerSummary['status'] ?? null, 'ms' => $ms];
    $summary['questions']++;

    $out = $res->terminalOutcome;
    if ($out === null) {
        $summary[($row['summary'] ?? '') === 'unavailable' ? 'unavailable' : 'no_material']++;
        $row['verdict'] = 'no_web_material';
    } elseif ($out->outcome !== 'answer') {
        $summary['terminal_escalate']++;
        $row['verdict'] = 'lane_escalated_ungrounded';
        $row['note'] = $out->trace['floor_decision']['note'] ?? null;
    } else {
        $summary['answered_raw']++;
        $hit = GeneralLanePostCheck::scan($out->answer);
        $row['answer'] = $out->answer;
        $row['sources'] = array_map(fn ($s) => ($s['id'] ?? $s['title'] ?? '?'), $out->trace['general_lane']['sources'] ?? []);
        if ($hit !== null) {
            $summary['blocked_by_postcheck']++;
            $row['verdict'] = 'blocked_by_postcheck';
            $row['postcheck'] = $hit;
        } else {
            $summary['passed_postcheck']++;
            $leaks = $audit($out->answer);
            $row['verdict'] = $leaks === [] ? 'surfaced_clean' : 'AUDIT_BYPASS';
            $row['audit'] = $leaks;
            if ($leaks !== []) {
                $summary['audit_bypass']++;
            }
        }
    }
    echo 'LANE '.json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}

echo 'LANESUM '.json_encode($summary + ['email' => $email, 'class' => $class])."\n";
