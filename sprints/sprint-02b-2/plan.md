# Sprint 2b-2 — Implementation Plan (the complete answer surface)

> Location: `hr-docs/sprints/sprint-02b-2/plan.md`
> Status: **draft — awaiting review. No code / migrations / config written yet.**
> Implements: `sprint-02b-2/sprint-02b-2-spec.md`. Read alongside `architecture.md` (§2, §5, §7, §11), `data-model.md` (§6, §8, §9, §11), the ADRs (esp. **ADR-0006, ADR-0007, ADR-0014, ADR-0015, ADR-0016**), `deploy.md` §1, `roadmap.md` §3/§7, `sprint-02a/review.md` (the salary SQL path + `convenio_job_categories`), and `sprint-02b-1/` (`review.md`, Correction-01, Correction-02, the adversarial probe).
> Scope: add the **router** + the **per-claim grounding check** to `hr-ai`; add **routing orchestration**, the **salary SQL answer path**, the **single-turn category-resolution flow**, and supersede the Correction-02 salary guard + the Correction-01 figure-guard-as-gate in `hr-backend`; add **salary answers**, the **constrained category-pick UI**, and the **citation-numbering fix** to `hr-frontend`. **No history-search UI / role-scope enforcement (Sprint 5), no agentic multi-turn clarifier (parked), no lens UI (Sprint 3), no board (Sprint 4), no guardrails-config UI (Sprint 6), no LLM tagging (Sprint 7), no analytics (Sprint 8); do NOT do the parked 2a wage-table-chunk cleanup.**

---

## 0. Guiding constraints (held throughout)

These are the non-negotiables from the docs; every decision below is checked against them.

- **Salary is SQL, never a vector/prose chunk (ADR-0006).** A salary figure comes only from `salary_tables` / `salary_table_rows` via SQL, must be **exact** and **year-aligned**, and is bound to its job category and year by construction. This is the structural antidote to the Q5 bug (a 2025 row reported as 2024 from an embedded wage-table prose chunk).
- **Synthesis in `hr-ai`; scope + all writes + the decision in `hr-backend` (ADR-0007).** `hr-ai` gains the router and the grounding check (both LLM calls), but still **writes only `document_chunks` + S3 and never migrates**. `hr-backend` resolves scope deterministically, owns **all** DB writes, and owns the answer-or-escalate **decision** (including the new salary and grounding outcomes — legal weight).
- **The router runs *after* the hardcoded guardrail baseline and is fail-safe (ADR-0016).** Sensitive / legal-medical / other-employee questions still escalate **first** in `GuardrailService`; the router never sees them. Router uncertainty/error → the safe prose+floor path or escalate, **never a silent misroute**.
- **The browser never sees the key and never calls a provider (ADR-0015).** The router and grounding calls reuse the **same** `hr-backend`-owned, encrypted, per-call key path as `/synthesise`. Router model/endpoint are **non-secret config** and may differ from the answer model.
- **Self-reported facts never silently ground a cited answer.** A category the employee picks (when their profile has none) is treated as **unverified**, is **shown in the answer**, and never becomes an authoritative cited figure without that disclosure.
- **Conservative, audit-first.** Answer only when grounded; otherwise escalate honestly. Coverage gaps are surfaced, never silent (ADR-0014).
- **GDPR is deploy-time (`deploy.md` §1).** If the router uses a distinct model/endpoint it must still be EU and is recorded as a `deploy.md` checkbox in the same change.
- **Docs move with code.** `architecture.md` §5/§7, `data-model.md` §8 (the `router_decision` + grounding trace blocks), `roadmap.md` (2b complete; the parked multi-turn clarifier → §7), `deploy.md` (if a distinct router endpoint), `ADR-0016`, and both code READMEs updated in the same change.

---

## 1. Build order across repos + dependencies

Three phases; the `hr-ai` contracts are pinned first so `hr-backend` can build against a fixed shape, and the frontend lands last against live routes. **The 2b-1 prose path, `/synthesise`, key handling, guardrail baseline, trace/history writes, and chat UI are reused unchanged except where called out.**

