# Sprint 7b-1 — Structured Reference Knowledge: the substrate + manual path

> Location: `hr-docs/sprints/sprint-07b-1/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `data-model.md` — **`salary_tables` / `salary_table_rows`** (the proven pattern this generalizes: scoped by `convenio_id`, `source_document_id` link, typed columns + `raw_values` jsonb verbatim, idempotent) and **the salary citation** (`message_citations.chunk_id = null`, points at the source document — ADR-0006); the `documents` `document_type` closed vocabulary; **ADR-0020** (the 7a "AI proposes → `ai_agent` provenance + `under_review`/unverified → human verifies → only then answerable" spine — **reused here**); the Sprint-3 Knowledge Center (the lens hierarchy, the document card, the distinct-leaf display); **ADR-0006** (salary is structured, queried-not-embedded), **ADR-0007** (hr-backend owns writes; hr-ai never migrates), **ADR-0011** (vocabulary into existing values only); `roadmap.md` Sprint 7b (the full accumulated scope — esp. *the leaf is a scoped fact*, *non-vectorized by design*, *authority-proposed-conservatively*, *the central design risk is scope-assignment*, and the canonical fixtures).
> **The foundation slice of 7b.** 7b is **L** and contains the project's single riskiest piece (AI scope-segmentation). We split it: **7b-1 (this) builds the entire machinery — storage, format readers, the new knowledge type, the review/verify flow, and a MANUAL way to create a scoped fact — WITHOUT the AI segmentation agent (that's 7b-2).** So the new knowledge type becomes real, storable, reviewable, and displayable on a proven foundation *before* the risky AI is layered on. (Answering *from* these facts is **7c** — out of scope here.)

## Goal
Build the **structured-reference-fact** knowledge type end to end, **except** the AI segmentation and the answer-composition: a new `reference_facts` store (mirroring the salary pattern — scoped, queried-not-embedded, source-linked, `raw_values`-preserving), **readers** for the non-salary `.docx`/`.xlsx` formats the PDF pipeline can't open, a **manual** path for a human to create/verify a scoped fact, the **review/verify flow** (reusing the 7a inert-until-verified spine), and the **Knowledge Center display** as a distinct, badged leaf. After 7b-1, a structured reference fact can exist, be verified, and be seen in the map — it just isn't yet AI-proposed (7b-2) or composed into an answer (7c).

## In scope

### A. The `reference_facts` store (a NEW dedicated table, salary-patterned)
- A **new dedicated** `reference_facts` table (decision: not overloaded onto `salary_tables` — different shape; same *pattern*). Each row is **one scoped fact**:
  - **Scope (the load-bearing columns):** `convenio_id` FK (territory/sector derive from it, exactly as everywhere — never set independently), `job_category_id` FK nullable, `topic_id` FK nullable.
  - **The fact:** a typed/`text` rule value (e.g. "periodo de prueba: 5 meses") + a `raw_values` jsonb preserving the source's exact phrasing/structure verbatim (the salary `raw_values` discipline — nothing lost).
  - **Validity/version:** `validity_start`/`validity_end` (the periodo docx come in *versions* — which is current).
  - **Authority:** `authority_level` defaulting **conservatively** to a reference/structured level — **never** `official_convenio` (the roadmap's "err low, never high"; a structured fact can never silently outrank the law).
  - **Provenance/state (the 7a spine):** `source` (`admin_manual` in 7b-1; `ai_agent` reserved for 7b-2), a **verified state** (`needs_review`/`verified` with `verified_by`/`verified_at`) — **unverified facts are NOT answerable**, the same inert-until-verified gate as ADR-0020.
  - **Source link:** `source_document_id` (+ a `source_locator` for "which part of the file" — page/sheet/section), so every fact is **traceable** to its origin (the salary `source_document_id` discipline).
- **Non-vectorized by design (ADR-0006):** these are **structured rows, never `document_chunks`** — no embedding, no chunking. (Whether/how the answer engine *queries* them is **7c** — 7b-1 only stores + displays.)
- **`hr-backend` owns all writes**; additive migration only.

### B. Format readers (the format gap)
- Readers for the **non-salary `.docx`/`.xlsx`** the current pipeline can't open (today: PDF via `/extract`, xlsx only via the narrow salary path). 7b-1 builds the *content-extraction* for these formats — turning a docx/xlsx into structured text/cells a human (7b-1) or the AI (7b-2) can segment.
- **Division (ADR-0007):** if the reading needs hr-ai (e.g. a parsing endpoint), hr-ai **reads-and-returns only, never migrates**; `hr-backend` persists. State the division in the plan. (For xlsx, hr-backend may read directly — the plan decides; salary already reads xlsx server-side via the import path.)
- **Salary-vs-reference routing:** a salary xlsx still goes to the **salary** path (ADR-0006/0014) — this reader handles **non-salary** structured content only. The `Tablas_acuerdo_parcial_Alhambra.xlsx` fixture (partly SMI/salary-ish, partly reference) is the **routing test** — the plan states how salary content is *not* swallowed into `reference_facts`.

### C. The manual create/verify path (no AI yet)
- A **manual** UI for a human to **create a scoped reference fact**: pick the scope (convenio via FK picker → territory/sector derive), topic, validity, authority (default reference-level), enter the rule value + optionally link a source document/locator. This proves the whole machinery without the AI.
- The **review/verify flow** (reusing the Sprint-3 confirm UI + the 7a spine): a fact is `needs_review` until a human **verifies** it → then `verified`. Every create/verify/edit **appends provenance** (who/when), never rewrites. Editing scope is **scope-affecting** → the same confirm gate as the Sprint-3 bounded edit.
- Authorization: creating/verifying reference facts gated by an ability (e.g. `knowledge.edit` to propose/create, the verify by the appropriate role — the plan proposes; mirror the 7a/Sprint-3 pattern).

### D. Knowledge Center display (the distinct knowledge type)
- A reference fact appears as a **leaf in the lens hierarchy** (graph + list), scoped under its convenio/territory/sector/topic, with a **distinct knowledge-type badge** — visually separate from `convenio_text`, `salary`, and `internal_hr_ruling` (the roadmap's "distinctly-badged leaf"). Reuse the Sprint-3 component.
- Its **card** shows: the scoped value, the **source reference** (file + locator, a link back), the topic, validity/version, authority, and the **provenance + review-state timeline** (manual-created → human-verified in 7b-1; the `ai_agent`-proposed lane lights in 7b-2). Unverified facts carry the **fuchsia `--provenance-ai`** treatment **only if `ai_agent`-sourced** — a `admin_manual` unverified fact uses the normal "needs review" treatment (fuchsia means *unverified-AI* specifically, per 7a).

## Out of scope (do NOT build)
- **The AI segmentation agent → 7b-2.** 7b-1 has **no** AI reading-a-file-and-proposing-facts. The `ai_agent` source/provenance lane is *reserved* in the schema but **unwritten** in 7b-1 (exactly as 7a reserved lanes before lighting them). The manual path is the only writer here.
- **Answering from reference facts → 7c.** 7b-1 **stores and displays**; it does **not** change the answer engine, does **not** make the engine query `reference_facts`. (2b frozen.) Without 7c, a verified fact exists and is visible but isn't yet composed into a chat answer — that's expected and correct for this slice.
- **OCR → 7e.** Salary xlsx → the existing salary path (not here). Vocabulary creation → `registry:import`/the 7a propose-approve flow (facts bind into existing vocabulary only).
- **Multi-source composition, semantic conflict** (7c/7d).

## Acceptance criteria
1. A `reference_facts` table exists (additive migration), salary-patterned: scoped by convenio (territory/sector derived), `topic_id`/`job_category_id`/validity/authority/`source_document_id`+locator/`raw_values`/verified-state. Non-vectorized (no chunks/embedding).
2. Readers open **non-salary `.docx` and `.xlsx`** and surface their content as structured text/cells; a **salary** xlsx is **not** swallowed here (routing test passes on the Alhambra fixture).
3. A human can **manually create** a scoped reference fact (FK-picker scope, value, validity, authority-defaults-low), it lands `needs_review`, **provenance appended**; a human **verify** flips it to `verified`; scope edits hit the confirm gate. Unverified ≠ answerable (the inert gate; and since 7c isn't built, *no* fact is answerable yet — fine).
4. A reference fact shows as a **distinctly-badged leaf** in the Knowledge Center (graph + list), with a card showing value + source link + topic + validity + authority + the provenance/review-state timeline.
5. `authority_level` defaults to reference-level (**never** `official_convenio`); the model cannot express a fact that outranks a convenio.
6. All writes `hr-backend`; hr-ai (if used for reading) never migrates; additive migration only; **no answer-loop change**, **no AI segmentation**, **no composition**.

## Eyes-on
**Manually create** a reference fact — e.g. `{Navarra, Hostelería, Grupo 1} → periodo de prueba 90/75 días`, validity set, authority reference-level, linked to a source doc — and watch it land `needs_review`. **Verify** it → it flips to `verified`, provenance shows manual-created → verified. Open the Knowledge Center → find it as a **distinctly-badged leaf** under Navarra → Hostelería, open its card (value, source link, timeline). Try setting its authority to `official_convenio` → **not allowed**. Open a non-salary **docx/xlsx** through the reader → see its content surfaced (ready for the 7b-2 AI, or for manual fact entry). Confirm a **salary** xlsx still routes to the salary path, not here.

## Risks / notes
- **Generalize the salary pattern, don't fork it.** `reference_facts` is a *new* table but must follow the salary discipline exactly: scope via convenio (derived territory/sector), `source_document_id` traceability, typed value + `raw_values` verbatim (nothing lost), idempotent writes. The plan should explicitly map each design choice to its salary precedent.
- **This slice deliberately can't answer yet** — and that's correct. A verified reference fact is *inert* until 7c teaches the engine to query it. Eyes-on must not expect a chat answer from a fact; it tests *storage, verify, and display*. (Don't let the build sneak in a retrieval hook — that's 7c, and it touches the frozen loop.)
- **The format reader is the real new engineering** (docx especially — nothing reads it today). Keep it a *content-extraction* utility, cleanly separated from segmentation (7b-2) — it returns structured content; it does not decide scope.
- **Authority defaults low, structurally.** The table/validation must make `official_convenio` *unselectable* for a reference fact — the "err low, never high" rule enforced in the schema/validation, not just convention (cf. the Sprint-6 reject-below-floor discipline).
- **`source` lane discipline (the 7a spine).** `admin_manual` is the only writer in 7b-1; `ai_agent` is reserved-but-unwritten until 7b-2 — state this so the build doesn't pre-wire the AI.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` (the new structured-reference-fact knowledge type: the `reference_facts` store, the format readers, the manual create/verify flow, the Knowledge Center display, the authority-low rule, the inert-until-verified reuse — and the explicit note that *answering from these is 7c*), `data-model.md` (the `reference_facts` table, salary-patterned, with the reserved `ai_agent` lane), `roadmap.md` (7b split into 7b-1 done / 7b-2 next; 7c/7d/7e remain), an **ADR** if the new knowledge type / the structured-reference authority model warrants one (likely — it's a new knowledge class beside ADR-0006). Cursor writes `hr-docs/sprints/sprint-07b-1/review.md` and **stops — no commit until I review**.
