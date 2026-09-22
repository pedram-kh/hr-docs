<?php
// Sprint 11c plan-gate measurement — READ ONLY (ADR-0030: measurement is
// read-only and reproducible). Every count in plan.md §A came from this script,
// run against hr-staging on 2026-09-22. It issues SELECTs only — no writes, no
// migrations, no artisan commands with side effects.
//
// Run it (from the workspace root, with the staging key in ~/.hr-staging):
//   EIP=$(cat ~/.hr-staging/eip_address)
//   scp -i ~/.hr-staging/hr-staging-ec2-key.pem \
//     hr-docs/sprints/sprint-11c/measure-graph.php \
//     hr-docs/sprints/sprint-11c/run-measure-graph.sh ubuntu@"$EIP":/tmp/
//   ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@"$EIP" \
//     'cd /opt/hr-staging \
//      && docker compose -f docker-compose.staging.yml cp /tmp/measure-graph.php hr-backend:/tmp/ \
//      && docker compose -f docker-compose.staging.yml cp /tmp/run-measure-graph.sh hr-backend:/tmp/ \
//      && docker compose -f docker-compose.staging.yml exec -T hr-backend bash /tmp/run-measure-graph.sh'

use Illuminate\Support\Facades\DB;

function q(string $label, string $sql): void
{
    echo "### $label\n";
    echo json_encode(DB::select($sql), JSON_UNESCAPED_UNICODE) . "\n\n";
}

