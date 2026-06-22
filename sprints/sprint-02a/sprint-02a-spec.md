# Sprint 2a — Ingestion-to-vectors

> Location: `hr-docs/sprints/sprint-02a/spec.md`
> Reviewer: Claude (architecture) · eyes-on test: Pedram
> Read first: `architecture.md` §3 & §5, `data-model.md` §5–6 & §11, **ADR-0006**, **ADR-0007**, **ADR-0010**, **ADR-0002/0011**, and the new **ADR-0013**. Builds on the Sprint 1 ingestion backbone.

## Goal
Turn Sprint-1's ingested documents into **retrievable knowledge**: prose convenios + the Estatuto into language-clean, scope-tagged BGE-M3 vector chunks; salary tables into exact relational rows queried by SQL; and the deterministic **scope-prefilter query path** that 2b will call. After this sprint, given a resolved scope, the system can return the eligible **chunk set** (prose) and the eligible **salary rows** (numeric) — verifiable through a test harness, with **no chat surface yet**.

This is the retrieval **substrate**, not the chat. No answer LLM, no router, no employee UI (that is 2b).

## Scope context (what changes vs Sprint 1)
Sprint 1 ingested PDFs as `documents` with provenance-tracked facet tags, **skipped salary `.xlsx`** (PDF-only), and left `document_chunks`, `salary_tables`, `salary_table_rows`, and `convenio_job_categories` **empty**. Sprint 2a fills them: it chunks + embeds the prose documents, extends ingestion to accept salary `.xlsx` and extracts their rows + job categories, and builds the prefilter query path. Per **ADR-0013**, chunking re-extracts **column-aware** and **normalizes before embedding**.

## In scope

### A. Embedding-model sanity test (first `hr-ai` task, before any bulk embed)
Confirm **BGE-M3 / `vector(1024)`** (ADR-0006) retrieves sensibly on real content before committing the corpus: embed a small mixed set — Euskara + Spanish chunks from the Gipuzkoa convenio and Spanish chunks from the Estatuto — and verify same-language queries retrieve the right article. The `vector(1024)` column already exists (Sprint 0); treat this as a **go/no-go sanity check**, report the result in `review.md`, and if it fails badly, **stop and raise** before re-dimensioning anything.

### B. Prose chunking + embedding (`hr-ai` writes `document_chunks`)
Per **ADR-0013**:
- **Re-extract** each in-scope document's original PDF from S3 **column-aware** (PyMuPDF block bboxes) to separate Euskara (left) from Spanish (right); **never blend languages** in a chunk.
- **Normalize** the intra-word spacing artifact (`hi tzar men → hitzarmen`, `rea li za do → realizado`) on the text used for embedding / `chunk.content`; leave `document_pages` text + images **untouched** (citation surface).
- **Article-aware chunking** (`N. artikulua` / `Artículo N.º`) with a size cap: sub-split oversized articles, pack tiny ones; record `page_from`/`page_to` and `chunk_index`.
- Embed with BGE-M3 (1024) and write `document_chunks`, with the denormalized scope columns (`convenio_id`, `territory_id`, `sector_id`, `validity_start/end`, `retrieval_status`, `authority_level`) populated **from the document's scope as resolved and passed by `hr-backend`** (hr-backend owns scope resolution, ADR-0007; hr-ai writes only chunks, ADR-0010).
- **In-scope document types:** `convenio_text`, `national_law` (Estatuto), `partial_agreement`. Embed **`active` + `historical`**; **exclude `draft` and `under_review`** (ADR-0013). Salary-type documents are **NOT** chunked.
- **Idempotent:** re-embedding a document replaces its chunks cleanly (no duplicates).

### C. Salary ingestion + extraction (xlsx-first)
- **Extend Sprint-1 ingestion to accept salary `.xlsx`** (Sprint 1 skipped these): ingest each as a `documents` row (`document_type = salary_tables`) **through the existing filename/folder parser + conflict/review path**, so its convenio association carries the same provenance and conflict handling as PDFs. Where a messy `.xlsx` name can't resolve to a convenio confidently, it lands `under_review` (`reason` per ADR-0011) for admin confirm/re-assign — **reuse Sprint 1's machinery, don't rebuild it**.
- **Parse each salary `.xlsx` in `hr-ai`** (Python tooling; the same **extract-and-return** pattern as ADR-0010 — hr-ai returns structured rows, hr-backend writes the DB). Handle the real messiness: ignore junk/notes sheets, locate the header row (**not** row 1), and map the cryptic columns **per format** (e.g. COEAS Andalucía: `TOTAL`→`gross_annual`, `14`/`12`→monthly base + `num_payments`, the hours-labelled column→`hourly_rate`, `SB`/`COMP`/`Comp. SMI`→`raw_values`).
- **`hr-backend` writes** `salary_tables` (one per convenio + year/validity; `source_document_id` → the xlsx doc) and `salary_table_rows` (one per job category; typed columns + `raw_values` catch-all).
- **Populate `convenio_job_categories` from the salary rows** as a **deliberate, logged, idempotent import** — the same managed-but-deliberate path as the Sprint-1 registry import (the admin running the import *is* the deliberate action; the AI never mints categories at tag time, ADR-0002). Categories are per-convenio (`convenio_job_categories.convenio_id`); no global dedup. Use the Spanish category name where bilingual.
- **Coverage gaps are made VISIBLE:** where a convenio's salary lives only in a PDF (e.g. Gipuzkoa *Limpieza*, pages 33–35) with no `.xlsx`, record/surface that the convenio has **no salary rows yet** — do not silently leave it blank. In-PDF salary-grid extraction and `.doc/.docx` prose are **OUT** of 2a (see Out of scope).

