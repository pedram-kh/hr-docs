# Sprint 10c — Plan: Batch fact segmentation across topics

> Status: PLAN — written against the real code, the real corpus, and the live staging DB (queried read-only over SSH, 2026-09-13). Every claim below cites `path:line` or a live query result. **STOP at the end of this document — no code, no prompt changes, no batch runs, no commits.**

---

## A. The agent as it actually is

### A.1 — End-to-end trace, and what must change to run per-topic

**The chain today:**

```
reference_source ingest (DocumentIngestor)
  → SegmentReferenceSource::dispatch($document->id)                    hr-backend/app/Jobs/SegmentReferenceSource.php:35,52
  → ReferenceFactProposalService::propose($document)                   hr-backend/app/Services/ReferenceFactProposalService.php:57-105
      buildCandidateConvenios()  — ALL 27 convenios, always            :313-329
      buildCandidateTopics()     — ALL approved topics, always         :338-342
      ExtractionClient::segmentFacts()                                 hr-backend/app/Services/ExtractionClient.php:420-449
  → hr-ai POST /segment-facts                                          hr-ai/app/main.py:906-966
  → ClaudeProvider.segment_facts()                                     hr-ai/app/providers/claude.py:1488-1626
  → ReferenceFactProposalService::persist()  — upsert ai_agent/needs_review   :116-212
```

**Is topic hardcoded, configured, or a parameter? Hardcoded — twice, in Spanish, in the system prompt:**

- Rule 6: *"topic: vincula `topic_id` al topic 'periodo de prueba' de la lista si está presente; si no, déjalo null. Nunca inventes un topic."* — `hr-ai/app/providers/claude.py:652-653`
- Rule 12 (the content gate): *"CONTENIDO NO-PERIODO EN ESTA VÍA → NO EMITAS NADA. Si la fuente es una hoja de salarios/jornada/horas … NO emitas ningún hecho salvo que una línea enuncie CLARAMENTE una regla de PERIODO DE PRUEBA."* — `claude.py:680-686`
- The illustrative example in the prompt's opening line is also periodo de prueba: *"una recopilación de periodos de prueba por provincia y sector"* — `claude.py:607-608`

There is **no `topic` request field** (`SegmentFactsRequest` at `main.py:312-330` carries `candidate_topics: list[...]`, a closed set, not a selector) and **no topic parameter on the job** (`SegmentReferenceSource(public int $documentId)` — `SegmentReferenceSource.php:35`). The model is asked to bind to periodo de prueba specifically and to emit nothing for any other content; the closed candidate-topic list is decorative for every other topic today (rule 12 already tells the model to suppress non-periodo content before it would ever consult that list).

**A second, independent block: the routing invariant.** `SegmentReferenceSource::handle()` only proceeds when `$document->documentType?->code === 'reference_source'` (`SegmentReferenceSource.php:47-49`). On staging there are exactly **2** `reference_source` documents, both periodo de prueba fixtures (doc 105/106, confirmed live: `select id,title from documents where document_type_id in (select id from document_types where code='reference_source')` → `PERIODOS DE PRUEBA` / `PERÍODOS PRUEBA ACTUALIZADOS 2026`). Every convenio's real text lives as `convenio_text` documents (64 of them, 27 convenios), which this job **never touches** — it only reads `reference_source` uploads, which is exactly what 7b-2 was fixture-shaped for (a human-curated cross-province recopilación) and exactly what §2.1's tranche is not (the source is the convenio text itself, already ingested).

**What must change to run the tranche (three independent changes, not one):**
1. **Source selection.** The 7b-2 agent segments an uploaded `reference_source` recopilación; the tranche's source is convenio text already in `document_pages`. The 7f `ConvenioGroupProposalService::convenioText()` pattern (`hr-backend/app/Services/ConvenioGroupProposalService.php:91-101` — join `document_pages`→`documents` on `convenio_id`, concatenate) is the precedent to follow: a new per-(convenio, topic) driver reads convenio text directly, not a `reference_source` file. This is a bigger structural change than "add a topic parameter" — it is a new source-selection path alongside the existing one, which stays for any future curated reference_source uploads.
2. **Topic parameterization.** The prompt's rule 6/12 must become topic-driven: pass ONE target topic per call (not a closed list to bind against opportunistically) and rewrite rule 12 from "only periodo de prueba" to "only content matching the target topic, everything else → no fact." The header-carry mechanic (territory→sector→group) and the restraint rules (statutory fallback, ambiguity, group scope) are topic-agnostic and carry over untouched.
3. **Input sizing.** `SEGMENT_TEXT_CAP = 48000` chars (`claude.py:701`) was sized for the 7b-2 fixtures (2,059 and 5,110 chars — confirmed live, doc 105/106 page-text totals). Real convenio text runs 4,410–263,142 chars per convenio (confirmed live, 16 active convenios' `convenio_text` page totals; median ≈163,666). Passing full convenio text at this cap truncates ~14 of 16 active convenios outright. §B.5/§C.9 below quantify the fix: passage-scoped input (anchor-filtered pages per topic, not the whole convenio) rather than raising the cap on full text, because the per-topic anchored volume is far smaller (§B.5 table) and keeps header-carry scoped to the topic's own clauses instead of feeding 150+ unrelated pages through a 32k-output-token call.

### A.2 — Validity: job-time vs dispatch-time, and the fix

**Where it's read today:** `ReferenceFactProposalService::persist()` stamps every fact from the **document row at persist time** (inside the job's `handle()`, which runs whenever the queue worker picks it up — not when `SegmentReferenceSource::dispatch()` was called):

```119:122:hr-backend/app/Services/ReferenceFactProposalService.php
        // Validity rides the SOURCE document's human-set window (Q7) — the agent
        // never parses dates from prose. NULL = open-ended.
        $validityStart = $document->validity_start?->toDateString();
        $validityEnd = $document->validity_end?->toDateString();
```

