# Sprint 7c — The multi-source composition layer (answer-engine change)

> Location: `hr-docs/sprints/sprint-07c/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: **the 2b answer loop** in `architecture.md` — the router (`/route` + the **deterministic salary pre-classifier** in `RouterService`), **3a Salary-in-chat** (`SalaryAnswerService`, SQL, ADR-0006 — **the exact pattern Phase 1 mirrors**: resolve scope → query the structured table → return the exact cell → cite the source document `chunk_id=null` → **skip `/ground`** → `salary_coverage_gap` on no row), **4 Synthesise** (`/synthesise`, ADR-0015 — abstains rather than fabricates; the substantive-vs-provenance rule), **5 the answer-or-escalate decision** (Check A/B, `floor_decision`), the **authority-precedence rule** (convenio governs; `national_law` baseline-where-silent; the widened-pool re-rank), the **per-claim grounding gate** (`/ground`), and the **salary citation** (`message_citations.chunk_id = null`); `data-model.md` — `reference_facts` (the queried columns), the `trace`/`floor_decision`/`router_decision`/`salary` blocks, `message_citations.chunk_id` nullable; ADR-0006/0015/0016/0021; `roadmap.md` Sprint 7c.
> **The ONE sprint that touches the frozen 2b answer loop — done by extending the proven salary pattern, then composing on top.** The de-risking insight: the loop already answers from an **exact-structured path (salary)** beside the vector path (prose). **Phase 1 does for reference facts exactly what salary already does** (`reference_facts` was built in 7b-1 to mirror the salary schema precisely so this is an extension, not new surgery). **Phase 2** adds the genuinely-new piece — composing a reference fact **and** convenio prose into one grounded answer.
> **Built as ONE sprint in TWO internal phases under one review/commit (Pedram's call):** **Phase 1 (the routed reference-fact answer) lands first and clean — proven, additive, regression-checked. Phase 2 (the fact+prose merge) builds on top.** Foundation-first ordering is deliberate: if Phase 2 proves hairier than expected mid-build, Phase 1 is a clean, shippable fallback (facts answerable on the proven pattern) and Phase 2 defers — rather than a half-built tangle. The phases share machinery (the pre-check, `ReferenceFactAnswerService`, the citation, the precedence slot); the review covers both, but the build order is strict.

## Goal
Make verified reference facts **answerable in chat** and **composable with convenio prose** — the payoff of all of 7b. **Phase 1:** a deterministic pre-check routes a reference-fact question to a new `ReferenceFactAnswerService` that answers **from the verified fact** (mirroring salary — exact, source-cited `chunk_id=null`, `structured_reference` authority, skip `/ground`). **Phase 2:** when a question needs **both** a reference fact and convenio prose, the engine retrieves both and composes one **grounded** answer with **authority preserved** (the convenio governs; the fact is the lower-authority structured datum). **Purely additive to the frozen loop:** a question that needs neither a fact nor composition gets the byte-for-byte identical answer it gets today. **Only `status = verified` facts answer** (the inert-until-verified gate finally lifts — exclusively for verified facts).

---

## PHASE 1 — The routed reference-fact answer path (the salary-parallel · build first, land clean)

### 1A. The deterministic reference-fact pre-check (mirrors the salary pre-classifier)
- A new detection step in `RouterService` (or sibling), in the **same slot** the deterministic salary pre-classifier runs (after the guardrail baseline, with/just-after the salary pre-check, before the LLM router). **Deterministic, no LLM** — reference facts are **topic-tagged + scoped**, so the check is *"does the employee's resolved scope (convenio + as-of) have a **verified** reference fact whose `topic` matches this question's topic?"*
- **Topic matching reuses the existing topic lexicon** the precedence re-rank uses (vacaciones, jornada, permisos, periodo de prueba, …) — the question's topic anchor vs the fact's `topic_id`. Do **not** invent a new classifier.
- **Fail-safe (ADR-0016):** uncertainty → fall through to the **existing** path (prose/router); never a wrong confident route. The pre-check only *adds* a route when a matching verified in-scope fact exists.

### 1B. `ReferenceFactAnswerService` (the salary sibling)
- A new `hr-backend` service, deterministic: given resolved scope + topic + as-of, **query `reference_facts`** for the verified, in-scope, in-validity, topic-matching fact:
  - **Scope:** `convenio_id` = resolved convenio (+ `job_category_id`/`group_label` if a group resolves, else the convenio-wide fact); **`status = 'verified'` only**; `topic_id` matches; validity contains as-of (the salary "most-recent `≤ as-of`" discipline → "validity_start ≤ as-of ≤ validity_end-or-open").
  - **Answer** states the fact's `value` (+ relevant `raw_values` breakdown — e.g. 90/75/60 by contract type), **cites `source_document_id`** with `chunk_id = null` (the salary citation shape), records **`authority_used = structured_reference`**, and **skips `/ground`** (exact, quoted from the verified fact — no generated claim to entail, exactly as salary skips it).
  - **No usable fact** (no verified in-scope in-validity match; only unverified/`rejected`; future-only validity) → **escalate `reference_fact_coverage_gap`** (distinct from `low_confidence`, the `salary_coverage_gap` precedent). **Never** quote an unverified or out-of-validity fact.
  - **Two verified facts match** → a deterministic safe rule (most-recent validity; else escalate). Conflict *resolution* is 7d.
- **Trace:** a `reference_fact` block parallel to `salary`: `{ convenio_id, topic_id, job_category_id/group_label, as_of_date, fact_id, validity_selection, value, authority_used, outcome, note }`; `floor_decision.path = "reference_fact"` (no A/B retrieval checks — structured-grounded).

### 1C. Phase-1 additivity (the load-bearing discipline)
- The prose path (`/retrieve`·`/synthesise`·`/ground`·Check A/B·precedence re-rank) and the salary path behave **identically to today** for every non-reference-fact question. Phase 1 is a **new routed branch + a new service + a new trace block** — **no change** to `/retrieve`/`/synthesise`/`/ground`/the re-rank/the salary path.
- **Only `verified` facts answer** (ADR-0020/0021 gate lifts exclusively for `verified`).
- **Phase 1 is independently shippable.** After Phase 1, facts are answerable on the proven pattern; a `git`-clean Phase 1 is the fallback if Phase 2 is deferred.

---

## PHASE 2 — Fact + convenio-prose composition (the merge · build on top of a clean Phase 1)

### 2A. Detecting a composition turn
- A turn needs composition when the question's topic has **both** a verified reference fact in scope **and** governing convenio prose (or the question has a fact-clause and a prose-clause). Detection extends Phase 1's pre-check: where Phase 1 found a fact, Phase 2 also runs the **existing** prose retrieval for the same question and sees whether governing convenio prose on the topic is present.
- If only the fact is relevant → Phase 1 (fact-only). If only prose → today's prose path. If **both** → the composition path (2B).

### 2B. Composing one grounded answer (authority preserved — the delicate core)
- Retrieve the **reference fact** (Phase 1's query) **and** the **convenio prose** (the existing `/retrieve` + precedence re-rank, unchanged). Hand **both** to synthesis as typed, authority-labelled sources — the fact at `structured_reference`, the convenio chunks at `official_convenio`, ordered by the **existing** precedence rule.
- **The convenio governs.** Synthesis composes an answer that uses the fact as the structured datum **under** the convenio's governing text — never letting the lower-authority fact override or contradict an official convenio statement on the same point. This reuses the **existing authority-precedence prompt discipline** (convenio governs; baseline-where-silent), extended to place `structured_reference` below `official_convenio`. On genuine fact-vs-convenio conflict on the same point → **escalate** (`low_confidence`/a conflict reason), **never blend or silently prefer the fact** (the same no-silent-override spine as the salary/Estatuto precedence and the Sprint-4 ruling gate).
- **Grounding applies (unlike Phase 1).** A composed answer **is synthesized** — it combines/paraphrases across sources — so it **goes through `/ground`** (the per-claim entailment gate), with each substantive claim entailed against **its** cited source (the fact's claim against the fact; the prose claim against its chunk). **This is the key difference from Phase 1:** Phase 1 quotes a verified value (skip ground); Phase 2 generates a composed answer (must ground). The citation set includes the fact (`chunk_id=null`) **and** the convenio chunks.
- **Trace:** the composition turn records both sources, `authority_used` listing both levels in precedence order, the grounding verdict, and a `composition` marker so an auditor sees *answered from convenio (governing) + reference fact (structured datum), grounded*.

### 2C. Phase-2 additivity + the fallback
- Phase 2 **reuses** the existing `/retrieve`, precedence re-rank, `/synthesise`, and `/ground` — it adds the **fact as an additional typed source** into synthesis and the grounding citation set. It must **not** change how prose-only or salary turns behave (the regression check from 1C still holds after Phase 2).
- **If Phase 2 proves too delicate to land safely this sprint, it defers** — Phase 1 ships alone (facts answerable, fact-only), and the merge becomes a clean follow-up. The plan must keep Phase 2 separable enough that this fallback is real (Phase 1 committed-clean before Phase 2 begins).

---

## Out of scope (do NOT build)
- **Unverified facts answering** (only `verified`); **vocabulary/scope changes** (read-only query of existing facts); **semantic conflict/version *resolution*** (7d — a deterministic safe rule only: most-recent validity else escalate; the rich resolution is 7d); **the prose/salary paths' internals** beyond adding the fact as a typed source in Phase 2 synthesis; **OCR** (7e); **new authority semantics** beyond slotting `structured_reference` into the existing order (ADR-0021).
- **Full per-clause decomposition of arbitrary multi-topic compounds** (the richer Fix-3 follow-up) beyond fact+prose on a shared topic — keep the composition scoped to the fact+convenio-prose case; a salary+fact+prose triple is escalate-with-note.

## Acceptance criteria
1. **(P1) Deterministic pre-check** routes to the reference-fact path **iff** the scope has a **verified**, in-validity, topic-matching fact; else falls through to **exactly today's** behavior. No LLM in the routing decision.
2. **(P1) `ReferenceFactAnswerService`** answers from the **exact verified fact** (`value` + relevant `raw_values`), cites the source doc `chunk_id=null`, records `authority_used=structured_reference`, **skips `/ground`**; trace carries the `reference_fact` block + `floor_decision.path="reference_fact"`.
3. **(P1) Only `verified` facts answer** — unverified/`rejected`/out-of-validity/future-only never quoted; no usable fact → `reference_fact_coverage_gap`.
4. **(P2) A fact+convenio-prose question composes one answer** with **the convenio governing**, the fact as the `structured_reference` datum beneath it; on genuine same-point conflict → escalate, never blend.
5. **(P2) The composed answer is grounded** (`/ground`, each substantive claim entailed against its cited source; the citation set = the fact `chunk_id=null` + the convenio chunks). Composition recorded in the trace with `authority_used` in precedence order.
6. **(both) Purely additive:** prose and salary turns are **byte-for-byte unchanged** vs today (a regression test confirms identical answers/traces pre-7c, holding after **both** phases). Phase 1 is **committed-clean before Phase 2 begins** (the separable fallback).
7. All decisions `hr-backend` (deterministic, legal-weight, owned); `hr-ai` synthesis/ground **reused not rewritten** (Phase 2 passes the fact as an added typed source); additive migrations only.

## Eyes-on
**(P1)** As an employee in **Navarra Hostelería Grupo 1**, ask *"¿cuál es mi periodo de prueba?"* → the chat **answers from the verified fact** (90/75/60 días), cited to its source, at `structured_reference` (trace shows `path:"reference_fact"`, the `fact_id`). The 7b work reaches an employee. **Ask the same for a scope whose fact is `needs_review`** → it does **NOT** answer from the fact (`reference_fact_coverage_gap` or fall-through) — **only verified answers**.
**(P2)** Ask a question where a fact **and** convenio prose both apply → one **composed** answer, the convenio governing, the fact beneath it, **grounded** (trace shows both sources + `authority_used` in precedence order). Force a fact-vs-convenio same-point conflict → **escalates**, doesn't blend.
**(both)** Ask a **prose** question (vacaciones) and a **salary** question → **exactly as before** (the additive guarantee).

## Risks / notes
- **This is the frozen loop — additivity is the whole discipline.** Biggest risk: a regression in prose/salary. The plan must make the change demonstrably additive and include a **regression check** that holds after both phases. Phase 2 **reuses** `/synthesise`/`/ground` (the fact is an *added typed source*), never rewrites them.
- **Phase 1 first, committed-clean, as the fallback.** The foundation-first order is the safety: if Phase 2's merge proves too delicate, Phase 1 ships (facts answerable) and the merge defers cleanly. Do **not** entangle them such that Phase 1 can't stand alone.
- **Only verified answers — the safety spine of all 7b connecting to chat.** The gate opens **only** for `verified`. A bug answering from a `needs_review` fact undoes 7b's safety. Test hardest.
- **Phase 1 skips `/ground`; Phase 2 must `/ground`.** The line is "quoted verified value" (P1, exact, skip) vs "synthesized composition" (P2, generated, must entail). Getting this right is the core grounding-correctness call of the sprint.
- **Precedence: the convenio always governs.** `structured_reference` sits below `official_convenio`; a fact never overrides the law. Same-point conflict escalates, never blends — the no-silent-override spine.
- **Don't over-reach Phase 2.** Scope the merge to fact+convenio-prose on a shared topic; triples and arbitrary multi-topic compounds stay escalate-with-note (the richer follow-up).

## Definition of done
All criteria pass (both phases, or Phase 1 alone with Phase 2 explicitly deferred if the merge can't land safely — stated plainly); Pedram eyes-on (the Navarra fact reaching chat; the unverified non-answer; the composed grounded answer; the prose/salary additivity); docs updated — `architecture.md` (the routed reference-fact path beside salary **and** the fact+prose composition: the pre-check, `ReferenceFactAnswerService`, the `chunk_id=null` citation, the `structured_reference` precedence slot, the skip-ground-vs-ground line between the phases, the `reference_fact_coverage_gap`, the composition's authority-preserved synthesis + grounding), `data-model.md` (the `reference_fact` trace block, `floor_decision.path:"reference_fact"`, the composition trace marker, the new escalation reason), `roadmap.md` (**7c done** — or 7c Phase 1 done / Phase 2 deferred, if so; 7d/7e remain), an **ADR** for the composition layer (the routed reference-fact answer + only-verified + skip-ground-vs-ground + the structured_reference precedence slot + the convenio-governs composition — beside ADR-0006/0015/0016/0021). Cursor writes `hr-docs/sprints/sprint-07c/review.md` (both phases, the additivity/regression proof, the only-verified test, the composition grounding) and **stops — no commit until I review**.
