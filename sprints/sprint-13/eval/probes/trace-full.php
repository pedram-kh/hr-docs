<?php

/**
 * Sprint 13 CP-1 root-cause probe — dump ONE persisted assistant trace in full
 * (env TRACE_MSG = chat_messages.id): every top-level trace key, with long
 * strings clipped to TRACE_CLIP chars (default 600) except `answer`.
 * Read-only; test-*@example.com sessions only.
 */

use App\Models\ChatMessage;

$id = (int) getenv('TRACE_MSG');
$clip = (int) (getenv('TRACE_CLIP') ?: 600);

$m = ChatMessage::with(['trace', 'session.employee'])->findOrFail($id);
if (! str_starts_with((string) ($m->session->employee->email ?? ''), 'test-')) {
    exit("refusing: not a test employee\n");
}

$clipper = function ($v) use (&$clipper, $clip) {
    if (is_string($v)) {
        return mb_strlen($v) > $clip ? mb_substr($v, 0, $clip).'…[+'.(mb_strlen($v) - $clip).']' : $v;
    }
    if (is_array($v)) {
        return array_map($clipper, $v);
    }

    return $v;
};

$t = $m->trace->trace ?? [];
echo 'ANSWER '.json_encode(trim($m->content), JSON_UNESCAPED_UNICODE)."\n";
echo 'KEYS '.implode(',', array_keys($t))."\n";
foreach ($t as $k => $v) {
    echo 'SECTION '.$k.' '.json_encode($clipper($v), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
}
