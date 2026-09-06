# Sprint 7d — Review (semantic comparison: the fence, fact versions, succession)

> Built in the plan's §6 order — **Step 0 (the compare primitive + calibration) → A (the fence) → B (fact resolution) → C (succession)** — with every confirmed design decision from the build prompt applied as written (ADR-0024).
>
> **NOTHING IS COMMITTED.** The work is in the working tree across `hr-ai` / `hr-backend` / `hr-frontend` / `hr-docs`, awaiting your review.
>
> **Read §2 first.** The mandate made calibration a hard prerequisite ("an unmeasured threshold on a safety gate is worse than the blunt fence"). Both harnesses are built, tested, and run — but **this environment has no corpus database and no running hr-ai, so no score was measured and the thresholds ship PROVISIONAL.** That is the one open item, and §2 says exactly what to run.

---

## 1. What was built

### Step 0 — the comparison primitive (hr-ai: additive, read-only, SELECT-only)

- **`POST /compare-scope`** (`hr-ai/app/main.py`) + **`chunks_db.compare_scope()`**. Embeds N probes with the same BGE-M3 model the corpus uses and ranks a scope's chunks against each. No LLM, no write, no migration.
  - The **`authority_level` filter is in the SQL `WHERE`** — the reality-check catch. The best eligible `official_convenio` passage is therefore rank 1 of an exactly-filtered, exactly-ordered set, so a threshold decision on `max_score` is **k-independent**: `k` controls only how many passages the human is shown, never the decision. (Filtering after a top-k, which is all `/retrieve` allows, could let other same-convenio chunks crowd out the overlapping passage — a safety gate reporting "no conflict" when there is one.)
  - **`candidate_document_ids`** pins the candidate set to the `documents` registry's truth, because `document_chunks` carries a **denormalized scope copy refreshed only on re-embed** — a document retired via `DocumentController::updateLifecycle` still has `active` chunks. hr-backend passes the registry ids and sends `retrieval_status: []` ("do not filter on that stale copy"). This was found during the build, not designed in.
  - **`document_ids`** as an alternative probe side (those documents' own chunk texts), so document↔document comparison never ships chunk text hr-ai already stores back over the wire.
- **`ExtractionClient::compareScope()`** (hr-backend) — the one new client method.
- The `/sandbox-retrieve`-per-document loop stays **recorded as the zero-hr-ai-change fallback and was not built** (it needs P×N calls and cannot filter authority in SQL — the property the whole safety argument rests on).

### Step A — the semantic publish fence

- **`SemanticFenceService`** + the **`SemanticComparison`** value object. The comparison's *only* constructors are `measured` / `unavailable` / `nothingComparable` / `nothingInScope`, and three of the four can never return `clear` — the fail-toward-caution rule is a property of the type, not of a caller remembering to check.
- **`EscalationService::resolve()`** — one injected collaborator and one branch. `detectConflicts()` is **not edited, narrowed or conditioned**; the semantic pass is consulted **only when it returns empty**. `fence = existing_block OR semantic_block` is therefore a property of the control flow: no threshold value can weaken the Sprint-4/Correction-01 fence, and there is no added latency on the already-blocked path.
- **Multi-probe, max-over-probes.** `resolution_text` is paragraph-split because the embedder truncates a long text silently, which would leave a ruling's tail uncompared (a fail-open). The splitter **adapts its bucket size until the whole text fits the probe cap** and folds any residue into the last probe — an early version sliced to the cap and silently dropped the tail, which the coverage test caught.
- **Two bands + the failure path** (`EscalationController`): `≥ semantic_conflict_threshold` → 409 `publish_blocked` reason `semantic_overlap` (a `conflict` task opens on the draft, the card returns to In Progress, `escalation_events.detail` holds the chunk ids + scores + thresholds); `≥ semantic_review_band` → 409 `publish_requires_acknowledgement` (draft untouched, zero chunks, card not re-opened) → re-POST with `acknowledge_semantic_overlap = true` publishes and records `publish_acknowledged_overlap`. **A failed comparison, or a scope whose convenio has no readable text, takes the acknowledgement path** with its own reason and its own copy. There is no fourth outcome and no silent pass.
- **§8.5 reverse re-check — BUILT, not deferred**, at the flag-only bar: `SemanticRecheckService` + the queued `RecheckRulingsForConvenio` (dispatched from `DocumentIngestor` and `DocumentController` when an `official_convenio` becomes `active`) + the backfill `php artisan rulings:scan-semantic-conflicts [--dry-run]`. It opens a `conflict` review task on an overlapping published ruling with a `kind` discriminator in `raw_unmatched_values` (existing task type reused, no CHECK rewrite) and **never touches `retrieval_status`** — a convenio arriving cannot silently demote an answer employees are receiving.
- **Migration 1** — `escalation_events.detail` (nullable jsonb).

### Step B — fact version resolution

- **`App\Support\GroupLabel`** — normalise / digit-set / `relate()` (EXACT · OVERLAP · DISJOINT · UNKNOWN). **Deterministic group-digit-token overlap**: `Grupos 1 y 2` ∩ `Grupo 2` = `{2}`. No embeddings, no threshold, unit-testable, and it cannot drift. (Aboutness here is a *set* question, not a *meaning* question — the deterministic answer is the better one, not merely the cheaper one.)
- **`php artisan facts:scan-duplicates [--dry-run]`** — flags same-convenio+topic facts with overlapping group digits, and **never re-flags a pair a human already resolved**.
- **`FactResolutionService`** — `supersede` (older's `validity_end = newer.validity_start − 1 day`, **both stay `verified`**, lineage in `resolution`/`superseded_by_id`/`resolved_by`/`resolved_at`, **never a delete**; refused with `ambiguous_dates` when the dates don't support the direction), `coexist`, `rejectDuplicate`. Each appends to `tag_events` (`facet = 'resolution'`, plus a `validity_end` row for the window a supersede closes).
- **Routes** (`knowledge.edit`): `GET /admin/reference-facts/{uuid}/duplicate-pair` (side-by-side + server-computed `differing_fields` + `supersede_candidate`) and `POST …/resolve-duplicate` (`ResolveFactDuplicateRequest`).
- **Migration 2** — the four nullable `reference_facts` columns.

### Step C — the succession proposal

- **`SuccessionProposalService`** — `compute()` (pure, read-only) + `propose()` (persists) + `relationship()` (the public rule the eval measures, so the thing under test is the thing that runs). **No LLM:** `successor` requires overlap ≥ `succession_overlap_threshold` **AND** a strictly-later `validity_start`; high overlap without later validity is `conflict`; low overlap is `coexisting_sibling`; between is `uncertain` with a stated reason. Same-convenio candidates only, capped at the same list the 7a confirm form offers, so a proposal can never name something the write-side would refuse.
- **`ProposeSuccession`** (queued, 7a propose-discipline: one try, logs, never rethrows) dispatched from `reviews:scan-expiry` (`--no-propose` to skip) and from `POST /admin/review/expiry/{taskId}/propose-succession`; **`POST …/reject-proposal`** records the verdict and leaves the task **open**.
- **`php artisan succession:gold-eval [--discover] [--json]`** — the labeled eval plus a **`--discover`** mode that needs no labels (it enumerates every same-convenio pair in the corpus, runs the real classifier, and reports how many `successor` claims the rule makes, which is the question that actually matters before go-live). Read-only, and wrapped in a rolled-back transaction so a future edit that starts writing still cannot touch the corpus.
- **Migration 3** — the three nullable `document_review_tasks` proposal columns.

### Frontend (three surfaces, existing tokens)

- **`EscalationCardDrawer.tsx`** — a semantic block shows the overlapping convenio passages and offers **no acknowledgement** (that outcome cannot be clicked through); the review band shows the near-passages, an explicit tick and a separate publish button. The tick **starts unchecked every time** and is **cleared by editing the draft**, because an acknowledgement is a decision about the text that was compared. A comparison that failed gets the same prompt with copy that names the cause.
- **`FactDuplicatePanel.tsx`** (new) — the two facts side by side, differing fields marked, shared scope left visible (it is what proves they are two versions of one fact). Three verdicts behind a confirm modal; the supersede direction is **pre-selected** from the server's `supersede_candidate` and the copy states the exact date the older window closes and that nothing is deleted.
- **`ReviewQueuePage.tsx`** — the reference-facts row gains a `Resolver versión` action for an **unresolved** flag; the expiry row gains the **fuchsia** `SuccessionProposalNotice` (relationship, score, both validity windows, the compared passages). **Confirm** pre-selects the proposal in the existing successor `<select>` and calls the unchanged 7a resolve endpoint — the "also retire" checkbox is **never** pre-checked by a proposal. A non-`successor` relationship offers no confirm button at all.
- **`index.css`** — one additive block of layout primitives (`.notice-body`, `.passage-list`, `.passage-text`, `.compare-grid`, `.compare-field`). No new colour; fuchsia keeps its single meaning via the existing `.notice--ai`.

### Docs

ADR-0024 (`architecture/decisions/0024-semantic-comparison-human-adjudicated-fail-toward-caution.md`); `architecture.md` (§2 the primitive, §8.3 the evolved fence, **§8.5 retitled from "Known boundary" to "closed at the FLAG level"**, §10 the two new review surfaces); `data-model.md` (the `detail` column + the distinguishable publish reasons, the fact-lineage columns + the never-deletes rule, the three proposal columns as the AI's entire write surface); `roadmap.md` (**7d DONE**, 7e/7f remain, and the follow-ups below recorded); the three READMEs.

---

## 2. Calibration — MEASURED on the real corpus; thresholds set from the data

**Both passes have now been run against the real corpus.** The measurement that §2 previously recorded as outstanding was taken on **2026-09-06** on the staging environment (`hr_platform` on `hr-staging-db`, eu-west-1), on the `sprint-7d` branch, with hr-ai up and BGE-M3 loaded — 171 eligible chunks in the Navarra scope, 134 in the Álava scope. The run was repeated on two different EC2 instance types (`t3.large` and `c7i.2xlarge`) and the scores were **bit-identical**, which is the expected property of a deterministic embedder and the reason the numbers below can be treated as reproducible rather than sampled.

Verbatim (`php artisan fence:calibrate-semantic`, read-only):

```
── Pass 1: real published-ruling distribution ──────────────────────
rulings found: 3  ·  measured: 3
+-----+------------------------------------+--------+----------+--------+--------+--------+--------+
| doc | title                              | probes | eligible | max    | p90    | median | min    |
+-----+------------------------------------+--------+----------+--------+--------+--------+--------+
| 99  | Resolución interna RR. HH. — vacac | 1      | 171      | 0.8116 | 0.5948 | 0.5659 | 0.552  |
| 100 | Resolución interna RR. HH. — vacac | 1      | 171      | 0.8116 | 0.5948 | 0.5659 | 0.552  |
| 101 | Resolución interna RR. HH. — vacac | 1      | 171      | 0.7342 | 0.6566 | 0.617  | 0.6015 |
+-----+------------------------------------+--------+----------+--------+--------+--------+--------+
per-ruling max: {"min":0.7342,"median":0.8116,"max":0.8116}
+-----------+-------------+-------------+-----------+------------+
| threshold | review_band | would BLOCK | would ASK | would pass |
+-----------+-------------+-------------+-----------+------------+
| 0.7       | 0.55        | 3           | 0         | 0          |
| 0.75      | 0.6         | 2           | 1         | 0          |
| 0.8       | 0.65        | 2           | 1         | 0          |
| 0.85      | 0.7         | 0           | 3         | 0          |
| 0.9       | 0.75        | 0           | 2         | 1          |
+-----------+-------------+-------------+-----------+------------+
Small N. This pass shows the SHAPE only — the labeled anchors below carry the threshold choice.

── Pass 2: labeled synthetic anchors (the ground truth) ────────────
| anchor                             | class                  | convenio       | max_score | eligible | top match / skip reason                   |
| a1-navarra-intervencion-periodo    | paraphrase             | 31101815012021 | 0.811602  | 171      | Artículo 22. Período de prueba.           |
| a2-alava-coeas-periodo             | paraphrase             | 01100635012017 | 0.800192  | 134      | Artículo 17. Período de prueba            |
| a3-vizcaya-intervencion-periodo    | paraphrase             | —              | —         | —        | no active official_convenio in that scope |
| a4-navarra-deportiva-periodo       | paraphrase             | —              | —         | —        | no active official_convenio in that scope |
| a5-estatal-coeas-periodo           | paraphrase             | 99100055012011 | —         | 0        | (convenio present; no comparable chunks)  |
| b1-navarra-intervencion-otro-punto | same_topic_other_point | 31101815012021 | 0.875149  | 171      | Artículo 25. Cese voluntario.             |
| b2-alava-coeas-otro-punto          | same_topic_other_point | 01100635012017 | 0.689159  | 134      | Artículo 24. Jornada máxima               |
| b3-vizcaya-intervencion-otro-punto | same_topic_other_point | —              | —         | —        | no active official_convenio in that scope |
| c1-unrelated-proteccion-datos      | unrelated              | 31101815012021 | 0.619803  | 171      | Artículo 57. Protección de datos …        |
| c2-unrelated-licitacion            | unrelated              | 01100635012017 | 0.588537  | 134      | Artículo 15. De la contratación en general |
| c3-unrelated-mantenimiento         | unrelated              | —              | —         | —        | no active official_convenio in that scope |
+------------------------+---+--------+--------+--------+
| class                  | n | min    | median | max    |
+------------------------+---+--------+--------+--------+
| paraphrase             | 2 | 0.8002 | 0.8116 | 0.8116 |
| same_topic_other_point | 2 | 0.6892 | 0.8751 | 0.8751 |
| unrelated              | 2 | 0.5885 | 0.6198 | 0.6198 |
+------------------------+---+--------+--------+--------+

── Recommendation ──────────────────────────────────────────────────
  ready: true
  semantic_conflict_threshold: 0.78
  semantic_review_band: 0.66
  justified_by: {"weakest_true_overlap (class a min)":0.8002,
                 "weakest_same_topic_other_point (class b min)":0.6892,
                 "strongest_unrelated (class c max)":0.6198}
```

**Which anchors actually scored: 2 of the 5 paraphrase anchors, exactly the two predicted** — a1 (Navarra Intervención Social) and a2 (Álava Ocio Educativo). The other three skipped for the reasons the fixture already documented, though one of them for a slightly different reason than expected: a3 and a4 skipped because their convenios hold **no active `official_convenio` document** at all, while a5's convenio (COEAS Estatal `99100055012011`) *is* present and active but returned **`eligible = 0`** — its documents have no comparable chunks, because that scope's text was never embedded (the COEAS Estatal PDFs are among the corpus's no-text-layer files). The distinction matters for reading the report: a5 is a *chunking* gap, not a registry gap.

**Two facts about the deployed corpus are worth recording, because they change how these numbers should be read:**

1. **Pass 1's three "rulings" are this sprint's own verification artifacts, not published rulings.** They are the three drafts created by the live fence check described below (`draft` status, **0 chunks each** — the fence wrote nothing to retrieval, which is the property that matters). Before that check, Pass 1 reported `rulings found: 0`: the corpus holds **no published internal ruling at all**, so the real-distribution pass has no independent signal and the threshold rests entirely on Pass 2, as the harness's own `ready: false` guard was designed to force. Note also that `FenceCalibrateSemantic::realDistribution()` selects every `internal_hr_ruling` **without filtering `retrieval_status`**, so a blocked draft is counted as a published ruling — the docblock says "already-published", the query does not enforce it. A one-line filter would fix it; it is listed in §9 rather than changed here, because it affects no threshold (Pass 1 is shape-only).
2. **No active convenio document in the corpus carries a topic tag.** All of them are untagged, and the Sprint-5 structural term treats an untagged in-scope convenio as governing (`orWhereDoesntHave('topics')` — fail-closed). So on today's corpus the **structural** term blocks every ruling publish in every scope, corpus-wide, and short-circuits before the semantic pass is ever called. The semantic fence is therefore *fully built and correct* but **not yet load-bearing in production terms**: it becomes the deciding term only as documents acquire topic tags through the Sprint-3/7a tagging path.

### Thresholds set from the data

`config/hr.php`, committed on the branch (`9257d9c`):

| value | set to | justified by |
|---|---|---|
| `semantic_conflict_threshold` | **0.78** | below the **weakest known-true overlap, 0.8002** (a2 Álava) — a 0.02 margin, so no genuine same-point overlap escapes the block |
| `semantic_review_band` | **0.66** | below the **class-(b) floor, 0.6892** (b2 Álava), so every same-topic-different-point ruling at least asks; and above the **class-(c) ceiling, 0.6198** (c1 RGPD boilerplate), so genuinely unrelated text is not dragged into the band |

`succession_overlap_threshold` (0.75) and `succession_sibling_ceiling` (0.55) were **measured and deliberately left unchanged** — see the succession subsection below and the comment block in `config/hr.php`.

**The honest caveat, and it is the most important line in this section: the two classes overlap in score space.** Class (b)'s maximum (**0.8751**, b1 — a *cese voluntario* probe matching `Artículo 25. Cese voluntario.`) is **higher than both paraphrase anchors** (0.8116, 0.8002). No threshold pair can separate "restates the convenio" from "same chapter, different rule" on this evidence, because the embedder scores b1 as the *most* similar probe in the whole set. The consequence at 0.78 is concrete: a ruling like b1 — which is **filling a gap, not overriding anything** — will be **blocked outright rather than merely asked about**. That is the direction the calibration mandate demanded (err toward blocking more; a wrongly-blocked publish costs one human's attention, a wrongly-allowed one costs the fence's whole purpose), but it is over-blocking, and it is the number to watch: if HR reports rulings being blocked that plainly fill gaps, the fix is a **richer anchor set** and probe-level rather than document-level scoring, not a higher threshold. With n = 2 per class this is a floor on confidence, not a verdict.

### Succession gold eval on the real corpus — MEASURED

The fixture's three provisional pairs were transcribed from `deploy.md`'s dev-corpus ids and **every one of them fingerprint-guarded into a SKIP** on this corpus — the guard did exactly its job, and the eval measured nothing. `succession:gold-eval --discover` was run first (19 same-convenio pairs examined, **3 successor claims**, 3m15s on `c7i.2xlarge`), every id was confirmed by SQL, and `succession-gold.json` was replaced with five labeled pairs (`f53f602`):

| pair | expected | got | score | verdict |
|---|---|---|---|---|
| Enseñanza no reglada Estatal 79 → 74 (2020-2023 → 2024-2027) | `successor` | `successor` | 0.99821 | **right** |
| Oficinas y despachos Valencia 93 → 92 (2021-2023 → 2024-2026) | `successor` | `successor` | 0.890335 | **right** |
| Alojamientos Gipuzkoa 23 → 28 (2020-2024 → 2025-2028) | `successor` | `successor` | 1.0 | **right** |
| Intervención Social Bizkaia 67 ↔ 65 (identical validity windows) | `conflict` | `conflict` | 0.987517 | **right** |
| Navarra Oficinas prose 30 vs `Tabla 2025` annex 33 | `coexisting_sibling` | `conflict` | 0.818874 | other_wrong |

```
right 4 | uncertain(miss) 0 | wrong-but-not-successor 1 | SKIPPED 0
CONFIDENTLY-WRONG SUCCESSORS: 0
```

**Confidently-wrong successors: 0 of 5 — the only number that had to be zero.** The one mismatch is a miss in the cautious direction: the `Tabla 2025` annex has a **NULL `validity_start`**, so the strictly-later conjunct cannot be satisfied and the rule returns `conflict` ("the dates do not establish which supersedes which") instead of `coexisting_sibling`. No threshold change can fix it — the block/sibling branches are ordered so that a score of 0.8189 is decided before the sibling ceiling is consulted — and the actual fix is a validity window on the annex in the registry. It is left labeled `coexisting_sibling` on purpose: re-labeling it would have made the tally look clean and hidden a real registry gap.

**Why the succession thresholds were not changed.** The three true successors scored 0.8903 / 0.9982 / 1.0, so `succession_overlap_threshold = 0.75` sits 0.14 below the weakest of them and loses none. But the **strongest non-successor scored 0.9875** — two duplicate ingests of the same Bizkaia text, filenames differing by a trailing underscore — which is *above* the weakest true successor. Score alone cannot separate the classes here either, so raising the threshold would buy no safety and only add misses. What actually prevented every wrong successor was the second conjunct, **strictly-later `validity_start`** — which is exactly why ADR-0024 made the rule a conjunction instead of a score cut, and the eval is the evidence that the design choice was the load-bearing one. `succession_sibling_ceiling` (0.55) was **never exercised**: the lowest score any real same-convenio pair produced was 0.8189, so that branch still has only unit-test coverage and there is no corpus evidence to tune it either way.

### The fence, verified live on staging

Three real publish attempts through `POST /admin/escalations/{uuid}/resolve` with a real super-admin token, against the real corpus (test employee in Navarra · Acción e Intervención Social, `31101815012021`):

| attempt | resolution text | corpus state | result |
|---|---|---|---|
| 1 | a1's near-verbatim *periodo de prueba* restatement | as-is (in-scope docs untagged) | **409 `publish_blocked`**, `reason: topic_scope_conflict`, `passages: []`, `max_score: null` — the **structural** term fired and short-circuited, so hr-ai was never called |
| 2 | same text | in-scope docs temporarily tagged `retribución`, ruling filed under `vacaciones` so the structural term allows | **409 `publish_blocked`**, `reason: semantic_overlap`, `max_score: 0.811602`, naming `31101815012021 Intervención Social de Navarra 2025` and returning the overlapping passage — **the semantic term blocking on its own** |
| 3 | a mid-band probe (training-leave hours) | same | **409 `publish_requires_acknowledgement`**, `reason: semantic_near_overlap`, `max_score: 0.734216`, `comparison_unavailable: false` — **the ask branch**, on real data inside the calibrated band |

The score in attempt 2 (**0.811602**) is the same number the calibration reported for a1 — the same measurement arriving through the real publish path, not a re-derivation. Attempt 1 is worth keeping in view: it is the corpus's default state, and it shows the disjunction short-circuiting in the fail-closed direction *before* spending a network call.

All three branches were also measured directly against the corpus through `SemanticFenceService::compareRulingToScope()` — `block` at 0.8116 and 0.8751, `acknowledge` at 0.7342 and 0.7032, `clear` at 0.6387 and 0.6198 — confirming the three-way split sits where the calibration put it.

The temporary `retribución` tags on documents 1 and 52 were **removed afterwards** (both back to 0 topics); the three ruling drafts were left in place as the evidence trail, `draft` with 0 chunks.

### Full suite, on the branch, with the final thresholds

```
php artisan test  →  112 tests, 112 passed, 550 assertions
  Sprint7dCalibrationTest            4 passed
  Sprint7dFenceNeverOpensTest       14 passed
  Sprint7dSuccessionProposalTest    11 passed
  Sprint7cAdditivityRegressionTest   2 passed
```

One test had to be repaired by the recalibration, and the repair is worth a read: `Sprint7dFenceNeverOpensTest::test_review_band_requires_acknowledgement_and_writes_nothing_until_given` hardcoded a score of **0.65**, which sat inside the *provisional* band (0.60–0.75) and outside the calibrated one (0.66–0.78), so it failed with `Expected 409, received 200`. The fix derives a mid-band score from `config` instead of hardcoding one, so the test now asserts the band's *behaviour* rather than a pair of numbers that calibration is expected to move.

---

## 3. The (A) fence proof — 14 tests, `Sprint7dFenceNeverOpensTest`

The seven required cases, plus the extras the build turned up:

| # | test | what it pins |
|---|---|---|
| 1 | `test_case1_no_topic_still_blocks…` · `…case2_untagged_governing_convenio…` · `…case3_convenio_tagged_same_topic…` | the three Sprint-5 Correction-01 blocking cases, run through the **combined** fence with the semantic fake at `0.0` — still BLOCK |
| 1 | `test_case4_convenio_tagged_other_topic_still_allows…` | case 4 with the fake at `0.0` — still ALLOWS (so the semantic pass adds nothing when it sees nothing) |
| 2 | `test_semantic_pass_blocks_the_case4_hole_and_records_the_matched_chunks` | case 4 at `0.95` → BLOCK, reason `semantic_overlap`, matches non-empty, `escalation_events.detail` carries chunk ids + scores |
| 3 | `test_the_fence_is_exactly_the_disjunction_of_its_two_terms` | the 2×2 matrix: `blocked == (existing ‖ semantic)` in all four cells |
| 4 | `test_review_band_requires_acknowledgement_and_writes_nothing_until_given` | band → `publish_requires_acknowledgement`, draft still `draft` with **0 chunks**, card not re-opened; then acknowledge → publishes |
| 5 | `test_comparison_failure_falls_to_acknowledgement_never_a_clean_publish` | the fake **throws** → acknowledgement path with `semantic_compare_unavailable`; never a clean publish |
| 6 | `test_threshold_monotonicity_and_no_threshold_can_open_the_existing_fence` | lowering the threshold only ever blocks more, **and** no threshold value re-opens a structurally-blocked case |
| 7 | `test_comparison_is_never_called_when_the_existing_fence_blocks` | the compare fake is **never invoked** when `detectConflicts` is non-empty (the disjunction is structural, and the common path pays nothing) |
| + | `test_acknowledgement_cannot_unblock_a_semantic_block_or_a_structural_one` | the flag satisfies **only** the band — it is not a master key |
| + | `test_unreadable_convenio_requires_acknowledgement_rather_than_passing` | a scope whose convenio has no readable text asks, rather than passing |
| + | `test_scope_with_no_official_convenio_publishes_without_calling_the_comparison` | nothing in scope → publish, no needless call |
| + | `test_probe_split_covers_the_entire_text_however_long` | **100 % text coverage** at any length — the test that caught the truncating splitter |

---

## 4. The (B) proof — 13 tests, `Sprint7dFactResolutionTest`

- **The Navarra pair is surfaced.** `test_group_token_overlap_relates_the_real_navarra_labels` pins the tokeniser on the real labels, and `test_the_navarra_version_pair_is_surfaced_by_the_token_overlap_pass` runs `facts:scan-duplicates` over the documented pair (file-1 *"Grupos 1 y 2 = 6 meses"* / file-2 *"Grupo 2 = 4 meses"*) and asserts it is **flagged** — `{1,2} ∩ {2}` — while a genuinely disjoint pair is not. `test_the_pass_is_idempotent_and_never_reflags_a_resolved_pair` keeps a resolved pair out of the queue.
- **Supersede closes validity and deletes nothing.** `test_supersede_closes_the_older_window_keeps_both_and_deletes_nothing` — the older's `validity_end` becomes `newer.validity_start − 1 day`, **both rows still exist and are still `verified`**, and the lineage columns are written. `test_supersede_is_idempotent_and_can_never_extend_a_window` — re-running never re-opens a closed window. `test_the_direction_of_a_supersede_is_never_guessed` and `test_supersede_requires_the_newer_fact_to_have_a_start_date` — the dates must justify the direction or the write is refused.
- **The data-only flow into the answer rule.** `test_the_unchanged_7c_rule_stops_escalating_once_the_data_is_corrected` builds the true ambiguous case (two verified facts, **identical** `validity_start`, differing values → 7c escalates `ambiguous_conflict`), has a human fix the older fact's date through the **existing** PATCH route, then supersedes — after which the answer service resolves the current value, and a question dated in the old window still gets the **old** value. **No answer-loop code was touched.** A companion test (`test_a_well_dated_version_pair_already_answers_correctly_before_any_resolution`) pins that an already-well-dated pair never needed resolution, so the sprint's contribution is precisely the ambiguous case.
  - *Worth flagging:* writing this test corrected my own initial assumption — `ReferenceFactAnswerService::selectMostRecent` already resolves distinct-validity pairs, so the escalation only arises on **identical** validity starts. The test now pins both behaviours rather than one.
- **Coexist / reject / the pair view / permissions** — `coexist` touches no validity, `reject` never applies to a human-verified fact, `duplicate-pair` names exactly what differs, and both routes 403 without `knowledge.edit`.

---

## 5. The (C) proof — 11 tests, `Sprint7dSuccessionProposalTest`

- **The conjunction** (`test_successor_is_emitted_only_when_overlap_and_later_validity_both_hold`) drives all six combinations: high overlap + strictly later → `successor`; high overlap with an **earlier** candidate, an **equal** start, or a **missing** date → `conflict`, never `successor`; strictly later with low overlap → `coexisting_sibling`; in between → `uncertain` with a reason. **A similarity opinion alone can never produce the dangerous label.**
- **Inertness** (`test_the_proposal_writes_only_the_three_task_columns_and_never_touches_a_document`) snapshots **every column of every document** before and after a proposal and asserts equality, then names the dangerous ones individually (`predecessor_document_id` null, `retrieval_status` unchanged, `validity_end` unchanged), plus `source = 'ai_agent'`, passages present, and the task still `open`.
- **Same-convenio only** (`test_the_proposal_never_leaves_the_convenio`) — a newer, 0.99-similar document in a *different* convenio is not even in the candidate id list hr-backend sends.
- **Nothing proposed, with a reason** (`test_no_candidate_or_failed_comparison_proposes_nothing_and_says_why`) — no candidate → a stated reason; a throwing comparison → `comparison unavailable: …`, never a guess, never rethrown into the scan.
- **Confirm runs the unchanged 7a path** (`test_confirm_writes_lineage_through_the_7a_path_and_never_retires_the_predecessor`) — lineage written with `admin_manual` provenance by the human's action, `ai_proposal_status → confirmed`, and **the predecessor stays `active`**. `test_retiring_still_needs_both_explicit_flags_even_with_an_ai_proposal` — a retire without `confirm_scope_change` still 409s and writes nothing, proposal or no proposal.
- **Reject writes nothing** (`test_rejecting_a_proposal_writes_no_lineage_and_leaves_the_task_open`) — a status + a `tag_events` row, the task **open**, and the proposal **kept** for the eval trail.
- **Wiring + gating** — `reviews:scan-expiry` queues the job without touching any document; the propose/reject routes 403 without `knowledge.edit` while the queue **read** still shows the proposal to an auditor.
- **The harness is tested too** — `test_the_gold_eval_detects_a_confidently_wrong_successor_and_writes_nothing` deliberately mislabels a true successor pair as a sibling and asserts the command reports **CONFIDENTLY-WRONG SUCCESSOR** and exits non-zero (an eval that cannot fail would make the number meaningless), then passes with the correct label. `test_the_gold_eval_skips_a_pair_whose_title_fingerprint_does_not_match_the_id` proves a stale id can never make the eval score the wrong document.

### The succession gold eval on the real corpus — RUN, and the fixture rebuilt from SQL

The plan said the build turn would confirm each pair by SQL; the build environment had no corpus database, so the fixture shipped provisional and **every reference fingerprint-guarded** — the eval trusts `document_id` only if `title_contains` also matches, and otherwise skips. On the staging corpus all three provisional pairs duly **SKIPPED** (`right 0 | uncertain 0 | wrong-but-not-successor 0 | SKIPPED 3`), which is the guard working exactly as intended: a stale id produced a refusal to measure, never a measurement of the wrong document.

`--discover` was then run against the real corpus and every id confirmed by SQL, and the fixture was replaced with five labeled pairs — the three real successors, the **conflict pair** `deploy.md` could not name (two duplicate ingests of the Bizkaia Intervención Social text with identical validity windows), and a **prose-vs-table-annex sibling** (Navarra Oficinas-despachos: the 2019-2025 prose text beside the active `Tabla 2025` annex — this corpus's version of the pair that must never come out `successor`, since calling it a succession could lead a human to retire the prose text and lose every non-salary answer for that province×sector).

**Result: 4 right, 0 uncertain, 0 confidently-wrong successors, 1 miss in the cautious direction.** The full table, the reason the sibling pair comes out `conflict`, and the evidence for leaving both succession thresholds unchanged are in **§2**.

---

## 6. The frozen loop — unchanged

- **`Sprint7cAdditivityRegressionTest` green** (prose + salary byte-for-byte, the 7c golden trace).
- **Full backend suite green: 112 tests / 550 assertions**, including `Sprint5Correction01FenceTest`, `Sprint5AccessMatrixTest`, `Sprint6GuardrailInvariantTest`, `Sprint7aTagProposalInvariantTest`, `Sprint7b1ReferenceFactInvariantTest`, `Sprint7b2SegmentationInvariantTest`, `Sprint7cCompositionTest`, `Sprint7cReferenceFactAnswerTest`, and the four new 7d suites (42 of the 112: 14 fence + 4 calibration + 13 fact resolution + 11 succession).
- **No answer-loop file was touched.** No change to `/retrieve`, `/synthesise`, `/ground`, `ChatService`, the router, `SalaryAnswerService`, `ReferenceFactAnswerService`, or the 7c composition. (B) reaches chat exclusively through corrected data.
- **hr-frontend `npm run build` green.** One pre-existing failure had to be cleared to get there: `DocumentDetailPanel.tsx:387` had an unused `onChanged` parameter (TS6133) from Sprint 7a, so `tsc -b` was **already red before this sprint** — fixed by dropping the unused binding and noting why in a comment. `npm run lint` shows **no new violations**; the 19 remaining errors are the codebase-wide `useEffect(refresh, [refresh])` idiom in pre-existing files (`DirectoryPage`, `HistoryPage`, `DocumentsPage`, `AdminsPage`, `EscalationBoardPage`, and the pre-7d parts of `ReviewQueuePage`).

---

## 7. Hard constraints — how each is held

| constraint | how |
|---|---|
| **The fence only gets stricter** | `existing OR semantic` as control flow, not configuration; the semantic pass runs only on an empty structural result; block-or-acknowledge with no third outcome; a failed comparison asks. Proven by the 2×2 matrix + the monotonicity test + the never-called test. |
| **Human-adjudicated everything** | no auto-resolve / link / retire / demote / publish anywhere. The succession proposal writes 3 task columns and 0 document columns (snapshot test). Supersede closes validity, never deletes. The reverse re-check flags, never demotes. Confirm runs the unchanged 7a write-side; retirement still needs two explicit flags. |
| **Answer loop untouched** | no file in the 2b/7c path modified; the 7c golden trace is byte-for-byte green after 7d. |
| **hr-ai reads/returns, never migrates** | one additive read-only SELECT-only endpoint; no write, no migration, no LLM. hr-backend owns every decision and write (ADR-0007). |
| **Additive migrations only** | three nullable additive migrations, no CHECK rewrite (free-string event type; the reverse re-check reuses `conflict` with a `kind` discriminator). |
| **Vocabulary bind-only (ADR-0011)** | nothing in 7d mints or edits vocabulary; the duplicate pass reads `group_label` text and never writes a `convenio_job_category`. |
| **Reuse, don't rebuild** | the embed machinery, the 7a propose-job pattern + expiry write-side, the 7b-2 flag + review tab, `EnsureCan`, append-only `tag_events` / `escalation_events`, the existing design tokens (one additive layout-only CSS block). |

---

## 8. Eyes-on checklist (Pedram runs live)

**(A) The fence**
1. Resolve an escalation and publish a ruling that **restates something the asker's convenio already says** → expect **409 blocked**, with the overlapping convenio passage shown and the score beside it. The card returns to In Progress and a `conflict` task appears on the draft.
2. Publish a ruling on a point the convenio is **silent** on → expect it to pass, **or** to ask you to acknowledge a near-passage. If it asks, note the score: that number is the review-band calibration signal.
3. Confirm an **old-style block still fires** — publish into a scope with an active convenio and no topic, or with the convenio tagged on the same topic.
4. Stop hr-ai and try to publish → expect the **acknowledgement prompt naming the unavailable comparison**, never a clean publish.

**(B) Fact versions**
5. `php artisan facts:scan-duplicates --dry-run`, then for real → the Navarra Intervención Social **6-vs-4** pair appears in the Reference-facts tab with `≈ version`.
6. `Resolver versión` → side by side, differing fields marked → **Sustituir** → the older fact's validity closes the day before the newer starts, **both facts remain**, provenance recorded on both.
7. Ask a chat question dated **inside the old window** → the **old** value; dated now → the new one.

**(C) Succession**
8. `php artisan reviews:scan-expiry` (queue worker running) → an expiring document shows a **fuchsia AI-proposed successor** with the compared passages → **Confirm** → lineage written and the old document **still active**.
9. On another task, **Reject** the proposal → the fuchsia clears, nothing is written, the task stays open.
10. `php artisan succession:gold-eval --discover` → review every `successor` claim the rule makes on the real corpus. **Any indefensible one is the number that matters.**

**The frozen loop**
11. Ask a **prose** question (Navarra *vacaciones* → 37 días laborables, cited to the convenio) and a **salary** question → identical to before.

---

## 9. Open items / follow-ups (recorded in `roadmap.md`)

1. ~~Run the two calibration harnesses on the corpus and set the thresholds~~ — **DONE** (§2): both passes run on the staging corpus, `semantic_conflict_threshold = 0.78` / `semantic_review_band = 0.66` committed on the branch with the justifying anchor scores, succession gold eval run with 0 confidently-wrong successors. Two things it surfaced, which replace it as open items:
   - **The anchor set needs widening before the thresholds are trusted further.** n = 2 per class, and the classes **overlap in score space** (class-(b) max 0.8751 > class-(a) min 0.8002), so the fence over-blocks same-topic-different-point rulings by construction at any threshold below 0.8751. The remedy is more anchors and probe-level scoring, not a higher threshold — see §2's caveat.
   - **`FenceCalibrateSemantic::realDistribution()` does not filter `retrieval_status`**, so `draft` rulings (including publishes the fence *blocked*) are counted in the "real published-ruling distribution". One-line fix; affects no threshold, since Pass 1 is shape-only.
2. **Nothing in the corpus carries topic tags yet, so the structural term blocks every ruling publish and the semantic fence never gets consulted in practice** (§2). The semantic fence is built, calibrated and proven, but it only becomes load-bearing as documents acquire topic tags through the Sprint-3/7a path. Worth knowing before reading a "the fence blocked it" report as evidence the semantic pass fired.
3. **Exposing the semantic thresholds in the guardrails UI must use `min(baseline, admin)`, never `max`** — ADR-0019's direction is inverted for a block-triggering threshold, and it needs additive `guardrail_configs` columns. A Sprint-6-family follow-up.
4. **Watch the acknowledgement band.** Every acknowledged publish is audited with its scores (`publish_acknowledged_overlap` + `detail`). An acknowledgement humans learn to click through is a fence that has quietly opened; if the rate is high, adjust the **band**, not the block.
5. ~~Label a `conflict` pair in the succession gold set and replace the fixture's provisional ids with SQL-confirmed ones~~ — **DONE** (§2, commit `f53f602`): the Bizkaia duplicate pair is the labeled conflict, and all five pairs are SQL-confirmed against the staging corpus. What remains is a **validity window on the `Tabla 2025` annex** (document 33), the missing registry fact that makes the rule return `conflict` where a human says `coexisting_sibling`.
6. **Question-granular knowledge growth was not built** — 7d evolved the *fence* to meaning; the flywheel still attaches a resolved escalation as a whole ruling document. The original 7d scope note is kept in `roadmap.md` for that intent.
7. **§8.5 was NOT deferred** — it is built at the flag-only bar (a `conflict` task, never a demotion). Stated explicitly because the build prompt named it the first deferral candidate.

---

## 10. What awaits your review

The 7d work below was committed to the **`sprint-7d` branch** in each repo (never to `main`) so it could be deployed to staging for §2's measurement. Two calibration follow-up commits sit on top of it:

- `hr-backend 9257d9c` — `config/hr.php` thresholds set from the calibration (0.78 / 0.66) with the justifying scores recorded in place, the succession thresholds annotated as measured-and-unchanged, and the one review-band test switched from a hardcoded score to a config-derived one.
- `hr-docs f53f602` — `succession-gold.json` rebuilt with SQL-confirmed staging ids (five labeled pairs, including the conflict pair).

The 7d feature work itself, across all four repos:

- **hr-ai** — `app/main.py`, `app/chunks_db.py` (one additive read-only endpoint + one SELECT function).
- **hr-backend** — 3 migrations; `SemanticFenceService`, `SemanticComparison`, `SemanticRecheckService`, `FactResolutionService`, `SuccessionProposalService`, `GroupLabel`, `ResolveFactDuplicateRequest`; `ProposeSuccession` + `RecheckRulingsForConvenio` jobs; `FenceCalibrateSemantic`, `FactsScanDuplicates`, `RulingsScanSemanticConflicts`, `SuccessionGoldEval` commands; edits to `EscalationService`, `EscalationController`, `ReferenceFactController`, `ReviewQueueController`, `DocumentIngestor`, `DocumentController`, `ReviewsScanExpiry`, `ExtractionClient`, three models, `config/hr.php`, `routes/api.php`; four test suites.
- **hr-frontend** — `FactDuplicatePanel.tsx` (new); `EscalationCardDrawer.tsx`, `ReviewQueuePage.tsx`, `ReferenceFactPanel.tsx`, `lib/api.ts`, `index.css`; the one pre-existing `DocumentDetailPanel.tsx` build fix.
- **hr-docs** — ADR-0024; `architecture.md`, `data-model.md`, `roadmap.md`; `sprint-07d/eval/anchors.json` + `succession-gold.json`; this file.
