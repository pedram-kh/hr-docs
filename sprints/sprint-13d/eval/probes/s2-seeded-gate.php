<?php
// Slice 13d S2 — seeded contradiction / precedence gate. NOTHING IS COMMITTED: every write below happens inside one
// outer transaction that is ALWAYS rolled back (also on a crash: an uncommitted transaction dies with the connection).
// The nested per-case transaction inside `answer:gate` becomes a savepoint, so it never commits either.
//
// Seed (convenio 21 "Hostelería Navarra", topic 4 "festivos" — no fact of any status exists there today; asserted below):
//   W1 "plus Alfa"  {plus_festivo}          W2 "compensación Beta" {compensacion_descanso}   (complementary, both wide)
//   G  "prima Gamma" {prima_grupo}          bound to node 35 "resto áreas" (scenario c/d)  or node 34 "área 5" (scenario e)
// Scenarios (env S2_SCENARIO):
//   a  W1a/W2a share the key plus_festivo with different values     -> employee (node 35) must NOT get an answer
//   b  W1b/W2b have null raw_values                                  -> must NOT answer
//   c  W1, W2, G(node 35); employee on node 35                       -> the group fact only, no fact_set
//   d  W1, W2, G(node 35); employee UNGROUPED                        -> the wide pair (both facts)
//   e  W1, W2, G(node 34 = child of node 32); employee on node 32    -> indeterminate: must NOT answer
// Env: S2_GATE_ARGS = JSON object of answer:gate options (engine, set, repeat, filter, stream, budget-usd, ...).
use App\Models\ConvenioGroup;
use App\Models\Document;
use App\Models\Employee;
use App\Models\ReferenceFact;
use App\Models\ReferenceFactGroupScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$scn = getenv('S2_SCENARIO') ?: '';
$args = json_decode(getenv('S2_GATE_ARGS') ?: '{}', true);
if (! in_array($scn, ['a', 'b', 'c', 'd', 'e'], true) || ! is_array($args) || ! isset($args['--set'])) {
    exit("usage: S2_SCENARIO=a|b|c|d|e S2_GATE_ARGS='{\"--engine\":\"both\",\"--set\":\"...\"}'\n");
}

const CONVENIO = 21;
const TOPIC = 4;
$email = 'test-hosteleria-navarra@example.com';

$before = [
    'facts_21_festivos' => ReferenceFact::where('convenio_id', CONVENIO)->where('topic_id', TOPIC)->count(),
    'facts_total' => ReferenceFact::count(),
    'scopes_total' => ReferenceFactGroupScope::count(),
    'emp_group' => Employee::where('email', $email)->value('convenio_group_id'),
];
if ($before['facts_21_festivos'] !== 0) {
    exit('ABORT: convenio 21 / festivos is not empty ('.$before['facts_21_festivos'].' facts); pick another topic.'."\n");
}

$doc = Document::where('convenio_id', CONVENIO)->orderBy('id')->firstOrFail();
$mk = function (string $value, ?array $raw, ?string $label = null, ?int $nodeId = null) use ($doc) {
    $f = ReferenceFact::create([
        'convenio_id' => CONVENIO, 'topic_id' => TOPIC, 'job_category_id' => null, 'group_label' => $label,
        'value' => $value, 'raw_values' => $raw, 'authority_level' => ReferenceFact::AUTHORITY_LEVEL, 'source' => 'ai_agent',
        'status' => 'verified', 'validity_start' => '2025-01-01', 'validity_end' => '2028-12-31',
        'source_document_id' => $doc->id, 'source_locator' => 'p1',
    ]);
    if ($nodeId !== null) {
        ReferenceFactGroupScope::create(['reference_fact_id' => $f->id, 'convenio_group_id' => $nodeId, 'bound_at' => now()]);
    }

    return $f;
};

$W1 = 'En los festivos de apertura la empresa abonará el plus Alfa de festivo trabajado.';
$W2 = 'Los festivos trabajados se compensan además con la compensación Beta de descanso equivalente.';
$G = 'Para el grupo resto de áreas, el festivo trabajado se abona con la prima Gamma.';
$empGroup = 35;

DB::beginTransaction();
try {
    $seeded = [];
    switch ($scn) {
        case 'a':
            $seeded[] = $mk($W1, ['plus_festivo' => 'plus Alfa'])->id;
            $seeded[] = $mk('En los festivos de apertura la empresa abonará el plus Delta de festivo trabajado.', ['plus_festivo' => 'plus Delta'])->id;
            break;
        case 'b':
            $seeded[] = $mk($W1, null)->id;
            $seeded[] = $mk($W2, null)->id;
            break;
        case 'c':
        case 'd':
            $seeded[] = $mk($W1, ['plus_festivo' => 'plus Alfa'])->id;
            $seeded[] = $mk($W2, ['compensacion_descanso' => 'compensación Beta'])->id;
            $seeded[] = $mk($G, ['prima_grupo' => 'prima Gamma'], 'resto áreas', 35)->id;
            $empGroup = $scn === 'c' ? 35 : null;
            break;
        case 'e':
            $seeded[] = $mk($W1, ['plus_festivo' => 'plus Alfa'])->id;
            $seeded[] = $mk($W2, ['compensacion_descanso' => 'compensación Beta'])->id;
            $seeded[] = $mk('Para el área 5 del grupo 2, el festivo trabajado se abona con la prima Gamma.', ['prima_grupo' => 'prima Gamma'], 'Grupo 2 (área 5)', 34)->id;
            $empGroup = 32;
            break;
    }
    Employee::where('email', $email)->update(['convenio_group_id' => $empGroup]);
    echo "SCENARIO $scn seeded fact ids [".implode(',', $seeded)."], employee group ".json_encode($empGroup)." (all inside a txn that will be rolled back)\n";
    echo 'sanity: nodes 32/34/35 = '.json_encode(ConvenioGroup::whereIn('id', [32, 34, 35])->get(['id', 'parent_id', 'status', 'label'])->toArray(), JSON_UNESCAPED_UNICODE)."\n";

    $code = Artisan::call('answer:gate', $args);
    echo Artisan::output();
    echo "GATE EXIT=$code\n";
} finally {
    DB::rollBack();
}

$after = [
    'facts_21_festivos' => ReferenceFact::where('convenio_id', CONVENIO)->where('topic_id', TOPIC)->count(),
    'facts_total' => ReferenceFact::count(),
    'scopes_total' => ReferenceFactGroupScope::count(),
    'emp_group' => Employee::where('email', $email)->value('convenio_group_id'),
];
echo 'ROLLBACK CHECK: '.($before === $after ? 'OK (staging unchanged)' : 'MISMATCH!!! '.json_encode(['before' => $before, 'after' => $after]))."\n";
