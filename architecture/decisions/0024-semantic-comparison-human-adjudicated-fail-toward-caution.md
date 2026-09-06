# ADR-0024 — Semantic comparison as a human-adjudicated, fail-toward-caution mechanism

**Status:** accepted (Sprint 7d)

## Context

Sprints 0–7c built every place where the system knows two pieces of knowledge might be about **the same thing**, and in each place it either used a **crude proxy** or **stopped short**:

- **The publish fence (§8.3, Sprint 4 + Correction-01).** An `internal_hr_ruling` may not override an active `official_convenio` in the asker's scope. The check is deterministic SQL: *does an active `official_convenio` exist in this convenio, and (if a topic is assigned) is it tagged with that topic?* The topic lens is a **proxy for aboutness**. Correction-01 already had to make it fail-closed when the convenio is untagged, precisely because tags are incomplete. A convenio tagged `jornada` that in fact regulates the probation period would let a contradicting ruling publish — the fence's own blind spot, and the one it cannot close with tags.
- **§8.5 — the reverse case.** A **new** convenio arriving over an already-published ruling was never checked at all.
- **The fact version trap (7b-2, ADR-0022).** Segmentation FLAGS a same-scope fact whose value differs (`duplicate_of_id`), and 7c's answer rule escalates a same-validity conflict. Neither could **resolve** one: the reviewer had nowhere to say "this is the 2025 version of that". The documented Navarra *Intervención Social* pair (`Grupos 1 y 2 = 6 meses` vs `Grupo 2 = 4 meses`) sat flagged.
- **Succession (7a, ADR-0020).** The expiry queue and the human-confirmed lineage write-side exist; the **suggestion** of which document supersedes which was deferred, because it needs comparison.

All four need the same missing primitive: **"do these two texts talk about the same point?"** — a question embeddings can answer approximately and tags cannot answer at all.

The danger to foreclose, by construction: **a semantic mechanism that makes the system less safe than the crude one it augments.** Three concrete forms: (1) a similarity miss that lets a contradicting ruling publish where the blunt topic fence would have blocked it; (2) an admin "tightening" a similarity threshold and in fact loosening the fence; (3) a confidently-wrong successor proposal that leads a human to retire a live document, cutting off answers for a whole population.

## Decision

Semantic comparison enters the system as **one read-only primitive** used in **three human-adjudicated surfaces**, and every use is arranged so that the machine's uncertainty resolves **toward caution** rather than toward action.

### 1. The primitive — `POST /compare-scope` (hr-ai, additive, read-only, SELECT-only)

One new endpoint. It embeds N probe texts with the same BGE-M3 model the corpus was embedded with (ADR-0006) and ranks a **scope's** chunks against each. No LLM, no write, no migration (ADR-0007: hr-ai reads and returns; hr-backend owns writes).

**The `authority_level` filter is applied in the SQL `WHERE`, not after the top-k — and that is a safety property, not an optimisation.** `/retrieve` (ADR-0015) has no authority filter, so a caller would have to filter *after* the top-k; an overlapping `official_convenio` passage could then be crowded out of the top-k by other same-convenio chunks, and a **safety gate would report "no conflict" when there is one**. With the filter in SQL, the best eligible passage is rank 1 of an exactly-filtered, exactly-ordered set, so **a threshold decision on `max_score` is k-independent**: no value of `k` can hide the passage that would block. `k` therefore controls only **how many passages the human is shown**, never the decision.

`candidate_document_ids` pins the candidate set to an exact document list, because `document_chunks` carries a **denormalized copy** of scope/status that is only refreshed on re-embed: a document retired through `DocumentController::updateLifecycle` still has `active` chunks. hr-backend passes the `documents` registry's truth and sets `retrieval_status: []`, so the comparison can never run against a document the registry no longer considers active.

**Multi-probe, max-over-probes.** At fence time the draft ruling has **no chunks** (nothing is published yet), so its `resolution_text` is embedded as the query. A ruling may be 20 000 characters — 5–6k tokens, at or over the embedder's limit, which **truncates silently**, leaving the tail of a long ruling uncompared: a fail-open. The text is therefore split into paragraph-sized probes and the fence takes the **maximum over all probes**, which both removes the truncation gap and makes the fence *stricter* (any probe reaching the threshold blocks). The splitter is **coverage-preserving**: it adapts its bucket size until the whole text fits the probe cap and folds any residue into the last probe, so no configuration can silently drop a tail (proven by test).

### 2. The fence — `existing_block OR semantic_block`, structurally

`detectConflicts()` is **not edited, narrowed, or conditioned**. The semantic pass is consulted **only when it returns empty**, so it can turn ALLOW→BLOCK and **can never turn BLOCK→ALLOW**. The disjunction is a property of the control flow rather than of a threshold, so no calibration error can weaken the Sprint-4/Correction-01 fence. It also costs nothing on the common (already-blocked) path, and it fires exactly where the topic lens leaks: an active convenio tagged on a *different* topic.

