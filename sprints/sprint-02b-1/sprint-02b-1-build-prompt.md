# Sprint 2b-1 — Cursor build-authorization prompt

> Paste the block below into the Sprint 2b-1 Cursor thread (the one that wrote `plan.md`). The plan is approved; this authorizes the **build**. Every `plan.md` §13 question is resolved below, and **one required addition** — authority precedence in synthesis — plus two notes are folded in. Build in the §1 phase order, update the named docs in the same change, write `review.md`, and **STOP — do not commit**.

---

The Sprint 2b-1 plan (`hr-docs/sprints/sprint-02b-1/plan.md`) is approved. Build it now in the Phase 1→2→3 order of plan §1. Honour **ADR-0007** (synthesis in `hr-ai`; scope + all DB writes + the decision in `hr-backend`), **ADR-0010** (`hr-ai` writes only `document_chunks` + S3), **ADR-0012** (design system), **ADR-0013** (chunks), **ADR-0015** (external answer model + key handling). All `plan.md` §13 questions are resolved as confirmed below, and apply the **required addition** and **two notes** exactly.

## Required addition — authority precedence in synthesis (legal-weight; do not skip)

The pipeline retrieves convenio **and** `national_law` (Estatuto) chunks together (`include_national_law: true`). The plan passes them **flat** to synthesis with no precedence rule — `authority_level` is only used for a display badge. That risks the system's core failure mode: handing an employee the **national baseline** when their **convenio improves on it** (e.g. the Gipuzkoa Limpieza convenio grants **31** vacation days vs the Estatuto's 30). Fix this in the synthesis layer:

1. **`hr-backend` labels each chunk with its `authority_level`** in the `/synthesise` payload (it already has it) and orders convenio chunks before `national_law` chunks.
2. **The synthesis prompt encodes the precedence rule explicitly:** the employee's **convenio governs the topics it addresses**; **`national_law` (the Estatuto) is the baseline that applies only where the convenio is silent**. Prefer convenio chunks when they speak; use the Estatuto for gaps.
3. **On genuine conflict** (convenio and Estatuto give *different specific answers to the same question*): surface the **convenio's** answer as governing and note the Estatuto as general baseline — **or escalate** (`reason: low_confidence`) if the model cannot confidently tell which governs. **Never blend, never silently present the baseline as the answer.**
4. **The trace records `authority_used`** — which authority level(s) the answer drew on — so an auditor can see "answered from the convenio" vs "answered from the baseline." Add this to the `message_traces.trace` structure (§7) and to the synthesis `trace_fragment`.

This is the **safe floor**, not full labor-law adjudication (norma más favorable / territory hierarchies remain later work) — but a scoped answer is meaningless if the convenio doesn't govern where it speaks.

## Two notes — fold in

- **Do NOT make Check C (model self-reported confidence ≥ `ANSWER_CONFIDENCE_FLOOR`) a primary gate.** LLM self-confidence is poorly calibrated; an external model that is confidently wrong is the exact risk ADR-0015 names. The **load-bearing gates are Check A (retrieval score ≥ `RETRIEVAL_SCORE_FLOOR`) and Check B (citations present and every cited `chunk_id` was in the provided set)**. Keep the self-confidence value **in the trace** and use it only as a **tiebreaker**, never as a sole reason to *pass* an answer that Checks A/B did not already support. (An answer still escalates if A or B fails, regardless of a high self-confidence.)
- **The broad sensitive-topic regex is intentionally conservative** — it will escalate some legitimately-answerable questions (e.g. "notice period for *despido* in my convenio"). That is the correct default; **do not narrow it**. Just **report, in `review.md`, the escalation outcome on a few borderline termination/disciplinary questions** so the eyes-on gate can judge the rate.

## Resolved §13 questions (apply as written)
- **A (grounding proxy):** citation-coverage + retrieval-score is the 2b-1 approximation — correct; the full per-claim grounding check is 2b-2. (Now combined with the precedence rule above.)
- **B (streaming):** non-streaming. Loading state in the UI ("Pensando…"), full response → evaluate → render.
- **C (test profiles):** the three seeded employee profiles + a seeded `super_admin`; **dev-only seeders, never committed corpus/secret data**.
- **D (sessions):** "most recent session within 24h, else new" — sufficient; no session list/picker/naming (Sprint 5).
- **E (escalation card):** create with `status = new`, `assigned_to = null` — decide-and-queue; the board is Sprint 4.
- **F (all chunks below floor):** escalate (`low_confidence`); record `retrieval.eligible_total` **and** `retrieval.top_score` in the trace so "no eligible chunks" vs "eligible but too weak" is distinguishable.
- **G (provider errors):** `hr-ai` catches provider exceptions → `hr-backend` escalates (`low_confidence`); the provider detail is **logged server-side without the key**, never shown to the employee.
- **H (concurrent sessions):** deferred to Sprint 5 — `ORDER BY last_activity_at DESC LIMIT 1` is fine for 2b-1.
- **I (answer language):** synthesis responds in the question's language (ADR-0006 — language is not a filter; retrieval returns both-language chunks).
- **J (trace shape):** the trace is a strict superset of the 2a `retrieval:probe` shape (adds guardrail, synthesis, floor-decision, **and `authority_used`** blocks).

