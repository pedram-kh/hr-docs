<?php
/**
 * Sprint 13b, build step 0 — reproduce the ≈90% / 18% baseline split from the existing
 * fact-routing rows, with NO spend (plan.md §1). Labels are outcome-independent:
 *   authored = the fixture field (canonical|colloquial)
 *   anchored = TopicLexicon::candidateTopicNames(question) contains the case's expected topic
 * Run: php baseline-split.php
 */
$root = realpath(__DIR__.'/../../../../..');
require $root.'/hr-backend/vendor/autoload.php';
use App\Support\TopicLexicon;

$res = $root.'/hr-docs/sprints/sprint-13/eval/results';
$fx = json_decode(file_get_contents($root.'/hr-docs/sprints/sprint-13/eval/fact-routing.json'), true)['cases'];
$label = [];
foreach ($fx as $c) {
    foreach (['canonical', 'colloquial'] as $a) {
        $names = array_map('mb_strtolower', TopicLexicon::candidateTopicNames($c[$a.'_question']));
        $label[$c['id'].'.'.$a] = [$a, in_array(mb_strtolower($c['topic']), $names, true)];
    }
}
$rows = [];
foreach (['parallel-run-facts-both-x3-partial.jsonl', 'cp2-facts-remaining-agent-x1.jsonl'] as $f) {
    foreach (file($res.'/'.$f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $l) { $rows[] = json_decode($l, true); }
}
$t = [];
$seen = [];
foreach ($rows as $r) {
    if (($r['skipped'] ?? false) || ! isset($label[$r['id']])) { continue; }
    [$a, $anc] = $label[$r['id']];
    $k = "$a/".($anc ? 'anchored' : 'unanchored').'/'.$r['engine'];
    $t[$k]['n'] = ($t[$k]['n'] ?? 0) + 1;
    $t[$k]['pass'] = ($t[$k]['pass'] ?? 0) + ($r['pass'] ? 1 : 0);
    $seen[$r['id']] = 1;
}
ksort($t);
echo "authored/anchored/engine        pass/n\n";
foreach ($t as $k => $v) { printf("%-30s %4d/%-4d %5.1f%%\n", $k, $v['pass'], $v['n'], 100 * $v['pass'] / $v['n']); }
$c = array_count_values(array_map(fn ($v) => $v[0].'/'.($v[1] ? 'anchored' : 'unanchored'), $label));
ksort($c); echo "\nphrasings (93 cases x2):\n"; foreach ($c as $k => $n) { echo "  $k: $n\n"; }
echo "distinct case-ids seen: ".count($seen)."\n";
$all = ['colloquial' => [0, 0], 'canonical' => [0, 0]];
foreach ($rows as $r) { if (($r['engine'] ?? '') === 'classic' && isset($label[$r['id']])) { $a = $label[$r['id']][0]; $all[$a][0]++; $all[$a][1] += $r['pass'] ? 1 : 0; } }
echo "classic reproduces review.md: canonical {$all['canonical'][1]}/{$all['canonical'][0]}, colloquial {$all['colloquial'][1]}/{$all['colloquial'][0]}\n";