**Two bands, and no third outcome:**

| max similarity | outcome | what the human sees |
|---|---|---|
| ≥ `semantic_conflict_threshold` | **409 `publish_blocked`**, reason `semantic_overlap` | the overlapping convenio passages; an open `conflict` review task on the draft; the card returns to In Progress; `escalation_events.detail` = chunk ids + scores + thresholds |
| ≥ `semantic_review_band` | **409 `publish_requires_acknowledgement`** | the near-passages, the draft **untouched** (still `draft`, zero chunks) and the card **not** re-opened; re-POST with `acknowledge_semantic_overlap = true` publishes and records `publish_acknowledged_overlap` with the scores |
| comparison **failed**, or the scope's convenio has **no readable text** | the **acknowledgement** path, reason `semantic_compare_unavailable` / `semantic_no_text_to_compare` | copy that names the cause, so the prompt is never mistaken for a real near-passage |

**There is no path from "the comparison did not work" to a clean publish.** A failure is not evidence of absence, so it produces a question, never silence. The acknowledgement is per-attempt and never stored (the `confirm_scope_change` pattern), it satisfies **only** the review band, and editing the draft clears it — an acknowledgement is about the text that was compared.

### 3. Block thresholds are code config only — and the direction is `min`, not `max`

**ADR-0019's `max(floor, admin)` is wrong for a block-triggering similarity threshold.** Every guardrail knob in ADR-0019 is a **floor**, where raising means more caution. A block threshold is inverted: **lowering it blocks more.** Feeding it through `GuardrailPolicy::maxFloor` would let an admin *loosen* the publish fence through a mechanism whose entire promise is that it can only tighten — the most dangerous kind of bug, because the UI would say "stricter".

Consequently `semantic_conflict_threshold` and `semantic_review_band` live in `config/hr.php` and are **not exposed in the Sprint-6 guardrails UI**. **Any future admin exposure must combine with `min(baseline, admin)`, never `max`**, and must be recorded as such; that exposure is a Sprint-6-family follow-up, tracked in `roadmap.md`.

### 4. Calibration is a prerequisite, and it needs labels

An unmeasured threshold on a safety gate is worse than the blunt fence it augments, so `fence:calibrate-semantic` (read-only, writes nothing) measures **both**:

1. **The real distribution** — every already-published `internal_hr_ruling` against its convenio's active `official_convenio` chunks: per-ruling max / p90 / median, and block/acknowledge counts at candidate threshold pairs. This shows the **shape** of real rulings but carries **no ground truth**: a high score might be a true overlap or a false alarm.
2. **Synthetic labeled anchors** (`sprint-07d/eval/anchors.json`) — real convenio clauses with three probes of **known** relationship: (a) a near-verbatim paraphrase (a true same-point overlap → anchors the **block** threshold), (b) a same-topic-different-point passage (→ the **acknowledge** band), (c) an unrelated passage (→ the floor). Because the relationship is known, these are the only **labeled** evidence available.

`semantic_conflict_threshold` is then set **below the lowest score any class-(a) anchor produced**, so no known-true overlap escapes; the band catches class (b). **The command refuses to recommend a block threshold without class-(a) evidence** — a real-distribution run alone can describe but not justify (proven by test).

### 5. Fact version resolution — deterministic token overlap, then a human verdict

The duplicate pass uses **no embeddings and no threshold**: within the same convenio + topic, the digit tokens of `group_label` are compared, so `Grupos 1 y 2` ∩ `Grupo 2` = `{2}` is an **overlap** and surfaces as a **flag**. This is auditable, unit-testable, free, and — unlike a similarity score — cannot drift. Aboutness here is a *set* question, not a *meaning* question, so the deterministic answer is the better one.

The human then chooses one of three verdicts, each append-only in `tag_events`:

- **supersede** — the older fact's `validity_end` closes at `newer.validity_start − 1 day`; **both facts stay `verified`** and the lineage is recorded (`resolution`, `superseded_by_id`, `resolved_by`, `resolved_at`). **Nothing is ever deleted**, so a question dated inside the old window still gets the answer that was true then. The direction must be justified by the validity dates; the service refuses a supersede the dates do not support.
- **coexist** — not versions (different groups, different subjects). Both remain answerable; the flag stops asking.
- **reject** — one is a mis-segmentation. It takes the existing `rejected` status and is never applied to a fact a human already verified.

The duplicate link is **retained** after resolution: the flag is resolved, not erased. Resolution reaches chat **through the data only** — the 7c answer rule is untouched, and a corrected pair simply stops satisfying its escalate condition.

### 6. Succession — a conjunction, no LLM, and never a write

The relationship is derived from two **independent** signals:

| relationship | rule |
|---|---|
| `successor` | overlap ≥ `succession_overlap_threshold` **AND** the candidate's `validity_start` is **strictly later** |
| `conflict` | overlap ≥ threshold **AND NOT** strictly later |
| `coexisting_sibling` | overlap ≤ `succession_sibling_ceiling` (same convenio, different subject) |
| `uncertain` | in between, or nothing comparable |

