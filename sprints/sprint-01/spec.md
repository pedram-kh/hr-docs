# Sprint 1 — Knowledge ingestion backbone

> Location: `hr-docs/sprints/sprint-01/spec.md`
> Reviewer: Claude (architecture) · eyes-on test: Pedram
> Read first: `architecture.md`, `data-model.md`, all `decisions/` (incl. the new **ADR-0010**), `glossary.md`, and `sprints/sprint-00/review.md`.
> Builds on the Sprint 0 walking skeleton.

## Goal
Get Sedena's **real documents into the system, correctly auto-tagged from their filenames against an imported convenio registry, with conflicts flagged for human review and every tag's provenance recorded** — verifiable through a basic admin documents list. After this sprint, an admin can upload the province folders and watch each document land with the right facet tags (or get flagged for review), and confirm/correct those tags.

This sprint is the **deterministic tier-1 tagging path only**. No embeddings, no chat, no LLM tagging, no rich hierarchy UI.

## Scope context (what changes vs Sprint 0)
Sprint 0 produced empty vocabulary tables and a dev-fixture convenio. Sprint 1 replaces the fixtures with the **real registry** and the **real documents**, and restructures `provinces` into a proper territorial model (the Andalucía issue from Sprint 0 review).

## In scope

### A. Territories restructure (do this first — schema change)
The Sprint 0 `provinces` table conflates scope levels (national `Estatal`, regional `Andalucía`, provincial `Álava`). Restructure it before importing the registry.
- **Rename `provinces` → `territories`.** Migrate data.
- Columns: `id`, `code varchar NULL` (relaxed from `char(2)` — regions/national may lack a 2-digit code), `name`, **`level enum(national|regional|provincial)`** (replaces `is_national`), `parent_territory_id FK→territories NULL` (self-ref; for future scope precedence — populate where obvious, no precedence *logic* this sprint), `aliases jsonb`, timestamps.
- Rename the FK columns that reference it: `convenios.province_id → territory_id`, `employees.province_id → territory_id`, `document_chunks.province_id → territory_id`.
- **Update `data-model.md`** (§4 `provinces`→`territories`, and the three FK references) in the same change. Add a short note that scope **precedence** between levels (does a provincial convenio override a regional one?) is an **open question deferred to the scoping/RAG sprint** — do not implement precedence now.

