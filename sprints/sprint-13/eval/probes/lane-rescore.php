<?php

/**
 * Offline re-score (no API spend) of saved `lane-forced.php` logs against the CURRENT
 * `GeneralLanePostCheck::scan()` and `::audit()`. Usage (from hr-backend):
 *   php artisan tinker --execute='include "<abs path>/lane-rescore.php";' with env LANE_LOGS="a.log b.log"
 * For every answer that was drafted, prints the post-check verdict and, for those that pass, the audit hits.
 */

use App\Services\Agent\Rules\GeneralLanePostCheck;

$n = 0;
$blocked = 0;
$bypass = 0;
foreach (array_filter(explode(' ', trim((string) getenv('LANE_LOGS')))) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (! str_starts_with($line, 'LANE {')) {
            continue;
        }
        $row = json_decode(substr($line, 5), true);
        if (empty($row['answer'])) {
            continue;
        }
        $n++;
        $hit = GeneralLanePostCheck::scan($row['answer']);
        if ($hit !== null) {
            $blocked++;
            echo basename($file).' | BLOCKED '.$hit['pattern_id'].' ('.$hit['matched_span'].') | '.mb_substr($row['question'], 0, 60)."\n";

            continue;
        }
        $audit = GeneralLanePostCheck::audit($row['answer']);
        $bypass += $audit === [] ? 0 : 1;
        echo basename($file).' | passed post-check | audit='.json_encode($audit).' | '.mb_substr($row['question'], 0, 60)."\n";
    }
}
echo "drafted answers: {$n}; blocked by post-check: {$blocked}; passed post-check: ".($n - $blocked)."; audit bypasses: {$bypass}\n";
