# Sprint 7b-1 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no reference-fact code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–7a are built and committed (answer engine, Knowledge Center, escalation board + flywheel, access-control, guardrails config, the LLM tagging tier). Sprint 7 was split into 7a/7b/7c/7d(/7e); **7b** is split again into **7b-1 (this) and 7b-2**. **This is Sprint 7b-1 — Structured Reference Knowledge: the substrate + manual path.** Read `roadmap.md`'s Sprint 7b entry (the full accumulated scope) and `hr-docs/sprints/sprint-07b-1/spec.md`.

7b-1 builds the **machinery** for a new structured-reference-fact knowledge type — storage, format readers, the manual create/verify path, and the Knowledge Center display — **without** the AI segmentation agent (7b-2) and **without** answering from the facts (7c). The new type becomes real, storable, reviewable, and visible on a proven foundation before the risky AI is layered on.

Before anything, read in full:
- `hr-docs/architecture/data-model.md` — **`salary_tables` + `salary_table_rows`** (the proven pattern to generalize: scoped by `convenio_id`, `source_document_id` link, typed columns + `raw_values` jsonb verbatim, idempotent, one-source→many-rows) and the **salary citation** (`message_citations.chunk_id = null`, points at the source document — ADR-0006); the `documents.document_type` closed vocabulary; the `convenios`/`territories`/`sectors`/`topics`/`convenio_job_categories` scope tables (the FK targets)
- **ADR-0020** + the 7a build — the **inert-until-verified spine** (`ai_agent` provenance + unverified/`needs_review` state + `verified_by`/`verified_at`; only a human verify makes a proposal count). 7b-1 **reuses** this for reference facts (manual writer now; `ai_agent` reserved for 7b-2)
- **ADR-0006** (structured = queried-not-embedded), **ADR-0007** (hr-backend owns writes; hr-ai never migrates), **ADR-0011** (vocabulary into existing values only); the salary import path (how xlsx is read server-side today)
- the **Sprint-3 Knowledge Center** — the lens hierarchy (graph + list), the document card, the distinct-leaf rendering, the bounded-edit confirm (the verify action), the provenance timeline; ADR-0012/0013 (the vanilla-CSS token system); the 7a fuchsia `--provenance-ai` semantics (unverified-**AI** only)
- how `.docx`/`.xlsx` are (not) read today — confirm the PDF `/extract` path can't open docx, and xlsx is read only via the salary import — so the **non-salary docx/xlsx reader is genuinely new**
- `roadmap.md` Sprint 7b/7c/7d; the canonical fixtures named in the roadmap (`PERIODOS_DE_PRUEBA.docx`, `…ACTUALIZADOS_2026.docx`, `Tablas_acuerdo_parcial_Alhambra.xlsx`)

Your task this turn: **inspect the real substrate and plan — write no reference-fact code.**

Produce `hr-docs/sprints/sprint-07b-1/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check — the pattern + the gap).** Show the **real** `salary_tables`/`salary_table_rows` columns and how a salary row is written (`salary:import`) and cited (`chunk_id = null` → source doc) — the template. Confirm the **format gap**: docx is **not** readable today; xlsx only via the salary path. Confirm the **7a spine** is reusable (the unverified→verify mechanism). Show real lines.
2. **The `reference_facts` table (new, salary-patterned).** The columns: scope (`convenio_id` FK; territory/sector **derived**, never independent; `topic_id`/`job_category_id` nullable), the fact value + `raw_values` jsonb, `validity_start`/`end`, `authority_level` (**default reference-level; `official_convenio` not selectable** — enforce in schema/validation), `source` (`admin_manual` now; `ai_agent` reserved), the **verified state** (`needs_review`/`verified` + `verified_by`/`verified_at` — unverified = not answerable), `source_document_id` + `source_locator`. **Non-vectorized** (no chunks/embedding). Map each choice to its salary precedent.
3. **The format readers.** How non-salary `.docx`/`.xlsx` content is extracted to structured text/cells; the **hr-ai-reads / hr-backend-persists** division if hr-ai is involved (hr-ai never migrates), or server-side reading if not (state which, and why). The **salary-vs-reference routing** — how a salary xlsx is kept on the salary path and **not** swallowed into `reference_facts` (the Alhambra fixture is the test).
4. **The manual create/verify path.** The UI + endpoints for a human to create a scoped fact (FK-picker scope → derived territory/sector, value, validity, authority-default-low, optional source link); the `needs_review` → **verify** flow (reusing the Sprint-3 confirm + the 7a spine); append-only provenance; the scope-edit confirm gate; the abilities (propose/create vs verify).
5. **The Knowledge Center display.** The distinct-badged leaf (graph + list) under its scope; the card (value, source link + locator, topic, validity, authority, provenance/review timeline). The unverified treatment (normal "needs review" for `admin_manual`; fuchsia reserved for `ai_agent` in 7b-2).
6. **Migrations & build order** across `hr-backend` (the table, the readers, the manual path, the verify flow), `hr-ai` (only if a reader endpoint is needed — no migration), `hr-frontend` (the create/verify UI, the distinct leaf + card). List every **additive migration**. Flag whether a new **ADR** is warranted (the new structured-reference knowledge class + the authority-low rule, beside ADR-0006).
7. **Assumptions & open questions** — esp. the docx-reading approach, the exact `reference_facts` shape, the salary-vs-reference routing rule, the verify authorization, and anything where the real salary pattern makes a choice non-obvious.

Hard constraints:
- **NO AI segmentation (7b-2) and NO answering-from-facts (7c) in this slice.** The `ai_agent` source lane is **reserved but unwritten**; the manual path is the only writer. **The answer engine is NOT touched** (2b frozen) — 7b-1 stores + displays; it does not make the engine query `reference_facts`. A verified fact being *not yet answerable* is correct for this slice.
- **Generalize the salary pattern** (scope via convenio/derived, `source_document_id` traceability, typed value + `raw_values` verbatim, idempotent, **queried-not-embedded** per ADR-0006) — a new table, same discipline.
- **Authority defaults low, structurally** — `official_convenio` must be **unselectable** for a reference fact (err low, never high; nothing the system generates outranks the law).
- **Inert until verified** (the 7a/ADR-0020 spine) — `needs_review` until a human verifies; append-only provenance.
- **`hr-backend` owns all writes + schema; additive migrations only; `hr-ai` (if used to read) never migrates** (ADR-0007). Facts bind into **existing** vocabulary only (no creation — ADR-0011). **Salary stays on the salary path** (non-salary only here).
- Reuse the **design system** + the **Sprint-3 Knowledge Center (leaf/card/confirm/timeline)** + the `EnsureCan` + append-only-provenance patterns.

Do not create or modify any file other than `hr-docs/sprints/sprint-07b-1/plan.md` this turn. After writing it, stop and say it is ready for review.