### D. Scope-prefilter query path (the retrieval primitive 2b will call)
Given a resolved scope (`convenio_id` **or** `authority_level = national_law`; `retrieval_status = active` [+ `historical` for time-scoped]; question date within `[validity_start, validity_end]`) per `data-model.md` §11:
- **Prose:** vector search over `document_chunks`, **pre-filtered by the denormalized scope columns BEFORE similarity ranking** (HNSW on `embedding` + btree on scope columns).
- **Salary:** SQL over `salary_tables` / `salary_table_rows` filtered by convenio + active year + the employee's `job_category_id`. **No vector search.**
- Expose both as **internal, testable primitives** (hr-backend resolves scope → calls hr-ai for vector retrieval; salary SQL is hr-backend). **NO chat, NO router, NO answer LLM** — those are 2b.

### E. Verification harness (how we prove it without chat)
A command / harness (**not a UI**) that, for a given employee profile + question + date, prints: the **resolved scope**, the **eligible prose chunks** (with scores + source doc/page), and the **eligible salary rows**. This exercises the whole substrate and seeds 2b's trace. Run it against real profiles — e.g. a Gipuzkoa *Limpieza* employee, an Andalucía COEAS employee, and an Estatuto-only question.

### F. Docs updated in the same change
- `data-model.md`: `document_chunks` now populated (chunking rule + the embedding-excludes-`under_review` note from ADR-0013); `convenio_job_categories` population from salary; any `salary_table_rows` column-mapping notes.
- `architecture.md` §5 if the retrieval primitive's shape needs it.
- ADR-0013 referenced; **ADR-0014 (salary-source format policy) lands with the build-authorization prompt.**

## Out of scope (do NOT build)
- The employee chat UI, the router, the answer/synthesis LLM, citations-in-chat, the guardrail/grounding check, chat history — all **2b**.
- Scope **precedence** between territory levels (provincial vs national override) — a 2b/answer-loop decision (`data-model` open item).
- **In-PDF salary-grid extraction** (salary tables embedded inside convenio PDFs); **`.doc/.docx` prose** convenios (e.g. Deporte Navarra). Both deferred; coverage gaps surfaced, not silently dropped.
- LLM tagging tier / vocabulary-proposal UI (Sprint 7); the lens hierarchy UI (Sprint 3).
- Language as **any** kind of retrieval filter/facet/lens (ADR-0006) — explicitly not built.

## Acceptance criteria
1. The BGE-M3/1024 sanity test passes on real ES + EU chunks (or is escalated); result in `review.md`.
2. In-scope prose documents are chunked **column-aware + normalized + article-aware**, embedded, and written to `document_chunks` with correct denormalized scope columns; `eu` and `es` never share a chunk; re-embedding is idempotent. Verified on the **Gipuzkoa bilingual convenio** (clean language separation + de-spaced text) and a monolingual Spanish doc.
3. Salary `.xlsx` ingest as `salary_tables`-type documents through the **existing** parser/conflict path; their rows populate `salary_tables`/`salary_table_rows` with correct typed-column mapping + `raw_values`; `convenio_job_categories` populated per-convenio, logged + idempotent. Verified on **COEAS Andalucía** + at least one other-format xlsx.
4. **Coverage gaps are visible:** a convenio whose salary is only in-PDF shows as "no salary rows yet," not a silent blank.
5. The prefilter query path returns the correct **eligible chunk set** (scope-prefiltered before ranking) and the correct **salary rows** (SQL by convenio + year + job category) for a set of real profiles, via the harness.
6. `hr-ai` writes only `document_chunks` + S3 and still **never migrates / never writes other tables**; all salary/category/document DB writes are `hr-backend`'s (ADR-0007/0010).
7. Nothing on the out-of-scope list is built; `data-model.md` reflects the chunk population, the `under_review`-exclusion note, and the salary/category population; ADR-0013 is honoured.

## Definition of done
All acceptance criteria pass; the substrate is provably correct via the harness on real profiles; docs updated in the same change; Cursor writes `sprint-02a/review.md` and stops. (2b consumes this substrate; nothing here depends on 2b.)

## Stress-test-before-build gates (carry into the build-authorization)
- **Eyeball real chunk boundaries** on the Gipuzkoa convenio (language separation + article splits + de-spacing) before authorizing the bulk embed — the same discipline that caught Sprint 1's bugs.
- **Run the xlsx parser against 2–3 differently-shaped salary files** (COEAS Andalucía, a Deporte/Navarra one, an Estatal one) before authorizing — the per-format header messiness is real.
