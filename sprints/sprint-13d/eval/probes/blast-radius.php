<?php
// Sprint 13d blast-radius replay — READ-ONLY, no model, no writes, no spend.
// Compares the pre-13d ReferenceFactAnswerService (verbatim copy of fddb6aa, class renamed
// LegacyReferenceFactAnswerService, loaded from /tmp/LegacyRFAS.php) against the deployed one for
// every (convenio x topic) x employee archetype, and reports every combination whose result differs.
// Expected on staging: exactly ONE differing (convenio, topic, tier) = (20, jornada, convenio_wide).
// Run:  ops/stg.sh hr-backend 'php artisan tinker --execute="require \"/tmp/blast-radius.php\";"'
require_once '/tmp/LegacyRFAS.php';

use App\Models\Convenio;
use App\Models\ConvenioGroup;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Services\ReferenceFactAnswerService;
use Illuminate\Support\Carbon;

$new = new ReferenceFactAnswerService;
$old = new App\Services\LegacyReferenceFactAnswerService;
$asOf = Carbon::parse(getenv('ASOF') ?: now()->toDateString());

$groupIds = ConvenioGroup::query()->pluck('id')->all();
$groupIds[] = 999999;

$combos = 0;
$diffs = [];
$byTier = [];
$tally = fn (string $k) => $byTier[$k] = ($byTier[$k] ?? 0) + 1;

$check = function (Employee $e, int $topicId, string $archetype) use ($new, $old, $asOf, &$combos, &$diffs, $tally) {
    $combos++;
    $a = json_encode($old->answer($e, $topicId, $asOf), JSON_PARTIAL_OUTPUT_ON_ERROR);
    $b = json_encode($new->answer($e, $topicId, $asOf), JSON_PARTIAL_OUTPUT_ON_ERROR);
    if ($a !== $b) {
        $key = ($e->convenio_id).'|'.$topicId.'|'.$archetype;
        $diffs[$key] = ['old' => json_decode($a, true), 'new' => json_decode($b, true)];
    }
    $tally('checked');
};

$facts = ReferenceFact::query()->get(['id', 'convenio_id', 'topic_id', 'job_category_id', 'group_label', 'status']);
$pairs = $facts->groupBy(fn ($f) => $f->convenio_id.'|'.$f->topic_id);

foreach ($pairs as $key => $rows) {
    [$convenioId, $topicId] = array_map('intval', explode('|', $key));
    $convenio = Convenio::find($convenioId);
    if (! $convenio) {
        continue;
    }
    $cats = $rows->pluck('job_category_id')->filter()->unique()->values()->all();
    $cats[] = 999999;
    $mk = function (?int $cat, ?int $grp) use ($convenio) {
        $e = new Employee;
        $e->convenio_id = $convenio->id;
        $e->job_category_id = $cat;
        $e->convenio_group_id = $grp;
        $e->setRelation('convenio', $convenio);
        $e->setRelation('jobCategory', null);

        return $e;
    };
    $check($mk(null, null), $topicId, 'no_cat_no_group');
    foreach ($cats as $c) {
        $check($mk($c, null), $topicId, "cat:$c");
    }
    foreach ($groupIds as $g) {
        $check($mk(null, $g), $topicId, "group:$g");
    }
}

// Also every real employee x every topic that has a fact for their convenio.
foreach (Employee::query()->whereNotNull('convenio_id')->get() as $emp) {
    foreach ($pairs as $key => $rows) {
        [$cid, $tid] = array_map('intval', explode('|', $key));
        if ($cid === (int) $emp->convenio_id) {
            $check($emp, $tid, 'real_employee:'.$emp->id);
        }
    }
}

echo json_encode([
    'as_of' => $asOf->toDateString(),
    'convenio_topic_pairs_with_any_fact' => $pairs->count(),
    'comparisons' => $combos,
    'differing' => count($diffs),
    'differing_convenio_topic_pairs' => array_values(array_unique(array_map(fn ($k) => implode('|', array_slice(explode('|', $k), 0, 2)), array_keys($diffs)))),
    'differing_keys' => array_keys($diffs),
], JSON_PRETTY_PRINT).PHP_EOL;

$first = array_key_first($diffs);
if ($first) {
    $d = $diffs[$first];
    echo "FIRST DIFF $first\n  old.outcome=".($d['old']['outcome'] ?? '?').' reason='.($d['old']['reference_fact']['escalation_reason'] ?? $d['old']['escalation_reason'] ?? '-').' selection='.($d['old']['reference_fact']['validity_selection'] ?? '-')."\n";
    echo '  new.outcome='.($d['new']['outcome'] ?? '?').' selection='.($d['new']['reference_fact']['validity_selection'] ?? '-').' citations='.count($d['new']['citations'] ?? [])."\n";
}
