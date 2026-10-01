<?php

/**
 * Slice 13c — the PURE part of the forced-lane harness (`lane-forced-13c.php`): the per-draft verdict and the summary maths.
 * No network, no DB, no framework boot beyond the autoloader — so `Sprint13cLaneForcedVerdictTest` (hr-backend) drives it with
 * canned drafts. The harness itself only fetches drafts and feeds them here.
 *
 * The verdict order is the ORDER THE LIVE RULES RUN (AgentServiceProvider: post-check → shape check), so a forced draft is
 * judged by exactly the locks an employee-facing turn would meet:
 *
 *   no_material | unavailable      the tool produced no draft at all (no page, no key, hr-ai error)
 *   ungrounded                     a WEB draft failed /ground inside the tool (terminal escalate) — the third lock
 *   blocked_postcheck              GeneralLanePostCheck::scan() hit (figures, entitlement language, …)
 *   blocked_shape                  ModelKnowledgeShapeCheck::check() blocked (S1 citation / S2 length / S3 pointer)
 *   AUDIT_BYPASS                   passed both AND the independent, broader GeneralLanePostCheck::audit() still flags it — HARD FAIL
 *   passed_clean                   passed everything
 */

use App\Services\Agent\Rules\GeneralLanePostCheck;
use App\Services\Agent\Rules\ModelKnowledgeShapeCheck;
use App\Services\Agent\ToolResult;
use App\Services\Answer\TurnOutcome;

/**
 * `locks` (answers only) runs EVERY deterministic lock independently of the live short-circuit, so a draft that the post-check
 * blocked is still shown to the shape check and the audit: {postcheck_ids, shape_ids, audit, sole_catcher}. `sole_catcher` names
 * the one lock that alone stopped the draft (`E2`, another post-check id, `shape:S2`, …) or null when two or more would have,
 * or none did — that is the "is E2 load-bearing?" evidence.
 *
 * @return array{verdict:string, basis:?string, answer:?string, word_count:?int, postcheck:?array, shape:?array, audit:list<string>, locks:?array, fallback:?array, cost_usd:?float, latency_ms:?int, note:?string}
 */
function lane13c_verdict(ToolResult $res): array
{
    $row = ['verdict' => '', 'basis' => null, 'answer' => null, 'word_count' => null, 'postcheck' => null, 'shape' => null, 'audit' => [], 'locks' => null, 'fallback' => null, 'cost_usd' => null, 'latency_ms' => null, 'note' => null];

    $out = $res->terminalOutcome;
    if (! $out instanceof TurnOutcome) {
        $row['verdict'] = ($res->plannerSummary['status'] ?? null) === 'unavailable' ? 'unavailable' : 'no_material';
        $row['note'] = $res->plannerSummary['status'] ?? null;

        return $row;
    }

    $lane = $out->trace['general_lane'] ?? [];
    $row['basis'] = $lane['basis'] ?? null;
    $row['fallback'] = $lane['fallback'] ?? null;
    // the draft's own cost, plus the abandoned web attempt's when the answer is the model-knowledge fallback
    $row['cost_usd'] = isset($lane['draft']['cost_usd']) ? round((float) $lane['draft']['cost_usd'] + (float) ($lane['fallback']['web_cost_usd'] ?? 0), 6) : null;
    $row['latency_ms'] = isset($lane['draft']['general_knowledge_ms']) ? (int) $lane['draft']['general_knowledge_ms'] : null;

    if ($out->outcome !== 'answer') {
        $row['verdict'] = 'ungrounded';
        $row['note'] = $out->trace['floor_decision']['note'] ?? null;

        return $row;
    }

    $row['answer'] = $out->answer;
    $row['word_count'] = ModelKnowledgeShapeCheck::wordCount($out->answer);
    $row['locks'] = lane13c_locks($out->answer, $row['basis']);

    $hit = GeneralLanePostCheck::scan($out->answer);
    if ($hit !== null) {
        $row['verdict'] = 'blocked_postcheck';
        $row['postcheck'] = $hit;

        return $row;
    }

    $shape = ModelKnowledgeShapeCheck::check($out->answer, $row['basis'] === ModelKnowledgeShapeCheck::BASIS_MODEL ? ModelKnowledgeShapeCheck::BASIS_MODEL : ModelKnowledgeShapeCheck::BASIS_WEB);
    $row['shape'] = ['verdict' => $shape['verdict'], 'rule_ids' => $shape['rule_ids'], 'hits' => $shape['hits']];
    if ($shape['verdict'] !== 'pass') {
        $row['verdict'] = 'blocked_shape';

        return $row;
    }

    $row['audit'] = GeneralLanePostCheck::audit($out->answer);
    $row['verdict'] = $row['audit'] === [] ? 'passed_clean' : 'AUDIT_BYPASS';

    return $row;
}

/**
 * Every lock, independently (no short-circuit). `sole_catcher` = the only lock that would have stopped the draft, else null.
 *
 * @return array{postcheck_ids:list<string>, shape_ids:list<string>, audit:list<string>, sole_catcher:?string}
 */
