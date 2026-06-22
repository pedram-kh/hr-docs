# Sprint 2a — Cursor kickoff prompt (plan-gate)

> Paste the block below into Cursor with the `hr-platform/` workspace open. As with Sprints 0 and 1, it must **plan first and stop** — do not let it build until the plan is reviewed.

---

You are in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprints 0 and 1 are built and committed; the design system is built pending eyes-on. Sprint 2a is **ingestion-to-vectors**.

Before doing anything, read in full:
- `hr-docs/architecture/architecture.md` (esp. §3 storage, §5 pipeline, §6 tagging)
- `hr-docs/architecture/data-model.md` (esp. §5 documents/pages/chunks, §6 salary tables, §11 scope resolution)
- every file in `hr-docs/architecture/decisions/` — esp. **ADR-0006** (pgvector / BGE-M3 / 1024, salary-never-embedded, language-never-a-filter), **ADR-0007** (Laravel owns schema + writes + scope), **ADR-0010** (extraction in hr-ai, writes only S3 + `document_chunks`), **ADR-0002/0011** (controlled vocabulary; conflict beats confidence), and the new **ADR-0013** (chunking & bilingual extraction)
- `hr-docs/glossary.md`
- `hr-docs/sprints/sprint-01/review.md` (what shipped, incl. the parser / conflict / registry-import machinery you will **reuse**)
- `hr-docs/sprints/sprint-02a/spec.md`
- the `AGENTS.md` in each code repo

Your task this turn is to **plan Sprint 2a only — do not write any code, migrations, or config yet.**

Produce a single file `hr-docs/sprints/sprint-02a/plan.md`, then **STOP and wait for review.** The plan must cover:

1. **Build order** across repos and dependencies (what `hr-ai` vs `hr-backend` each build; what 2b will consume).
2. **Embedding-model sanity test:** how you run the BGE-M3/1024 retrieval check on real ES + EU chunks as the first `hr-ai` task, what "pass" looks like, and what you do if it fails.
3. **Prose chunking pipeline (`hr-ai`)**, stated against ADR-0013: column-aware re-extraction from S3 (how you use PyMuPDF block bboxes to split `eu`/`es`); the normalization pass for the intra-word-spacing artifact (your approach + how you avoid over-merging legitimate spaces); article-aware chunking + size cap; how `chunk.content` (normalized) relates to `document_pages` (untouched, citation surface); how you write `document_chunks` with denormalized scope columns **received from `hr-backend`**; idempotent re-embed.
4. **Chunking selection:** which document types are in scope, `active`+`historical` only, exclude `draft`/`under_review` — and exactly how that selection query reads.
5. **Salary ingestion + extraction:** how salary `.xlsx` enter as `salary_tables`-type documents through the **EXISTING** Sprint-1 parser/conflict path (reuse, don't rebuild); the `hr-ai` xlsx parser (ignore junk sheets, find the header row, map cryptic columns per format) and the extract-and-return contract (hr-ai returns structured rows; **hr-backend writes** `salary_tables` / `salary_table_rows`); the typed-column mapping vs `raw_values`; `convenio_job_categories` population as a deliberate, logged, idempotent import.
6. **Coverage-gap surfacing:** how a convenio with salary only in a PDF (no xlsx) is shown as "no salary rows yet" rather than a silent blank.
7. **Scope-prefilter query path** (`data-model` §11): the eligible-chunk query (scope-prefilter **before** similarity) and the salary SQL (convenio + year + job category); how `hr-backend` resolves + passes scope and calls `hr-ai` for vector retrieval.
8. **Verification harness** (command, **not** UI): inputs (profile + question + date) and outputs (resolved scope, eligible chunks with scores + source, eligible salary rows); the real profiles you'll test against.
9. **Assumptions and questions** where the spec or the real files are ambiguous — call out anything you find by reading actual sample files that the spec didn't anticipate.

Hard constraints (from the docs):
- `hr-backend` owns ALL migrations and ALL DB writes. `hr-ai` writes **ONLY** `document_chunks` + S3, never other tables, never migrates (ADR-0007, ADR-0010). `salary_tables` / `salary_table_rows` / `convenio_job_categories` / `documents` are **hr-backend** writes.
- Salary tables are relational/SQL, **NEVER** embedded (ADR-0006). Prose is vector; salary is SQL.
- Language is recorded but **NEVER** a retrieval filter, facet, or lens (ADR-0006). `eu` and `es` never share a chunk (ADR-0013).
- Controlled vocabulary: the AI never mints convenios/sectors/territories/job-categories at tag time; conflict beats confidence (ADR-0002/0011). `convenio_job_categories` is populated by a **deliberate, logged import**, not at tag time.
- Build nothing on the spec's out-of-scope list: no chat UI, no router, no answer LLM, no citations-in-chat, no guardrail, no chat history (all 2b); no in-PDF salary grids; no `.doc/.docx` prose; no precedence logic; no LLM tagging tier; no lens hierarchy.

Do not create or modify any file other than `hr-docs/sprints/sprint-02a/plan.md` this turn. After writing it, stop and say it is ready for review.
