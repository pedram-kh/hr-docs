# Sprint 7c — Review (the reference-fact answer + the fact·convenio-prose composition)

> The ONE sprint that touches the frozen 2b answer loop. **Additivity is the whole
> discipline** — the load-bearing proof is the golden-trace regression (§4). Built in
> the plan's §5 order, all open questions resolved per the build prompt (ADR-0023).
>
> **Phase 1 is committed** at its gate (regression-clean, per the strict build order):
> hr-backend `a13cfef`, hr-frontend `288d15c`. **Phase 2 is built but NOT committed —
> awaiting your review** (the working tree across hr-backend / hr-frontend / hr-ai /
> hr-docs).

---

## 1. What was built

### Phase 1 — the routed reference-fact answer (the salary sibling) · committed

**hr-backend**
- **Migration `…130001_add_reference_fact_coverage_gap_to_escalation_cards_reason`** (additive) — the **only** schema migration in all of 7c; appends `reference_fact_coverage_gap` to the `escalation_cards.reason` CHECK enum via the proven introspect-drop-readd idiom (working `down()`), pattern-identical to the salary one.
- **`App\Support\TopicLexicon`** — `TOPIC_ANCHORS` lifted out of `ChatService` (a **pure relocation**) into a shared read-only lexicon: the anchor terms + a static `anchor → approved-topic-name` map + `matchTopicKeys()`/`candidateTopicNames()`. `ChatService::chunkTopics()` now delegates to it (re-rank behavior unchanged — proven by §4).
- **`ReferenceFactRouter`** — the deterministic, **no-LLM** pre-check (the salary-pre-classifier slot): non-salary questions only (defers to `RouterService::matchesSalary`, now `public`), anchors the **question** on the lexicon → approved `topics` row, and confirms a `verified`, in-scope, in-validity fact **exists**. Fail-safe: no match / ambiguity / error → `null` (fall through).
- **`ReferenceFactAnswerService`** (mirrors `SalaryAnswerService`) — the verified-in-scope-in-validity query; the most-specific-else-escalate scope resolution (Q2: `job_category_id` → confidently-resolved `group_label` → convenio-wide → escalate, **never guess**); the validity-contains-as-of selection (Q3: future-only → coverage-gap); the two-verified safe rule (most-recent validity, same-validity conflict → escalate); the exact `value`/`raw_values` answer; the `chunk_id = null` / `is_reference_fact` / `structured_reference` citation; **skip `/ground`**; `reference_fact_coverage_gap`.
- **`ChatService`** — the routed branch (Step 2c) + `answerReferenceFact()` + the `reference_fact` trace block + `floor_decision.path = "reference_fact"`, all through the **unchanged** `persistTurn`.

**hr-frontend**
- **`CitationList.tsx`** — a **"Dato de referencia"** badge for an `is_reference_fact` citation (the salary-citation rendering path; null-safe `key`).
- **`TracePanel.tsx`** + **`api.ts`** — the `reference_fact` trace step + the additive `is_reference_fact` / `reference_fact` types.

**hr-ai:** none (a DB query + answer assembly in hr-backend, exactly like salary).

### Phase 2 — the fact·convenio-prose merge · built, awaiting review

