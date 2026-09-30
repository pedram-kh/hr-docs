<?php
/**
 * Sprint 13b S4 — derive the three labelled slices of the EXISTING Sprint 13 fact-routing set (93 cases x 2 phrasings)
 * so `answer:gate` can run them and report the 2x2. Labels are outcome-independent (same rule as label-banks.php):
 * authored = the field the phrasing came from; anchored = TopicLexicon::candidateTopicNames(question) contains the
 * case's expected topic. Case ids and expectations are copied UNCHANGED from sprint-13/eval/fact-routing.json, so the
 * gate ids (`<id>.canonical|colloquial`) match the CP-2 rows one-for-one.
 * Run: php label-existing.php   (writes ../fact-routing-existing-*.json)
 */
$root = realpath(__DIR__.'/../../../../..');
require $root.'/hr-backend/vendor/autoload.php';
use App\Support\TopicLexicon;

$src = json_decode(file_get_contents($root.'/hr-docs/sprints/sprint-13/eval/fact-routing.json'), true);
$lexSha = hash('sha256', json_encode([TopicLexicon::ANCHORS, TopicLexicon::TOPIC_NAMES], JSON_UNESCAPED_UNICODE));
$slices = ['canonical-anchored' => [], 'colloquial-unanchored' => [], 'colloquial-anchored' => []];
foreach ($src['cases'] as $c) {
    foreach (['canonical', 'colloquial'] as $a) {
        $q = $c[$a.'_question'];
        $names = array_map('mb_strtolower', TopicLexicon::candidateTopicNames($q));
        $anchored = in_array(mb_strtolower($c['topic']), $names, true);
        if ($a === 'canonical' && ! $anchored) { fwrite(STDERR, "FIXTURE ERROR: unanchored canonical {$c['id']}\n"); exit(1); }
        $case = $c;
        unset($case['canonical_question'], $case['colloquial_question']);
        $case[$a.'_question'] = $q;
        $case['authored'] = $a;
        $case['anchored'] = $anchored;
        $slices[$a.'-'.($anchored ? 'anchored' : 'unanchored')][] = $case;
    }
}
foreach ($slices as $name => $cases) {
    $doc = ['_description' => "Sprint 13b S4 slice '$name' of sprint-13/eval/fact-routing.json (ids/expectations unchanged; labels outcome-independent).", '_lexicon_sha' => $lexSha, 'cases' => $cases];
    file_put_contents(__DIR__.'/../fact-routing-existing-'.$name.'.json', json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)."\n");
    echo str_pad($name, 24).count($cases)."\n";
}
echo "lexicon_sha $lexSha\n";
