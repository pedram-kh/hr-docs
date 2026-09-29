<?php

/**
 * Sprint 13 CP-1 — dump the persisted agent trace of the most recent N assistant
 * turns (newest first) for test-*@example.com employees, as compact JSON lines.
 * Read-only. Usage (via tinker): set env TRACE_N (default 7) and optional
 * TRACE_EMAIL to filter to one employee.
 */

use App\Models\ChatMessage;

$n = (int) (getenv('TRACE_N') ?: 7);
$email = getenv('TRACE_EMAIL') ?: null;

$q = ChatMessage::where('role', 'assistant')
    ->whereHas('session.employee', function ($q) use ($email) {
        $q->where('email', 'like', 'test-%@example.com');
        if ($email) {
            $q->where('email', $email);
        }
    })
    ->with(['trace', 'citations.document', 'session.employee'])
    ->orderByDesc('id')->limit($n)->get();

foreach ($q->reverse() as $m) {
    $t = $m->trace->trace ?? [];
    $floor = $t['floor_decision'] ?? [];
    $agent = $t['agent'] ?? [];
    $user = ChatMessage::where('session_id', $m->session_id)->where('role', 'user')->where('id', '<', $m->id)->orderByDesc('id')->first();

    $steps = [];
    foreach ($agent['steps'] ?? [] as $s) {
        $type = $s['type'] ?? '?';
        $row = ['type' => $type];
        foreach (['round', 'tool', 'status', 'rule', 'boundary', 'verdict', 'reason', 'ms', 'category', 'sub'] as $k) {
            if (array_key_exists($k, $s)) {
                $row[$k] = $s[$k];
            }
        }
        if ($type === 'planner_round') {
            $row['calls'] = array_map(fn ($c) => ($c['tool'] ?? '?').json_encode($c['input'] ?? [], JSON_UNESCAPED_UNICODE), $s['calls'] ?? []);
            $row['tokens'] = $s['tokens'] ?? null;
        }
        if ($type === 'tool_call') {
            $row['summary'] = $s['planner_summary'] ?? null;
            $row['tokens'] = $s['tokens'] ?? null;
        }
        $steps[] = $row;
    }

    echo 'TRACE '.json_encode([
        'message_id' => $m->id,
        'session' => $m->session->uuid ?? null,
        'email' => $m->session->employee->email ?? null,
        'question' => $user->content ?? null,
        'engine' => $t['engine'] ?? null,
        'outcome' => $floor['outcome'] ?? null,
        'reason' => $floor['escalation_reason'] ?? ($floor['reason'] ?? null),
        'path' => $floor['path'] ?? null,
        'authority_used' => $floor['authority_used'] ?? null,
        'note' => $floor['note'] ?? null,
        'citations' => $m->citations->map(fn ($c) => 'doc'.$c->document_id.' p'.$c->page_number)->unique()->values(),
        'answer' => trim($m->content),
        'planner' => $agent['planner'] ?? null,
        'termination' => $agent['termination'] ?? null,
        'budget' => $agent['budget'] ?? null,
        'general_lane_blocked' => $agent['general_lane_blocked'] ?? null,
        'planner_escalation' => $agent['planner_escalation'] ?? null,
        'steps' => $steps,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}