### B. Registry import (`hr-backend` artisan command)
Import `01_listado_convenios.xlsx` (the project's master index) into the real vocabulary + registry. Idempotent (re-runnable without duplicates; match on natural keys).
- Parse the **LABOUR AGREEMENTS** sheet → `convenios`: `numero`, `name`, `territory_id`, `sector_id`, `annual_hours`, `weekly_hours`, `numero_a3`, `it_complement`. Preserve multi-value headline cells (e.g. `1742 (1698)`, `38,5 / 36,5`) verbatim in `notes`.
- Derive `territories` from each `numero` prefix and the row's province/scope: map the 2-digit prefix to a territory; set `level` from the prefix range (provincial vs autonomic/regional vs `99` national). **Surface the exact prefix→level mapping as a plan assumption** to confirm against the real sheet (e.g. confirm Andalucía COEAS `711035…` resolves to a regional territory, not a province).
- Derive `sectors` from the sector/title column (dedup against the controlled list; absorb spelling variants via `aliases`).
- **Do NOT** populate `convenio_job_categories` from the registry split-cells this sprint — job categories are populated from salary tables, which is deferred. Leave them empty (the employee FK is nullable).
- Remove/replace the Sprint 0 `DEV FIXTURE — placeholder` rows once the real registry is in (or clearly supersede them).

### C. Document ingestion (folder/batch upload)
- **Upload endpoint(s)** accepting a batch that **preserves the province-folder grouping** (the folder name is a tagging signal). PDFs only this sprint.
- For each uploaded PDF:
  1. Store the **original** to S3 (`storage_path`).
  2. Call **`hr-ai` `/extract`** (ADR-0010) → per-page text + page-image S3 keys.
  3. `hr-backend` writes the `documents` row and its `document_pages` rows.
- Idempotent on re-upload of the same file (match on a content hash or filename+convenio).

### D. Deterministic filename parser (tier-1 tagging)
- Parse facets from the filename + containing folder, e.g. `01100635012017_OCIO_EDUCATIVO_ALAVA_20232026_Tablas_2026.pdf` → territory (`01`), convenio `numero`, sector, validity window (`2023–2026`), `document_type` (`salary_tables` from `Tablas`, `changes` from `Cambios`, else `convenio_text`), and the file's year where present.
- Assign tags **as foreign keys into the controlled vocabulary** (territory, sector, convenio, document_type). NEVER free text. If a parsed value has no vocabulary row, that is a conflict (see E) — the parser **never creates** vocabulary values.
- Set `retrieval_status` (default `active` unless the validity window is already past → `historical`), `authority_level` (`national_law` for the Estatuto; else `official_convenio`), `validity_start`/`validity_end`, `language` (default `es`; `eu` if the filename/folder signals Euskara).
- Set `tagging_status` = `verified` only after human confirmation; auto-parsed docs start `auto_proposed` (or `under_review` if conflicted).

### E. Conflict detection (outranks confidence — ADR-0002)
Cross-check parsed tags against the imported registry. Flag a document to review (`tagging_status = under_review` + a `document_review_tasks` row, `type = conflict`) when:
- the parsed `numero` is **not in the registry**, or
- the `numero` prefix's territory **disagrees** with the parsed/folder territory, or
- the parsed sector **disagrees** with the registry's sector for that convenio.
A conflicted document is **not** silently auto-tagged as if correct.

### F. Provenance logging (every tag decision)
- Every facet assignment writes an append-only `tag_events` row: `source = filename_parse`, `confidence` (1.0 for clean deterministic parses; lower/flagged for partial), `old_value`/`new_value`, timestamp.
- Admin confirmation or correction writes a further `tag_events` row: `source = admin_manual`, `actor_id`, so the timeline reads "parsed → confirmed/changed by admin."

### G. Basic admin verification UI (`hr-frontend`) — minimal, not the lens hierarchy
- A **Knowledge → Documents** table: columns title, territory, sector, convenio, document_type, validity, `tagging_status`, and a conflict flag. Filterable by each facet and by `tagging_status` (so an admin can find everything `under_review`).
- A **document detail panel**: shows the document's facet tags, its `tag_events` provenance timeline, validity/status/authority, and the source pages (from `document_pages`).
- Actions: **confirm tags** (sets `tagging_status = verified`, writes provenance) and **re-assign a single facet** from the controlled-vocabulary options (writes provenance). This exercises the human-in-the-loop validation and the provenance log.
- This is **not** the graph/list/card lens experience — that is a later sprint. A plain table + detail panel is sufficient to verify ingestion.

## Out of scope (do NOT build)
- Chunking, embeddings, vector search, the retrieval pipeline, any chat answering.
- The LLM tagging tier (tier-1 deterministic parser only).
- Structured salary-row extraction into `salary_table_rows` (Tablas PDFs ingest as documents; their numbers are not parsed yet).
- `convenio_job_categories` population.
- The rich lens hierarchy (graph/list/expandable tree) and the full document card with expiry countdown/lineage UI.
- Escalation board, guardrails UI, analytics, employee chat UI.
- Non-PDF ingestion (docx/xlsx) beyond what the registry import reads.

## Acceptance criteria
1. The territories restructure migrates cleanly; `territories` has correct `level` values (Álava `provincial`, Andalucía `regional`, Estatal `national`); the three FK columns are renamed; `data-model.md` is updated to match.
2. The registry import command populates `convenios`, `territories`, and `sectors` from `01_listado_convenios.xlsx`; is idempotent; preserves multi-value headline cells in `notes`; supersedes the Sprint 0 dev fixture.
3. Uploading a province folder of PDFs ingests every file: original in S3, page text + page images produced via `hr-ai` `/extract`, and `documents` + `document_pages` rows written.
4. The filename parser assigns territory, sector, convenio, document_type, validity, status, and authority **as FKs**, for a representative set of real files; assignments appear in `tag_events` with `source = filename_parse`.
5. A deliberately mismatched file (e.g. an Álava-named file dropped in the Andalucía folder, or an unknown `numero`) is flagged `under_review` with a `conflict` review task — not auto-tagged as correct.
6. The admin Documents table lists ingested documents with their tags and status, filters work, and the detail panel shows the provenance timeline and source pages.
7. **Confirm tags** sets `verified` and writes an `admin_manual` provenance row; **re-assign a facet** changes the value (from controlled vocab only) and writes provenance.
8. Nothing on the out-of-scope list is built; `hr-ai` gains only the `/extract` endpoint and still never writes to the database.

## Definition of done
All acceptance criteria pass; `data-model.md` reflects the territories restructure; ADR-0010 is honoured (extraction in `hr-ai`, DB writes only in `hr-backend`); provenance is recorded for every tag; READMEs updated with the import + upload steps. Cursor writes `sprint-01/review.md` and stops.

## Docs to update during this sprint
- `data-model.md` — the `provinces`→`territories` restructure and the three FK renames; the deferred-precedence note.
- If any implementation detail forces a change to a decision, update the **named** ADR/architecture section in the same change and note it in `review.md`. Do not diverge silently.
