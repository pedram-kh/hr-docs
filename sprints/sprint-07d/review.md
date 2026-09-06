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

## 2. Calibration — the harnesses are proven; the MEASUREMENT is outstanding

**Both passes were built and both were run. Neither could measure anything here, because this environment has only a freshly-migrated empty test database (`hr_platform_test`) — the corpus database `hr_platform` does not exist on this machine, and hr-ai is not running with the BGE-M3 model.** Rather than invent numbers, the harnesses were written to **say so loudly** and to refuse a recommendation without evidence. Verbatim output:

```
Sprint 7d — semantic fence calibration (READ-ONLY; nothing was written)

 Pass 1: real published-ruling distribution
rulings found: 0 · measured: 0
per-ruling max: {"min":null,"median":null,"max":null}
+-----------+-------------+-------------+-----------+------------+
| threshold | review_band | would BLOCK | would ASK | would pass |
+-----------+-------------+-------------+-----------+------------+
| 0.7       | 0.55        | 0           | 0         | 0          |
| 0.75      | 0.6         | 0           | 0         | 0          |
| 0.8       | 0.65        | 0           | 0         | 0          |
| 0.85      | 0.7         | 0           | 0         | 0          |
| 0.9       | 0.75        | 0           | 0         | 0          |
+-----------+-------------+-------------+-----------+------------+

 Pass 2: labeled synthetic anchors (the ground truth)
fixture: hr-docs/sprints/sprint-07d/eval/anchors.json
| a1-navarra-intervencion-periodo    | paraphrase             | — | — | convenio 31101815012021 not in this database |
| a2-alava-coeas-periodo             | paraphrase             | — | — | convenio 01100635012017 not in this database |
| a3-vizcaya-intervencion-periodo    | paraphrase             | — | — | convenio 48006185012006 not in this database |
| a4-navarra-deportiva-periodo       | paraphrase             | — | — | convenio 31008235012003 not in this database |
| a5-estatal-coeas-periodo           | paraphrase             | — | — | convenio 99100055012011 not in this database |
| b1-navarra-intervencion-otro-punto | same_topic_other_point | — | — | convenio 31101815012021 not in this database |
| b2-alava-coeas-otro-punto          | same_topic_other_point | — | — | convenio 01100635012017 not in this database |
| b3-vizcaya-intervencion-otro-punto | same_topic_other_point | — | — | convenio 48006185012006 not in this database |
| c1-unrelated-proteccion-datos      | unrelated              | — | — | convenio 31101815012021 not in this database |
| c2-unrelated-licitacion            | unrelated              | — | — | convenio 01100635012017 not in this database |
| c3-unrelated-mantenimiento         | unrelated              | — | — | convenio 48006185012006 not in this database |
+------------------------+---+-----+--------+-----+
| class                  | n | min | median | max |
+------------------------+---+-----+--------+-----+
| paraphrase             | 0 | —   | —      | —   |
| same_topic_other_point | 0 | —   | —      | —   |
| unrelated              | 0 | —   | —      | —   |
+------------------------+---+-----+--------+-----+

 Recommendation
 ready: false
 note: 'No labeled `paraphrase` anchor scored. A block threshold CANNOT be chosen
        from unlabeled data alone: without a known-true overlap there is nothing to
        prove the threshold sits below one. Keep the conservative config defaults
        and record this in review.md.'
```

**The anchor fixture is real material, not toy data** (`sprint-07d/eval/anchors.json`): eleven probes across five real convenios by `numero` (Navarra Intervención Social `31101815012021`, Álava COEAS `01100635012017`, Vizcaya Intervención Social `48006185012006`, Navarra Deportiva `31008235012003`, Estatal COEAS `99100055012011`), three labeled classes — (a) near-verbatim paraphrase of a real *periodo de prueba* clause → anchors the **block** threshold; (b) same-topic-different-point → anchors the **acknowledge** band; (c) unrelated (data protection, tendering, maintenance) → the floor.

### What the calibration math *is* proven to do (`Sprint7dCalibrationTest`, 4 green)

- `test_it_refuses_to_recommend_a_block_threshold_without_labeled_evidence` — a real-distribution-only run yields `ready: false` with the reason above. **This is the test that makes the "measure first" rule enforceable rather than aspirational.**
- `test_the_recommended_threshold_sits_below_the_weakest_true_overlap` — with scripted anchor scores, the recommendation is strictly below the lowest class-(a) score, so no known-true overlap escapes.
- `test_a_weaker_true_overlap_pulls_the_threshold_down_never_up` — adding a weaker true overlap can only make the fence **stricter**.
- `test_the_calibration_run_writes_nothing` — the whole run is read-only.

### Chosen values — PROVISIONAL, and why they are safe to ship unmeasured

`config/hr.php`: `semantic_conflict_threshold = 0.75`, `semantic_review_band = 0.60` (plus `semantic_compare_k = 5`, probe caps 12 / 120–600 chars; `succession_overlap_threshold = 0.75`, `succession_sibling_ceiling = 0.55`). The config block states in place that these are **provisional pending the calibration run** and how to set them.

They are safe to ship *unmeasured* for one structural reason: the fence is `existing OR semantic`, so an uncalibrated threshold **can only add blocks and acknowledgements**. A too-low threshold over-blocks (costs human attention, routes to a person — the safe direction); a too-high threshold degrades gracefully to the Sprint-4/Correction-01 fence, which is exactly today's behaviour. The band is what to watch: every acknowledged publish is audited with its scores, so click-through is measurable.

