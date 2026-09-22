<?php
// Sprint 11c Step 3 — cross-checks the REAL KnowledgeGraphBuilder (not a
// hand-written SQL approximation) against hr-staging, and against the counts
// measure-graph.php found independently on 2026-09-22 (261 nodes / 421 edges).
// READ ONLY: DB::table()->get() selects only, no writes, no migrations.
//
// Run it (from the workspace root, with the staging key in ~/.hr-staging):
//   EIP=$(cat ~/.hr-staging/eip_address)
//   scp -i ~/.hr-staging/hr-staging-ec2-key.pem \
//     hr-backend/app/Support/KnowledgeGraphBuilder.php \
//     hr-docs/sprints/sprint-11c/verify-endpoint.php \
//     hr-docs/sprints/sprint-11c/run-verify-endpoint.sh ubuntu@"$EIP":/tmp/
//   ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@"$EIP" \
//     'cd /opt/hr-staging \
//      && docker compose -f docker-compose.staging.yml cp /tmp/KnowledgeGraphBuilder.php hr-backend:/tmp/ \
//      && docker compose -f docker-compose.staging.yml cp /tmp/verify-endpoint.php hr-backend:/tmp/ \
//      && docker compose -f docker-compose.staging.yml cp /tmp/run-verify-endpoint.sh hr-backend:/tmp/ \
//      && docker compose -f docker-compose.staging.yml exec -T hr-backend bash /tmp/run-verify-endpoint.sh'
//
// This deliberately does NOT check out the sprint-11c branch on staging (that
// is Step 8, after CP-1) — it loads just the one pure-function class file
// straight into a running tinker session, on top of whatever is deployed.

use Illuminate\Support\Facades\DB;

require_once '/tmp/KnowledgeGraphBuilder.php';

$rows = [
    'convenios' => DB::table('convenios')->select('id', 'numero', 'name', 'territory_id', 'sector_id')->get()->map(fn ($r) => (array) $r)->all(),
    'documents' => DB::table('documents')->select('id', 'uuid', 'title', 'convenio_id', 'retrieval_status', 'tagging_status')->get()->map(fn ($r) => (array) $r)->all(),
    'reference_facts' => DB::table('reference_facts')->select('id', 'uuid', 'value', 'convenio_id', 'topic_id', 'status', 'source', 'source_document_id')->get()->map(fn ($r) => (array) $r)->all(),
    'document_topics' => DB::table('document_topics')->select('document_id', 'topic_id', 'source', 'verified_by')->get()->map(fn ($r) => (array) $r)->all(),
    'territories' => DB::table('territories')->select('id', 'name')->get()->map(fn ($r) => (array) $r)->all(),
    'sectors' => DB::table('sectors')->select('id', 'name')->get()->map(fn ($r) => (array) $r)->all(),
    'topics' => DB::table('topics')->select('id', 'name')->get()->map(fn ($r) => (array) $r)->all(),
];

$graph = \App\Support\KnowledgeGraphBuilder::build($rows);

echo "### COUNTS\n";
echo json_encode($graph['counts'], JSON_PRETTY_PRINT) . "\n\n";

echo "### EDGE KIND BREAKDOWN\n";
$byKind = [];
foreach ($graph['edges'] as $e) {
    $byKind[$e['kind']] = ($byKind[$e['kind']] ?? 0) + 1;
}
echo json_encode($byKind, JSON_PRETTY_PRINT) . "\n\n";

echo "### NODE TYPE BREAKDOWN\n";
$byType = [];
foreach ($graph['nodes'] as $n) {
    $byType[$n['type']] = ($byType[$n['type']] ?? 0) + 1;
}
echo json_encode($byType, JSON_PRETTY_PRINT) . "\n\n";

echo "### AGAINST measure-graph.php's 2026-09-22 NODES/EDGES totals (261/421)\n";
echo 'nodes match: ' . ($graph['counts']['nodes'] === 261 ? 'YES' : 'NO (' . $graph['counts']['nodes'] . ')') . "\n";
echo 'edges match: ' . ($graph['counts']['edges'] === 421 ? 'YES' : 'NO (' . $graph['counts']['edges'] . ')') . "\n";

echo "### SANITY: source_document surfaced (not drawn) for a shared-source fact\n";
$sample = null;
foreach ($graph['nodes'] as $n) {
    if ($n['type'] === 'fact' && ($n['source_document']['id'] ?? null) !== null) {
        $sample = $n;
        break;
    }
}
echo $sample !== null ? json_encode($sample, JSON_PRETTY_PRINT) . "\n" : "none found\n";