**hr-backend (`ChatService`, `GroundingService`)**
- **`composeFactWithProse()`** — runs on a verified-fact answer: reuse the existing `retrieveUnion`; detect **governing convenio/ruling prose on the topic** that clears **Check A** (Q5 — composition rides the proven floor). No governing prose / no answer model → return `null` → the Phase 1 quote (the additive fallback).
- **Same-point conflict → escalate, never blend.** `detectFactProseConflict()` (a deterministic, conservative figure-by-unit check) runs **before** `/synthesise`; a same-unit / different-value disagreement escalates `conflict` (the convenio governs) and never reaches the model. (The entailment gate alone can't catch this — it would find the fact's figure entailed by the fact source.)
- **One generated, grounded answer.** The fact is appended as one typed source (`source_type=reference_fact`, `chunk_id=null`, `structured_reference`) ordered **below** the convenio; `/synthesise` → Check B (a citation is valid as a provided chunk OR the fact source) → **`/ground`** (the composed answer **must** ground; the fact's claim entails against the fact, a prose claim against its chunk). Citation set = the cited convenio chunks + the fact (`chunk_id=null`), emitted **in the model's citation order** so `[Fuente N]` stays 1:1. `floor_decision.path = "reference_fact_composition"`; a `composition` trace block.
- **`GroundingService::check()`** — made null-safe + `source_type`-aware **additively**: a chunk source keeps its int `chunk_id` and the default `"chunk"` type (prose/salary grounding payload unchanged), a fact source carries `chunk_id=null` + `source_type=reference_fact`.

**hr-ai (`main.py`, `providers/base.py`, `providers/claude.py`)** — additive, no rewrite (Q7):
- `SynthesisChunk` / `GroundChunkBody` (Pydantic) + `ChunkInput` / `GroundChunk` (dataclasses) gain `chunk_id: int | None = None` and `source_type: str = "chunk"`.
- `_AUTHORITY_RANK` gains `structured_reference: 1` — **between** `official_convenio`/`internal_hr_ruling` (0) and `national_law` (now 2); `_authority_label` gets its Spanish label.
- The citation dedup key is **null-safe**: a fact keys on `(source_type, document_id)` instead of a colliding `None`. A prose-only turn sends no fact source → the request is byte-for-byte identical to pre-7c.

**hr-frontend (`api.ts`, `TracePanel.tsx`)** — the additive `composition` trace block + a **Composición** trace step (governing-prose count; a conflict shown as *escalates, no mezcla*). `CitationList` already renders the multi-source set from Phase 1.

### Docs
- **ADR-0023** (this sprint's decision). `architecture.md` §5 (the 7c block), `data-model.md` (the `reference_fact` / `composition` trace blocks, `floor_decision.path`, the null-`chunk_id` reference-fact citation, the new escalation reason, the `verified`-answerable note), `roadmap.md` (**7c DONE**), the three READMEs, and this `review.md`.

---

## 2. The additivity argument — what changes, what does NOT

**The single load-bearing claim:** a turn takes a 7c path **only** when a `verified` reference fact in the asker's scope matches the question's topic. Until 7c no fact was answerable, so **today no turn takes this branch**; after 7c, only the newly-answerable verified-fact turns diverge. Every other turn is unchanged **by construction**:

- The guardrail baseline + admin layer are untouched and still run first.
- The salary pre-classifier still wins an obvious salary question first; the reference-fact pre-check runs **only on a non-salary question** and **falls through** whenever no verified in-scope topic-matching fact exists (every prose/salary turn that exists today) → the LLM router + the full prose path run exactly as before.
- `/retrieve` is unchanged; the `/synthesise` + `/ground` Phase-2 fields are **absent** on a prose-only turn (defaults: `source_type="chunk"`, `chunk_id` int) → the payloads and behavior are identical to today.
- `persistTurn`, citation persistence, trace persistence reused unchanged.

---

## 3. The safety spine — only verified answers, the convenio governs (tested hardest)

`Sprint7cReferenceFactAnswerTest` (10 tests) — Phase 1 / the inert-until-verified gate:

| invariant | test |
|---|---|
| a `verified` fact answers in chat at `structured_reference`, cites `chunk_id=null`, **skips `/ground`** | `test_verified_fact_answers_in_chat_…_and_skips_ground` |
| a `needs_review` / `rejected` fact is **never** quoted (route not reachable + service refuses) | `test_needs_review_…`, `test_rejected_fact_is_never_quoted` |
| a **future-only** / **expired** verified fact is never quoted → `reference_fact_coverage_gap` | `test_future_only_…`, `test_expired_…` |
| a per-group-only fact with an **unresolved** group **escalates, never guesses** | `test_per_group_only_fact_with_unresolved_group_escalates_not_guesses` |
| a per-group fact answers when the employee's group **resolves**; job-category is most-specific | `test_per_group_fact_answers_…`, `test_job_category_fact_is_most_specific_…` |
| two verified, same-validity, differing values → **escalate, never blend** (7d resolves); different validity → most-recent | `test_two_verified_same_validity_conflict_…`, `test_two_verified_different_validity_picks_most_recent` |

`Sprint7cCompositionTest` (3 tests) — Phase 2 / the merge:

| invariant | test |
|---|---|
| a verified fact + governing convenio prose compose **one grounded answer** (multi-source citations, the fact + the convenio chunk; **`/ground` ran**; both authority levels) | `test_composition_merges_fact_and_convenio_into_one_grounded_answer_that_must_ground` |
| a same-point conflict (fact 90 días vs convenio 60 días) **escalates `conflict` before synthesis — never blends** (exploding `/synthesise` proves it is never reached) | `test_same_point_conflict_escalates_and_never_blends` |
| a composed answer that fails the entailment gate **escalates `low_confidence`** (must-ground) | `test_composed_answer_failing_grounding_escalates_low_confidence` |

---

## 4. The regression check — the frozen loop is provably unchanged (the load-bearing section)

`Sprint7cAdditivityRegressionTest` (2 tests) pins a representative **prose** turn (Navarra *vacaciones* → "37 días laborables", cited to the convenio chunk, never the Estatuto) and a **salary** turn to a pre-7c **golden trace**, asserting the **answer text + the full trace** (router_decision, retrieval passes/rerank, floor_decision, authority_used, grounding) are **identical**, and that **no `reference_fact` block is present** (the pre-check provably falls through). It was the Phase 1 commit gate and was **re-run after Phase 2** — both green.

**Run status (this turn, after Phase 2):**
- `Sprint7cAdditivityRegressionTest` → **2 passed**.
- `Sprint7cReferenceFactAnswerTest` → **10 passed**.
- `Sprint7cCompositionTest` → **3 passed**.
- The **full `tests/Feature` suite → 69 passed, 312 assertions** (prose, salary, guardrail, router, the whole answer loop — green).
- hr-frontend `tsc --noEmit` → clean. hr-ai dataclasses/rank/labels verified to import and construct with the new fields (the `app.main` import needs `fitz`/PyMuPDF, absent in this venv — unrelated to the change; the Pydantic field pattern mirrors the existing `page_from: int | None = None`).

---

## 5. The skip-ground (P1) vs must-ground (P2) line — drawn explicitly

- **Phase 1 quotes a verified value → skips `/ground`.** The answer *is* the fact's `value`/`raw_values`, verbatim (like the salary cell). Nothing generated to entail.
- **Phase 2 generates a composed answer → must `/ground`.** It combines/paraphrases the fact *and* convenio prose into new sentences — synthesized substantive claims, each entailed against its cited source.

A subtle bug found-and-fixed during the build: `compositionCitations` first ordered the fact ahead of the convenio chunks, which would have broken the backend's `[Fuente N]` 1:1 renumbering (markers follow the model's citation order). Fixed to **preserve the model's citation order** while resolving each entry to the fact citation or a convenio chunk citation.

---

## 6. Separability + the deferral fallback (honored)

Phase 1 introduced **no** call to `/synthesise`/`/ground` and **no** change to their shapes, so it was built, regression-checked, and **committed clean** before Phase 2 began (the strict build order). Phase 2 sits entirely on top: if it had proven too delicate it would have deferred cleanly, leaving Phase 1 shippable (facts answerable, fact-only). One test fixture adjustment documents the boundary precisely: the Phase 1 "exploding-AI" happy-path test now also stubs `/retrieve` to return empty, so Phase 2 composition **provably falls through** to the Phase 1 quote (the test still proves the fallback never calls `/route`/`/synthesise`/`/ground`).

---

## 7. Scope honored / out of scope

- **No conflict/version resolution (7d).** The two-verified safe rule and the same-point conflict check **escalate**; they never pick a winner, merge, or delete.
- **Composition is scoped to fact + convenio-prose on a shared topic.** A salary+reference compound, or arbitrary multi-topic triples, stay escalate-with-note (the existing `cross_path` posture).
- **One additive migration in all of 7c** (`reference_fact_coverage_gap`); Phase 2 adds none; hr-ai never migrates (ADR-0007).
- **Group-matcher bare-digit limitation → recorded as Sprint 7f (don't fix here).** `factMatchesGroup` keys off a bare-digit token in the prose `group_label`, so a fact whose group distinction lives only as prose (e.g. convenio 21 *"Grupo 2 (área 5)"* vs *"Grupo 2 (resto áreas)"*) could be a confident wrong-group answer once a group-scoped fact is verified **and** an employee resolves a clean digit `group_code`. **Latent today** (convenio 21 has zero `convenio_job_categories`, so no group resolves). The proper fix — structured group scope, AI-propose-then-human-verify, deterministic exact match at answer time — is **Sprint 7f**, which **gates group-scoped answering from going live** (see `roadmap.md` Sprint 7f and the `deploy.md` precondition).

---

## 8. Pre-existing item (out of scope, noted)

`hr-frontend/src/pages/admin/DocumentDetailPanel.tsx` carried a pre-existing unused-symbol lint (`onChanged`) flagged in earlier sprints; it is untouched by 7c and `tsc --noEmit` for the project is clean. Left as-is to stay in scope.

---

## 9. What awaits your review (not committed)

The Phase 2 working tree: **hr-backend** (`ChatService`, `GroundingService`, `README`, `Sprint7cReferenceFactAnswerTest` fixture, new `Sprint7cCompositionTest`), **hr-frontend** (`api.ts`, `TracePanel`, `README`), **hr-ai** (`main.py`, `providers/base.py`, `providers/claude.py`, `README`), **hr-docs** (ADR-0023, `architecture.md`, `data-model.md`, `roadmap.md`, this `review.md`). Phase 1 is already committed at its gate.