// ---------------------------------------------------------------------------
// The drawn sets, as the spec's rules define them (spec §2). Shared by every
// derived query below so the node table and the edge table cannot disagree.
//   - hubs (territory / sector / topic) are drawn only at >= 2 attachments
//   - the DEV-FIXTURE convenio is excluded (deploy.md §4: it is not real data)
//   - `rejected` facts are excluded (a discard record, not knowledge)
//   - a document with no edge to any drawn node is not drawn (orphan)
// ---------------------------------------------------------------------------
$drawn = "
drawn_territory AS (
  SELECT t.id FROM territories t
  WHERE (SELECT count(*) FROM convenios c WHERE c.territory_id=t.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') >= 2
),
drawn_sector AS (
  SELECT s.id FROM sectors s
  WHERE (SELECT count(*) FROM convenios c WHERE c.sector_id=s.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') >= 2
),
drawn_topic AS (
  SELECT t.id FROM topics t
  WHERE ((SELECT count(*) FROM document_topics dt WHERE dt.topic_id=t.id)
       + (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id AND rf.status <> 'rejected')) >= 2
),
drawn_convenio AS (
  SELECT c.id FROM convenios c WHERE c.numero NOT LIKE 'DEV-FIXTURE-%'
),
drawn_fact AS (
  SELECT rf.id FROM reference_facts rf WHERE rf.status <> 'rejected'
),
drawn_document AS (
  SELECT d.id FROM documents d
  WHERE d.convenio_id IS NOT NULL
     OR EXISTS (SELECT 1 FROM document_topics dt JOIN drawn_topic x ON x.id=dt.topic_id WHERE dt.document_id=d.id)
)";

// === §A.1 raw inventory ====================================================

q('convenios', "SELECT count(*) AS total,
  count(*) FILTER (WHERE numero LIKE 'DEV-FIXTURE-%') AS dev_fixture,
  count(*) FILTER (WHERE numero NOT LIKE 'DEV-FIXTURE-%') AS real_convenios,
  count(DISTINCT territory_id) AS distinct_territories,
  count(DISTINCT sector_id) AS distinct_sectors,
  count(*) FILTER (WHERE territory_id IS NULL) AS null_territory,
  count(*) FILTER (WHERE sector_id IS NULL) AS null_sector
FROM convenios");

q('documents_by_retrieval_status', "SELECT retrieval_status, count(*) AS n,
  count(*) FILTER (WHERE convenio_id IS NOT NULL) AS bound,
  count(*) FILTER (WHERE convenio_id IS NULL) AS unbound
FROM documents GROUP BY retrieval_status ORDER BY retrieval_status");

q('documents_totals', "SELECT count(*) AS total,
  count(*) FILTER (WHERE convenio_id IS NULL) AS unbound,
  count(*) FILTER (WHERE derived_from_document_id IS NOT NULL) AS derived,
  count(DISTINCT convenio_id) AS distinct_convenios
FROM documents");

q('documents_by_tagging_status', "SELECT tagging_status, count(*) AS n FROM documents GROUP BY 1 ORDER BY 1");
q('documents_by_authority', "SELECT authority_level, count(*) AS n FROM documents GROUP BY 1 ORDER BY 1");

q('reference_facts_status_source', "SELECT status, source, count(*) AS n,
  count(DISTINCT convenio_id) AS convenios, count(DISTINCT topic_id) AS topics
FROM reference_facts GROUP BY 1,2 ORDER BY 1,2");

q('reference_facts_totals', "SELECT count(*) AS total,
  count(*) FILTER (WHERE topic_id IS NOT NULL) AS with_topic,
  count(DISTINCT convenio_id) AS distinct_convenios,
  count(DISTINCT topic_id) AS distinct_topics,
  count(DISTINCT source_document_id) AS distinct_source_docs,
  count(*) FILTER (WHERE source_document_id IS NULL) AS no_source_doc,
  count(*) FILTER (WHERE group_label IS NOT NULL) AS with_group_label
FROM reference_facts");

q('topics_detail', "SELECT t.id, t.name, t.status,
  (SELECT count(*) FROM document_topics dt WHERE dt.topic_id=t.id) AS docs,
  (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id) AS facts,
  (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id AND rf.status <> 'rejected') AS facts_not_rejected
FROM topics t ORDER BY 5 DESC, t.id");

q('territories_detail', "SELECT t.id, t.name, t.level,
  (SELECT count(*) FROM convenios c WHERE c.territory_id=t.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') AS convenios
FROM territories t ORDER BY 4 DESC, t.id");

q('sectors_detail', "SELECT s.id, s.name,
  (SELECT count(*) FROM convenios c WHERE c.sector_id=s.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') AS convenios
FROM sectors s ORDER BY 3 DESC, s.id");

q('convenio_groups_by_status', "SELECT status, count(*) AS n, count(*) FILTER (WHERE parent_id IS NULL) AS roots
FROM convenio_groups GROUP BY 1 ORDER BY 1");

q('approved_group_detail', "SELECT g.id, g.convenio_id, g.parent_id, g.label,
  (SELECT count(*) FROM reference_fact_group_scopes s WHERE s.convenio_group_id=g.id) AS facts_bound
FROM convenio_groups g WHERE g.status='approved' ORDER BY g.parent_id NULLS FIRST, g.id");

q('salary', "SELECT (SELECT count(*) FROM salary_tables) AS salary_tables,
  (SELECT count(DISTINCT convenio_id) FROM salary_tables) AS salary_convenios,
  (SELECT count(*) FROM salary_table_rows) AS salary_rows");

// === §A.2 the graph the rules produce ======================================

q('NODES', "WITH $drawn
SELECT
  (SELECT count(*) FROM drawn_convenio) AS convenio_nodes,
  (SELECT count(*) FROM drawn_document) AS document_nodes,
  (SELECT count(*) FROM drawn_fact) AS fact_nodes,
  (SELECT count(*) FROM drawn_territory) AS territory_hubs,
  (SELECT count(*) FROM drawn_sector) AS sector_hubs,
  (SELECT count(*) FROM drawn_topic) AS topic_hubs,
  (SELECT count(*) FROM drawn_convenio) + (SELECT count(*) FROM drawn_document)
  + (SELECT count(*) FROM drawn_fact) + (SELECT count(*) FROM drawn_territory)
  + (SELECT count(*) FROM drawn_sector) + (SELECT count(*) FROM drawn_topic) AS total_nodes");

q('NODES_folded_or_dropped', "WITH $drawn
SELECT
  (SELECT count(*) FROM territories t WHERE t.id NOT IN (SELECT id FROM drawn_territory)
     AND (SELECT count(*) FROM convenios c WHERE c.territory_id=t.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') = 1) AS territories_folded_singleton,
  (SELECT count(*) FROM sectors s WHERE s.id NOT IN (SELECT id FROM drawn_sector)
     AND (SELECT count(*) FROM convenios c WHERE c.sector_id=s.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') = 1) AS sectors_folded_singleton,
  (SELECT count(*) FROM sectors s WHERE (SELECT count(*) FROM convenios c WHERE c.sector_id=s.id AND c.numero NOT LIKE 'DEV-FIXTURE-%') = 0) AS sectors_unused,
  (SELECT count(*) FROM topics t WHERE t.id NOT IN (SELECT id FROM drawn_topic)
     AND ((SELECT count(*) FROM document_topics dt WHERE dt.topic_id=t.id)
        + (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id AND rf.status<>'rejected')) = 1) AS topics_folded_singleton,
  (SELECT count(*) FROM topics t WHERE ((SELECT count(*) FROM document_topics dt WHERE dt.topic_id=t.id)
        + (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id AND rf.status<>'rejected')) = 0) AS topics_unused,
  (SELECT count(*) FROM documents d WHERE d.id NOT IN (SELECT id FROM drawn_document)) AS documents_dropped_orphan,
  (SELECT count(*) FROM reference_facts WHERE status='rejected') AS facts_dropped_rejected,
  (SELECT count(*) FROM convenios WHERE numero LIKE 'DEV-FIXTURE-%') AS convenios_dropped_dev_fixture");

q('EDGES', "WITH $drawn
SELECT
  (SELECT count(*) FROM documents d JOIN drawn_document dd ON dd.id=d.id
     JOIN drawn_convenio dc ON dc.id=d.convenio_id) AS e_document_convenio,
  (SELECT count(*) FROM document_topics dt JOIN drawn_document dd ON dd.id=dt.document_id
     JOIN drawn_topic tt ON tt.id=dt.topic_id) AS e_document_topic,
  (SELECT count(*) FROM reference_facts rf JOIN drawn_fact df ON df.id=rf.id
     JOIN drawn_convenio dc ON dc.id=rf.convenio_id) AS e_fact_convenio,
  (SELECT count(*) FROM reference_facts rf JOIN drawn_fact df ON df.id=rf.id
     JOIN drawn_topic tt ON tt.id=rf.topic_id) AS e_fact_topic,
  (SELECT count(*) FROM convenios c JOIN drawn_convenio dc ON dc.id=c.id
     JOIN drawn_territory dt ON dt.id=c.territory_id) AS e_convenio_territory,
  (SELECT count(*) FROM convenios c JOIN drawn_convenio dc ON dc.id=c.id
     JOIN drawn_sector ds ON ds.id=c.sector_id) AS e_convenio_sector");

q('EDGES_optional_group', "SELECT
  (SELECT count(*) FROM reference_fact_group_scopes s
     JOIN convenio_groups g ON g.id=s.convenio_group_id
     JOIN reference_facts rf ON rf.id=s.reference_fact_id
   WHERE g.status='approved' AND rf.status <> 'rejected') AS e_fact_group,
  (SELECT count(*) FROM convenio_groups WHERE status='approved') AS group_nodes,
  (SELECT count(*) FROM convenio_groups WHERE status='approved' AND parent_id IS NOT NULL) AS e_group_parent");

q('node_degree_top', "WITH $drawn,
deg AS (
  SELECT 'topic:'||t.id AS node, t.name AS label,
    (SELECT count(*) FROM document_topics dt JOIN drawn_document dd ON dd.id=dt.document_id WHERE dt.topic_id=t.id)
  + (SELECT count(*) FROM reference_facts rf WHERE rf.topic_id=t.id AND rf.status<>'rejected') AS degree
  FROM topics t JOIN drawn_topic x ON x.id=t.id
  UNION ALL
  SELECT 'convenio:'||c.id, c.name,
    (SELECT count(*) FROM documents d JOIN drawn_document dd ON dd.id=d.id WHERE d.convenio_id=c.id)
  + (SELECT count(*) FROM reference_facts rf WHERE rf.convenio_id=c.id AND rf.status<>'rejected')
  + (CASE WHEN c.territory_id IN (SELECT id FROM drawn_territory) THEN 1 ELSE 0 END)
  + (CASE WHEN c.sector_id IN (SELECT id FROM drawn_sector) THEN 1 ELSE 0 END)
  FROM convenios c JOIN drawn_convenio dc ON dc.id=c.id
  UNION ALL
  SELECT 'territory:'||t.id, t.name,
    (SELECT count(*) FROM convenios c WHERE c.territory_id=t.id AND c.numero NOT LIKE 'DEV-FIXTURE-%')
  FROM territories t JOIN drawn_territory x ON x.id=t.id
  UNION ALL
  SELECT 'sector:'||s.id, s.name,
    (SELECT count(*) FROM convenios c WHERE c.sector_id=s.id AND c.numero NOT LIKE 'DEV-FIXTURE-%')
  FROM sectors s JOIN drawn_sector x ON x.id=s.id
)
SELECT node, label, degree FROM deg ORDER BY degree DESC LIMIT 15");

// === §A.4 the ambiguous cases ==============================================

q('shared_source_docs_multi_convenio', "SELECT source_document_id, count(*) AS facts,
  count(DISTINCT convenio_id) AS convenios
FROM reference_facts WHERE source_document_id IS NOT NULL
GROUP BY 1 HAVING count(DISTINCT convenio_id) > 1 ORDER BY 3 DESC, 2 DESC");

q('docs_105_106', "SELECT id, title, convenio_id, retrieval_status, authority_level FROM documents WHERE id IN (105,106)");
q('docs_105_106_topics', "SELECT dt.document_id, t.id AS topic_id, t.name
FROM document_topics dt JOIN topics t ON t.id=dt.topic_id WHERE dt.document_id IN (105,106)");

q('topic1_facts_come_from_105_106', "SELECT
  (SELECT count(*) FROM reference_facts WHERE topic_id=1) AS topic1_facts,
  (SELECT count(*) FROM reference_facts WHERE topic_id=1 AND source_document_id IN (105,106)) AS topic1_facts_from_105_106,
  (SELECT count(*) FROM reference_facts WHERE source_document_id IN (105,106) AND topic_id <> 1) AS facts_from_105_106_other_topic");

q('unbound_docs_by_status_and_topics', "SELECT d.retrieval_status, d.authority_level, count(*) AS n,
  count(*) FILTER (WHERE EXISTS (SELECT 1 FROM document_topics dt WHERE dt.document_id=d.id)) AS with_any_topic
FROM documents d WHERE d.convenio_id IS NULL GROUP BY 1,2 ORDER BY 1,2");

q('unbound_docs_that_are_drawn', "WITH $drawn
SELECT d.id, d.title, d.retrieval_status,
  (SELECT string_agg(t.name, ', ') FROM document_topics dt JOIN topics t ON t.id=dt.topic_id WHERE dt.document_id=d.id) AS topics
FROM documents d JOIN drawn_document dd ON dd.id=d.id
WHERE d.convenio_id IS NULL ORDER BY d.id");

q('convenios_with_nothing', "SELECT c.id, c.numero, c.name FROM convenios c
WHERE c.numero NOT LIKE 'DEV-FIXTURE-%'
  AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.convenio_id=c.id)
  AND NOT EXISTS (SELECT 1 FROM reference_facts rf WHERE rf.convenio_id=c.id)
ORDER BY c.id");

// === §D.11 colour-state counts =============================================

q('drawn_document_state', "WITH $drawn
SELECT d.retrieval_status, d.tagging_status, count(*) AS n
FROM documents d JOIN drawn_document dd ON dd.id=d.id GROUP BY 1,2 ORDER BY 1,2");

q('drawn_fact_state', "SELECT status, source, count(*) AS n FROM reference_facts
WHERE status <> 'rejected' GROUP BY 1,2 ORDER BY 1,2");

q('drawn_doc_topic_edges_by_provenance', "WITH $drawn
SELECT dt.source, (dt.verified_by IS NULL) AS unverified, count(*) AS n
FROM document_topics dt JOIN drawn_document dd ON dd.id=dt.document_id
JOIN drawn_topic tt ON tt.id=dt.topic_id GROUP BY 1,2 ORDER BY 1,2");

// Fuchsia on a DOCUMENT node, per ADR-0020 (fuchsia = unverified AI only, and
// it reverts on verify): the document is itself still in the inert state AND
// carries at least one unverified ai_agent facet proposal.
q('drawn_docs_fuchsia_rule', "WITH $drawn
SELECT count(*) AS docs_unverified_ai
FROM documents d JOIN drawn_document dd ON dd.id=d.id
WHERE d.tagging_status='under_review'
  AND EXISTS (SELECT 1 FROM document_topics dt WHERE dt.document_id=d.id AND dt.source='ai_agent' AND dt.verified_by IS NULL)");

q('drawn_docs_with_any_unverified_ai_facet', "WITH $drawn
SELECT d.id, d.title, d.retrieval_status, d.tagging_status, d.convenio_id
FROM documents d JOIN drawn_document dd ON dd.id=d.id
WHERE EXISTS (SELECT 1 FROM document_topics dt WHERE dt.document_id=d.id AND dt.source='ai_agent' AND dt.verified_by IS NULL)
ORDER BY d.id");
