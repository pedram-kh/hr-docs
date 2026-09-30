# Slice 13d — Plan: multiple verified facts on one topic

> Status: **ACCEPTED 2026-09-30 — build authorised.** Decisions: Q1 R-Q (ADR-0037; spec §3 and criteria 1/7 amended; the
> audit's complementary pairs are a human-reviewed go-live / post-ingestion / post-triage item, deploy.md §6c); Q2 additive
> hr-ai `fact_id` approved; Q3 criterion 1 as restated (fact-vs-prose guard escalating 143 is reported, not tuned); Q4 nest under
> `reference_fact.fact_set`, cohorts only; Q5 gate scope as proposed (≈ $13.4, cap $15); Q6 WIP commits + branch deploy authorised
> (squash at merge; copy streams off before any recreate); Q7 noted; Q8 ticket the unreachable resolver, propose audit `--flag`
> (roadmap §7); Q9 cap 3; Q10 ho-jor-04 gated on the agent, classic recorded. Also: delete snapshot `hr-staging-post-13-cp1`.
> Original plan-gate status: read-only `SELECT` batches against staging (Appendix A) and reads of the repo only.
> Inputs read: `sprint-13d-spec.md`, ADR-0034, Sprint 7c `review.md`, Slice 13b `review.md`
> (ho-jor-04 root cause, line 103), `roadmap.md:314` (the ticket), plus the code cited inline.

---

## 0. Verdict up front — one finding changes the slice

**The spec's complementary/contradictory rule cannot classify the case the slice exists for.**

The spec (§3) says: *different logical keys surviving precedence → complementary; same key + different
value + no supersession → contradictory.* The logical key is `convenio_id, topic_id, job_category_id,
group_label, validity_start, validity_end` (`ReferenceFact.php:48`; the 7b-2 writer adds `source` and
`source_document_id`, `ReferenceFactProposalService.php:360-369`).

Staging facts **140 and 143 have the identical full key** — convenio 20, topic 2, no category, no group
label, `2025-01-01..2028-12-31`, `ai_agent`, source document 50 (Appendix A.4). It is the *only* same-key
pair in the whole table, across all statuses. Under the spec's rule they are **contradictory** — i.e.
13d as written would change nothing for ho-jor-04, and spec §5 acceptance criteria 1 and 7 could not pass.

Two further problems with the "different key ⇒ complementary" clause, independent of 140/143:

* **Tier 3 can never have different keys.** The convenio-wide tier is defined as null category *and* null
  group label (`ReferenceFactAnswerService.php:145`); within it every candidate has the same key by
  construction, apart from validity. The clause is dead code for the only tier we need.
* **In Tiers 1–2 it would be unsafe.** Tier 1 yields facts with one `job_category_id`; Tier 2 yields facts
  *bound to the employee's node* (`:274-337`). Facts there can differ only by `group_label`, a *printed
  string* (ADR-0028: the node is the identity, the label is provenance). Two facts bound to the same node
  with labels "Grupo 2" / "Grupo 2 (área 5)" are exactly what 7d/7g flag as **probable versions**
  (`FactResolutionService.php:202-350`, "same node"). Treating a label difference as "complementary"
  would compose two versions of one value — the blend the whole safety spine forbids.

So the key cannot be the discriminator. §3 proposes the smallest deterministic replacement that (a) needs
no schema change, (b) needs no model, (c) does not change what a logical key is (spec §4), and (d) fails
toward today's behaviour whenever it cannot prove the facts are about different quantities.
**This needs your decision before build (Open question Q1).** Everything below is written against the
recommended rule; the alternatives are costed in §3.5.

Other headline points:

* **Citing both facts is impossible without a small additive hr-ai change.** `/synthesise` de-duplicates
  cited fact sources on `(source_type, document_id)` (`hr-ai/app/providers/claude.py:1490`); 140 and 143
  are the same document, so the model's citation of the second fact collapses onto the first. §4.4.
* **The trace field `composition` already exists** (`trace.composition`, the fact+prose block,
  `ReferenceFactPath.php:204-209`) and adding *any* key to every `reference_fact` block would break the
  four fact-route golden traces. §5 nests the new fields and emits them only when a set is involved.
* **Spend fits ≤ $15 only with a trimmed live regression net** (§8): ≈ $13.4 estimated, ≈ $1.6 headroom.
* **Criterion 1 as worded ("every jornada question … citing both facts") is not something the route can
  guarantee**: synthesis cites what it uses (§4.6, Q3). Same for classic + colloquial phrasing (13b contract).

---

## 1. Today's selection, exactly

### 1.1 How candidates are gathered

`ReferenceFactAnswerService::answer()` (`ReferenceFactAnswerService.php:59`). One query, no ordering:
`convenio_id` = employee's convenio, `topic_id`, `status = 'verified'`, and *validity contains as-of*
(`validity_start` null or ≤ as-of; `validity_end` null or ≥ as-of) — `:93-99`. Empty →
`reference_fact_coverage_gap` with the `coverage_gap_detail` breakdown (`:101-108`). There is **no filter on
`resolution` / `superseded_by_id`**: a superseded fact leaves the set only because a supersede sets its
`validity_end` to the day before the newer start (`FactResolutionService.php:82-97`), which the same
validity predicate then excludes.

### 1.2 The precedence ladder (first non-empty tier returns; nothing composes across tiers)

| Tier | Lines | Selects | Returns |
|---|---|---|---|
| 1 job category | `:112-118` | `job_category_id == employee's` (only if the employee has one) | `resolveAndAnswer(..., 'job_category')` |
| 2 approved group node | `:120-142` | facts *bound* (`reference_fact_group_scopes`) to the employee's approved node, by integer id (`matchByGroupNode`, `:274-337`) | indeterminate scope → **hard-stop escalate** (`:132-137`, "escalate rather than answer less specifically"); matched → `resolveAndAnswer(..., 'group')`; nothing matched → fall through |
| 3 convenio-wide | `:144-148` | `job_category_id === null && group_label === null` | `resolveAndAnswer(..., 'convenio_wide')` |
| 4 | `:150-153` | verified facts exist, none applicable | escalate "never guess" |

`resolveAndAnswer()` (`:165-204`) calls `selectMostRecent($tier)` **once, on the winning tier only**.

### 1.3 What "conflict" means in code today (`selectMostRecent`, `:216-237`)

1. One fact → `[fact, 'single']` (`:218-220`).
2. Otherwise sort by `validity_start` desc, null = oldest (`:223`); `top` = first.
3. **Conflict** ⇔ some *other* fact has the *same* `validity_start` as `top` **and** `value !== top->value`
   (strict, byte-for-byte string compare) (`:228-230`) → `[null, 'ambiguous_conflict']` → `resolveAndAnswer`
   escalates `reference_fact_coverage_gap` with note "two verified facts with the same most-recent validity
   and differing values — escalate, do not blend (resolution is 7d)" (`:168-176`).
4. Else `[top, 'most_recent_validity']` (`:235`).

What it does **not** look at: the key, `raw_values`, `validity_end`, the topic's *quantity*, or whether the
facts are about the same thing. Two facts with different `validity_start` are resolved by recency
(the older is silently dropped even if it is about something else) — that is the intended supersession
semantics for the *same* quantity and is **left untouched** by 13d (see §6 `recency_shadowed`).
Identical-`value` duplicates at the same start are not a conflict; which one is `top` then depends on DB
row order (the query has no `ORDER BY`) — also untouched.

Only `ReferenceFactPath::handle()` calls `answer()` (`ReferenceFactPath.php:84`; grep of `app/`), and both
engines reach it (classic `ChatService` → path; agent `ReferenceFactTool` → path, and Round 1a via the same
tool: `ReferenceFactTool.php:88`, `AgentChatService.php:433`). Downstream readers of the result:
`ReferenceFactPath.php:108,121,157`, `EscalationExplainer.php:331-333`, `TracePanel.tsx:65-74`.

### 1.4 R1 — grouped employee with both a group fact and a convenio-wide fact on one topic

Today, and after 13d (the set logic is *inside* a tier, never across):

| Employee state | Result | Evidence |
|---|---|---|
| has a job category with a matching fact | category fact only; group and wide never consulted | `:112-118`; `test_job_category_fact_is_most_specific_and_wins_over_group_and_wide`, `Sprint7cReferenceFactAnswerTest.php:204` |
| approved node **and** a fact bound to that node | **group fact wins outright**; the wide fact is never selected, never composed (`:139-141` returns before `:146`) | same test; 7f tests (a)(c)(d) `:232,267,280` |
| approved node, facts bound to *other* nodes only, plus a wide fact | wide fact answers (`matchByGroupNode` returns empty, no escalate → `:146`) | `:274-337`; `test_h…` `:352` |
| node proves scope indeterminate (sub-area / split parent) | escalate; **does not fall through to the wide fact** | `:132-137`; `test_an_indeterminate_group_does_not_fall_through…` `:393` |
| node rejected / missing | as if no node → wide answers | `:280-283`; `test_a_rejected_node_lands_where_a_null_node_lands` `:411` |

**Answer to the spec's R1 question: group wins; nothing composes across tiers — and 13d keeps exactly that.**

Honest note on the requested reference: the **wt-01 / wt-05 "pass-with-caveat" rows** (Sprint 13 review
`:781-784`, table `:813`) are convenio 13 with **one** convenio-wide vacaciones fact (id 124) and an
employee with no group/category. They pin the **Tier-3 single-fact** behaviour — answered with the fact's
figures *plus a caveat* — and the fact that a convenio-wide answer for an employee whose group is unknown is
the accepted, caveated outcome. They contain no group fact, so they cannot pin group-vs-wide; that is pinned
by the 7c/7f tests above. 13d leaves the single-fact branch byte-identical (§3.2), so those rows are unaffected.
It matters in one more way: after 13d an ungrouped convenio-20 employee gets the wide pair (140+143) even
though group facts 141/142 ("Técnicos de …", unbound) exist — the same "convenio-wide answer, group unknown"
posture as wt-01/05, with fact 140's own words carrying the exception ("salvo para los Técnicos…").

---

## 2. The logical key (R3)

### 2.1 The key

* Declared: `ReferenceFact::LOGICAL_KEY = [convenio_id, topic_id, job_category_id, group_label, validity_start, validity_end]` (`ReferenceFact.php:48`).
* 7b-2/10c writer (`ReferenceFactProposalService::persist`, `:304`) upserts on that tuple **plus** `source='ai_agent'` and `source_document_id` (`:360-369`), null-safe (`findByLogicalKey`, `:439-447`). Inside one `persist()` call a second fact with the same tuple *overwrites* the first (the ADR-0034 Bug A).
* Separately, `findScopeDuplicate` (`:455-476`) flags a *different* fact with the same scope identity
  (convenio/topic/category/normalised group) and a differing value as `duplicate_of_id` — a "probable version" signal for 7d.

### 2.2 Staging (read-only, 2026-09-30, Appendix A)

* **Every verified fact carries a complete key.** 132 verified (131 `ai_agent`, 1 `admin_manual`): `convenio_id` null 0, `topic_id` null 0, `source_document_id` null 0. The nullable components are legitimately null: category set on 3, group label on 87, `validity_start` null on 2 (ids 144, 147), `validity_end` null on 50. R3's "fallback for facts without a key" therefore has **nothing to list** — but see 2.3.
* 102 verified facts are in validity today; 45 are convenio-wide; exactly one convenio-wide (convenio, topic) has ≥ 2 facts: (20, jornada) = {140, 143}, both starting `2025-01-01`. No job-category-tier or group-tier cohort exists (group bindings exist only for convenio 21, whose topic-1 facts are all group-labelled and none wide). I re-verified the ticket on 102 in-validity facts and on the single shared convenio-wide pair; the 59 and 45 counts are taken from the ticket, not re-derived.
* **Same-key collisions across all 154 rows: exactly one — {140, 143}.**

### 2.3 How 140/143 came to share a key (hypothesis, flagged as such)

Both rows were created in one batch (`proposal_batch_id 95f04bb3…`, `2026-09-14 00:50:40`), so `persist()` could
not have produced two rows with an identical key (the second would have upserted onto the first). Fact 140
then received an admin edit on 2026-09-15 00:32:59 touching `group_label` and `value` ("AI-assisted triage,
batch-applied"), and its own `uncertainty` still says the model had to *infer* the group. Most likely the
model gave 140 a non-null group label and the triage edit nulled it, **creating** the collision after
`persist()`'s guard. The `tag_events` row logs `old_value = null` for that edit, so I cannot prove it.
Consequence either way: **the collision is producible by a human edit, which `persist()` cannot prevent** —
the audit (§6) must be re-run after triage batches, not only after ingestion.

### 2.4 Fallback (R3) if the key cannot discriminate — which is the case that matters

Not "keyless facts", but "keyed identically". The fallback is the rule in §3 (quantity keys from
`raw_values`), and for any pair the rule cannot prove complementary the behaviour is **today's**: escalate.

---

## 3. The complementary / contradictory rule, as code

### 3.1 Recommended rule (R-Q): same *quantity*, not same key

Idea: two facts are about different things iff the **named quantities in their `raw_values` are disjoint**.
`raw_values` is the structured breakdown already stored on every fact and already human-verified as part of
the fact (ADR-0034 §1: "complete per-motivo desglose lives inside that single fact's `value`/`raw_values`").
Fact 140: keys `{2025, 2026, 2027, 2028}`. Fact 143: `{jornada_irregular, computo_tiempo_trabajo,
dias_libre_disposicion, descanso_jornada_continuada}` (staging, A.4). Disjoint ⇒ complementary.

This is the roadmap's fix option (b) "scope the conflict rule to facts stating the same quantity" with the
"per-fact quantity key" *derived from data that already exists* instead of a new column (`roadmap.md:314`).
It is not the ADR-0034 §3 rejected alternative: nothing is written; ADR-0034 rejected a model label as an
*upsert identity* (unstable across runs → silent duplication). Here the keys are read once from rows a human
already verified, and only to decide "compose vs escalate", never to match or overwrite.

`FactSetClassifier` (new, pure, `app/Support/`; shared by the service and the audit so they cannot drift):

```php
// A tie cohort = verified, in-validity facts in ONE tier sharing the top validity_start,
// with >= 2 distinct `value`s (i.e. exactly what selectMostRecent() calls ambiguous_conflict today).
final class FactSetClassifier
{
    public const COMPLEMENTARY = 'complementary';
    public const CONTRADICTORY = 'contradictory';

    /** Quantity keys of a fact, or null when it cannot vouch for any (=> fail toward escalation). */
    public static function quantityKeys(ReferenceFact $f): ?array
    {
        $raw = $f->raw_values;
        if (! is_array($raw) || $raw === [] || array_is_list($raw)) {
            return null;                                   // null / [] / JSON list: no named quantities
        }
        $keys = [];
        foreach (array_keys($raw) as $k) {
            $keys[self::normalise((string) $k)] = true;    // lowercase, strip accents, non-alnum -> "_"
        }
        return array_keys($keys);
    }

    /** @return array{relation:string, reason:string, shared_keys:list<string>} */
    public static function relate(ReferenceFact $a, ReferenceFact $b): array
    {
        if (self::flaggedAsVersionsUnresolved($a, $b)) {   // a.duplicate_of_id==b.id or reverse, and resolution IS NULL
            return ['relation' => self::CONTRADICTORY, 'reason' => 'flagged_duplicate_unresolved', 'shared_keys' => []];
        }
        $ka = self::quantityKeys($a); $kb = self::quantityKeys($b);
        if ($ka === null || $kb === null) {
            return ['relation' => self::CONTRADICTORY, 'reason' => 'no_quantity_keys', 'shared_keys' => []];
        }
        $shared = array_values(array_intersect($ka, $kb));
        return $shared === []
            ? ['relation' => self::COMPLEMENTARY, 'reason' => 'disjoint_quantity_keys', 'shared_keys' => []]
            : ['relation' => self::CONTRADICTORY, 'reason' => 'same_quantity', 'shared_keys' => $shared];
    }
}
```

Set-level: a cohort is a **complementary set only if every pair is complementary**. One contradictory pair
⇒ the *whole cohort* escalates with that pair listed (never a subset, never "the compatible ones"). Identical
`value`s within a cohort are collapsed to their lowest id before classifying. Why each fail-safe exists:

* `raw_values` null / empty / list → cannot vouch → contradictory. **2 of 102 in-validity verified facts have
  null `raw_values`**; they can never enter a set (A.2).
* Any *shared* quantity key (after normalisation) with different values → same quantity, different value → contradictory. Two year-keyed schedules that both contain `2025` collide, correctly.
* A pair that 7b-2/7d already flagged as probable versions and no human has resolved (`duplicate_of_id`
  set between them, `resolution IS NULL`) → contradictory even if the keys look disjoint. `coexists` does
  **not** unlock composition: `FactResolutionService::coexist`'s own docblock says such a pair "will still
  escalate … that is correct" (`:128-138`), and this slice does not redefine that.

Residual risk, stated: a pair about the **same** quantity under **different** key names
(`horas_anuales` vs `jornada_maxima_anual`) would classify as complementary and be composed, so the answer
would show two figures, each verbatim and each cited (visible, not silent). Mitigations: each fact was
independently human-verified; the audit lists every complementary pair with both key sets for a human look
after each ingestion/triage; Option B (§3.5) removes the risk at the cost of a manual step.

### 3.2 Where it sits — the legacy function stays byte-for-byte

```php
// ReferenceFactAnswerService (new): selectMostRecent() is NOT edited (lines 216-237 stay identical).
private function selectFactSet($tier): array   // -> [list<ReferenceFact>|null, string $selection, array $meta]
{
    [$fact, $selection] = $this->selectMostRecent($tier);          // legacy, first, always
    if ($fact !== null)                       return [[$fact], $selection, []];   // single | most_recent_validity
    // Only here did legacy say 'ambiguous_conflict'. Nothing else reaches the new code.
    $cohort = /* facts at top validity_start, one per distinct value, ordered by id */;
    $verdict = FactSetClassifier::classifySet($cohort);            // pairwise, all-or-nothing
    if ($verdict['composition'] === 'contradictory') return [null, 'ambiguous_conflict', $verdict];   // as today + pair listed
    return [$this->orderAndCap($cohort), 'same_validity_complementary', $verdict];
}
```

`resolveAndAnswer` gains one branch: a list of ≥ 2 facts → set answer (§4); a list of 1 → the existing code,
unchanged. This is the *structural* half of "nothing else moves": the new code is reachable only for inputs
on which the old code returned `ambiguous_conflict`.

### 3.3 Supersession and recency (unchanged, tested)

* Older fact closed by a supersede → its `validity_end` is before as-of → not a candidate → 1 candidate →
  `single`, no set, no `fact_set` in the trace. Test S5.
* Different `validity_start` (both open) → legacy `most_recent_validity`, regardless of quantity. Test S5b.
  This is the one place a fact can be dropped by recency although it is "about something else"; the audit
  reports it as `recency_shadowed` (informational; **0 on staging today**) so it is visible, not fixed.

### 3.4 Tests for every branch (full inventory in §9.2)

| Branch | Test |
|---|---|
| disjoint keys, same start ⇒ set | S2 (real 140/143 `raw_values`), U1 |
| overlapping key, different value ⇒ conflict, pair listed | S4, U2 |
| null / list / empty `raw_values` ⇒ conflict (existing `:429` test unchanged and green) | S3, U3 |
| unresolved duplicate flag ⇒ conflict despite disjoint keys | U4 |
| 3 facts, one bad pair ⇒ whole cohort conflict | U5 |
| supersession (older closed) ⇒ single | S5 |
| recency (different starts) ⇒ legacy | S5b |
| Tier 2: two facts bound to one node, different *labels*, overlapping keys ⇒ conflict (the spec's key rule would have composed) | S8 |
| identical values ⇒ legacy `most_recent_validity` | S9 |
| differential vs a verbatim copy of the legacy function over generated tiers | S1 |

### 3.5 Alternatives, for the decision (Q1)

| | Rule | 140/143 | Cost / risk |
|---|---|---|---|
| **A — R-Q (recommended)** | disjoint `raw_values` keys, fail-safe elsewhere | composes ✔ | heuristic on model-authored key names; residual false-complementary risk above; no schema, no manual step |
| B — human-declared | compose only when a human marked the pair coexisting (`resolution='coexists'`) | escalates until HR acts | zero heuristic, but (1) `coexist()` requires a `duplicate_of_id` pair and 140/143 are unflagged, so **"Resolver versión" cannot reach them** (`ReferenceFactController.php:353-357`); (2) redefines `coexists` (ADR-0024 says the pair still escalates); (3) a data write on staging; (4) every future pair waits on HR |
| C — spec literal (key difference) | different key ⇒ complementary | **contradictory** — slice achieves nothing; unsafe in Tiers 1–2 | not viable |
| D — data fix | HR merges 140+143 into one bundled fact (ADR-0034's intended shape: one fact per document/topic/scope) | fixed by data | roadmap option (a); not a code slice, and does not cover the class |

I recommend A with the audit in the loop, and B recorded as a possible stricter follow-up.

---

## 4. Composition of a set

### 4.1 What changes in the service result

`ReferenceFactAnswerService::answer()` returns, for a set only, an extra top-level key `facts`
(ordered list of `{id, uuid, value, raw_values, citation}`) next to the existing keys; `reference_fact.fact_id`
/ `fact_uuid` / `value` keep meaning **the primary (first) fact** so every existing reader
(`EscalationExplainer.php:556,677`, `ReferenceFactPath.php:108,157`) keeps working. Single-fact results have no
`facts` key — same array as today.

### 4.2 Ordering (R2)

Deterministic and independent of DB row order (the candidate query is unordered and stays so):

1. number of distinct `(unit, figure)` pairs found in `value` by the existing `extractFiguresByUnit`
   (`ReferenceFactPath.php:419`), **descending** — "figure-bearing first";
2. shorter `value` first;
3. lower `id`.

For the convenio 20 case: 140 → `hora{1704,1700,1696,1692}` = 4; 143 → `hora{6}`, `dia{2}` = 2 ⇒ **140, then 143** —
annual figure first, rules second, as the spec proposes. Honest scope of the rule: ordering fixes (a) the
source order handed to synthesis, (b) which facts survive the cap, (c) the Phase-1 quote order. It cannot
control the order in which the model writes its answer.

**Cap = 3.** Classification runs over *all* members first (a contradictory 4th escalates the cohort). If the
set is complementary and has > 3 members, the first 3 are used and the rest are recorded in the trace
(`facts_omitted`), not mentioned in the answer. The audit flags such cohorts `over_cap` so HR can bundle them
(ADR-0034). Rationale for 3: each fact's `value` is quoted verbatim (143 alone is 851 characters), and
each adds a citation the employee must read; 3 keeps the synthesis source block and the citation list short.

### 4.3 Phase 1 (bare quote — no answer model, or no governing prose clears Check A)

Today `composeAnswer()` quotes one fact (`ReferenceFactAnswerService.php:395-403`). For a set, a sibling
`composeSetAnswer()` quotes each fact in order, numbered, each with its own `raw_values` breakdown (same
`renderRawValues`, same whole-entry cap), one trailing sentence as today. Citations = each fact's
`referenceFactCitation()` (`:454`), in order — two entries with `chunk_id=null`, `is_reference_fact=true`, different
`snippet` locators ("p9" / "p10"). `/ground` is skipped exactly as for a single quote (nothing generated).

### 4.4 Phase 2 (composition with governing convenio prose) — `ReferenceFactPath::composeFactWithProse`

Today it takes one fact: `$factCitation = $factResult['citations'][0]`, `$factValue = …['value']`
(`ReferenceFactPath.php:156-157`), one `$factSource` (`:237-245`), a single-fact Check B (`:302-309`), one
grounding source (`:462-476`), one citation (`:504-521`). Generalised to an ordered list; **the
one-element list builds byte-identical arrays** (test P6 + golden 13):

| Step | Set behaviour |
|---|---|
| Fact-vs-prose conflict | `detectFactProseConflict` runs **per fact** over the same governing chunks; any conflict ⇒ escalate `conflict` before synthesis, exactly as today (fail toward caution) |
| Sources to `/synthesise` | governing convenio chunks (authority order), then **one `reference_fact` source per fact, in set order**, inserted before the `national_law` block as today; each `content` is that fact's `value` verbatim, `score 1.0`, `structured_reference`. **Only in the set case** each source also carries `fact_id` |
| hr-ai citation identity | **required change** — see below |
| Check B | a fact-type citation is valid iff its `fact_id` ∈ the offered set (hallucinated / missing id ⇒ dropped, fail closed) |
| `/ground` | each cited fact contributes its **own** value as a `reference_fact` source (`GroundingService.php:38-39` already carries `chunk_id=null`+`source_type`; `/ground` has no de-dup) — entailment is per claim against the fact that was cited |
| Citations out | in the model's citation order, each fact resolved to *its* `referenceFactCitation` (the marker↔list 1:1 rule of 7c stays true) |

**Required hr-ai change (additive, ~6 lines + a script test).** `synthesise()` keys a null-`chunk_id` source
by `(source_type, document_id)` (`claude.py:1490`). Facts 140 and 143 are both document 50 ⇒ same key ⇒ the
second citation reuses the first's display number, the answer's two markers point at one list entry, and the
backend cannot tell which fact was cited. Fix: optional `fact_id: int | None = None` on `SynthesisChunk`
(`main.py:176-190`) and `ChunkInput` (`base.py:26-39`); key becomes `(source_type, document_id, fact_id)`;
the returned citation dict includes `fact_id` **only when not None**. Absent ⇒ identical key, identical
output — a prose turn and a single-fact composition send and receive exactly what they do today. No prompt text
changes (`SYSTEM_PROMPT` and `_build_user_prompt` are untouched; the fact labels already render as
"dato de referencia estructurado del convenio (verificado)", `claude.py:132-141`). Rejected alternatives:
collapse the set into one source (breaks "each fact its own citation" and 1:1 markers); smuggle a negative
`chunk_id` (abuses ADR-0006's "a fact is never a chunk"). Needs approval — Q2.

### 4.5 Synthesis input for the convenio 20 case

Order handed to `/synthesise` for "¿Cuál es mi jornada máxima anual?" (employee `test-deporte-navarra@example.com`,
convenio 20, no group/category; governing prose expected to include chunk 3558, doc 50 p9–10):

```
Pregunta: ¿Cuál es mi jornada máxima anual?
FUENTES disponibles:
[Fuente 1] (convenio (gobierna su materia), p. 9–10): …<chunk 3558>…
   … further governing chunks by authority/score …
[Fuente k]   (dato de referencia estructurado del convenio (verificado)): Con carácter general, salvo para los Técnicos de Actividad deportiva y los Técnicos de Sala, que tienen jornada propia: Jornada anual a tiempo completo con carácter general (art. 84.2 y 34 ET): Año 2025: 1704 horas de trabajo efectivo; Año 2026: 1700 horas; Año 2027: 1696 horas; Año 2028: 1692 horas.        <- fact 140, fact_id=140
[Fuente k+1] (dato de referencia estructurado del convenio (verificado)): Reglas generales de jornada aplicables a todo el personal, con independencia del grupo: el tiempo de trabajo se computará de forma que al inicio y al final de la jornada diaria el trabajador se encuentre en su puesto de trabajo; en jornadas diarias continuadas de más de 6 horas se establece un descanso de 15 minutos, que no tiene la consideración de tiempo de trabajo efectivo, cuyos turnos se acuerdan entre la empresa y los trabajadores según las necesidades del servicio; cada trabajador podrá disfrutar, en cada año de vigencia del convenio, de 2 días de libre disposición de carácter no recuperable (mismo criterio de disfrute que el punto 3 del art. 25, resolviendo concurrencias por orden de prestación y, en su caso, por antigüedad); y, de conformidad con el art. 34.2 ET, se acuerda que la jornada se distribuirá de modo irregular en un 0%.   <- fact 143, fact_id=143
[Fuente m]   (ley nacional / Estatuto (base mínima)): …
```

(The two fact `content` strings are the staging `value`s verbatim, A.4; the `fact_id` is not rendered into the
model prompt — it only rides the request so the returned citations stay distinguishable.)

### 4.6 What "citing both" can and cannot mean (Q3)

Synthesis rule 2 says every substantive claim carries the citation of the source that states it
(`claude.py:66-75`); the model will cite 143 only if its answer uses 143. For an annual-hours question
it may legitimately cite 140 alone, and for a jornada-overview question both. The route can guarantee:
both facts are **offered**, each is **distinguishably citable**, only offered facts can be cited (Check B),
and every cited fact is grounded. It cannot — and must not (fabricating a citation for a claim not made
would defeat the grounding gate) — force a citation. Proposed restatement of criterion 1, §8.1.

### 4.7 Persistence and display

Persisted citations are `(message_id, document_id, chunk_id, page_number)` with no unique constraint
(`TurnPersister.php:111-117`, migration `2026_06_20_131019`), so two fact citations on document 50 store as
two rows. The live list shows both with their locators (`CitationList.tsx:52-66` keys on index).
Reloaded history already drops the reference-fact badge/snippet for a *single* fact (`ConversationPresenter.php:185-198`
rebuilds from rows) — pre-existing, unchanged, noted so a reviewer is not surprised by two identical
"convenio" rows after a reload.

---

## 5. Trace fields

### 5.1 Constraints found

* Adding a key to *every* `reference_fact` block breaks byte-identity of the four fact-route goldens
  (`12_reference_fact_p1`, `13_reference_fact_p2`, `14_reference_fact_conflict`, `15_reference_fact_gap_tier4` all serialise
  the whole block, verified by reading the fixtures).
* `trace.composition` is taken (fact+prose merge: `detected`, `governing_on_topic_chunks`, `check_a`, `conflict`
  — `ReferenceFactPath.php:204-209`, `TracePanel.tsx:76-88`, `api.ts:643-652`). The spec's
  `composition: complementary|single|conflict` cannot share that name.

### 5.2 Proposal (deviation from the spec's names — Q4)

Emitted **only when a tie cohort with ≥ 2 distinct values was evaluated**; a single fact / recency pick adds
nothing (so `single` is expressed by absence and by the existing `validity_selection: single`).

```jsonc
"reference_fact": {
  "...existing keys unchanged...": "…",
  "fact_id": 140,                        // primary fact (unchanged meaning)
  "validity_selection": "same_validity_complementary",   // new value; 'ambiguous_conflict' unchanged for conflicts
  "fact_set": {
    "composition": "complementary",      // | "conflict"   (the spec's `composition`, nested to avoid trace.composition)
    "facts_selected": [140, 143],        // ordered; on conflict: the cohort ids
    "facts_omitted": [],                 // over-cap remainder
    "order_rule": "figures_desc,length_asc,id_asc",
    "pairs": [ { "a": 140, "b": 143, "relation": "complementary", "reason": "disjoint_quantity_keys", "shared_keys": [] } ]
  }
}
// composition-turn block gets (set case only):  "composition": { …existing…, "fact_ids_offered": [140,143], "fact_ids_cited": [140,143] }
```

Nothing else in any trace moves. The escalation explainer is intentionally **not** edited (its frozen strings
are under test): the conflict card's `found` text stays as is, and `fact_set.pairs` gives HR the pair.
(The spec calls the sub-outcome `version`; in code it is `same_validity_conflict`, `EscalationExplainer.php:331-333`
and the two dictionaries' `reference_fact_coverage_gap.same_validity_conflict` — unchanged.)

### 5.3 Rendering (both dictionaries)

`TracePanel.tsx:65-74` builds the "Dato de referencia" step from `rf.match_kind`, `rf.validity_selection`, etc.
A small pure helper `factSetMeta(t, rf)` (unit-testable, like `agentTrace.ts`) appends:

* complementary: ` · datos #140, #143 (complementarios)` / ` · facts #140, #143 (complementary)`, plus ` · +N omitidos` when capped;
* conflict: ` · CONFLICTO datos #a, #b (mismo alcance)` / ` · CONFLICT facts #a, #b (same quantity)`, reason from `pairs[].reason`;
* composition step (`:76-88`): ` · N datos ofrecidos, M citados`.

New keys under `tracePanel` in `es.ts` (next to `referenceFactLabel` `:1565`, `validityPrefix` `:1583`) and `en.ts`
(`:1384`, `:1402`): `factSetPrefix`, `factSetComplementary`, `factSetConflict`, `factSetOmitted`,
`factSetSharedKeys`, `compositionFactsOffered`. `api.ts:627-651` types gain the optional fields. Tests: vitest
on the helper in **both** dictionaries, the existing i18n parity/`noHardcodedStrings` tests, `tsc -b`.

---

## 6. `facts:same-topic-audit`

Read-only artisan command (`app/Console/Commands/FactsSameTopicAudit.php`), same style as `facts:scan-duplicates`
(`--json`, `--convenio=`, `--as-of=` defaulting to today). It reuses `FactSetClassifier` and the service's
tier definitions, so it reports what the route will do.

**Buckets (the "scope" of "(scope, topic)")** — one per (convenio, topic, tier-key): wide (`null` category and
label); each `job_category_id`; each approved bound node (a fact bound to two nodes appears in both). Facts
group-labelled but **unbound** are Tier-4 material that no employee can ever be answered from; they are
counted (`unbound_group_labelled_excluded`), not classified.

Per bucket with ≥ 2 candidates at the as-of date, one row:

```
convenio  topic  tier            top_validity_start  fact_ids     class            reason                  shared_keys  over_cap  dup_flagged
20        2      convenio_wide   2025-01-01          140,143      complementary    disjoint_quantity_keys  -            no        no
```

`class` ∈ `complementary` | `contradictory` | `identical_value` (legacy-fine duplicates) | `recency_shadowed`
(older still-valid fact dropped by the recency rule; informational). Summary line:
`N convenio×topic pairs with a verified in-validity fact; V verified in-validity facts; W convenio-wide; cohorts:
complementary=…, contradictory=…, recency_shadowed=…, identical_value=…`. `--json` mirrors it. `dup_flagged=no` on a
contradictory row prints the hint that "Resolver versión" needs a `duplicate_of_id` (§10 Q8).

**Expected on staging (my dry classification of Appendix A data; 59 / 45 as per the ticket): 59 pairs / 102 / 45; complementary = 1 (20·jornada·{140,143}),
contradictory = 0, recency_shadowed = 0, over_cap = 0** — i.e. exactly the ticket's figures (criterion 7). Under
the spec-literal rule the same command would print contradictory = 1.

Tests A1–A4 (§9.2): classification on seeded fixtures; the 13b-figures fixture; `--json` shape; no write
statements issued (DB query log asserts `SELECT` only).

---

## 7. Golden traces

### 7.1 Which of the 22 touch the fact route

`Sprint13GoldenTraceTest.php`: **12** `12_reference_fact_p1` (Phase-1 quote, `:266-291`), **13** `13_reference_fact_p2`
(composition, `:297-347`), **14** `14_reference_fact_conflict` (fact-vs-prose conflict, `:352-393`), **15**
`15_reference_fact_gap_tier4` (`:400-424`). All four use a single fact (or none applicable) ⇒ `count === 1` /
no candidate ⇒ legacy branches. Fixtures 01–11 and 16–22 do not reach `ReferenceFactAnswerService`.

**Why they stay byte-identical (three independent reasons):** (1) `selectMostRecent` is unedited and runs first;
(2) new trace keys exist only inside the set/conflict branches (§5); (3) the one-element list in
`composeFactWithProse` builds the same arrays and the synthesis request has no `fact_id` (P6). Verified by
running the 22 unchanged, plus the differential test S1.

### 7.2 New fixtures (≥ 2), recorded on the current code first

The harness writes a fixture on first run and fails until re-run (`assertGoldenTrace`, class docblock `:52`).

| Fixture | Scenario | On current code (recorded first) | After the change |
|---|---|---|---|
| `23_reference_fact_set_complementary` | two convenio-wide verified facts, same start, 140/143-shaped `raw_values`, governing chunk, scripted synthesise citing both + ground OK | `escalate` `reference_fact_coverage_gap`, `validity_selection: ambiguous_conflict` | `answer`, path `reference_fact_composition`, 2 citations, `fact_set` block, `composition.fact_ids_offered/cited` — **the diff is the deliverable** |
| `24_reference_fact_set_contradictory` | same start, **overlapping** quantity key, different values | `escalate` gap, `ambiguous_conflict` | `escalate` gap, `ambiguous_conflict`, **plus** `fact_set{composition:conflict,pairs:[…]}` only — outcome/answer/citations unchanged |
| `25_reference_fact_set_phase1` (optional) | same as 23 with no answer model (bare quote, exploding AI) | escalate | `answer`, path `reference_fact`, both values quoted, 2 citations |

Order of work: add the tests, run them against the **unmodified** tree (fixtures = "before"), commit the
"before" fixtures separately, then the code change makes 23/25 fail loudly until deliberately re-recorded —
so the diff is reviewable in git.

---

## 8. Gate plan (spec §5), both engines, spend, single CP-1

Every seeded-data step below runs **inside a rolled-back transaction** (the 13b precedent:
`inject-negatives-staging.php:10-26`; `answer:gate` already rolls each case back unless `--persist`,
`AnswerGate.php:192-207`); no verified fact is ever committed to staging. All bank files are frozen with a
`MANIFEST.sha256` like 13b's; each stage uses `--budget-usd`.

### 8.0 Before any spend (free)

* Full suites + goldens (22 unchanged + new), hr-ai script, vitest, `tsc -b`, pint.
* **Blast-radius replay on staging data** (read-only, rolled back, no model): run legacy `answer()` (a verbatim
  copy in the probe) vs new `answer()` for every (convenio × topic) with a verified in-validity fact × employee
  archetype (no scope; each category; each approved node) and diff `outcome / fact_id / value / citations /
  reference_fact`. **Expected: exactly one differing combination — (20, jornada, convenio-wide)** — and zero
  elsewhere. This is the empirical "nothing else moves" proof; S1 is the algorithmic one.
* Retrieval-side check for the fact-vs-prose figure guard (real risk R4): on staging convenio 20, chunk 3558
  (p9–10, jornada) contains the figures of **both** facts (`hora`: 1704…1692 **and 6**; `dia`: 2) (A.5), so a
  retrieval that returns it cannot trip `conflict` for either fact; if retrieval instead returns only p9
  chunks (e.g. 3552 has `hora {22,00; 6,00}`), fact 143's `6 horas` could look like a same-unit disagreement.
  That is the pre-existing guard's behaviour (it would equally hit 143 alone) and I do **not** propose changing
  it (classic-wide). S1's ×3 runs measure it; if it fires, it is reported, not tuned away (Q3).

### 8.1 Stages

| # | Acceptance (spec §5) | Set / method | Engines × repeats | Pass rule |
|---|---|---|---|---|
| **S1** | 1. convenio 20 / jornada | new frozen bank `sprint-13d/eval/c20-jornada.json`: 5 canonical variants (annual hours = `fr-c20-jornada-493db`'s canonical, overview, descanso, libre disposición, jornada irregular) + 2 colloquial (`fr-c20-jornada-493db`'s, `ho-jor-04`) for employee `test-deporte-navarra@example.com` | agent ×3 on all 7; classic ×3 on the canonical ones, ×1 on the colloquial ones (classic colloquial-unanchored is 0 % by 13b contract, recorded not gated) | every canonical + ho-jor-04 answers via `reference_fact[_composition]`; `facts_selected=[140,143]`; ≥ 1 cited fact and every cited source ∈ offered set; on the **overview** question both cited (reported for the others) |
| **S2** | 2. contradiction escalates; 3. precedence | seeded in a rolled-back txn on unused topic/convenio (chosen at build by query, likely festivos): (a) two same-quantity facts, different values → escalate `reference_fact_coverage_gap`/`same_validity_conflict`; (b) null-`raw_values` pair → escalate; (c) group fact + complementary wide pair, employee on that node → group fact only; (d) same, employee ungrouped → the wide pair; (e) indeterminate node + wide pair → escalate | agent + classic ×3 | (a)(b)(e) never answer; (c) no `fact_set`, group value only; (d) both wide facts |
| **S3** | 4. fact-routing, all classes, no regression | the existing 93-case set (canonical + colloquial, 186 rows) | agent ×1 on all 186; classic ×1 on the 93 canonical | no case that passed at 13b S4 fails; `fr-c20-jornada-493db` and `ho-jor-04` flip fail→pass; the two `fact_group_labelled_unbound` c20 cases (`-942ab`, `-b7bd5`) still pass (`any_of` includes answer) |
| **S4** | 5. whitelist-temptation | 16 cases | agent ×1, classic ×1 | 0 hard |
| **S5** | 6. goldens | done in 8.0 | — | 22/22 byte-identical + ≥ 2 new recorded |
| **S6** | 7. audit | `facts:same-topic-audit` on staging | — | 59 / 102 / 45; complementary 1; contradictory 0 |
| **CP-1** | single checkpoint | live persisted turns as `test-deporte-navarra@example.com`, both engines, the jornada overview + annual-hours questions; Historial trace panel opened | agent ×1 + classic ×1 each | **Pedram reads:** do the two facts read as one coherent answer, each cited? |

Departures from the spec's wording, for approval (Q5): S3 skips **classic colloquial** (0 % fact-route by the 13b
contract, 353/359 rows already agree across engines, and the replay in 8.0 proves fact *selection* is identical);
S1 restates "citing both" as above.

### 8.2 Spend estimate (≤ $15) — list price, assumptions stated

Measured 13b per-turn costs: agent canonical $0.037, existing colloquial-unanchored $0.049, anchored-colloquial
control $0.055, composed jornada turns likely at the top of these; whitelist $0.023. **Classic per-turn is not
measured anywhere in the repo docs**; I assume $0.02 (no planner round; synthesise + ground only) — the first
stage reports the real figure.

| Stage | Turns | Basis | Est. |
|---|---|---|---|
| S1 | agent 21, classic 17 | $0.06 / $0.02 | $1.6 |
| S2 | agent 15+, classic 15+ (mostly escalations, no synthesis) | $0.03 / $0.01 | $0.6 |
| S3 agent | 186 | 13b S4 measured: $3.43 + $3.75 + $0.93 | $8.1 |
| S3 classic canonical | 93 | $0.02 | $1.9 |
| S4 | agent 16, classic 16 | $0.023 / $0.02 | $0.7 |
| CP-1 | ~6 | | $0.4 |
| Live smoke after deploy | 2 | | $0.1 |
| **Total** | | | **≈ $13.4** (headroom ≈ $1.6) |

Levers if classic runs dearer than assumed: drop the 17-row anchored-colloquial control (−$0.9), or run S3 agent
on canonical + existing-76 only. Each stage refuses to start if its projection exceeds its `--budget-usd`
(`AnswerGate.php:68`); a hard stop at $15 total. **The estimate only holds if the hr-ai and hr-backend deploy is
a single one** — a second redeploy adds no model spend but does add a CP delay.

---

## 9. Build order, tests, deployment

### 9.1 Ordered build steps (each ends green before the next)

0. **Authorisation needed first:** WIP commits on branch `sprint-13d` in the repos touched (the staging deploy clones pinned
   SHAs from GitHub, so a working tree cannot be deployed — the 13b deviation, squashed at merge). Q6.
1. Tests for S1–S10, U1–U9, P1–P6 written **red**; goldens 23/24/(25) recorded on the unmodified tree, "before" fixtures committed alone.
2. `FactSetClassifier` (pure) → unit tests green.
3. `ReferenceFactAnswerService`: `selectFactSet`, set branch in `resolveAndAnswer`, `composeSetAnswer`, `facts` result key, trace block; `selectMostRecent` untouched (diff must show zero hunks in `:216-237`). Service tests + differential S1 green; the 22 goldens byte-identical.
4. `ReferenceFactPath`: list-generalised composition; P1–P6 green; golden 13 identical.
5. hr-ai: optional `fact_id`; `scripts/synthesis_factset_contract_test.py` in the style of `planner_contract_test.py` (fake client): two same-document fact sources → 2 citations with `fact_id`; same `fact_id` twice → 1; no `fact_id` → legacy key and no `fact_id` in output.
6. `facts:same-topic-audit` + tests.
7. Frontend: `factSetMeta`, dictionaries (es/en), `api.ts` types, vitest, `tsc -b`.
8. Docs: **ADR-0037** (quantity-key rule, why the logical key cannot discriminate, the residual risk, the hr-ai `fact_id`), addenda pointing from ADR-0023/0024/0034, `data-model.md` trace fields, `roadmap.md:314` ticket status + the new tickets (Q8), the audit runbook line in `deploy.md` ("re-run after each ingestion **and triage** batch").
9. Free checks (8.0): full suite, replay probe, offline retrieval check.
10. Freeze the 13d banks (`MANIFEST.sha256`); deploy hr-backend + hr-ai + hr-frontend (+ docs) to staging; re-apply the box-only fixed OTP as in 13b's notes; 22-golden verify on the deployed image (`ops/golden-verify.sh`).
11. Gate S1→S4 (stop on a hard violation), S6, then CP-1; close-out (review.md, merge, deploy, snapshot).

### 9.2 Test inventory

**Unit (`FactSetClassifier`, `FactSetOrdering`)** — U1 disjoint real 140/143 `raw_values` ⇒ complementary; U2 shared key ⇒ contradictory + shared_keys; U3 null/`[]`/JSON-list `raw_values` ⇒ contradictory; U4 unresolved mutual `duplicate_of_id` ⇒ contradictory despite disjoint keys (and a *resolved* one is judged on keys); U5 3 facts with one bad pair ⇒ whole-cohort conflict naming that pair; U6 key normalisation (accents/case/underscores); U7 ordering deterministic under shuffled input (figures desc, length asc, id asc); U8 cap: 4 complementary ⇒ 3 + `facts_omitted`, and a contradictory 4th ⇒ conflict; U9 year-keyed schedules that both contain `2025` ⇒ contradictory.

**Service (`ReferenceFactAnswerService`, RefreshDatabase)** — S1 **differential**: a verbatim copy of the legacy `selectMostRecent` as oracle over ≥ 500 generated tiers (sizes 1–6, random starts incl. null, values, `raw_values` shapes): whenever the oracle does not return `ambiguous_conflict`, the new selection equals it (fact + selection string); when it does, the new result is `ambiguous_conflict` or a complementary set; S2 tier-3 pair ⇒ answer with `facts`, `fact_set.composition=complementary`, primary = 140-shaped; S3 same start, null `raw_values` ⇒ `ambiguous_conflict` (existing `Sprint7cReferenceFactAnswerTest.php:429` unchanged); S4 overlapping key ⇒ escalate + pair listed; S5 older fact closed by `FactResolutionService::supersede` ⇒ `single`, no `fact_set`; S5b different starts ⇒ `most_recent_validity`; S6 R1 matrix (group fact + wide complementary pair ⇒ group only; ungrouped ⇒ pair; indeterminate node ⇒ escalate, no fall-through; rejected node ⇒ pair); S7 Tier 1 category pair ⇒ set with `match_kind=job_category`; S8 Tier 2 two facts bound to one node with different labels, overlapping keys ⇒ conflict; S9 identical values ⇒ `most_recent_validity`; S10 `needs_review`/expired/future members never enter a cohort.

**Path (`ReferenceFactPath`, fake hr-ai)** — P1 Phase 1: both values quoted in order, 2 citations, exploding AI proves no `/synthesise`/`/ground`; P2 Phase 2: request has two `reference_fact` sources with `fact_id`, verbatim, in order, before `national_law`; `/ground` receives both values; citations in model order; trace `fact_ids_offered/cited`; P3 model cites one ⇒ one citation, `fact_ids_cited=[140]`; P4 fact-vs-prose conflict on one member ⇒ `conflict`, `/synthesise` never called; P5 unknown `fact_id` cited ⇒ dropped, Check B accordingly; P6 single-fact request has **no** `fact_id` key (byte-compare with the pre-change payload).

**Goldens** — 22 unchanged + 23, 24 (+25). **hr-ai script** — H1–H3 as step 5. **Audit** — A1 classifications on seeded fixtures (complementary / contradictory / identical / recency_shadowed / over_cap / unbound excluded); A2 the ticket's numbers via a fixture shaped like staging; A3 `--json` shape; A4 read-only. **Frontend** — helper vitest es+en, parity tests, `tsc -b`. **Live** — S1–S4, CP-1 (§8).

### 9.3 "Nothing else moves" — summary of the proof

| Claim | Evidence |
|---|---|
| Legacy selection unchanged | `selectMostRecent` has zero diff; runs first; new code reached only on `ambiguous_conflict` |
| Only the tie-with-distinct-values class can change | S1 differential + 8.0 staging replay (expected 1 combination of ~hundreds) |
| Traces unchanged elsewhere | keys added only in set/conflict branches; goldens 12–15 byte-identical |
| Single-fact composition unchanged | one-element list builds identical arrays; P6; golden 13 |
| hr-ai unchanged for everything but a set | `fact_id` optional; absent ⇒ same key, same output |
| Precedence unchanged | tiers untouched; S6 matrix |
| Classic changes on exactly one class | "same-start distinct-value cohort whose quantity keys are provably disjoint": escalate → answer |
| Files touched | `ReferenceFactAnswerService`, `ReferenceFactPath` (shared, additive), new `Support/FactSetClassifier`, new command, hr-ai synthesise key, frontend trace helper + dictionaries + types; **not** `ChatService`, routers, retrieval, `GroundingService`, `EscalationExplainer`, `AnswerGate` |

---

## 10. Open questions (numbered; Q1–Q3 block the build)

* **Q1 — the rule (blocking).** The spec's key-difference clause can't classify 140/143 (identical keys) and is unsafe in Tiers 1–2 (§0, §3). Approve **R-Q** (recommended), or B (human-declared, needs pairing UX + a staging data write, redefines `coexists`), or choose D (HR bundles 140+143; no code slice). This also amends spec §3 and criteria 1/7 wording.
* **Q2 — hr-ai `fact_id` (blocking for "cite both").** Approve the additive hr-ai field (§4.4), or accept that two facts from one document cannot be separately cited (then criterion 1 reduces to "answers, cites the document").
* **Q3 — criterion 1 wording.** Restate "citing both facts" as: answers on every canonical variant and on ho-jor-04; both facts offered; every cited source ∈ offered set; ≥ 1 fact cited; both cited on the overview question. And: if the pre-existing fact-vs-prose figure guard escalates 143 for lack of a `6 horas` chunk, report it, don't tune it (classic-wide)?
* **Q4 — trace names.** OK to nest as `reference_fact.fact_set{composition,facts_selected,…}` and emit only for cohorts (goldens + `trace.composition` collision)?
* **Q5 — gate scope.** Approve S3 without classic-colloquial, the ≈ $13.4 estimate with classic per-turn unmeasured, and the drop-order of levers if classic is dearer.
* **Q6 — WIP commits / branch deploy.** Authorise `sprint-13d` WIP commits and a staging deploy of hr-backend + hr-ai + hr-frontend (13b precedent, squashed at merge).
* **Q7 — 140/143's data.** Independently of the code, HR may prefer to bundle them (ADR-0034). Not in this slice; noted that a human triage edit *created* the collision (§2.3), so the audit belongs in the post-triage checklist.
* **Q8 — unreachable resolver.** "Resolver versión" works only on pairs with `duplicate_of_id` (`ReferenceFactController.php:353-357`). A contradictory pair that arose by edit (as 140/143 did) is unflagged, and `facts:scan-duplicates` only flags overlapping *group labels* (null-label pairs are invisible to it). Ticket a follow-up (audit `--flag`, or a pair-by-uuid resolver) — out of scope here.
* **Q9 — cap behaviour.** > 3 complementary facts: use 3, list the rest in the trace (as briefed) — or escalate with "too many facts, bundle them"? Default: as briefed.
* **Q10 — colloquial on classic.** Confirm ho-jor-04 (colloquial, unanchored) is gated on the agent only; classic is recorded, not gated (13b contract).

---

## Appendix A — read-only staging queries (2026-09-30, `hr-staging`, SELECT only)

Run over SSH through the hr-backend container's `tinker` with `DB::select` only (no writes, no model calls).
Raw outputs kept under `/tmp/q13d/` (not committed).

**A.1 status × source:** `needs_review/ai_agent 17`, `rejected/ai_agent 5`, `verified/admin_manual 1`, `verified/ai_agent 131`.

**A.2 verified key columns (132):** null convenio 0, null topic 0, null source document 0; category set 3; group label set 87; null `validity_start` 2 (ids 144, 147); null `validity_end` 50. In-validity verified: 102 (`raw_values`: object 100, null 2). Verified rows carrying a resolution/duplicate flag: 41, 42, 48, 50, 51, 56, 79, 80 (all `supersedes`); no verified `superseded/coexists/rejected_duplicate`.

**A.3 same full-key rows, all statuses:** one group only — `convenio 20 / topic 2 / (no category, no label) / 2025-01-01..2028-12-31`, ids `{140,143}`, both `verified`, both `source_document_id 50`, both `ai_agent`.

**A.4 facts 140 / 143:** same batch `95f04bb3-da79-461a-b2fe-0e27a0f0913a`, created `2026-09-14 00:50:40`; 140 locator `p9`, edited 2026-09-15 00:32:59 (`group_label`, `value`), verified 2026-09-21; 143 locator `p10`, verified 2026-09-15. `raw_values` keys — 140: `2025, 2026, 2027, 2028`; 143: `jornada_irregular, computo_tiempo_trabajo, dias_libre_disposicion, descanso_jornada_continuada`. Neither has `duplicate_of_id`, `resolution`, or `superseded_by_id`.

**A.5 convenio 20 chunks (60):** jornada-topic chunks 3541, 3543, 3548, 3549, 3552, 3557, **3558 (p9–10: `hora {1704,1700,1696,1692,…,6}`, `dia {2}`)**, 3559, 3564, 3566, 3568, 3569, 3574, 3583, 3584, 3587, 3589 (all `official_convenio`, `active`).

**A.6 convenio-wide (convenio, topic) with ≥ 2 facts:** only (20, 2). Group bindings exist only for convenio 21 (8 bindings / 6 facts); approved group trees only convenio 21. Test employees: `test-deporte-navarra@example.com` (id 11) and five `test-answer-gate-*` accounts on convenio 20, all without category/node.