### What this slice consumes (already built)

| From | Consumed |
|---|---|
| **2a** | `hr-ai POST /retrieve` (scope-prefilter + full-recall exact scan); the **salary SQL surface** (`SalaryTable`/`SalaryTableRow`, the `RetrievalProbe::probeSalary()` year-resolution + row-query logic — the reference implementation for the salary answer path); `convenio_job_categories`; the deterministic **scope resolver**; the coverage-gap signal (ADR-0014). |
| **2b-1** | `hr-ai POST /synthesise` (cited, precedence-aware, abstaining); `ChatService` pipeline + `persistTurn` (one-transaction writes); `GuardrailService` (sensitive baseline); `ExtractionClient`; the **key handling** (`AnswerModelSetting`, decrypt-per-call); the `message_traces.trace` superset; the chat UI (`ChatScreen`, `CitationList`, `TracePanel`); the **figure-guard** (`ChatService::checkFigureGrounding`, now demoted to a pre-check, see §5). |

### Phase A — `hr-ai` gains (no external dependency until a key is supplied; pin the contracts first)

1. **`POST /route` — the router (ADR-0016).** A small/fast-model classification call (reuses the ADR-0015 pluggable provider + per-call key in the body, internal-token-guarded like `/synthesise`). Returns `{ label: "salary" | "prose" | "off_domain", confidence, subqueries?, reason }`. Prompt is a module constant (a new `app/providers/` prompt, mirroring `claude.py`'s `SYSTEM_PROMPT` discipline). For a **compound** question it may also return decomposed `subqueries` (used by recall hardening, §6).
2. **`POST /ground` — the per-claim grounding check (§5).** Per-claim entailment of a synthesized prose answer against its **cited** chunks, reusing the provider + key. Returns `{ grounded, claims:[{ claim, grounded, supporting_chunk_id|null }], ungrounded:[…] }`. **Table-aware** by prompt instruction (digit-presence is not entailment).
3. **Provider port extension.** `AnswerProvider` (`app/providers/base.py`) gains `classify()` and `ground()` alongside `synthesise()`; `ClaudeProvider` implements all three. `get_provider()` registry unchanged. Non-secret config gains `ROUTER_MODEL` (default: a smaller Claude model) and optional `ROUTER_ENDPOINT` (defaults to `ANSWER_ENDPOINT`) in `app/config.py`.
4. **Recall hardening hooks (§6).** Either reuse `/retrieve` per sub-query from `hr-backend` (preferred — no new `hr-ai` surface), or add a `min_national_law` depth knob to `/retrieve`. Decision in §6.

### Phase B — `hr-backend` gains (depends on Phase A contracts being pinned)

5. **`RouterService`** — calls `hr-ai /route` with the decrypted key (same envelope as `synthesise()` in `ExtractionClient`). Owns the **deterministic salary pre-classifier** (the repurposed Correction-02 patterns, §2) and the **fail-safe** logic. Writes the `router_decision` trace block.
6. **`SalaryAnswerService`** — the SQL answer path (§3): resolve job category (§4) + as-of year → query `salary_tables`/`salary_table_rows` (factor the `RetrievalProbe::probeSalary()` logic into this shared service) → compose an exact, structurally-aligned, **cited** salary answer or a coverage-gap escalation. No LLM, no `/synthesise`.
7. **`GroundingService`** — calls `hr-ai /ground` for prose answers; the **entailment result is the real gate**; the figure-guard remains a fast deterministic pre-check (§5).
8. **`ChatService` rewire** — insert the router between the guardrail baseline and `/retrieve`; branch to salary (6) / prose (existing + 7) / off-domain (escalate). **Move `SALARY_PATTERNS` out of `GuardrailService`'s escalation set** into the `RouterService` pre-classifier (superseding Correction-02). Keep sensitive / legal-medical / other-employee patterns in `GuardrailService` firing first, unchanged.
9. **No migration.** `router_decision` and the grounding result are JSON in the existing `message_traces.trace`; salary citations reuse `message_citations` (`chunk_id` is nullable — cite the salary-table source document). Escalation reasons reuse the existing enum (§8). `hr-ai` still never migrates.
10. **Seeders.** Extend `ChatTestUserSeeder`: a salary-answerable profile with a **set `job_category_id`** on a convenio that has imported `salary_tables` (e.g. a Cantabria/Andalucía/Estatal COEAS category), and a profile **without** a category (to exercise the constrained pick), and a coverage-gap profile (e.g. `test-gipuzkoa`, salary is PDF-only).

### Phase C — `hr-frontend` gains (depends on Phase B routes being live)

11. **Salary answer rendering** — a salary answer states **category + year explicitly** and cites the salary table; render via the existing `AnswerBlock`/`CitationList` (a salary citation badge for the salary-table source document).
12. **Constrained category-pick UI** — when the response is a `needs_category` disambiguation, render a **picker** of the convenio's actual `convenio_job_categories` (not a free-text field). The chosen category is sent back as an **unverified** selection on the follow-up message (§4); the resulting answer shows it.
13. **Citation-numbering fix (§7)** — in-text `[Fuente N]` ↔ the displayed FUENTES list, **1:1**.
14. **`lib/api.ts` types** — extend `ChatResponse` (router decision surfaced in the trace, a `needs_category` outcome + categories list, salary fields), add the follow-up `selected_job_category_id` parameter to `sendChatMessage`.

### Dependency graph

`hr-ai /route` + `/ground` contracts (A1–A3) → `RouterService` + `GroundingService` (B5, B7) → `ChatService` rewire (B8); `SalaryAnswerService` (B6) depends only on the 2a salary SQL + scope; recall hardening (A4/B via §6) plugs into the prose branch. Frontend (C) depends on the final `ChatService` response shapes.

---

## 2. The router (ADR-0016)

### Placement and order (unchanged guarantees first)

```
POST /chat/message
  → 1. scope resolve (deterministic SQL — no LLM)            [hr-backend, unchanged]
  → 2. GUARDRAIL BASELINE (sensitive / legal-medical /        [hr-backend, GuardrailService]
       other-employee) — fires FIRST, escalates, provider
       never called. The router NEVER sees these.
  → 3. ROUTER (salary / prose / off_domain)                   [hr-backend RouterService → hr-ai /route]
       3a. deterministic salary pre-classifier (high precision, no LLM)
       3b. else small-LLM /route call (fail-safe)
  → 4. branch:
       • salary    → SalaryAnswerService (SQL)                [hr-backend, §3]
       • prose     → /retrieve → floor → /synthesise → GROUND [hr-backend + hr-ai, §5–§6]
       • off_domain→ escalate (reason: off_domain)            [hr-backend]
  → 5. persist (always): messages, citations, trace, card     [hr-backend, unchanged shape]
```

The guardrail-first ordering is the existing `ChatService::handleMessage()` short-circuit; the router is inserted **after** it returns `fired:false`.

### The small-LLM classification call

- `RouterService` calls `hr-ai /route` exactly as `ChatService` calls `/synthesise`: the key is `AnswerModelSetting::current()->decryptKey()`, decrypted **just before** the call, passed in the **body**, dropped after. `provider_config` carries the **router model** (`config('services.hr_ai.router_model')`, default a smaller/faster Claude) and the EU endpoint.
- `hr-ai /route` returns `{ label, confidence, subqueries?, reason }`. The router sees the **question only** (not the chunks) — same privacy posture as ADR-0016.
- **If the answer model key is not configured**, `/route` cannot run → **fail-safe to the prose path** (which itself escalates honestly via the existing "answer model not configured" branch). No silent misroute.

### Deterministic salary pre-classifier (keeps Correction-02's high precision, drops its escalation)

The Correction-02 `SALARY_PATTERNS` were a *guard* that **escalated**. In 2b-2 they are **repurposed** as a high-precision **pre-classifier feeding the router**, per ADR-0016:

- The patterns **move from `GuardrailService` to `RouterService`** and **no longer escalate**. A confident salary-pattern match (e.g. `salario`, `nómina`, `tablas salariales`, `cuánto gano/gana…`) routes **directly to the salary path** with `router_decision.source = "deterministic_salary"`, **without** an LLM call (bounded cost, the ADR-0016 short-circuit for the unmistakable case).
- **Order preserved:** `other_employee` (privacy) still fires in `GuardrailService` **before** the router, so `"¿cuánto gana Pedro García?"` still escalates as `other_employee_data` and never reaches the salary path. The bare-verb `paga` exclusion is retained.
- Everything not caught by the pre-classifier goes to the **LLM `/route`** call, which is the real discriminator on the fuzzy salary-vs-policy-vs-off-domain boundary.

### Fail-safe

| Condition | Action |
|---|---|
| `/route` error / `hr-ai` unreachable | **prose path** (then the answer-or-escalate floor, which can still escalate) — never a silent misroute |
| `label` low confidence (`< ROUTER_CONFIDENCE_FLOOR`, a named `config/hr.php` knob, conservative default e.g. 0.5) | **prose path** (the safest non-escalating default; the floor + grounding still gate it) |
| Answer-model key not configured | prose path → existing "answer model not configured" escalation |
| `off_domain` (confident) | escalate `off_domain` |

The rule: an uncertain or failed route lands on the **safe prose+floor path or an escalation**, never a wrong-path answer.

### Trace

`router_decision` (today `null` in 2b-1) is populated:

```json
"router_decision": {
  "label": "salary" | "prose" | "off_domain",
  "confidence": 0.0,
  "source": "deterministic_salary" | "llm" | "fail_safe",
  "subqueries": ["…", "…"] | null,
  "model": "<router model>",
  "note": "<fail-safe reason if any>"
}
```

---

## 3. Salary-in-chat (SQL-grounded) — supersedes the Correction-02 blanket escalation

When the router yields `salary`, `SalaryAnswerService` runs (no LLM, no `/synthesise`):

1. **Resolve job category** (§4) — profile category if present; else the constrained pick; else escalate.
2. **Resolve the as-of year** — default **most-recent `salary_tables` row with `year ≤ as-of year`**, else the earliest available (and state the year explicitly so a non-current table is visible — see the open decision in §9). This mirrors the 2a `RetrievalProbe::probeSalary()` resolution (`= year` → `≤ year desc` → latest), which is factored into the shared service.
3. **Query** `salary_tables` (convenio + resolved year) → `salary_table_rows` filtered by `job_category_id`.
4. **Compose an exact, structurally-aligned answer** — the figure is bound to its category and year **by construction** (the SQL row), not parsed from prose. The answer **states the category and the year explicitly** ("Para la categoría *Técnico/a*, según la tabla salarial de **2026**: bruto anual 22 058,76 €, base mensual (14 pagas) 1 575,63 €…"), drawn from the typed columns (`gross_annual`, `base_salary_monthly`, `num_payments`, `hourly_rate`, etc.). Only fields present (non-null) are stated; nothing is invented.
5. **Cite the salary table** — a `message_citations` row to `salary_tables.source_document_id` (the `.xlsx` document), `chunk_id = null` (nullable per data-model §8), with a salary-table authority/badge. The trace records a `salary` block (table id, year, `year_selection`, category, `category_source`, the row values, outcome).

**Coverage-gap honesty (ADR-0014).** If no `salary_table` exists for the convenio at all (the visible 2a gaps — e.g. `test-gipuzkoa`, salary is PDF-only), or the table exists but has no row for the category → **escalate honestly** ("No tengo todavía tu tabla salarial para ese año / esa categoría; te derivo con una persona del equipo de RR. HH.") with reason `salary_not_in_chat` (kept distinct from `low_confidence` so analytics can tell "salary deferred for a coverage gap" apart from a low-confidence prose escalation — the same distinction Correction-02 introduced, now meaning *the SQL path found no row*). **Never guess, never fall back to a prose/embedded-table figure (ADR-0006).**

This **supersedes** the Correction-02 guard: salary questions now **answer from SQL or escalate on a genuine gap**, instead of always escalating.

---

## 4. Category resolution (single-turn — spec §B)

Salary lookup needs the employee's `job_category_id`. Single-turn flow, three terminal outcomes (answer / disambiguating-pick / escalate):

1. **Profile category present** → use `employees.job_category_id` directly (verified, from the directory; Sprint 5 manages it, seeded test profiles set it). The bot rarely needs to ask.
2. **Profile category absent or ambiguous** → return a **`needs_category` disambiguation** response: the **constrained list** of `convenio_job_categories` for the employee's convenio (a closed pick, **not** free text). This is **not** an escalation and **not** an answer — it persists the turn (user message + an assistant "elige tu categoría" message + trace), creates **no** escalation card, and the frontend renders the picker (§C12).
   - The follow-up message carries `selected_job_category_id`. `hr-backend` **validates it belongs to the employee's convenio** (structurally constrained — a FK into `convenio_job_categories` scoped to the convenio; a self-declared free-text category is impossible). The selection is treated as **unverified**: the salary answer **shows it explicitly** ("Para la categoría *Oficial de primera* (según tu indicación)…") so any mismatch is visible to the employee.
   - **A self-declared category never silently grounds a cited figure** — the disclosure ("según tu indicación") and the visible category+year are the guardrail; a wrong pick yields a clearly-labelled "for the category you selected" answer, not an authoritative one.
3. **No salary table exists at all for the convenio/year (coverage gap)** → **escalate** (§3). Asking the employee can't conjure a document the system doesn't have, so don't loop — hand to a person. The coverage-gap check runs **before** prompting for a category, so a gap convenio escalates rather than asking pointlessly.

**Single-turn only.** One question → answer / one constrained pick / escalate. The fuller **agentic multi-turn clarifier** (follow-up questions that gather missing facts then answer) is **parked** and recorded in `roadmap.md` §7 — it is a larger design decision (a new place for scope/precedence/guardrails to go wrong) deserving its own scoping, and must not be smuggled in via the category dependency.

---

## 5. Full per-claim grounding check — supersedes the figure-guard *as the gate*

Replaces 2b-1's citation-coverage (Check B) + figure-guard proxy **as the real gate** for prose answers. (Salary answers are SQL-grounded by construction and do not go through this check.)

### Mechanism (`hr-ai POST /ground`, reusing the ADR-0015 provider + key)

- After `/synthesise` returns a candidate prose answer, `hr-backend` (`GroundingService`) sends `{ answer, citations:[{ chunk_id, content, authority_level }] }` to `hr-ai /ground`.
- `hr-ai` runs an **LLM entailment check per claim**: decompose the answer into atomic claims; for each, decide whether a **cited** chunk **directly entails** it. Returns `{ grounded, claims:[{ claim, grounded, supporting_chunk_id|null }], ungrounded:[…] }`.
- **Catches the non-numeric unsupported claim** — the gap the figure-guard structurally cannot see (it only checks numbers-with-units). A prose assertion the cited chunk doesn't support is flagged ungrounded.
- **Table-aware** — the prompt is explicit that **digit presence is not entailment** (Q5's lesson): a number appearing in a tabular/columnar chunk does **not** support a claim unless the row/column/context match. (Salary now routes to SQL, but the check must not regress to digit-presence on a prose answer that happens to cite a table chunk — the parked 2a wage-table chunks still exist in the index, and this is their backstop per the spec's out-of-scope note.)

### The gate (escalate vs drop — recommended granularity)

- **Default (recommended): any ungrounded claim → escalate the turn** (`low_confidence`, conservative, audit-first — matches the existing legal-weight posture and the simplest auditable rule). The trace records exactly which claim failed.
- **Alternative considered — drop-and-re-evaluate** (strip the ungrounded claim, re-check the remainder, answer if what's left is still grounded and non-trivial). This is more permissive and adds a second pass and an "answer was edited" provenance concern. **Recommendation: escalate-the-turn for 2b-2**; revisit drop-and-re-evaluate only if eyes-on shows escalation is too aggressive. (Flagged as an open decision in §9.)

### Layering with the figure-guard

The Correction-01 figure-guard stays as a **cheap deterministic pre-check** that runs first (it already lives in `ChatService::checkFigureGrounding`): a load-bearing figure entirely absent from cited chunks → escalate immediately, **before** spending the `/ground` LLM call. The **entailment check is the real gate** for everything the figure-guard can't see. The trace keeps `figure_grounding` and adds the new `grounding` block (§8).

---

## 6. National-law recall hardening (incl. compound queries)

**Requirement (not a fixed mechanism):** a topic the convenio is **silent** on must reliably reach the relevant **Estatuto** article; a **compound** question must not dilute retrieval so a **governed** sub-topic flips to the baseline (Q10).

### Proposed mechanism (two complementary fixes)

1. **Compound-query decomposition (primary fix for Q10).** The router (`/route`) returns `subqueries` for a compound question ("vacaciones + periodo de prueba" → `["días de vacaciones", "periodo de prueba"]`). `hr-backend` issues **one `/retrieve` per sub-query** and **unions** the eligible sets (dedupe by `chunk_id`, keep the max score), then passes the union to `/synthesise`. This guarantees each sub-topic's top convenio chunks are present, so neither is diluted below the other — fixing the Q10 baseline flip while preserving the 2a **full-recall** property per sub-query.
2. **National-law retrieval depth (the silent-topic recall, the Art. 14 ET miss).** Ensure a **minimum number of `national_law` chunks** reach the eligible set even when convenio chunks dominate the top-k. Preferred low-surface option: `hr-backend` issues a **second `/retrieve` restricted to `national_law`** (`convenio_id=null`, `include_national_law=true`) and merges its top results in — so the on-topic Estatuto article surfaces for a silent-convenio topic. Alternative: add a `min_national_law` depth knob to `/retrieve` (more `hr-ai` surface; only if the two-pass merge proves insufficient).

Re-ranking is **not** proposed for 2b-2 (decomposition + the national-law pass address the observed failures with less machinery); noted as a future option if recall still lags.

### How it's proven (acceptance — §spec D)

- **Q10 (Navarra, vacaciones + periodo de prueba)** re-run → **both** sub-topics grounded from the **convenio** (no baseline flip); each figure passes grounding.
- **trabajo a distancia** (silent fallback) still resolves to **`national_law`** (the Estatuto), cited correctly — confirming the national-law pass didn't break the silent-topic path.

---

## 7. Citation-marker numbering (`[Fuente N]` ↔ FUENTES list, 1:1)

**The 2b-1 paper-cut:** `/synthesise` numbers `[Fuente N]` by the **input chunk order** (e.g. 8 chunks), but the displayed FUENTES list shows only the **cited subset** (e.g. 2), so the text could read `[Fuente 4]` with only 2 sources listed.

**Fix (in `hr-ai`, which owns both the answer text and the citation mapping):** after `ClaudeProvider` maps `cited_sources` → the real chunk subset, **remap** the cited indices to a compact **1..M** in the order they appear in the `citations` array, and **rewrite the `[Fuente N]` markers in the `answer` text** to those display numbers. The endpoint returns markers already 1:1 with `citations`. The frontend `CitationList` renders `citations` in array order numbered 1..M — guaranteeing in-text ↔ list parity. (Salary answers compose their own single `[Fuente 1]` ↔ the salary-table citation, trivially 1:1.) This is a small change confined to `claude.py`'s post-mapping step + the existing `CitationList`.

---

## 8. Trace + regression

### Trace (`message_traces.trace`) — additions (no schema change; JSON superset)

- **`router_decision`** — populated (§2): `{ label, confidence, source, subqueries?, model, note }`. No longer `null`.
- **prose branch** — a **`grounding`** block in `floor_decision` (or alongside it): `{ checked, grounded, claims:[{ claim, grounded, supporting_chunk_id }], ungrounded:[…], gate:"entailment" }`. The existing `figure_grounding` block stays (now the pre-check). The decision pass becomes **Check A ∧ Check B ∧ figure-guard ∧ entailment-grounding**.
- **salary branch** — a **`salary`** block: `{ table_id, convenio_id, year, year_selection:"exact"|"most_recent_le"|"earliest_available", job_category_id, category_source:"profile"|"picked_unverified", rows:[…], outcome:"answer"|"escalate", note }`. `retrieval`/`synthesis` blocks are **absent** on the salary branch (no vector search, no `/synthesise`) — the same way they're absent when the guardrail short-circuits.
- **needs_category** — `floor_decision.outcome = "needs_category"` (a third outcome; no escalation card written).

### Regression set (how it's run)

Run on the live stack with a real EU key, after the rewire, **before** any sign-off:

1. **The three gold tests** (must still behave): Gipuzkoa vacaciones → convenio answer (31/26 días, `official_convenio`); Navarra periodo de prueba → convenio answer (15/30/6, `official_convenio`); Navarra trabajo a distancia → `national_law`. Each must now also pass the **per-claim grounding** gate (not just the figure-guard).
2. **The nine passing adversarial probes** (Q1–Q4, Q6a/b, Q7, Q8, Q9, Q11) → same outcomes; specifically Q8 (`despido`) still escalates `sensitive_topic` **before** the router (provider never called), and Q11 (trabajo a distancia, Gipuzkoa) still → `national_law`.
3. **The two superseded stopgaps, verified superseded:**
   - **Q5 (the salary bug)** → now **answered from SQL** with the **right year** (the 2024 figure is the real 2024 figure, not the 2025 row), cited to the salary table — or escalated honestly on a coverage gap. **Never** a figure from a prose chunk. This is the single most important eyes-on check.
   - The Correction-02 **blanket salary escalation is gone**: a salary question with a covered category/year **answers**; only a genuine gap escalates (`salary_not_in_chat`).
4. **New 2b-2 gates:** router (salary q → SQL, policy q → prose, off-domain q → escalate, deliberately ambiguous q → fail-safe not a confident misroute); grounding (a constructed **non-numeric over-claim** → escalate); recall (Q10 compound → both grounded from the convenio); citation numbering 1:1.

The regression is run via the chat UI as the seeded profiles (and/or a `ChatService`-level probe like 2b-1's), probe rows cleaned up afterward, results recorded in `review.md`. **No commit** — Cursor writes `review.md` and stops.

---

## 9. Assumptions & open questions

**A. Router model (default).** Same provider (Claude) via the ADR-0015 infra with a **smaller/faster model** (`ROUTER_MODEL`, non-secret config; default a small Claude). It reuses the existing `hr-backend`-owned key and the EU endpoint. *Open:* confirm the specific small model and whether its endpoint differs from the answer model — if it does, a `deploy.md` §1 checkbox is added (still EU, same key envelope, DPA/zero-retention).

**B. Grounding mechanism + escalate-vs-drop granularity.** Default: **LLM entailment per claim** (conservative, per ADR-0016's pairing note), table-aware, with the figure-guard as a fast pre-check. Default granularity: **any ungrounded claim → escalate the turn**. *Open:* confirm escalate-the-turn vs the more permissive drop-and-re-evaluate (recommend escalate for legal weight in 2b-2).

**C. As-of year selection for salary.** Default: **most-recent `salary_tables` row with `year ≤ as-of year`** (matches the 2a probe), with the year **stated explicitly** in the answer. *Open:* (i) when only an **older** table exists (no row ≤ as-of within a "fresh" window), do we still answer with the year clearly stated, or escalate as stale? Recommend **answer with the year explicit** (transparent + honest) and revisit if eyes-on wants a staleness cutoff. (ii) when only a **future** table exists (year > as-of), recommend **escalate** (we don't have the applicable year yet) rather than quoting a not-yet-effective figure — confirm.

**D. Category-pick follow-up shape.** The `needs_category` outcome + a follow-up carrying `selected_job_category_id` (validated against the convenio). *Open:* confirm this single-turn pick (a constrained disambiguation, not a card, not the parked multi-turn clarifier) is the intended UX, and that the picked category's "unverified / según tu indicación" disclosure in the answer is sufficient.

**E. Off-domain reason.** `off_domain` from the router reuses the existing `escalation_cards.reason` enum value (no migration). Salary coverage-gap escalation reuses `salary_not_in_chat` (meaning shifts from "salary is never in chat" to "salary deferred — no SQL row for this category/year"); confirm we keep that value rather than minting `salary_coverage_gap` (which would need a migration).

**F. Recall mechanism surface.** Preference is to keep recall hardening in `hr-backend` (multiple `/retrieve` calls + union) so `hr-ai`'s `/retrieve` stays unchanged; the `min_national_law` knob on `/retrieve` is the fallback only if the two-pass merge is insufficient. Confirm the `hr-backend`-side union is acceptable (it issues 2–3 `/retrieve` calls per compound/silent question — bounded, and embedding the sub-queries is cheap).

**G. Cost/latency.** A prose turn can now make up to: 1 router call + N retrieve calls (decomposition) + 1 synthesise + 1 ground = several LLM/embedding calls. Bounded by: the deterministic salary pre-classifier short-circuiting obvious salary; the small router model; the figure-guard pre-check short-circuiting before `/ground`; decomposition only on compound questions. Accepted per ADR-0016 ("bounded by a small/fast model and deterministic guards"). Non-streaming is retained (2b-1 decision B).

**H. No new migration is required this sprint.** All new data lands in the existing `message_traces.trace` JSON and `message_citations` (nullable `chunk_id`); escalation reasons reuse the existing enum. If review prefers a distinct `salary_coverage_gap` reason (E), that single additive migration would be the only schema change — to be decided at review.

---

## 10. Documentation changes (in the same change as the code, at build time — not this turn)

- **`architecture.md` §5** — the router step (placement after the guardrail baseline; fail-safe; deterministic salary pre-classifier); the salary-in-chat SQL answer path (supersedes Correction-02); the per-claim grounding check (supersedes the figure-guard *as the gate*; figure-guard demoted to pre-check); recall hardening (decomposition + national-law pass). **§7** — note the salary guard is superseded (salary now answers via SQL).
- **`data-model.md` §8** — `router_decision` now populated (shape); the `grounding` + `salary` trace blocks; salary citation via `message_citations` (`chunk_id` null, salary-table source document); the `needs_category` outcome.
- **`roadmap.md`** — mark **2b complete** (2b-2 built); record the **agentic multi-turn clarifier** as parked in **§7**; note the 2a wage-table-chunk cleanup remains parked (the grounding check is its backstop).
- **`deploy.md` §1** — if the router uses a distinct model/endpoint, add a checkbox (EU, DPA, zero-retention, same key envelope).
- **`ADR-0016`** — honoured (no change needed unless the deterministic-pre-classifier-vs-router decision is recorded as a consequence).
- **READMEs** — `hr-ai` (`/route`, `/ground`, the provider port extension, `ROUTER_MODEL` config); `hr-backend` (`RouterService`, `SalaryAnswerService`, `GroundingService`, the `ChatService` rewire, the moved salary patterns, the `ROUTER_CONFIDENCE_FLOOR` knob, the seeded salary profiles).

---

**This plan is ready for review.** No code, migrations, or config have been written; only this file was created.
