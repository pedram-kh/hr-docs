<?php

/**
 * Sprint 13, step 10 — "classic is unchanged" smoke check.
 *
 * Runs the four 2c gold cases (verbatim from sprint-10a/eval/gold-answer-run.php
 * / sprint-13/eval/gold-2c.json) plus one sensitive-topic escalation through
 * `ChatService::handleMessage()` — the CLASSIC engine, called directly, so it
 * does not matter what `HR_ANSWER_ENGINE` / the DB override currently say.
 * Written to run identically against the pre-sprint-13 image (BEFORE) and the
 * sprint-13 image (AFTER): same public signature on both.
 *
 * Each turn gets its own fresh ChatSession (created first, its uuid passed in),
 * so no case sees another's history.
 *
 * NOT read-only: a chat turn persists a session, two messages, citations and a
 * trace. Test accounts only (test-*@example.com).
 *
 * Output: one JSON object per case on its own line, prefixed `CASE `.
 */

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\Employee;
use App\Services\ChatService;

$cases = [
    ['2c-periodo-prueba-navarra', 'test-navarra@example.com', '¿cuánto dura el periodo de prueba?'],
    ['2c-vacaciones-gipuzkoa', 'test-gipuzkoa@example.com', '¿qué vacaciones tengo?'],
    ['2c-trabajo-a-distancia-navarra', 'test-navarra@example.com', '¿puedo trabajar a distancia?'],
    ['2c-trabajo-a-distancia-gipuzkoa', 'test-gipuzkoa@example.com', '¿puedo trabajar a distancia?'],
    ['escalation-sensitive-topic', 'test-navarra@example.com', 'tengo un problema de acoso laboral con un compañero'],
];

$chat = app(ChatService::class);

foreach ($cases as [$id, $email, $question]) {
    $employee = Employee::where('email', $email)->firstOrFail();
    $session = ChatSession::create(['employee_id' => $employee->id, 'started_at' => now(), 'last_activity_at' => now()]);

    $t0 = microtime(true);
    $chat->handleMessage($employee, $question, $session->uuid, null);
    $ms = (int) round((microtime(true) - $t0) * 1000);

    $msg = ChatMessage::where('session_id', $session->id)->where('role', 'assistant')
        ->with(['trace', 'citations.document'])->orderByDesc('id')->first();
    $trace = $msg->trace->trace ?? [];
    $floor = $trace['floor_decision'] ?? [];

    $cites = [];
    foreach ($msg->citations as $c) {
        $cites[] = 'doc'.$c->document_id.' p'.$c->page_number;
    }
    sort($cites);

    echo 'CASE '.json_encode([
        'id' => $id,
        'email' => $email,
        'question' => $question,
        'outcome' => $floor['outcome'] ?? null,
        'escalation_reason' => $floor['escalation_reason'] ?? ($floor['reason'] ?? null),
        'path' => $floor['path'] ?? ($trace['path'] ?? null),
        'authority_used' => $floor['authority_used'] ?? null,
        'citations' => array_values(array_unique($cites)),
        'answer' => trim($msg->content),
        'ms' => $ms,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}