### To finish the mandate (run these on the corpus, then set the two values)

```bash
# 1. with hr-ai up and the corpus database attached:
php artisan fence:calibrate-semantic            # human-readable
php artisan fence:calibrate-semantic --json     # for the record

# 2. set HR_SEMANTIC_CONFLICT_THRESHOLD *below* the lowest class-(a) anchor score,
#    HR_SEMANTIC_REVIEW_BAND in the class-(b) region — err toward blocking more.

# 3. the (C) side, which needs no labels to be useful:
php artisan succession:gold-eval --discover
php artisan succession:gold-eval                # after labeling the fixture
```

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

### The succession gold eval on the real corpus — OUTSTANDING, and honestly so

The plan said the build turn would confirm each pair by SQL. **It could not: there is no corpus database here.** So the fixture (`sprint-07d/eval/succession-gold.json`) is transcribed from the document ids `deploy.md` §5 records and is **explicitly marked provisional**, with every reference **fingerprint-guarded** — the eval trusts `document_id` only if `title_contains` also matches, and otherwise **skips**. Verbatim run here:

```
Succession gold eval — labeled real corpus pairs (read-only)
 thresholds: overlap ≥ 0.75 AND strictly-later validity → successor; ≤ 0.55 → sibling

 [vizcaya-intervencion-social-family]      SKIPPED  (document(s) not found in this database)
 [coeas-estatal-under-review-successor]    SKIPPED  (document(s) not found in this database)
 [navarra-oficinas-prose-vs-salary-table]  SKIPPED  (document(s) not found in this database)

 right 0 | uncertain(miss) 0 | wrong-but-not-successor 0 | SKIPPED 3
 CONFIDENTLY-WRONG SUCCESSORS: 0
 Every pair was skipped — this database does not hold the labeled corpus.
 The eval has not measured anything; run it against the corpus database.
```

The three labeled pairs and why they were chosen: **a true successor** (Vizcaya Intervención Social, the id-18 + 13/16 family `deploy.md` names); **an `under_review` successor** (COEAS Estatal id 72 — proving a proposal is still made for an untrusted candidate, since the proposal is inert and the human decides); and — the important one — **a coexisting sibling that must never come out `successor`** (Navarra Oficinas-despachos: expired prose id 93 beside the **active salary-table PDF** id 94; calling that a succession could lead a human to retire the prose text and lose every non-salary answer for that province×sector). A **conflict** pair could not be named from `deploy.md`, which records coverage gaps rather than overlaps — `--discover` is the way to find one on the corpus, and the fixture says so.

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

1. **Run the two calibration harnesses on the corpus and set the thresholds** (§2). This is the one piece of the mandate this environment could not complete, and it is stated as an outstanding item rather than papered over.
2. **Exposing the semantic thresholds in the guardrails UI must use `min(baseline, admin)`, never `max`** — ADR-0019's direction is inverted for a block-triggering threshold, and it needs additive `guardrail_configs` columns. A Sprint-6-family follow-up.
3. **Watch the acknowledgement band.** Every acknowledged publish is audited with its scores (`publish_acknowledged_overlap` + `detail`). An acknowledgement humans learn to click through is a fence that has quietly opened; if the rate is high, adjust the **band**, not the block.
4. **Label a `conflict` pair in the succession gold set** from `--discover` output, and replace the fixture's provisional ids with SQL-confirmed ones.
5. **Question-granular knowledge growth was not built** — 7d evolved the *fence* to meaning; the flywheel still attaches a resolved escalation as a whole ruling document. The original 7d scope note is kept in `roadmap.md` for that intent.
6. **§8.5 was NOT deferred** — it is built at the flag-only bar (a `conflict` task, never a demotion). Stated explicitly because the build prompt named it the first deferral candidate.

---

## 10. What awaits your review (nothing committed)

Working tree only, across all four repos:

- **hr-ai** — `app/main.py`, `app/chunks_db.py` (one additive read-only endpoint + one SELECT function).
- **hr-backend** — 3 migrations; `SemanticFenceService`, `SemanticComparison`, `SemanticRecheckService`, `FactResolutionService`, `SuccessionProposalService`, `GroupLabel`, `ResolveFactDuplicateRequest`; `ProposeSuccession` + `RecheckRulingsForConvenio` jobs; `FenceCalibrateSemantic`, `FactsScanDuplicates`, `RulingsScanSemanticConflicts`, `SuccessionGoldEval` commands; edits to `EscalationService`, `EscalationController`, `ReferenceFactController`, `ReviewQueueController`, `DocumentIngestor`, `DocumentController`, `ReviewsScanExpiry`, `ExtractionClient`, three models, `config/hr.php`, `routes/api.php`; four test suites.
- **hr-frontend** — `FactDuplicatePanel.tsx` (new); `EscalationCardDrawer.tsx`, `ReviewQueuePage.tsx`, `ReferenceFactPanel.tsx`, `lib/api.ts`, `index.css`; the one pre-existing `DocumentDetailPanel.tsx` build fix.
- **hr-docs** — ADR-0024; `architecture.md`, `data-model.md`, `roadmap.md`; `sprint-07d/eval/anchors.json` + `succession-gold.json`; this file.
