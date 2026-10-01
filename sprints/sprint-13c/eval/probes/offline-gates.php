<?php

/**
 * Slice 13c step 0 — offline, $0, read-only, DB-free. Reproduces plan.md §3.4 / §4.1 / App. D from the FROZEN fixtures.
 *
 *   php offline-gates.php            (hr-backend vendor/ must be installed; HR_BACKEND env overrides the path)
 *
 * Prints, for the CURRENT (v1) pre-screen and the pre-corpus gates:
 *  - pool:      candidates stopped before the corpus by guardrail / salary router / pay intent / v1 pre-screen
 *  - prescreen: entitlement recall, false-deny on explanatory, minimal pairs separated, adversarial leak — all, dev, heldout
 *  - negatives: colloquial negatives caught by v1 / stopped by a pre-corpus gate
 * Static classes only: GeneralLanePostCheck, GuardrailService, RouterService::matchesSalary (constructor bypassed — it is
 * a pure regex method), SalaryIntentPreCallRule::hasPayIntent.
 */

use App\Services\Agent\Rules\GeneralLanePostCheck as P;
use App\Services\Agent\Rules\SalaryIntentPreCallRule;
use App\Services\GuardrailService;
use App\Services\RouterService;

$backend = getenv('HR_BACKEND') ?: __DIR__.'/../../../../../hr-backend';
require $backend.'/vendor/autoload.php';

$dir = __DIR__.'/..';
$load = static fn (string $f): array => json_decode((string) file_get_contents("$dir/$f"), true, 512, JSON_THROW_ON_ERROR)['cases'];

$guard = new GuardrailService;
$router = (new ReflectionClass(RouterService::class))->newInstanceWithoutConstructor();
$payRule = new SalaryIntentPreCallRule($router);
$gate = static function (string $q) use ($guard, $router, $payRule): array {
    $g = $guard->check($q);
    $out = [];
    if ($g['fired']) {
        $out[] = 'guardrail:'.$g['rule'];
    }
    if ($router->matchesSalary($q)) {
        $out[] = 'salary_router';
    }
    if ($payRule->hasPayIntent($q)) {
        $out[] = 'pay_intent';
    }

    return $out;
};

// ---- pool ----
$pool = $load('lane-positives-pool.json');
$gated = 0;
foreach ($pool as $c) {
    $g = $gate($c['question']);
    $ps = P::questionPrescreenHit($c['question']);
    if ($g || $ps) {
        $gated++;
        echo "POOL GATED  {$c['id']} ".implode(',', array_merge($g, $ps ? ['prescreen_v1'] : []))."  {$c['question']}\n";
    }
}
printf("pool: %d candidates, %d stopped before the corpus\n\n", count($pool), $gated);

// ---- prescreen fixtures ----
$fx = $load('prescreen-fixtures.json');
$report = static function (string $label, array $set) {
    $ent = array_filter($set, fn ($c) => $c['class'] === 'entitlement');
    $exp = array_filter($set, fn ($c) => $c['class'] === 'explanatory');
    $adv = array_filter($set, fn ($c) => $c['class'] === 'adversarial');
    $caught = count(array_filter($ent, fn ($c) => P::questionPrescreenHit($c['question'])));
    $fd = array_filter($exp, fn ($c) => P::questionPrescreenHit($c['question']));
    $leak = count(array_filter($adv, fn ($c) => ! P::questionPrescreenHit($c['question'])));
    printf("prescreen v1 [%s]: entitlement %d/%d caught (%.0f%%); explanatory false-deny %d/%d; adversarial leaking %d/%d\n",
        $label, $caught, count($ent), count($ent) ? 100 * $caught / count($ent) : 0, count($fd), count($exp), $leak, count($adv));
};
$report('all', $fx);
$report('dev', array_filter($fx, fn ($c) => $c['half'] === 'dev'));
$report('heldout', array_filter($fx, fn ($c) => $c['half'] === 'heldout'));

$byPair = [];
foreach ($fx as $c) {
    if ($c['pair']) {
        $byPair[$c['pair']][$c['class']] = $c;
    }
}
$sep = 0;
foreach ($byPair as $pair => $m) {
    $ok = P::questionPrescreenHit($m['entitlement']['question']) && ! P::questionPrescreenHit($m['explanatory']['question']);
    $sep += $ok ? 1 : 0;
    if (! $ok) {
        echo "  pair $pair NOT separated: {$m['entitlement']['question']}\n";
    }
}
printf("minimal pairs separated: %d/%d\n", $sep, count($byPair));
foreach ($fx as $c) {
    if ($c['class'] === 'entitlement' && ! P::questionPrescreenHit($c['question'])) {
        echo "  MISSED {$c['id']} {$c['question']}\n";
    }
}
foreach ($fx as $c) {
    if ($c['class'] === 'adversarial' && ! P::questionPrescreenHit($c['question'])) {
        echo "  ADV LEAK {$c['id']} {$c['question']}\n";
    }
}
echo "\n";

// ---- colloquial negatives ----
$neg = $load('lane-colloquial-negatives.json');
$caught = 0;
$pre = 0;
foreach ($neg as $c) {
    $hit = P::questionPrescreenHit($c['question']);
    $g = $gate($c['question']);
    $caught += $hit ? 1 : 0;
    $pre += $g ? 1 : 0;
    echo ($hit ? 'CAUGHT ' : 'missed ')."{$c['id']} ".implode(',', $g)." {$c['question']}\n";
}
printf("colloquial negatives: v1 catches %d/%d; %d/%d stopped by a pre-corpus gate\n", $caught, count($neg), $pre, count($neg));
