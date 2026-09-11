<?php
/**
 * Answer-level national-law gold run (Sprint 10a CP-2).
 *
 * The 2c gold set is stated as claims about ANSWERS — "periodo de prueba 15/30",
 * "Gipuzkoa 31/26 cited only to the convenio", "trabajo a distancia resolves to
 * national_law" — so a retrieval-level probe can only ever be a proxy for it.
 * This drives the real loop through `ChatService::handleMessage()` and prints
 * what each turn actually decided.
 *
 * Runs the DEPLOYED answer loop (staging still serves the pre-10a image); the
 * only thing that changed underneath it is doc 75's chunks. That is the
 * comparison CP-2 asks for.
 *
 * NOT read-only: a chat turn persists a session, two messages, citations and a
 * trace, exactly as an employee asking the question would. Test accounts only.
 */

use App\Models\ChatMessage;
use App\Models\Employee;
use App\Services\ChatService;

$cases = [
    ['test-navarra@example.com', '¿cuánto dura el periodo de prueba?', 'periodo de prueba → 15/30, convenio'],
    ['test-gipuzkoa@example.com', '¿qué vacaciones tengo?', 'vacaciones → 31/26, convenio only'],
    ['test-navarra@example.com', '¿puedo trabajar a distancia?', 'trabajo a distancia → national_law'],
    ['test-gipuzkoa@example.com', '¿puedo trabajar a distancia?', 'trabajo a distancia → national_law'],
];

$chat = app(ChatService::class);

foreach ($cases as [$email, $question, $expectation]) {
    $employee = Employee::where('email', $email)->firstOrFail();

    echo str_repeat('=', 78), "\n";
    echo "{$email}\n  Q: {$question}\n  expect: {$expectation}\n";

    $chat->handleMessage($employee, $question, null, null);

    $msg = ChatMessage::where('role', 'assistant')
        ->whereHas('session', fn ($q) => $q->where('employee_id', $employee->id))
        ->with(['trace', 'citations.document'])
        ->orderByDesc('id')->first();

    $trace = $msg->trace->trace ?? [];
    $floor = $trace['floor_decision'] ?? [];

    echo '  outcome:   ', ($floor['outcome'] ?? '?'), "\n";
    echo '  router:    ', ($trace['router']['route'] ?? '?'), '  path: ', ($trace['path'] ?? '?'), "\n";
    echo '  authority: ', json_encode($floor['authority_used'] ?? null, JSON_UNESCAPED_UNICODE), "\n";
    echo '  fallback:  ', array_key_exists('fallback', $floor) ? $floor['fallback'] : '(key absent)', "\n";

    $cites = [];
    foreach ($msg->citations as $c) {
        $cites[] = 'doc'.$c->document_id.' ('.($c->document->source_filename ?? '?').' p'.$c->page_number.')';
    }
    echo '  citations: ', $cites ? implode('; ', array_unique($cites)) : '(none)', "\n";
    echo "  answer:\n    ", str_replace("\n", "\n    ", trim($msg->content)), "\n";
}
