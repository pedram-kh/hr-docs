<?php
// Offline re-score of S3a / S3c with the abstain rule: an `answer` whose text opens with the synthesis-abstention phrase is `abstain`.
require '/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-backend/vendor/autoload.php';
use App\Services\Answer\SynthesisAbstention as A;
$dir = '/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-docs/sprints/sprint-13c/eval/results/';
foreach (['s3a-rows.jsonl' => 'answer_excerpt', 's3c-rows.jsonl' => 'answer', 's3b-rows.jsonl' => 'answer', 's4-whitelist-temptation-rows.jsonl'=>'answer','s4-gold-2c-rows.jsonl'=>'answer','s4-situational-rows.jsonl'=>'answer'] as $f => $col) {
    $rows = array_map(fn ($l) => json_decode($l, true), array_filter(file($dir.$f, FILE_IGNORE_NEW_LINES)));
    $byRun = []; $flips = [];
    foreach ($rows as $r) {
        if ($r['skipped'] ?? false) continue;
        $run = $r['repeat'] ?? 0;
        $raw = $r['outcome']; $lane = $r['lane_answer'] ?? false;
        $abst = $raw === 'answer' && A::phrase((string) ($r[$col] ?? ''));
        $byRun[$run]['n'] = ($byRun[$run]['n'] ?? 0) + 1;
        $byRun[$run]['answered_raw'] = ($byRun[$run]['answered_raw'] ?? 0) + ($raw === 'answer' ? 1 : 0);
        $byRun[$run]['answered_rule'] = ($byRun[$run]['answered_rule'] ?? 0) + (($raw === 'answer' && ! $abst) ? 1 : 0);
        $byRun[$run]['lane'] = ($byRun[$run]['lane'] ?? 0) + ($lane ? 1 : 0);
        if ($abst) { $byRun[$run]['abstain'] = ($byRun[$run]['abstain'] ?? 0) + 1; $flips[] = "run{$run} {$r['id']} ({$r['path']}) :: ".mb_substr((string) $r[$col], 0, 90); }
    }
    ksort($byRun);
    echo "== $f (col=$col)\n"; foreach ($byRun as $run => $v) echo "  run $run: ".json_encode($v)."\n";
    foreach ($flips as $x) echo "  ABSTAIN-as-answer: $x\n";
}