This runs inside `persist()`, called from `propose()` (`ReferenceFactProposalService.php:105`), called from the job's `handle()` (`SegmentReferenceSource.php:52`) — i.e. it re-reads `$document` fresh at **job-execution** time via `Document::with('documentType')->find($this->documentId)` (`SegmentReferenceSource.php:39`). A batch of hundreds of queued jobs can sit in the queue for minutes; if an admin edits a document's `validity_start`/`validity_end` while jobs are queued (e.g. correcting a convenio's validity window mid-batch), every job that executes *after* the edit stamps facts with the **new** window even though it was queued *before* — the exact spec §2.4 defect.

**The fix — capture at dispatch, carry through the job, never re-read at execute time:**

1. `SegmentReferenceSource`'s constructor gains two nullable fields, captured from the document **at dispatch time** (inside `DocumentIngestor` / the manual re-segment action / the new batch driver — wherever `dispatch()` is called), not read again inside `handle()`:
   ```php
   public function __construct(
       public int $documentId,
       public ?string $capturedValidityStart = null,
       public ?string $capturedValidityEnd = null,
   ) {}
   ```
2. `ReferenceFactProposalService::propose()` gains the two fields as parameters (or a small DTO) and passes them straight through to `persist()`, which stamps from them instead of re-reading `$document->validity_start`/`validity_end`.
3. Every existing call site (`DocumentIngestor.php:372`, `ReferenceFactController::segment()` at `:467`, and the new batch driver) reads the document's current validity **once, at the dispatch call**, and passes it in. `SerializesModels` already serializes the job's public properties into the queue payload — a plain string is stable through Laravel's queue serialization the same way `$documentId` already is.
4. Test: enqueue a job with a captured window, then mutate the underlying document's `validity_start`/`validity_end` *before* the job executes, run the queue, and assert the persisted facts carry the **captured** (pre-mutation) window, not the document's current one. This is §D.11's dispatch-validity invariant test.

This is a small, mechanical, additive change — no schema change (facts already carry `validity_start`/`validity_end` copied values, not a live join to `documents`), no change to the manual-create or verify paths.

### A.3 — Group-dependent clauses: restraint, refusal, or convenio-wide extraction?

**Restraint around ambiguity and the statutory fallback is prompt-only** (rules 2/11/`uncertainty.field='scope'`, `claude.py:634-636,668-679`) — a deterministic backstop exists only for closed-set id validation (`claude.py:1563-1576`, drops/nulls invalid ids) and non-empty value (`:1566-1568`). **There is no restraint rule at all — prompt or code — for "this convenio has no approved group tree."** Searching the system prompt (`claude.py:604-696`) for any mention of `convenio_groups`, tree approval, or "sin árbol aprobado" returns nothing; rules 4/5 tell the model to always fill `group_label` "as written" and to flag `uncertainty.field='group'` only for a *compound* expression ("Grupo 1 y área cinco de Grupo 2") that doesn't map to one category — never for the *absence* of an approved tree to bind against.

**What the agent does in practice today, with real evidence:** it extracts a convenio-wide-looking `group_label` fact regardless of tree status. Live query against `reference_facts`:

| convenio_id | group_label | value | tree status (`convenio_groups.status`) |
|---|---|---|---|
| 3 | Grupo 1 | "Cinco meses" | `needs_review` (6 nodes, unapproved) |
| 4 | Grupo 1 | "Seis meses" | `needs_review` (6 nodes, unapproved) |
| 8 | Grupos 1 y 2 | "Un mes" | *(no tree row at all)* |
| 11 | Grupo 1 | "Seis meses" | `needs_review` (6 nodes, unapproved) |
| 12 | Técnicos titulados | "4 meses…" | *(no tree row at all)* |
| 13 | Grupo 1 | "Seis meses" | *(no tree row at all)* |
| 21 (Hostelería Navarra) | Grupo 1 (todas las áreas) y Grupo 2 (área 5) | "90/75/60 días…" | **`approved`** (5 nodes) — the one convenio with an approved tree |

(Full table via `select id, convenio_id, group_label, value from reference_facts where group_label is not null order by convenio_id` — 80 of 82 `needs_review` rows carry a `group_label`; only convenio 21 has an approved tree, live-confirmed: `select convenio_id, status, count(*) from convenio_groups group by 1,2` → 5 convenios `needs_review`, 1 (convenio 21) `approved`.)

The agent proposes a `group_label`-carrying fact for **every** convenio regardless of whether its tree is approved — it never refuses and never falls back to a convenio-wide fact when a tree is missing, because it has no signal that a tree even exists (the segmentation prompt is never told about `convenio_groups` at all). **Enforcement is entirely downstream, at answer time, not at proposal time:** `ReferenceFactAnswerService::matchByGroupNode()` only matches a fact to an employee via `reference_fact_group_scopes` — a *human-bound* join row (`ReferenceFactAnswerService.php:295-337`); an unbound group-labelled fact reaches neither Tier 2 (needs a bound node) nor Tier 3 (needs `group_label === null`, `:145`) and therefore always escalates at Tier 4 (`:150` comment: *"an unbound label is a fact nobody has vouched for"*). So the system is **safe by construction downstream** — a mis-scoped or unbound group-labelled `needs_review` fact can never be served even after verification unless a human also binds it to a group node — but the **proposal-time** behavior itself is not restraint at all; it's unconditional group-scoped extraction with no refusal-record lane (7f's `ReferenceFactGroupScope` manual-bind lane is a human action taken *after* verification, not something 7b-2 ever writes to).

**Verdict, plainly:** enforcement of "don't extract group scope without an approved tree" is **prompt-only in the sense that there is no prompt rule for it either** — it doesn't exist at proposal time. The safety net is entirely the answer-time matcher's fail-closed design (unbound → escalate) plus the human verification gate. That is sufficient to prevent a wrong answer from reaching an employee, but it is not what spec §2.3 asks for ("the agent must not invent group scope; it either proposes a convenio-wide fact only when the source is genuinely group-independent, or records a refusal").

