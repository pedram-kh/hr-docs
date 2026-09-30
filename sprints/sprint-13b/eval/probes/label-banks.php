<?php
/**
 * Sprint 13b, build step 0 — label + verify the banks (no DB, no LLM, $0).
 * Fills `anchored` (TopicLexicon, outcome-independent) and `_lexicon_sha`, and REFUSES to write
 * a bank that (a) has an anchored phrasing, (b) would be settled/escalated before the planner
 * (guardrail baseline, explicit-request, pay-intent), (c) duplicates or too closely resembles
 * another bank / the prompt examples / the CP-1 ten / the five existing fixture sentences.
 * Run: php label-banks.php   (from anywhere; needs hr-backend/vendor)
 */
$root = realpath(__DIR__.'/../../../../..');
require $root.'/hr-backend/vendor/autoload.php';

use App\Services\GuardrailService;
use App\Services\RouterService;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Support\TopicLexicon;

$eval = realpath(__DIR__.'/..');
$lexSha = hash('sha256', json_encode([TopicLexicon::ANCHORS, TopicLexicon::TOPIC_NAMES], JSON_UNESCAPED_UNICODE));

$guard = new GuardrailService;
$router = (new ReflectionClass(RouterService::class))->newInstanceWithoutConstructor();
$salary = new SalaryIntentPreCallRule($router);

$tok = function (string $s): array {
    $s = str_replace('ñ', 'n', TopicLexicon::stripAccents(mb_strtolower($s, 'UTF-8')));
    preg_match_all('/[a-z0-9]+/', $s, $m);
    return array_values(array_unique($m[0]));
};
$jac = function (array $a, array $b): float {
    $i = count(array_intersect($a, $b)); $u = count(array_unique(array_merge($a, $b)));
    return $u ? $i / $u : 0.0;
};

$dev = json_decode(file_get_contents($eval.'/fact-routing-colloquial-dev.json'), true);
$held = json_decode(file_get_contents($eval.'/fact-routing-colloquial.json'), true);
$fr = json_decode(file_get_contents($root.'/hr-docs/sprints/sprint-13/eval/fact-routing.json'), true)['cases'];
$neg = json_decode(file_get_contents($eval.'/normalization-negatives.json'), true)['cases'];
$ex = json_decode(file_get_contents($eval.'/prompt-examples.json'), true)['examples'];
$cp1 = json_decode(file_get_contents($eval.'/cp1-colloquial.json'), true)['cases'];

$others = []; // name => question
foreach ($ex as $i => $e) { $others["prompt-example-$i"] = $e['literal']; }
foreach ($cp1 as $c) { $others[$c['id']] = $c['question']; }
foreach ($fr as $c) { $others['fr:'.$c['canonical_question']] = $c['canonical_question']; $others['fr:'.$c['colloquial_question']] = $c['colloquial_question']; }
foreach ($neg as $c) { $others[$c['id']] = $c['literal']; }

$errors = []; $warn = [];
$all = [];
foreach ([['dev', &$dev], ['held_out', &$held]] as [$bank, &$doc]) {
    foreach ($doc['cases'] as &$c) {
        $q = $c['colloquial_question'];
        $names = TopicLexicon::candidateTopicNames($q);
        $own = in_array(mb_strtolower($c['topic']), array_map('mb_strtolower', $names), true);
        $c['anchored'] = $own;                       // the label (expected topic visible to the lexicon)
        if ($names !== []) { $errors[] = "{$c['id']}: lexicon sees topics [".implode(',', $names)."] in «{$q}»"; }
        $g = $guard->check($q);
        if ($g['fired']) { $errors[] = "{$c['id']}: guardrail baseline {$g['rule']} fires on «{$q}»"; }
        if ($router->matchesExplicitRequest($q)) { $errors[] = "{$c['id']}: explicit_request fires on «{$q}»"; }
        if ($salary->hasPayIntent($q)) { $errors[] = "{$c['id']}: pay intent fires on «{$q}»"; }
        $t = $tok($q);
        foreach ($all as $id2 => $t2) { $j = $jac($t, $t2); if ($j >= 0.8) { $errors[] = "{$c['id']} ~ $id2 (Jaccard ".round($j,2).")"; } elseif ($j >= 0.6) { $warn[] = "{$c['id']} ~ $id2 (".round($j,2).")"; } }
        foreach ($others as $id2 => $q2) { $j = $jac($t, $tok($q2)); if ($j >= 0.8) { $errors[] = "{$c['id']} ~ $id2 (Jaccard ".round($j,2).")"; } elseif ($j >= 0.6) { $warn[] = "{$c['id']} ~ $id2 (".round($j,2).")"; } }
        $all[$c['id']] = $t;
    }
    unset($c);
    $doc['_lexicon_sha'] = $lexSha;
}
unset($doc);

// CP-1 ten: must reach the planner too (not anchored, not pre-empted)
foreach ($cp1 as $c) {
    $q = $c['question'];
    $why = [];
    if (TopicLexicon::candidateTopicNames($q) !== []) { $why[] = 'anchored'; }
    if ($guard->check($q)['fired']) { $why[] = 'guardrail'; }
    if ($router->matchesExplicitRequest($q)) { $why[] = 'explicit_request'; }
    if ($salary->hasPayIntent($q)) { $why[] = 'pay_intent'; }
    if ($why) { $errors[] = "{$c['id']}: pre-empted before the planner (".implode(',', $why).") «{$q}»"; }
}

echo "lexicon_sha $lexSha\n";
echo "dev ".count($dev['cases'])." held ".count($held['cases'])." anchored: ".array_sum(array_map(fn($c)=>(int)$c['anchored'], array_merge($dev['cases'], $held['cases'])))."\n";
foreach ($warn as $w) { echo "WARN  $w\n"; }
if ($errors) { foreach ($errors as $e) { echo "ERROR $e\n"; } fwrite(STDERR, count($errors)." error(s) — banks NOT written\n"); exit(1); }
file_put_contents($eval.'/fact-routing-colloquial-dev.json', json_encode($dev, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
file_put_contents($eval.'/fact-routing-colloquial.json', json_encode($held, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
echo "OK banks labelled (all unanchored, none pre-empted by guardrail/explicit/pay-intent, no near-duplicates)\n";