## Hard constraints (from the docs + spec)
- **Answer synthesis lives in `hr-ai`** (ADR-0007); `hr-backend` resolves scope (no LLM), owns ALL DB writes (`chat_sessions`, `chat_messages`, `message_citations`, `message_traces`, `escalation_cards`, `answer_model_settings`) **and the answer-or-escalate decision**. `hr-ai` writes **only** `document_chunks` + S3, never other tables, never migrates.
- **The browser never sees the API key and never calls the provider.** Key set in the admin screen → **encrypted at rest** (`Crypt`, app-key) → **masked** (`••••<last4>` from `key_last_four`, reconstructed without decrypting) → **rotatable, never read back** by any endpoint. Decrypted only in `ChatService` immediately before the call, passed to `hr-ai` in the request **body** (never a header), never bound beyond the call stack, never logged/persisted by `hr-ai`.
- **Sensitive-topic / guardrail baseline fires deterministically in `hr-backend` BEFORE any `hr-ai` call** — a firing question never reaches the provider. Baseline is hardcoded, admins cannot weaken it, fires regardless of confidence (`GuardrailService`, not inline).
- **Conservative, audit-first:** answer only when Check A AND Check B pass (Check C tiebreaker only) AND the precedence rule is satisfied; otherwise escalate, never guess.
- **`answer_confidence_floor` + `RETRIEVAL_SCORE_FLOOR` are named config values** (never inline), conservative defaults, commented as the **Sprint-6-exposable, additive-only** knobs (admin may raise, never lower below the hardcoded default).
- **Citations:** every cited `chunk_id` must be in the provided set (reject hallucinated citations); **an uncited answer never reaches the employee**.
- **Chat UI on the design-system tokens/classes** (ADR-0012) — no bespoke styling, no new colour literals, no per-screen one-offs.
- **GDPR is deploy-time** (`deploy.md` §1: EU endpoint, signed DPA, zero-retention) — referenced, not built. The `ANSWER_MODEL`/`ANSWER_ENDPOINT` config must point at an **EU-available** model/endpoint.
- Build **nothing** out-of-scope (spec "Out of scope"): no router, no salary-in-chat, no full grounding model, no escalation board, no guardrails config UI, no history-search UI / role-scope enforcement, no lens UI, no analytics.

## Eyes-on / stress-test gates (run on the running app; report each in `review.md`)
- **Precedence (the new requirement) — the gold test:** a **Gipuzkoa Limpieza** employee asks *"¿cuántos días de vacaciones tengo?"* → the answer is the convenio's **31 días** (Art. 7), cited to the **convenio**, **not** the Estatuto's 30. The trace shows `authority_used` = convenio.
- **Silent-topic fallback:** a **Limpieza Navarra** employee asks about *periodo de prueba* (the convenio defers to the Estatuto) → a correct **Estatuto-based** answer, cited to `national_law`, `authority_used` = national_law.
- **Sensitive topic:** a harassment/mental-health/disciplinary question **escalates immediately, without any `hr-ai` call** (verify the provider was never hit); no legal/medical advice; an explicit "¿cuánto gana [Nombre]?" is caught.
- **Floor:** an out-of-scope / salary question escalates (no router) rather than guessing; the trace distinguishes no-retrieval from weak-retrieval.
- **Key screen:** set → masked → rotate round-trips; the **raw key never appears in any API response or any log** (grep the logs).
- **History + trace:** every turn (answer and escalation) is in `chat_messages` with a complete `message_traces.trace` (incl. `authority_used`); design-system adherence confirmed (no new colour literals).

## When done
Update `data-model.md` (population of `chat_sessions`/`chat_messages`/`message_citations`/`message_traces`/`escalation_cards`; the role-scoping-ready note; **the `authority_used` trace field**), `architecture.md` §5 (the `/synthesise` step + the precedence rule) and §7 (the hardcoded baseline pattern), reference `deploy.md` §1, update `roadmap.md` for the 2b-1/2b-2 split, and the `hr-ai`/`hr-backend` READMEs. Write `hr-docs/sprints/sprint-02b-1/review.md` documenting: the precedence gold-test + silent-fallback results, the guardrail/floor/key-screen gate outcomes, the borderline-sensitive escalation report, and any forced doc changes. Then **STOP — do not commit until I review.**

---

After Cursor builds and writes `review.md`, paste it back. I'll review against the spec's acceptance criteria — verifying the precedence gold test (vacation-days = the convenio's 31, not the Estatuto's 30) against the real corpus, not the summary — then you do your running-app eyes-on, then we commit and move to **2b-2**.