`successor` is the one label that would tempt a human to **retire a live document**. Putting it behind a **conjunction** means a similarity model's opinion alone can never produce it: the validity dates, which are registry facts rather than inferences, must agree. A confidently-wrong successor needs two independent things to be wrong at once. Candidates are **same-convenio only**, matching the 7a confirm form, so a proposal can never suggest something the write-side would refuse.

The honest cost: a document that *contradicts* the expiring one without being a version comes out `conflict` with no explanation of what contradicts — the human reads the compared passages. For a queue whose only job is to route to a human, that is the correct conservative outcome, and it means (C) needs **no answer-model key**.

The proposal is **inert** (ADR-0020): it writes exactly three columns on the **review task** (`ai_proposal`, `ai_proposal_status`, `ai_proposed_at`) and **no `documents` column at all**. It is rendered fuchsia, because BGE-M3 similarity is an AI inference even without an LLM — the provenance rule is about whether a *machine* made the claim, not which kind. Confirming runs the **unchanged** 7a write-side, so lineage is still written by a human action, retirement still needs both `retire_predecessor` **and** `confirm_scope_change`, and **the predecessor is never auto-retired**. Rejecting writes a status and an audit row and leaves the task **open** — the document is still expiring.

### 7. §8.5 — the reverse re-check, at the flag-only bar

When an `official_convenio` becomes active (ingest or admin activation), published `internal_hr_ruling`s in the same scope are compared **in reverse**; anything above the review band opens a `conflict` review task carrying a `kind` discriminator in `raw_unmatched_values`, plus an artisan backfill. It **flags only**: it never changes `retrieval_status`, so a convenio arriving cannot silently demote a ruling. The existing `conflict` task type is reused rather than adding an enum value (no CHECK rewrite).

## Consequences

- **Three additive nullable migrations, no CHECK rewrite** (ADR-0007): `escalation_events.detail` (jsonb — machine-readable chunk ids/scores/thresholds behind `publish_blocked` and `publish_acknowledged_overlap`); `reference_facts.resolution` + `superseded_by_id` + `resolved_by` + `resolved_at`; `document_review_tasks.ai_proposal` + `ai_proposal_status` + `ai_proposed_at`. The new event types are free-string columns; the new publish reasons need no enum change.
- **hr-ai: one additive read-only endpoint, nothing else.** `POST /compare-scope` + one SELECT function. No write, no migration, no LLM. The `/sandbox-retrieve`-per-document loop is recorded as the zero-hr-ai-change fallback and deliberately **not built** (it would need P×N calls and could not filter authority in SQL — the property the safety argument rests on).
- **The fence is provably only stricter.** The four `Sprint5Correction01FenceTest` cases run through the combined fence with the semantic pass at `0.0` and behave exactly as before; case 4 (the untagged-topic pass) blocks at `0.95`; a 2×2 matrix asserts `blocked == (existing || semantic)`; a throwing comparison reaches the acknowledgement path and never a clean publish; the comparison is **never called** when `detectConflicts` is non-empty.
- **The answer loop is untouched.** No change to `/retrieve`, `/synthesise`, `/ground`, the router, or the 7c fact path. `Sprint7cAdditivityRegressionTest` (prose + salary, byte-for-byte) stays green, as does the whole existing suite.
- **Every AI relationship is a proposal.** Nothing auto-resolves, auto-links, auto-retires, auto-demotes or auto-publishes. Supersede closes validity and never deletes. The reverse re-check flags and never demotes.
- **Thresholds ship PROVISIONAL until calibrated on the corpus.** The build environment had no corpus database, so no score was measured; the harnesses, the labeled fixtures and the calibration *math* are tested, and `config/hr.php` says plainly that the values are provisional and how to set them. Until that run, the semantic fence's contribution is unquantified — but bounded: it can only add blocks and acknowledgements, so an uncalibrated threshold costs human attention, never safety.
- **The acknowledgement band is the mechanism to watch.** An acknowledgement a human learns to click through is a fence that has quietly opened. It is per-attempt, worded to name its cause, cleared by editing the draft, and audited with its scores (`publish_acknowledged_overlap`) so the click-through rate is measurable. If it turns out to be routinely dismissed, the band — not the block — is what needs adjusting.
- Cites **ADR-0007** (hr-backend owns writes; hr-ai reads/returns), **ADR-0011** (vocabulary bind-only; the review-task queue), **ADR-0015** (`/retrieve` and why it is not the comparison primitive), **ADR-0016** (fail-safe routing), **ADR-0019** (raise-only guardrails — and the documented inversion for a block threshold), **ADR-0020** (AI proposals inert until verified; fuchsia = unverified AI), **ADR-0021** (the `structured_reference` bound), **ADR-0022** (the segmentation flag this resolves), **ADR-0023** (the 7c answer rule, reached through data only).
