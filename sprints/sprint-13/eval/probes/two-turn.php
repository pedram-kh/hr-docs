<?php

/**
 * Sprint 13 step 10 — two-turn agent probe. A follow-up question skips round 0's
 * deterministic short-circuit (AgentChatService's `!$isFollowUp` gate), so the
 * PLANNER sees it — the only way to put a "tempts a group question" prompt in
 * front of the planner on a fresh session. Each case: fresh ChatSession, turn 1
 * (any harmless opener), turn 2 (the probe). Persisted (visible in Historial);
 * test-*@example.com employees only.
 *
 * Reads /var/hr-docs/sprints/sprint-13/eval/probes/two-turn-cases.json: [{"id","email","turn1","turn2"}, ...]
 */

use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\AnswerEngineDispatcher;

$cases = json_decode((string) file_get_contents('/var/hr-docs/sprints/sprint-13/eval/probes/two-turn-cases.json'), true) ?: [];
$dispatcher = app(AnswerEngineDispatcher::class);

foreach ($cases as $c) {
    if (! preg_match('/^test-.*@example\.com$/', $c['email'])) {
        echo "REFUSED non-test employee {$c['email']}\n";

        continue;
    }
    $employee = Employee::where('email', $c['email'])->firstOrFail();
    $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

    $dispatcher->handle($employee, $c['turn1'], $session->uuid, null, 'agent');
    $t0 = microtime(true);
    $r = $dispatcher->handle($employee, $c['turn2'], $session->uuid, null, 'agent');
    $ms = (int) round((microtime(true) - $t0) * 1000);

    echo 'TWO '.json_encode([
        'id' => $c['id'], 'email' => $c['email'], 'session' => $session->uuid, 'turn2' => $c['turn2'],
        'outcome' => $r['outcome'] ?? null, 'reason' => $r['escalation_reason'] ?? null, 'ms' => $ms,
        'answer' => mb_substr((string) ($r['answer'] ?? ''), 0, 240),
    ], JSON_UNESCAPED_UNICODE)."\n";
}