function lane13c_locks(string $answer, ?string $basis): array
{
    $postIds = array_values(array_unique(array_column(GeneralLanePostCheck::scanAll($answer)['hits'], 'pattern_id')));
    // scanAll() does not strip legal citations the way scan() does; the live post-check's own first hit is authoritative for "would it block"
    $live = GeneralLanePostCheck::scan($answer);
    if ($live === null) {
        $postIds = [];
    } elseif ($postIds === []) {
        $postIds = [$live['pattern_id']];
    }
    $shape = ModelKnowledgeShapeCheck::check($answer, $basis === ModelKnowledgeShapeCheck::BASIS_MODEL ? ModelKnowledgeShapeCheck::BASIS_MODEL : ModelKnowledgeShapeCheck::BASIS_WEB);
    $audit = GeneralLanePostCheck::audit($answer);

    $catchers = array_merge(array_map(fn ($id) => $id, $postIds), array_map(fn ($id) => 'shape:'.$id, $shape['rule_ids']));
    $sole = null;
    if ($live !== null || $shape['verdict'] !== 'pass') {
        // "distinct locks": each post-check pattern id is a lock of its own, each shape rule too
        $sole = count($catchers) === 1 ? $catchers[0] : null;
    }

    return ['postcheck_ids' => $postIds, 'shape_ids' => $shape['rule_ids'], 'audit' => $audit, 'sole_catcher' => $sole];
}

/** Nearest-rank percentile (0 < $p ≤ 100) of a numeric list; null on an empty list. */
function lane13c_percentile(array $values, float $p): int|float|null
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $rank = (int) max(1, ceil($p / 100 * count($values)));

    return $values[min($rank, count($values)) - 1];
}

/**
 * Summary per (class, basis) bucket, plus an "all" bucket per class. R1 = blocked / drafts, where a DRAFT is a row that reached the
 * deterministic locks (i.e. produced an answer): `blocked_postcheck + blocked_shape + passed_clean + AUDIT_BYPASS`. `ungrounded`,
 * `no_material` and `unavailable` are counted but are not drafts the post-check/shape lock ever saw.
 *
 * @param  list<array<string,mixed>>  $rows  each: class, basis, verdict, word_count, cost_usd, latency_ms, postcheck, shape, answer, question
 * @return array<string, array<string,mixed>>
 */
function lane13c_summarise(array $rows): array
{
    $buckets = [];
    foreach ($rows as $r) {
        $class = (string) ($r['class'] ?? 'unknown');
        $basis = (string) ($r['basis'] ?? 'none');
        foreach ([$class.'/'.$basis, $class.'/all'] as $key) {
            $buckets[$key][] = $r;
        }
    }
    ksort($buckets);

    $draftVerdicts = ['blocked_postcheck', 'blocked_shape', 'passed_clean', 'AUDIT_BYPASS'];
    $out = [];
    foreach ($buckets as $key => $list) {
        $count = fn (string $v) => count(array_filter($list, fn ($r) => $r['verdict'] === $v));
        $drafts = count(array_filter($list, fn ($r) => in_array($r['verdict'], $draftVerdicts, true)));
        $blocked = $count('blocked_postcheck') + $count('blocked_shape');
        $postIds = [];
        $shapeIds = [];
        $sole = [];
        $anyLock = [];
        foreach ($list as $r) {
            foreach ($r['locks']['postcheck_ids'] ?? [] as $id) {
                $anyLock[$id] = ($anyLock[$id] ?? 0) + 1;
            }
            foreach ($r['locks']['shape_ids'] ?? [] as $id) {
                $anyLock['shape:'.$id] = ($anyLock['shape:'.$id] ?? 0) + 1;
            }
            if (($r['locks']['sole_catcher'] ?? null) !== null) {
                $sole[$r['locks']['sole_catcher']] = ($sole[$r['locks']['sole_catcher']] ?? 0) + 1;
            }
            if ($r['verdict'] === 'blocked_postcheck' && isset($r['postcheck']['pattern_id'])) {
                $postIds[$r['postcheck']['pattern_id']] = ($postIds[$r['postcheck']['pattern_id']] ?? 0) + 1;
            }
            if ($r['verdict'] === 'blocked_shape') {
                foreach ($r['shape']['rule_ids'] ?? [] as $id) {
                    $shapeIds[$id] = ($shapeIds[$id] ?? 0) + 1;
                }
            }
        }
        ksort($postIds);
        ksort($shapeIds);
        ksort($sole);
        ksort($anyLock);
        $words = array_values(array_filter(array_map(fn ($r) => $r['word_count'], $list), fn ($w) => $w !== null));
        $costs = array_values(array_filter(array_map(fn ($r) => $r['cost_usd'], $list), fn ($c) => $c !== null));
        $lat = array_values(array_filter(array_map(fn ($r) => $r['latency_ms'], $list), fn ($l) => $l !== null));

        $out[$key] = [
            'rows' => count($list),
            'drafts' => $drafts,
            'blocked_postcheck' => $count('blocked_postcheck'),
            'blocked_shape' => $count('blocked_shape'),
            'passed_clean' => $count('passed_clean'),
            'audit_bypass' => $count('AUDIT_BYPASS'),
            'ungrounded' => $count('ungrounded'),
            'no_material' => $count('no_material'),
            'unavailable' => $count('unavailable'),
            'denied_by_prescreen' => $count('denied_by_prescreen'),
            'block_rate' => $drafts > 0 ? round($blocked / $drafts, 4) : null,
            'postcheck_rule_ids' => $postIds,
            'shape_rule_ids' => $shapeIds,
            'would_catch_any' => $anyLock,
            'sole_catcher' => $sole,
            'fallbacks' => count(array_filter($list, fn ($r) => ($r['fallback'] ?? null) !== null)),
            'words' => ['p50' => lane13c_percentile($words, 50), 'p95' => lane13c_percentile($words, 95), 'max' => $words === [] ? null : max($words)],
            'cost_usd' => ['sum' => round(array_sum($costs), 4), 'p50' => lane13c_percentile($costs, 50), 'p95' => lane13c_percentile($costs, 95)],
            'latency_ms' => ['p50' => lane13c_percentile($lat, 50), 'p95' => lane13c_percentile($lat, 95)],
        ];
    }

    return $out;
}