**Proposed deterministic backstop (for D.11):** before persisting a segmented fact with a non-null `group_label`, check whether the fact's `convenio_id` has an `approved` `convenio_groups` row. If not: **still persist the fact** (rejecting it would throw away real information a human might want to review and manually bind later — 7f's manual-bind lane exists precisely for this), but set `uncertainty = {field: 'group', reason: 'no hay árbol de grupos aprobado para este convenio — revisar y vincular manualmente'}` if the model didn't already flag it, so the *queue* — not just the eventual answer path — surfaces "this one needs a human to also approve/bind a tree" rather than looking identical to a fully resolvable fact. This is additive to `ReferenceFactProposalService::persist()` (one more deterministic check alongside the existing duplicate-flag check at `:180-189`), touches no prompt, and is testable directly (`Sprint7b2SegmentationInvariantTest`'s sibling for 10c).

---

## B. The corpus, sampled for real

### B.4 — Per-topic difficulty, with real quotes

All quotes below are pulled live from `document_pages.text` for documents with `document_type=convenio_text` and `retrieval_status='active'` (16 convenios, confirmed live). Convenio numbers below are `convenios.numero`/id, not codenames.

| Topic | Sample (convenio id → quote) | Shape | Prediction |
|---|---|---|---|
| **permisos** | c.2: *"17 días naturales en caso de matrimonio o constitución de pareja de hecho…"* · c.10: *"Matrimonio o pareja de hecho: 16 días naturales…"* · c.18: *"Dieciséis (16) días naturales en caso de matrimonio…"* · c.25: *"Matrimonio y registro de parejas de hecho: 15 días naturales… Por fallecimiento de familiares 1º grado: 5 días laborables…"* | **Clean, scoped, enumerated.** Every convenio samples as a numbered list of "motivo → N días", almost always convenio-wide (no group qualifier seen in 5 samples). | **Easiest topic — passes the gate with the least iteration.** Matches 7b-2's own periodo de prueba shape (short enumerated clauses with an unambiguous figure). |
| **vacaciones** | c.13: *"un período de vacaciones anual mínimo de 31 días naturales"* · c.15: *"…mínimo de 33 días naturales"* · c.18: *"33 días laborables o 45 días naturales"* · c.19: *"32 días naturales"* · c.20: *"30 días naturales"* — but also c.25: *"Cuando la persona trabajadora se encuentre disfrutando las vacaciones y sea baja por nacimiento… interrumpirá el disfrute…"* (pure narrative, no figure) | **Bimodal.** A clean day-count sentence exists in most convenios, but the surrounding article is often long and narrative (interruption rules, calendar-negotiation procedure, collective-shutdown clauses) with the figure buried mid-paragraph, not in a table. c.18 mixes two units (laborables/naturales) in one sentence — a real double-figure trap. | **Medium — the figure is extractable but the source is narrative, not tabular; expect some low-confidence/uncertainty flags on unit-ambiguous or interruption-heavy passages, not mis-scope.** |
| **preaviso** | c.12 (employer-to-employee dismissal notice): *"Conceder un plazo de preaviso… de un mes…"* · c.18 (shift-scheduling notice): *"…con un preaviso mínimo de cinco (5) días"* · c.25 (temporary-worker recall): *"Durante el plazo de preaviso el trabajador deberá…"* · c.13 (working-time flexibility notice): *"…con un preaviso mínimo de cinco días al inicio del trabajo"* | **Polysemous, not narrative.** "Preaviso" appears attached to at least four *different* legal contexts in this corpus alone (dismissal notice, resignation notice, shift-change notice, temporary-worker recall notice, working-time-flexibility notice) — the word anchors the lexicon correctly but the same anchor hits clauses that are not "employee's resignation preaviso" at all. | **Hardest of the two "should be easy" topics — real risk is not mis-scope on convenio/group, but topic mis-identification (extracting the wrong *kind* of preaviso as if it were the employee-resignation one). The gate should specifically gold-fixture this confusion, not just scope.** |
| **jornada anual** | c.20: *"Año 2025: 1637 horas al año… Año 2026: 1634 horas… Año 2027: 1631 horas… Año 2028: 1628 horas"* (a yearly-declining schedule, one fact per year) · c.13: *"1.592,5 horas anuales, que en cómputo semanal resultan 35 horas"* | **Clean but multi-valued over time within one clause** (c.20's four-year declining schedule is one article, four validity-windows) — a natural fit for 7b-2's existing "one fact, full breakdown in `value`+`raw_values`" rule (rule 3), same as periodo de prueba's contract-type breakdown. | **Easy-to-medium: the figure is unambiguous, but a multi-year schedule inside one article needs the agent to either split by year (four facts, four validity windows — which the agent is *forbidden* from proposing dates for, rule 8) or bundle it in `raw_values` as one convenio-wide fact valid for the source's whole window. This is a genuine prompt-shape question the gold fixture should force a decision on, not leave implicit.** |
| **festivos** | c.20: *"…retribución por descanso semanal, pagas extraordinarias, vacaciones y festivos"* (a pay-composition clause, no day-count) · c.25: *"Plus de trabajo en domingos y festivos"* (a premium-pay clause) · c.12: *"TRABAJOS EN DÍAS FESTIVOS"* (a section header, not a rule) | **Mostly not the right kind of fact at all** — the anchor fires reliably (15/16 active convenios) but the surrounding clauses in the 6-sample pull are compensation/premium rules or headers, not a scoped statement of *how many* festivos or *which* holidays apply (that's typically a calendar, sourced from the official provincial holiday list, not the convenio text). | **Likely the topic to re-order or drop per spec §2.1's own escape hatch — "too narrative/off-target to yield scoped verbatim facts" is a legitimate finding here, pending a deeper sample before the gate (only 6 of 45 anchor-hit pages were read for this plan; a real gold-fixture pass should pull 15–20 before deciding).** |
| **descanso semanal** | c.3: *"descanso semanal que hubiere correspondido a la persona trabajadora"* (a make-up-day clause) · c.13: *"…sin perjuicio del descanso semanal de la tra[bajadora]"* · c.12: *"Cuando coincida la fiesta del descanso semanal con una…"* | **Narrative/embedded**, similar to festivos — the anchor fires but sampled clauses are almost all subordinate conditions inside other articles (make-up days, premium interactions), not a standalone "el descanso semanal es de N horas/días" statement. Weekly rest is largely governed by the Estatuto (ET art. 37) as a floor the convenio rarely restates numerically. | **Hard — expect a low direct-figure yield; predict most real facts here would properly be `uncertainty.field='scope'` (statutory-fallback-shaped) rather than convenio-specific, which is itself a useful, correct restraint result — not a failure.** |
| **horas extraordinarias** | c.18: *"Las personas trabajadoras a tiempo parcial no podrán realizar horas extraordinarias"* (a prohibition, not a figure) · c.25: *"No se podrán realizar horas extraordinarias o complementarias por parte de los…"* (same shape) · c.12: header only in the sample pulled | **Mixed: prohibitions/eligibility rules (clean, scoped, boolean-ish — extractable as a "no procede" fact) plus (per the anchor-hit page count, 34 across 13 convenios) likely a smaller number of clauses with the statutory +% surcharge figure.** A deeper pull (this plan sampled 6 pages) would very likely surface the surcharge-percentage clauses (ET art. 35's 75% floor is a common convenio pattern to restate or improve on). | **Medium — some real figures exist per the anchor-hit density, but this plan's shallow sample skewed toward prohibition clauses; the gold-fixture pass for this topic (after permisos/vacaciones prove the method) should pull a wider, deeper sample before predicting pass/fail with confidence.** |

**Caveat, stated plainly:** this table is built from **3–6 samples per topic**, exactly as asked (§B.4's own ask). Festivos and descanso in particular need a deeper pull before the per-topic gate is built — that deeper pull is itself part of §D.10's gold-fixture step, not a prerequisite to this plan.

### B.5 — Eligible sources per topic, yield range, and R2 group-dependence density

**Eligible sources (convenios with active ingested text × topic-anchor pages hit), live:**

| Topic | Convenios hit (of 16 active-text) | Pages hit | Total anchor-passage chars | Avg chars/convenio (of those hit) |
|---|---|---|---|---|
| vacaciones | 15 | 129 | 522,194 | 34,813 |
| permisos | 15 | 107 | 409,348 | 27,290 |
| jornada_anual | 15 | 77 | 298,053 | 19,870 |
| preaviso | 14 | 59 | 219,238 | 15,660 |
| horas_extra | 13 | 34 | 145,199 | 11,169 |
| festivos | 15 | 45 | 163,617 | 10,908 |
| descanso | 14 | 28 | 109,679 | 7,834 |

(Query: anchor regex per topic against `document_pages.text` joined to active `convenio_text` documents, grouped by topic — matches the same seven topics as the spec's tranche, using regexes built from `TopicLexicon::ANCHORS`.)

**Expected yield range per topic (facts proposed, before any group-tree unlock):** using 7b-2's own real ratio as the anchor — file 2 (5,110 chars of dense periodo-de-prueba recopilación) yielded 49 facts (≈1 fact per 104 chars of *already-dense, pre-curated* text) — and discounting heavily for the fact that convenio-text passages are 5–10× less dense per fact (narrative prose around each figure, per §B.4), a rough per-convenio yield of **1–4 facts/convenio for permisos and vacaciones** (multiple named categories/motivos per article) and **1 fact/convenio for preaviso/jornada anual/horas extra** (usually one governing figure, occasionally a multi-year schedule as one fact) is a reasonable planning range, giving:

| Topic | Convenios hit | Est. facts (low–high) |
|---|---|---|
| permisos | 15 | 20–50 (several motivos per convenio: matrimonio, fallecimiento, nacimiento, etc., each its own fact per rule 3's "one fact per ámbito") |
| vacaciones | 15 | 15–25 (usually 1 figure, occasionally 2 if group-split) |
| jornada_anual | 15 | 15–30 (multi-year schedules count as more raw_values inside fewer facts, per rule 3, but a genuine group split doubles this) |
| preaviso | 14 | 10–20 (real risk: over-yield from the polysemy trap in §B.4, more facts than *actually* about resignation preaviso — the gate must catch this) |
| festivos | 15 | 5–15 (predicted low-yield per §B.4's narrative finding) |
| descanso | 14 | 5–15 (predicted low-yield, statutory-fallback-shaped) |
| horas_extra | 13 | 10–20 |

These are **planning ranges for HR/queue-sizing purposes, not eval targets** — the per-topic gate (§D) measures accuracy, not volume; a topic yielding fewer facts than predicted because the source turned out to be narrative is §2.1's explicitly legitimate outcome, not a shortfall.

**R2 — group-dependence density, quantified:** only **1 of 27 convenios** (convenio 21, Hostelería Navarra) has an `approved` `convenio_groups` tree; 5 more have a `needs_review` tree (convenios 3, 4, 11, 18, 19 — live: `select convenio_id, status, count(*) from convenio_groups group by 1,2`); the remaining 21 have no tree row at all. Cross-referencing against the tranche's active-text convenios: of the 16 convenios with active text, **5** have a pending (`needs_review`) tree (3, 4, 11, 18, 19) and **0** of those 5 have an approved one — convenio 21's approved tree is itself in `retrieval_status='historical'` territory (its only `convenio_text` doc is historical, confirmed live: `documents.id=51, retrieval_status='historical'`), meaning the one convenio with an approved group structure currently contributes **zero** eligible active-text pages to this sprint's batch. **Practically: every group-labelled fact this sprint proposes across vacaciones/preaviso (the two topics most likely to be group-dependent, per real-world convenio structure) will land unbound and inert at answer time until the data pass approves a tree for one of the 5 pending convenios that also has active text** — which is 3, 4, 11, 18, or 19. This is the number for the data-pass handoff: **approving any of trees 3/4/11/18/19 unlocks group-scoped yield for an *already-active-text* convenio; approving 21's tree unlocks nothing until 21 gets a current (non-historical) text document.**

### B.6 — Convenios 4/21 (expired): would the agent pick them up by default?

**Confirmed: convenio 4 and convenio 21's only ingested text documents are both `retrieval_status = 'historical'`, not `active`** — live query: `select id, convenio_id, retrieval_status, validity_start, validity_end from documents where convenio_id in (4,21) and document_type='convenio_text'` → doc 89 (convenio 4, validity 2022-01-01 to 2025-12-31, `historical`) and doc 51 (convenio 21, same window, `historical`). Neither convenio has any `active` `convenio_text` document at all (both are absent from the 16-row active-text list in §B.5's table).

**Does the agent's source-selection pick them up by default?** Under the existing 7b-2 path, no document-selection logic exists at all (it only ever reads whatever `reference_source` is explicitly ingested). Under the **new** per-convenio driver this sprint must build (§A.1, following the `ConvenioGroupProposalService::convenioText()` precedent), the natural and correct implementation reads `document_pages` joined to `documents.convenio_id` **filtered to `retrieval_status = 'active'`** — the same filter every other retrieval path in the system already uses (prose retrieval, salary answers, and 7f's own group proposer all key off `active`/current text). Built that way, convenios 4 and 21 are **automatically excluded** — not because of a special-cased skip list, but because they simply have no active text row to select. **Spec R5's skip recommendation is therefore already the natural behavior of the correct implementation, not an extra rule to add** — the plan's build-prompt should state this explicitly as a design constraint ("source selection reads `retrieval_status='active'`, full stop") rather than hand-writing an `convenio_id not in [4,21]` exclusion, which would be a hardcoded hack that silently goes stale the moment a 7th expired convenio appears.

---

## C. Queue, ranking, cost

### C.7 — Current review-queue state and ordering

**State, live:** 88 total reference facts; 82 `needs_review` + 6 `verified`, all currently topic = periodo de prueba (`select source,status,topic.name,count(*) from reference_facts group by 1,2,3` → the only two rows are `ai_agent/needs_review/periodo de prueba: 82` and `ai_agent/verified/periodo de prueba: 6`). Of the 82 `needs_review`: 8 carry `uncertainty`, 80 carry a `group_label` (only 2 are convenio-wide), 6 are flagged possible duplicates, spanning 20 distinct convenios, average confidence 0.947 (min 0.700).

**Ordering today** (`ReferenceFactController.php:69-76`, confirmed by direct read):
```php
$query->orderByRaw('(uncertainty IS NOT NULL) DESC')
    ->orderByRaw('confidence ASC NULLS LAST')
    ->orderByDesc('id');
```
Uncertain-first, then lowest-confidence, then newest — a **risk-based** order (surfaces the AI's own self-flagged doubt first), not a demand-based one. No `sort`/`page_size` override exists server-side; the frontend (`ReviewQueuePage.tsx:102-104`) exposes pagination only.

**Is Analítica demand-ranking wired in? No — confirmed by absence.** `QuestionClusteringService::topicBreakdown()` and `unansweredRanking()` (`hr-backend/app/Support/QuestionClusteringService.php:265-285,298-317`) are only ever called from `AnalyticsController::clusters()` (`hr-backend/app/Http/Controllers/Admin/AnalyticsController.php:84-109`), which feeds the Analítica screen. Nothing in `ReferenceFactController` references `QuestionClusteringService` or `question_clusters` at all.

**Real demand signal available for ranking, live** (`question_clusters`, run_date 2026-09-13, ordered by `member_count`):

| Cluster (medoid) | member_count | escalation_rate | top_escalation_reason | headcount_weight |
|---|---|---|---|---|
| "¿Cuántos días de vacaciones me corresponden?" | 60 | 0.77 | estatuto_fallback_gap | 8 |
| "¿Cuánto puede durar mi periodo de prueba?" | 29 | 0.59 | reference_fact_coverage_gap | 8 |
| "¿Cuántos días de permiso tengo por matrimonio según mi convenio?" | 17 | 0.82 | estatuto_fallback_gap | 4 |
| "¿Qué permiso tengo por fallecimiento de un familiar?" | 17 | 0.94 | estatuto_fallback_gap | 4 |
| "¿Qué preaviso me deben dar en un despido objetivo?" | 16 | 1.00 | sensitive_topic | 4 |
| "¿Cuál es la jornada máxima anual?" | 16 | 0.875 | estatuto_fallback_gap | 4 |
| "¿Cuántas horas extraordinarias puedo hacer al año?" | 16 | 0.875 | estatuto_fallback_gap | 4 |
| "¿Cuánto preaviso tengo que dar si me voy?" | 16 | 0.875 | estatuto_fallback_gap | 4 |
| "¿Cuánto dura el permiso por nacimiento?" | 15 | 0.87 | estatuto_fallback_gap | 4 |

This **independently confirms the spec §2.1 tranche order is right**: vacaciones (60 members) and permisos-family (matrimonio 17 + fallecimiento 17 + nacimiento 15 = 49 combined) are the two highest-demand gaps after periodo de prueba itself, exactly the plan's first two topics for §D.10. Note "¿Qué preaviso me deben dar en un despido objetivo?" escalates as `sensitive_topic` (dismissal, not resignation) — a live, real-traffic confirmation of §B.4's preaviso-polysemy prediction: the highest-volume real "preaviso" question in the corpus is about a topic this sprint's preaviso facts must NOT try to answer.

**Proposed minimal ordering change (presentation only, no bulk action, respects §2.5's boundary):** add a fourth `ORDER BY` tier — after uncertainty and confidence (safety always outranks demand) — that sorts by a per-topic demand score joined from `question_clusters.headcount_weight`/`member_count` via `topic_id` → `TopicLexicon::TOPIC_NAMES` reverse-lookup → matching cluster medoids' summed weight. Concretely:
```php
->orderByRaw('(uncertainty IS NOT NULL) DESC')
->orderByRaw('confidence ASC NULLS LAST')
->orderByRaw('topic_demand_score DESC')   // new: joined subquery/precomputed column
->orderByDesc('id')
```
Cheapest correct implementation: a small nightly-computed `topic_demand_score` column on `topics` (populated by the same `questions:cluster` scheduled command that already computes `question_clusters`, reusing `topicBreakdown()`'s topic_key mapping) rather than a live join on every queue page load — keeps the query fast and reuses existing machinery. This is ordering/presentation only, exactly as spec §2.5 requires; it changes which fact a reviewer sees first, never what they can do to it.

### C.8 — Does the reviewer's per-fact view suffice? What's missing?

**What 7g already provides, confirmed by direct read of `ReviewQueuePage.tsx`/`ReferenceFactPanel.tsx`:** the list shows id/value/first-line source excerpt (hover tooltip for the full text)/scope (territory·sector or convenio numero)/group/confidence/flags, paginated 50/page. The **detail panel** (opened per-row) shows the full `source_excerpt` as a pre-wrapped blockquote, full convenio name + derived territory/sector/job category/group_label/topic/validity range, `source_locator`, and the uncertainty field+reason. This is sufficient for the periodo de prueba queue's actual shape (80/82 facts already single-line, single-figure, `Territory › Convenio › Group: value`).

**What's missing, concretely, for the tranche ahead:**
1. **`topic` is not shown in the list view** (only in the detail panel). Today this doesn't matter (queue is 100% one topic); the moment topic 2 lands, a reviewer working the queue sees rows from two topics interleaved by uncertainty/confidence with no way to tell which is which without opening each one. **Minimal fix: add a `topic` column to the list table** (`ReviewQueuePage.tsx`'s existing row renderer, `r.topic` is already in the API response per `listRow()` — `ReferenceFactController.php:566` — it's just not rendered in the list).
2. **No topic filter in the UI** (confirmed: "UI does NOT expose: convenio/topic/status/source filters" from direct inspection). The backend already accepts `topic_id` as a query param (`ReferenceFactController.php:54-56`) — only the frontend needs a dropdown. **Minimal fix: a topic-select control wired to the existing `topic_id` param**, letting a reviewer focused on "finish vacaciones this week" filter to it — this is presentation, not a new capability, and stays within §2.5's ordering/presentation boundary.
3. **The source-document link in the detail panel is rendered but non-functional** (`ReferenceFactPanel` mounted without `onOpenDocument` in `ReviewQueuePage.tsx:167-172`, confirmed by direct read) — a pre-existing gap, not new to 10c, but it will matter more once reviewers are checking real convenio-text excerpts (which are longer articles, more worth opening in full) rather than 7b-2's short recopilación lines. **Worth fixing in the same pass** since it's a one-line wiring fix, not new design.

Nothing above is a new field or a new backend capability — every piece of data needed already exists in the API response; this is exclusively presentation wiring, consistent with spec §2.5.

### C.9 — Cost/runtime estimate, resize question

**Real numbers from the actual 7b-2 runs (live + code-confirmed):**
- Model: `claude-sonnet-5` (confirmed live on staging: `HR_AI_ANSWER_MODEL=claude-sonnet-5`).
- System prompt: ≈6,650 chars (≈2,080 tokens at ~3.2 chars/token for Spanish).
- Vocabulary blocks (always sent in full, per `buildCandidateConvenios()`/`buildCandidateTopics()`): 27 convenios + 94 job categories + 12 approved topics ≈ 10,570 chars (≈3,300 tokens) — this is a **fixed per-call overhead regardless of topic or passage size**, confirmed live (`convenio_block_chars: 5026, jobcat_chars: 5146, topic_block_chars: 399`).
- File 2 (5,110 chars input, 49 facts out) is the closest real analogue to a topic-scoped passage call. `SEGMENT_MAX_TOKENS=32000` (streaming) is the ceiling, but a 49-fact JSON output is nowhere near that ceiling in practice (7b-2's review confirms it landed well inside budget after the truncation fix).
- **Estimated cost per call:** input ≈ (2,080 + 3,300 + passage tokens) tokens; output scales with fact count (2b-2's 49-fact output was the largest real sample — a few thousand output tokens). At `claude-sonnet-5` list pricing (Anthropic's published per-million-token rate — not separately re-verified this plan, flagged as an open question in §D.11), a single passage-scoped call (permisos/vacaciones-sized passage, ≈25-35k chars per §B.5) costs low-single-digit-dollar-cents to low tens of cents per convenio, not dollars — the 7b-2 precedent (2 files, full run) was inexpensive enough that its review never called out a cost figure at all (confirmed: no `$`/cost figure appears anywhere in `sprint-07b-2/review.md`).
- **Total tranche estimate:** 7 topics × ~14-15 eligible convenios × one call each ≈ **~100 calls** for the full batch phase (after all 7 gates pass) — at a conservative $0.05–$0.20/call this is **$5–$20 total for the entire tranche's batch runs**, trivial against the project's existing ~$4.5/day staging cost. This should be treated as a planning estimate to refine with the first real topic-1 batch's actual `trace_fragment.prompt_tokens`/`completion_tokens` (already returned by the endpoint per `claude.py:1616-1625` — just not currently logged by `ReferenceFactProposalService`, a one-line addition worth making as part of this sprint so the estimate becomes a measurement after topic 1).
- **Wall-clock:** `ExtractionClient::segmentFacts()` timeout is 180s per call (`ExtractionClient.php:431`); ~100 sequential calls ≈ 5 hours worst-case serial, far less if queued jobs run with any concurrency (the queue worker is single-process today per `docker-compose.staging.yml:136` — `queue:work` with no `--queue`/concurrency flags — so realistically this is serial. **Recommendation: run the batch overnight or accept a multi-hour wall-clock; do not add worker concurrency for this sprint alone** — it's a bigger infra change than the data volume justifies).
- **Resize needed? No.** This is 100% an external Anthropic API workload (identical shape to 7b-2, which needed no resize per spec R4's own framing) — no local embedding, no CPU-bound step. `t3.large` is unchanged; confirmed no resize was needed for 7e's OCR batch either, which is the closest precedent (external-API-bound, not CPU-bound).

---

## D. Evals + plan output

### D.10 — Gold-fixture method for permisos and vacaciones

Following the 7b-2 method exactly (`sprint-07b-2/eval/README.md`): hand-built gold set → score offline → live run → paste numbers into `review.md`. Concretely for the first two topics:

**Permisos:**
- **Source passages:** pull the full "Artículo — Permisos/Licencias retribuidas" article (not just the anchor-hit line) from 6–8 of the 15 anchor-hit convenios, prioritizing structural variety already visible in §B.4's sample: c.2 (single-list format), c.10 (lettered sub-clauses with conditional day-counting), c.18 (compound eligibility conditions — "no procede… con la misma pareja"), c.25 (numbered list explicitly stating "todos los permisos… en referencia a la consanguinidad y/o afinidad" — a scope note the agent must not drop), plus 2-3 more sampled during fixture-building to include at least one convenio with a group-dependent permiso (if any exists — this plan did not find one in its 5-sample pull, which itself is a finding worth confirming or refuting with the deeper gold-fixture pull).
- **Gold size:** ~25-35 entries (multiple motivos per convenio × 6-8 convenios, per rule 3's one-fact-per-motivo shape — matrimonio, fallecimiento (with degree-of-kinship breakdown, likely one fact with the breakdown in `raw_values` per rule 3, not several), nacimiento, etc.)
- **Mis-scope assertions:** every fact's convenio_id matches its source convenio exactly (0 tolerance, per the 0-mis-scope gate); a fact drawn from a degree-of-kinship table (c.25's "1º grado… 2º grado…") must land as ONE fact per motivo with the degree breakdown inside `value`/`raw_values`, not as separate facts per degree (a spot-check trap, same shape as 7b-2's own contract-type breakdown trap).
- **Restraint assertions:** any convenio-wide-looking permiso clause that is actually conditioned on a group/category (watch for this specifically — it wasn't seen in the 5-sample pull, but a 25-35-entry gold set drawn from 6-8 convenios is far more likely to surface one) must carry `group_label` and, if no approved tree exists for that convenio (true for all convenios sampled so far per §A.3), the new deterministic backstop's `uncertainty.field='group'` flag.
- **Harness location:** `hr-docs/sprints/sprint-10c/eval/permisos/` — `gold-set.json`, `score_eval.py` (reuse 7b-2's scorer, extended for the new topic's scope-matching shape if the value structure differs), `facts_*.json` (live-run exports).

**Vacaciones:**
- **Source passages:** the same 15 anchor-hit convenios' "Vacaciones" articles in full (not just the day-count sentence) — critically including c.18's dual-unit sentence ("33 días laborables o 45 días naturales") as a deliberate trap (the gold value must specify which unit or both, and a proposed fact that silently drops one unit is a mis-scope-adjacent value error the scorer must catch) and c.20's four-year declining schedule (per §B.4, a deliberate test of the multi-year-in-one-fact shape).
- **Gold size:** ~15-20 entries (mostly 1 fact/convenio, a few multi-year-schedule convenios contributing one fact with a richer `raw_values`).
- **Mis-scope assertions:** same 0-tolerance convenio-identity match; additionally, a specific assertion that c.20's four-year schedule is captured as one fact (not four, since rule 8 forbids the model proposing per-year validity dates) — this is the concrete decision §B.4 flagged as needing to be forced, and the gold set is where it gets decided and locked in.
- **Restraint assertions:** c.25's nacimiento/adopción-interruption narrative (no day figure at all) must NOT produce a fact (correct restraint, not a miss) — a deliberate negative case in the gold set, same spirit as 7b-2's salary-sheet negative test.
- **Harness location:** `hr-docs/sprints/sprint-10c/eval/vacaciones/`, same file shape.

**Later topics (preaviso onward) follow this exact pattern** once permisos/vacaciones prove the harness works end-to-end (source selection → topic-scoped prompt → score) — the spec's own framing (§2.2.4) and this plan agree there's no reason to design all seven gold sets before topic 1 is even measured.

### D.11 — Ordered steps, batch ceiling, invariant tests, open questions, checkpoints

**Ordered steps:**

1. **Dispatch-validity fix first** (§A.2) — small, mechanical, independently testable, and spec §2.4 requires it "before any batch runs." Ships with its own invariant test (below), green suites, no batch run yet.
2. **Group-tree deterministic backstop** (§A.3's proposal) — additive to `ReferenceFactProposalService::persist()`, ships alongside step 1 since both touch the same persist path and both are prerequisites the spec gates batch runs on (§2.3's "the plan must show how the current agent behaves here" is satisfied by §A.3; the fix itself should land before topic 1's batch, not after).
3. **Build the per-topic, per-convenio source-selection + prompt path** (§A.1) — the new driver (active-text-only, per §B.6), the topic-parameterized prompt (rewritten rules 6/12), passage-scoped input (anchor-filtered pages, not full convenio text, per §A.1's sizing finding). This is genuinely new code, not a config change — size accordingly.
4. **Topic 1 (permisos) gold-fixture eval** (§D.10) — build, measure, iterate the prompt until 0 mis-scoped. ⏸ **checkpoint** (see below) before batch.
5. **Topic 1 batch run** across all eligible convenios (§B.5's permisos row: 15 convenios) — capped per the ceiling below.
6. **Topic 1 yield + queue-ordering change** (§C.7's demand-score column) ships alongside topic 1's batch so the reviewer sees the new facts sensibly ordered from day one, not after topic 2.
7. **⏸ checkpoint: Pedram reviews topic 1's eval results** before topic 2 starts (per kickoff prompt's explicit minimum).
8. **Topic 2 (vacaciones) gold-fixture eval → batch**, same pattern.
9. **Topics 3-7, one at a time**, each gated exactly the same way; a topic whose sampling (§B.4) already predicts a narrative/low-yield shape (festivos, descanso) gets its gold-fixture pass done with an explicit go/no-go framing — "gate fails or yields near-zero" is §2.1's own legitimate outcome, to be reported, not forced.
10. **Queue UI presentation fixes** (§C.8: topic column, topic filter, document-link wiring) ship once, early enough to matter for topic 2 onward — not blocking topic 1, since topic 1's queue is still single-topic-equivalent in practice.
11. **Data-pass handoff document** (§B.5's group-tree unlock numbers, §B.6's 4/21 skip confirmation) — written once all topics have run, as the sprint's own deliverable.

**Batch ceiling proposal (R3):** per spec §2.5's "no bulk-approve" boundary, the ceiling isn't about limiting what's *approved* — it's about not flooding the queue faster than HR can plausibly work it. Given the existing queue already holds 82 (mostly unworked, per the periodo-de-prueba escalation cluster still showing a 0.59 escalation rate live), and the demand-ranked ordering (§C.7) will surface the highest-value facts first regardless of total volume: **propose no artificial per-topic cap on the batch run itself** (running fewer than "all eligible convenios" for a topic just means an artificially incomplete topic, which the per-topic yield table (§4 acceptance criteria) would then have to caveat) — instead, cap **how many topics run before the first human check-in**, which is already what the checkpoint structure above does (topic 1 fully reviewed before topic 2 batches). If HR's real throughput on the existing 82 turns out to be slow once observed, the *next* topic's batch is the natural place to hold, not a mid-topic artificial ceiling. This is a recommendation, not a decision — flagged as an open question below since it's a genuine product call.

**Invariant tests:**
- **Dispatch-validity test** (§A.2): queue a job with a captured validity window, mutate the document's validity after dispatch but before execution, assert the persisted fact carries the captured (not current) window.
- **Additivity proof** (spec §4.3): extend `Sprint7cAdditivityRegressionTest`'s existing pattern — segmentation writes only `needs_review` facts (never `verified`), and `ReferenceFactRouter::detectTopic()` only ever matches `status='verified'` (`ReferenceFactRouter.php:69-77`, confirmed by direct read) — so a batch run across all 7 new topics can be proven inert to the answer path by asserting the golden-trace suite's byte-for-byte outputs are unchanged after the batch (the existing test already fakes `ExtractionClient` entirely per the roadmap's own note that this test is "model-inert by construction" — the additivity proof for 10c is showing that inserting rows into `reference_facts` with `status='needs_review'` for new topics doesn't change a single golden trace, which is a direct consequence of the router's `where('status','verified')` clause and should be asserted directly against that clause, not just re-run the existing suite unchanged).
- **Group-restraint backstop test** (§A.3): a segmented fact with a non-null `group_label` for a convenio with no `approved` `convenio_groups` row must persist with `uncertainty.field='group'` set (if not already set by the model) — direct unit test on `ReferenceFactProposalService::persist()`, same style as the existing `Sprint7b2SegmentationInvariantTest`.
- **Source-selection test:** the new per-topic driver, given a convenio with only `historical` text (like 4 or 21), produces zero candidate pages — proving §B.6's "natural exclusion" claim directly rather than leaving it as an inference from the filter clause.

**Open questions (genuinely unresolved, not rhetorical):**
1. Anthropic's exact current per-million-token rate for `claude-sonnet-5` was not independently re-verified this plan (§C.9's cost estimate is a planning range built from 7b-2's real-but-unmeasured-in-dollars precedent, not a priced API lookup) — worth confirming before the batch-cost acceptance criterion (§4.4) is reported as a hard number.
2. Whether preaviso's polysemy trap (§B.4/§C.7) is severe enough to warrant a **prompt-level disambiguation instruction** (e.g. "only extract preaviso rules describing an employee's OWN resignation notice, never dismissal notice, shift notice, or recall notice") decided now vs. left to be discovered by the topic's own gold-fixture pass when its turn comes — this plan recommends deciding it now (add the instruction pre-emptively, since the ambiguity is already documented with real evidence) rather than waiting to fail the gate first, but that's a judgment call for review.
3. The batch-ceiling question above (no artificial cap vs. a per-topic cap) is a product decision, not an engineering one — flagged, not resolved.
4. Whether the group-tree backstop (§A.3) should be a hard **skip** (never persist a group-labelled fact for an untreed convenio) instead of the proposed **flag-and-persist** — this plan recommends flag-and-persist (matching 7b-2's own "wrong-but-flagged is tolerable" philosophy, spec §2.2.1) but a hard skip is a legitimate stricter alternative worth a explicit decision, not an assumption.

**⏸ Checkpoints (minimum, per the kickoff prompt):**
- ⏸ **Pedram approves the topic order + batch ceiling decision** (open question 3) before any batch run — including topic 1's.
- ⏸ **Pedram reviews topic 1's (permisos) eval results** — mis-scope count, restraint cases, the preaviso-disambiguation decision (open question 2) — before topic 2 (vacaciones) starts.
- ⏸ (Implicit in the per-topic gate structure, spec §2.2.4) the same review happens before every subsequent topic's batch, not just topic 2's — restated here so it isn't read as a one-time gate.

---

**STOP.** No code, no prompt changes, no batch runs, no commits have been made. Waiting for review.
