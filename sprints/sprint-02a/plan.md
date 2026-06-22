# Sprint 2a — Implementation Plan

> Location: `hr-docs/sprints/sprint-02a/plan.md`
> Status: **draft — awaiting review. No code/migrations/config written yet.**
> Implements: `sprint-02a/spec.md`. Read alongside `architecture.md` (§3, §5), `data-model.md` (§5–6, §11), `decisions/` (esp. **ADR-0006, ADR-0007, ADR-0010, ADR-0002/0011, ADR-0013**), `glossary.md`, and `sprint-01/review.md` (the parser / conflict / registry-import / `/extract` machinery this sprint **reuses**).
> Scope: turn Sprint-1's ingested prose into language-clean, scope-tagged BGE-M3 vector chunks; ingest salary `.xlsx` into exact relational rows; and build the deterministic scope-prefilter retrieval path + a verification harness. **No chat surface (2b).**

---

## 0. Guiding constraints (held throughout)

- **`hr-backend` owns ALL migrations and ALL DB writes.** `hr-ai` writes **only** `document_chunks` (its one permitted table) + **S3**, and **never** migrates and **never** writes any other table (ADR-0007, ADR-0010). `salary_tables`, `salary_table_rows`, `convenio_job_categories`, `documents`, `document_pages` are **hr-backend** writes.
- **Salary is SQL, prose is vector.** Salary tables are **never** embedded (ADR-0006); they are queried relationally. Only prose is chunked + embedded.
- **Language is never a retrieval filter / facet / lens** (ADR-0006). `document_chunks` has **no** `language` column by design (data-model §5). `eu` and `es` **never** share a chunk (ADR-0013) — separation happens at extraction, not via a filter.
- **Controlled vocabulary, conflict beats confidence** (ADR-0002/0011). The parser/AI never mints convenios/sectors/territories. **`convenio_job_categories` is populated by a deliberate, logged, idempotent admin-run import** (the same allowed path as the Sprint-1 registry import) — never minted at document-tag time.
- **Embedding is BGE-M3 / `vector(1024)`** (ADR-0006), gated by a go/no-go retrieval sanity test on real ES + EU chunks **before** any bulk embed.
- **Re-use, don't rebuild.** Salary `.xlsx` flow through the **existing** Sprint-1 upload → `FilenameParser` → `DocumentTagger` → conflict/review path; `hr-ai` follows the existing ADR-0010 **extract-and-return** contract pattern.
- **Build nothing out-of-scope:** no chat UI / router / answer LLM / citations-in-chat / guardrail / chat history (all 2b); no in-PDF salary-grid extraction; no `.doc/.docx` prose; no scope-precedence logic; no LLM tagging tier; no lens hierarchy UI; language as no kind of filter.
- **Docs move with code:** `data-model.md` (chunk population + `under_review`-exclusion note + salary/category mapping) and `architecture.md` §5 (retrieval primitive shape) are edited in the **same** change; ADR-0014 (salary-source format policy) **lands with the build-authorization prompt, not this turn**.

---

## 1. Build order across the repos (and dependencies)

`hr-backend` stays the hub (schema, scope resolution, every non-chunk write). `hr-ai` gains real work for the first time: embeddings, the column-aware chunker, the xlsx salary parser, and direct writes to its one table (`document_chunks`). Two distinct `hr-ai` patterns are used and must not be confused:

- **Chunks** → `hr-ai` **writes `document_chunks` directly** (its permitted table; ADR-0010/0013). `hr-backend` selects the in-scope documents, resolves each document's scope, and triggers the embed, passing the denormalized scope columns.
- **Salary** → `hr-ai` **extract-and-return only** (the ADR-0010 pattern, exactly like `/extract`): `hr-ai` parses the `.xlsx` and returns structured rows; **`hr-backend` writes** `salary_tables` / `salary_table_rows` / `convenio_job_categories`.

**Order (critical path bolded):**

1. **Embedding sanity test (`hr-ai`) — FIRST, go/no-go (§2).** Blocks everything downstream that embeds. If it fails badly: stop and raise before any bulk embed or re-dimensioning.
2. **Index + write-path prerequisites (`hr-backend` migration; `hr-ai` connection) — §3.4 / §7.1.** Confirm/create the HNSW index on `document_chunks.embedding` + btree indexes on the scope columns (a `hr-backend` migration — `hr-ai` cannot migrate), and give `hr-ai` a write-capable DB path **scoped to `document_chunks` only**. Depends on nothing; do in parallel with (1).
3. **Column-aware re-extraction + normalization + article chunker (`hr-ai`) (§3).** The chunk-production library. Depends on (1) passing. Independent of the salary track.
4. **Embed driver + selection query + scope pass-through (`hr-backend`) (§3.4, §4).** An artisan command that selects in-scope docs (§4), resolves scope, and calls the `hr-ai` embed endpoint per document. Depends on (2) + (3).
5. **Salary `.xlsx` ingestion (reuse Sprint-1 path) (`hr-backend`) (§5.1).** Extend the upload gate + ingestor to accept `.xlsx` as `salary_tables`-type documents through the **existing** parser/conflict/review path. Independent of the prose track; can run in parallel from the start.
6. **Salary xlsx parser (`hr-ai`) + extract-and-return contract (§5.2).** New `hr-ai` endpoint; same auth/return discipline as `/extract`. Depends on (5) for the call site.
7. **Salary writers + `convenio_job_categories` import (`hr-backend`) (§5.3–§5.4).** Writes `salary_tables`/`salary_table_rows`; the deliberate logged idempotent category import. Depends on (6).
8. **Scope-prefilter retrieval path (§7).** `hr-ai` `/retrieve` (vector, scope-prefiltered) + `hr-backend` scope resolver + salary SQL. Depends on (4) (chunks exist) and (7) (salary rows exist).
9. **Verification harness (`hr-backend` artisan command) (§8).** Exercises the whole substrate on real profiles. Depends on (8).
10. **Docs in the same change (§10).** `data-model.md`, `architecture.md` §5.

**What 2b consumes from 2a:** the populated `document_chunks` (clean, scoped, embedded), the populated `salary_tables`/`salary_table_rows`/`convenio_job_categories`, the two retrieval primitives (`hr-ai` `/retrieve` for prose + the salary SQL), and the harness output shape that seeds `message_traces.trace`.

> Eyes-on gates (from spec “stress-test-before-build”): **(a)** eyeball real chunk boundaries on the Gipuzkoa convenio (language separation + article splits + de-spacing) **before authorizing the bulk embed**; **(b)** run the xlsx parser against 2–3 differently-shaped salary files **before authorizing**. Both gates require the **real corpus**, which is **not in the repo today** (§9, Q1) — they happen at build-authorization with files supplied, mirroring Sprint 1’s eyes-on discipline.

---

## 2. Embedding-model sanity test (first `hr-ai` task — go/no-go)

**Goal:** confirm BGE-M3 / `vector(1024)` retrieves sensibly on *our* real content (Euskara + Spanish) before committing the corpus. The `vector(1024)` column already exists (Sprint 0); this is a **go/no-go check, not a re-dimensioning exercise** (spec §A).

**Model loading.** Load `BAAI/bge-m3` in-process in `hr-ai` (proposed dep: `FlagEmbedding`, or `sentence-transformers`; decide at build — §9 Q7). CPU is acceptable — embedding is a background admin action, not a latency-critical employee path (ADR-0010 framing). Config already carries `embed_model = "BGE-M3"` / `embed_dim = 1024` placeholders (`hr-ai/app/config.py`).

**Procedure (a small, standalone script — not wired into ingestion):**
1. Hand-pick a small mixed set of **real** chunks: a handful of Euskara articles + their parallel Spanish articles from the **Gipuzkoa bilingual convenio**, plus several Spanish articles from the **Estatuto**. These come from a *manual* run of the column-aware extractor + normalizer (§3) on the two real files — so this test also smoke-tests §3 on real input.
2. Embed all chunks with BGE-M3 (1024).
3. Run same-language probe queries (a Spanish question that should hit a specific Spanish article; a Euskara question that should hit its Euskara article) and rank by cosine similarity over the small set.

**What “pass” looks like:**
- For each probe, the **correct article** for that language is the top hit (or clearly in the top-k with a comfortable score margin over unrelated articles).
- A Spanish query about topic X retrieves the Spanish article on X; a Euskara query about the same topic retrieves the **Euskara** article on X **without language being a filter** — i.e. cross-language retrieval is *allowed* (no filtering) but same-language semantic match dominates, demonstrating BGE-M3’s multilingual space works for our corpus.
- De-spaced text (post-normalization) scores meaningfully better than the raw artifact text on the same probe (a quick before/after spot-check that normalization §3.2 helps, not hurts).

**If it fails badly:** **stop and raise** in `review.md` *before* any bulk embed and before touching the column width. Do **not** silently swap models or re-dimension. Capture the failing probes + scores so the escalation is concrete. (Re-dimensioning would be a `hr-backend` migration anyway — out of `hr-ai`’s hands.)

**Output:** the result (pass/fail + the probe table with scores) is recorded in `sprint-02a/review.md` (spec acceptance criterion 1).

---

## 3. Prose chunking pipeline (`hr-ai`) — per ADR-0013

All of §3 is a new `hr-ai` module (e.g. `app/chunking/`). It re-reads the **original PDF from S3** (not the stored linear page text) so it can work column-aware (ADR-0013). The Sprint-1 `document_pages` text + page images are **left untouched** — they remain the authoritative **citation surface**.

### 3.1 Column-aware re-extraction (split `eu` / `es`) — never blend languages
- Read the original from S3 with the existing `storage.get_object_bytes` (already used by `/extract`); open with PyMuPDF (`fitz`).
- For each page, use **block bounding boxes** (`page.get_text("dict")` → blocks, each with a `bbox`). Compute each block’s horizontal centre `x_mid`.
- **Two-column detection (per page, geometry-only, not language):** cluster block `x_mid` values; if they form two groups separated by a clear central gutter (a low-density band near `page_width/2`), the page is **two-column**. Left group = Euskara stream, right group = Spanish stream (ADR-0013: bilingual convenios run `eu` left / `es` right). If the centres are unimodal (one column), the page is **monolingual** → a single stream (Spanish for our corpus).
- Read order **within** a column: sort blocks top-to-bottom (`bbox.y0`), then left-to-right for ties. Concatenate each column’s blocks into its own text stream. This produces, per document, up to two language-pure streams — **never** a blended chunk (ADR-0013, ADR-0006).
- Robustness: detection is **per page** (a document may have mixed single/two-column pages — e.g. a bilingual body but a single-column annex). A page that doesn’t cleanly bimodal-split is treated as single-column and flagged in the run log for the eyes-on gate, never force-split.
- **No language column is written** to `document_chunks` (data-model §5): the stream’s language governs *which text goes in a chunk*, not any stored filter. (If display-time language proves useful in 2b, that is a future `hr-backend` migration — §9 Q5.)

### 3.2 Normalization of the intra-word spacing artifact (de-spacing)
The BOG justified two-column typesetting splits tokens: `hi tzar men → hitzarmen`, `Gi puz koa → Gipuzkoa`, `rea li za do → realizado`. We normalize **only the text that is embedded / stored as `document_chunks.content`** — display/citation stays on the untouched `document_pages` (ADR-0013), so normalization only has to be “good enough to embed,” not perfect prose.

**Primary approach — glyph-gap geometry (dictionary-free, avoids over-merging):**
- The artifact arises because justification inflates *inter-word* spaces while *intra-word* fragment gaps stay near-zero. So the signal is geometric, not lexical.
- Use per-character geometry (`page.get_text("rawdict")` → spans → chars with `bbox`). Within a line, compute the gap between consecutive glyphs (`next.x0 − cur.x1`).
- The gaps cluster into two populations: **tiny gaps** (justification artifact *inside* a word) and **real word-separator gaps** (wide). Pick a per-line threshold (e.g. a multiple of the median glyph advance, or a 2-means split of the gap distribution). A whitespace is a **real space** only if its gap is in the wide cluster; gaps in the tiny cluster are **deleted** (fragments merged).
- This is robust to language (works for `eu` and `es` identically) and to font size (threshold is relative, per line).

**Guards against over-merging legitimate spaces:**
- The threshold is **relative per line** (median-based), so a genuinely wide space is never merged regardless of absolute font size.
- **Never merge across** a hyphen, an em/en dash, sentence punctuation (`. , ; : ¿ ? ¡ !`), or a digit↔letter boundary.
- A conservative **upper bound on merge run-length** (merging more than N consecutive fragments into one token is suspicious → leave as-is and log for the eyes-on gate).
- Optional **lexical sanity cross-check (advisory, non-authoritative):** flag (not silently fix) cases where geometry merged a string that *also* looked like two valid words — surfaced in the run log for eyeballing. We do **not** use a dictionary to *drive* merging (would risk language-specific over-merge and contradicts the “good-enough-to-embed” bar).
- The **eyes-on gate** (§1) on the Gipuzkoa convenio is the final guard before bulk embed; surviving edge cases are a known, bounded quality risk (ADR-0013 Consequences).

### 3.3 Article-aware chunking + size cap
- After de-spacing, split each language stream on **article boundaries**: `N. artikulua.` (eu) and `Artículo N.º` / `Artículo N` (es) via anchored regexes (ADR-0013 notes these are consistent throughout the corpus).
- **Size cap** (target ~paragraph-to-article granularity; concrete token cap fixed at build, e.g. ~400–512 tokens — well under BGE-M3’s window but right for retrieval precision):
  - **Oversized article** (e.g. Art. 9 *licencias*) → sub-split on paragraph/sentence boundaries into multiple chunks, each ≤ cap, preserving article identity in order.
  - **Tiny articles** (Art. 1–5) → **pack** consecutive same-language articles together up to the cap.
- Record, per chunk: `chunk_index` (sequential within the document, across both language streams in a deterministic order), `page_from` / `page_to` (from the source blocks’ page numbers), `token_count` (BGE-M3 tokenizer count), and `content` (the normalized, single-language text).
- A non-article-structured in-scope doc (e.g. the Estatuto if its headings differ) falls back to a size-capped sliding/paragraph chunker on its single stream — still single-language, still de-spaced.

### 3.4 Writing `document_chunks` with denormalized scope received from `hr-backend`
- `hr-ai` does **not** resolve scope. `hr-backend` (system of record) resolves each document’s scope and **passes** it in the embed request (ADR-0007/0013). The embed contract (internal, `X-Internal-Token`, like `/extract`):
  - **Request** (`hr-backend → hr-ai`): `{ document_id, document_uuid, storage_key, scope: { convenio_id, territory_id, sector_id, validity_start, validity_end, retrieval_status, authority_level } }`.
  - **`hr-ai` behaviour:** re-extract column-aware (§3.1) → normalize (§3.2) → article-chunk (§3.3) → embed each chunk (BGE-M3/1024) → **write `document_chunks` rows directly** (its one permitted table), copying the passed `scope` verbatim into the denormalized columns (`convenio_id`, `territory_id`, `sector_id`, `validity_start`, `validity_end`, `retrieval_status`, `authority_level`). It sets `document_id`, `chunk_index`, `page_from/to`, `content`, `token_count`, `embedding`.
  - **Response:** `{ chunks_written, language_streams: {eu, es}, pages, warnings: [...] }` — counts + any de-spacing/column warnings for the run log; **no DB rows returned** (they’re already written).
- `hr-ai` never derives or overrides scope; if `scope` is missing/under-review it refuses (the selection in §4 should never send such a doc).

### 3.5 Idempotent re-embed
- Embedding a document is **replace, not append**: inside one transaction on `hr-ai`’s `document_chunks` connection, `DELETE FROM document_chunks WHERE document_id = :id` then insert the freshly produced chunks. Re-running yields the same row set (same `chunk_index` ordering), **no duplicates** (spec acceptance criterion 2).
- Keying on `document_id` (stable, owned by `hr-backend`) makes re-embed safe after a re-extraction-rule change or a scope re-assignment (re-assigning a convenio in the admin UI changes scope → `hr-backend` re-triggers embed with the new scope).

> **Index + write-path prerequisites (build-order step 2).** The `vector(1024)` column exists (Sprint 0), but the retrieval index may not. The **HNSW index on `document_chunks.embedding`** + **btree indexes on the scope columns** are a `hr-backend` **migration** (hr-ai cannot migrate) — confirm they exist, else add them this sprint (§9 Q4). Separately, `hr-ai`’s DB connection is read-only today (`hr-ai/app/db.py` sets `default_transaction_read_only = on`); it needs a **write-capable path scoped to `document_chunks` only**. Recommended: a dedicated Postgres role for `hr-ai` with `SELECT` on registry/scope tables + `INSERT/DELETE/UPDATE` on `document_chunks` and **no** other write/DDL grant — enforcing ADR-0007 at the database, not just by convention (§9 Q6).

---

## 4. Chunking selection (which documents get embedded — exact query)

Per ADR-0013 + spec §B, the selection lives in **`hr-backend`** (it owns `documents` and resolves scope; build-order step 4). An artisan command (proposed `php artisan chunks:embed {--document=} {--all}`) selects the in-scope set and calls the embed endpoint per document.

**In scope:** `document_type ∈ { convenio_text, national_law, partial_agreement }`. **Salary-type documents are NOT chunked.** (`changes`, `summary`, `internal_hr_ruling`, `other` are not embedded this sprint — only the three named types.)

**Lifecycle:** `retrieval_status ∈ { active, historical }` (each chunk carries its `retrieval_status` so 2b’s eligibility filter decides current-vs-time-scoped). **Exclude `draft`.** Per ADR-0013, **also exclude `under_review`** — a conflicted/unresolved document’s denormalized scope is not yet trustworthy, and scope drives eligibility.

**Exact selection (reads as):**

```sql
SELECT d.*
FROM documents d
JOIN document_types dt ON dt.id = d.document_type_id
WHERE dt.code IN ('convenio_text', 'national_law', 'partial_agreement')
  AND d.retrieval_status IN ('active', 'historical')   -- excludes 'draft'
  AND d.tagging_status <> 'under_review'                -- ADR-0013: scope not yet trustworthy
  -- ('auto_proposed' and 'verified' are both embeddable; whether 2b further
  --  gates on 'verified'-only is a separate 2b policy decision, ADR-0013)
ORDER BY d.id;
```

For each selected document, `hr-backend` resolves its scope (the convenio-derived `territory_id`/`sector_id`, the document’s `convenio_id`/`validity_*`/`retrieval_status`/`authority_level`; national-law docs carry `convenio_id = NULL` + `authority_level = national_law`) and passes it in the §3.4 embed request. Documents with empty extracted text (Sprint-1 `empty_text` scans, e.g. the image-only Huesca sample) produce **zero chunks** and are logged — visible, not a silent skip.

---

## 5. Salary ingestion + extraction (xlsx-first) — reuse Sprint-1 path

### 5.1 `.xlsx` enter as `salary_tables`-type documents through the EXISTING parser/conflict path
Sprint 1 already does the hard part: `FilenameParser` maps the `TABLAS` keyword → `salary_tables` doc type, resolves the `numero` → convenio, and routes a messy/unresolvable name to `under_review` with the right ADR-0011 `reason`. **We reuse it; we do not rebuild tagging.** The only change is the **format gate**:

- **`DocumentController::upload()`** today rejects non-PDF (`hr-backend/app/Http/Controllers/Admin/DocumentController.php`, the `$isPdf` block). Extend the gate to also accept `.xlsx` (`mime ∈ {application/vnd.openxmlformats-officedocument.spreadsheetml.sheet}` / `.xlsx` extension). PDFs and xlsx both continue through `DocumentIngestor`.
- **`DocumentIngestor::ingest()`** is reused as-is for hashing, S3 store, filename parse, `DocumentTagger`, idempotency (`content_hash` primary; `source_filename`+`convenio_id` fallback), `documents` row, `tag_events`, and `document_review_tasks`. Two format-aware tweaks:
  - The stored original’s `ContentType` becomes the xlsx mime (today it hardcodes `application/pdf`); the S3 key becomes `documents/{uuid}/original.xlsx`.
  - The extraction branch differs by document type: PDFs call `/extract` (text + page images, as today); **salary `.xlsx` call the new `/extract-salary`** (§5.2) instead. A salary xlsx has **no `document_pages`** (it is not prose, has no page-image citation surface this sprint) — the ingestor writes the `documents` row + provenance + any review task, and **returns the parsed salary rows up to the controller** for the §5.3 write step. (`document_pages` stays a prose/PDF concept.)
- A messy `.xlsx` name that can’t resolve to a convenio confidently lands `under_review` with the ADR-0011 `reason` (`conflict` vs `unresolved`) exactly as a PDF would — admin confirm/re-assign, then re-run salary extraction. **No new tagging code.**
- The Sprint-1 mixed-sample salary file (`MADRID/28102145012018_…_Tablas_2026.xlsx`) that was *skipped* now ingests through this same path.

### 5.2 `hr-ai` xlsx parser + extract-and-return contract (ADR-0010 pattern)
A new `hr-ai` endpoint `POST /extract-salary` (internal, `X-Internal-Token`, same discipline as `/extract`). **Extract-and-return only — `hr-ai` writes no DB rows for salary.** Proposed dep: `openpyxl` (read-only) — and/or `pandas` if convenient.

- **Request:** `{ storage_key, document_uuid }` (the uploaded xlsx’s S3 key).
- **`hr-ai` behaviour (handle the real messiness):**
  - **Ignore junk/notes sheets:** skip sheets that are empty, all-text notes, or obvious legends; pick the sheet(s) that contain a salary grid (a row of recognizable headers above numeric rows).
  - **Locate the header row (not row 1):** scan down for the first row whose cells look like column headers (a run of non-empty short labels with numeric data beneath), rather than assuming row 1.
  - **Map cryptic columns per format** (a small set of per-format header→field maps, matched by normalized header text). Worked example — **COEAS Andalucía** (from Sprint-1 review + spec §C): `TOTAL → gross_annual`; the `14`/`12` columns → `base_salary_monthly` + `num_payments` (12 or 14); the hours-labelled column → `hourly_rate`; `SB` / `COMP` / `Comp. SMI` and any other original columns → preserved verbatim in `raw_values`.
  - Capture each row’s **job-category name** (the row label, e.g. *Director/a Gerente*, *Técnico/a*, *Animador/a Sociocultural*; use the **Spanish** name where bilingual) and a `group_code` if the sheet has one (e.g. `2.1`, `3.2` as in the Cantabria table).
- **Response (structured, typed + raw):**
  ```json
  {
    "tables": [
      {
        "year": 2026, "validity_start": null, "validity_end": null,
        "rows": [
          {
            "job_category_name": "Técnico/a", "group_code": null,
            "gross_annual": 24000.00, "base_salary_monthly": 1714.29,
            "extra_pay": null, "num_payments": 14, "hourly_rate": 13.79,
            "night_plus": null,
            "raw_values": { "SB": "...", "COMP": "...", "Comp. SMI": "..." }
          }
        ]
      }
    ],
    "warnings": ["sheet 'Notas' ignored", "header found on row 4"]
  }
  ```
- `hr-ai` returns this to `hr-backend`; **all DB writes are `hr-backend`’s** (§5.3). The per-format maps are deliberately small and explicit — the spec’s stress gate (run against COEAS Andalucía + a Deporte/Navarra + an Estatal file) drives which maps exist before authorization (§9 Q2).

### 5.3 `hr-backend` writes `salary_tables` / `salary_table_rows` (typed columns vs `raw_values`)
On the `/extract-salary` response, `hr-backend` writes (in a transaction):
- **`salary_tables`** — one per convenio + year/validity, `source_document_id` → the xlsx `documents` row. `year` from the parser’s `year` (falls back to the filename `file_year`, already parsed by `FilenameParser`). Idempotent on `(convenio_id, year)` (re-running replaces its rows).
- **`salary_table_rows`** — one per job category: the **typed columns** (`gross_annual`, `base_salary_monthly`, `extra_pay`, `num_payments`, `hourly_rate`, `night_plus`) for the common, queryable concepts; **`raw_values` (jsonb)** holds **every** original column verbatim (the convenio-specific long tail — `SB`, `COMP`, `Comp. SMI`, totals…). Money `numeric(10,2)`, decimal-comma normalized (`1.652,13 → 1652.13`) exactly like the Sprint-1 registry import does for hours.
- `job_category_id` on each row FKs into `convenio_job_categories` — populated first by §5.4.

### 5.4 `convenio_job_categories` population — deliberate, logged, idempotent import
Categories are **never minted at document-tag time** (ADR-0002). They are populated by a **deliberate admin-run import** — the same allowed managed path as the Sprint-1 `registry:import` (the admin running it *is* the deliberate action).

- Implemented as an explicit step of the salary import (proposed `php artisan salary:import {path|--document=}`, mirroring `registry:import`’s shape), **not** a side effect of tagging.
- For each parsed row’s `job_category_name`, `updateOrCreate` a `convenio_job_categories` row keyed on **(`convenio_id`, normalized `name`)** — **per-convenio, no global dedup** (spec §C; categories belong to a convenio). Set `group_code`, and category-specific `annual_hours`/`weekly_hours` if the sheet provides them. Use the **Spanish** name where bilingual.
- **Idempotent:** re-running creates nothing new on unchanged input; **logged** like the registry import (summary + `Log::warning` on anything ambiguous), surfaced not silent (ADR-0011).
- Order within the import: upsert categories → create `salary_tables` → create `salary_table_rows` (now able to resolve `job_category_id`). All logged.

---

## 6. Coverage-gap surfacing (no silent blanks)

A convenio whose salary lives **only in a PDF** (the canonical case: **Gipuzkoa *Limpieza*** convenio `20000785011981`, salary on BOG pages 33–35, no `.xlsx`) must be shown as **“no salary rows yet,”** not a silent blank — because in-PDF salary-grid extraction is explicitly **out of scope** for 2a (§12), so these convenios will legitimately have zero salary rows.

**How it’s made visible:**
- A coverage query (reads as) flags convenios with **no salary rows**:

```sql
SELECT c.id, c.numero, c.name
FROM convenios c
LEFT JOIN salary_tables st ON st.convenio_id = c.id
WHERE st.id IS NULL
ORDER BY c.numero;          -- convenios with no salary table at all → "no salary rows yet"
```

  (A second variant flags convenios that *do* have a `salary_tables` row but **zero** `salary_table_rows`, catching a parse that produced an empty table.)
- The `salary:import` command prints this gap list in its summary (and `Log::warning`s it), so an admin running the import sees exactly which convenios still need salary data.
- The **verification harness** (§8) prints `"salary: no rows yet for convenio <numero>"` instead of an empty result when a salary lookup finds no rows — the gap is explicit in the substrate test, seeding the analytics “coverage gap” surface that lands as a UI later (architecture §10.5; not built here).

This deliberately distinguishes **“no salary data ingested yet”** (a coverage gap to fill) from **“this category has no such pay concept”** (a legitimate NULL typed column).

---

## 7. Scope-prefilter query path (the retrieval primitive 2b will call)

Implements `data-model.md` §11. `hr-backend` resolves scope (deterministic, legal weight — never an LLM); prose retrieval is delegated to `hr-ai`; salary is `hr-backend` SQL. **No router, no answer LLM, no chat** (that is 2b) — these are exposed as **internal, testable primitives** only.

### 7.1 Prose: scope-prefilter BEFORE similarity
- `hr-backend` resolves the eligibility scope from the profile + question date (§11): `convenio_id` (the employee’s) and the `national_law` universality, `retrieval_status` (`active`, plus `historical` for an explicitly time-scoped question), and the question `date`.
- `hr-backend` calls `hr-ai` `POST /retrieve` (internal) with `{ query, scope_filters, k }` where `scope_filters = { convenio_id, include_national_law: true, retrieval_status: [...], as_of_date }`.
- `hr-ai` embeds the query (BGE-M3/1024) and runs the **scope-prefilter then rank** query. The prefilter is a SQL `WHERE` on the **denormalized btree scope columns**, applied **before** similarity ranking (ADR/AGENTS rule); reads as:

```sql
SELECT dc.id, dc.document_id, dc.chunk_index, dc.page_from, dc.page_to,
       dc.content, (dc.embedding <=> :query_vec) AS distance
FROM document_chunks dc
WHERE ( dc.convenio_id = :convenio_id OR dc.authority_level = 'national_law' )
  AND dc.retrieval_status = ANY(:statuses)              -- ['active'] or ['active','historical']
  AND ( dc.validity_start IS NULL OR dc.validity_start <= :as_of_date )
  AND ( dc.validity_end   IS NULL OR dc.validity_end   >= :as_of_date )
ORDER BY dc.embedding <=> :query_vec                     -- similarity AFTER the scope filter
LIMIT :k;
```

  - **Language is not in the WHERE** (ADR-0006) — retrieval runs across all languages.
  - At this corpus size the scope predicate is highly selective and cheap; the HNSW index serves the ANN ordering. If the post-filter interacts poorly with HNSW (filtered-ANN recall), use pgvector iterative scan / a larger `ef_search` (over-fetch then keep top-k) — correctness of the **scope prefilter** is never traded for ANN speed. (§9 Q4.)
- **Returns** `{ chunks: [{ document_id, chunk_index, page_from, page_to, content, score }] }` — `score = 1 − distance`. `hr-backend` maps `document_id` → document metadata (title, convenio, source page) for the harness/trace.

### 7.2 Salary: SQL by convenio + year + job category (no vector search)
Pure `hr-backend` SQL (§11 step 3) — **no `hr-ai`, no embeddings**:

```sql
SELECT r.*, jc.name AS job_category
FROM salary_tables st
JOIN salary_table_rows r ON r.salary_table_id = st.id
JOIN convenio_job_categories jc ON jc.id = r.job_category_id
WHERE st.convenio_id = :convenio_id
  AND st.year = :year                  -- the active/as-of year
  AND r.job_category_id = :job_category_id;   -- the employee's category
```

  If this returns nothing, the harness reports the §6 coverage-gap message (distinguishing “no rows yet” from “no such category”).

### 7.3 Who calls what
`hr-backend` is the orchestrator of the primitive: resolve scope → (salary) run §7.2 SQL itself; → (prose) call `hr-ai` `/retrieve` (§7.1). This is exactly the deterministic, scope-carries-legal-weight split of ADR-0007. 2b will wrap these primitives with the router + answer LLM + trace persistence; 2a stops at returning the eligible sets.

---

## 8. Verification harness (command, not UI)

A `hr-backend` artisan command (proposed `php artisan retrieval:probe`) — **not a UI** (the chat surface is 2b). It exercises the whole substrate end-to-end and prints a human-readable trace that **seeds 2b’s `message_traces.trace`** shape.

- **Inputs:** an employee **profile** (by `--email=` to use a real seeded employee, or explicit `--convenio=`/`--job-category=`/`--territory=`), a **question** (`--question=`), and a **date** (`--date=`, default today). The question is free text; in 2a there is no router, so the harness can take a `--mode=prose|salary` hint (or run both) since query classification is 2b.
- **Outputs (printed):**
  1. **Resolved scope** — the eligibility filter `hr-backend` computed: convenio, `national_law` inclusion, `retrieval_status` set, as-of date, resolved `job_category_id`.
  2. **Eligible prose chunks** — top-k from §7.1 with **score + source** (document title, convenio `numero`, `page_from`–`page_to`, `chunk_index`), so a human can eyeball that scope-prefiltering worked and the right article surfaced.
  3. **Eligible salary rows** — from §7.2 (typed columns + the raw_values keys), or the §6 **“no salary rows yet”** message.
- **Real profiles to test against** (all exist in the registry Sprint 1 imported — review §2):
  - **Gipuzkoa *Limpieza*** employee (convenio `20000785011981`): a prose question (e.g. about *licencias* / leave) returns Gipuzkoa convenio chunks; a salary question surfaces the **coverage gap** (“no salary rows yet” — salary is only in the PDF, pages 33–35). Also validates clean `eu`/`es` separation in the retrieved chunks.
  - **Andalucía COEAS** employee (convenio `71103505012022`, *OCIO EDUCATIVO Y ANIMACIÓN ANDALUCÍA*, regional code `71`): a salary question for the employee’s job category returns the **xlsx-derived** `salary_table_rows`; a prose question returns COEAS chunks.
  - **Estatuto-only** question: a universal-law question (employee whose convenio has no matching doc) retrieves **`national_law`** chunks via the `OR authority_level = 'national_law'` arm — proving universality works without language or precedence logic.

The harness is the proof of spec acceptance criterion 5 (correct eligible chunk set, scope-prefiltered; correct salary rows) and the integration check that ties §3–§7 together.

---

## 9. Assumptions & questions (ambiguities + real-file findings)

**Highest priority — the real corpus is NOT in the repo.**
1. **No sample files ship today** (a workspace scan finds zero PDFs/xlsx; Sprint-1 review §3 confirms the eyes-on run used *generated* samples). The two “stress-test-before-build” gates (eyeball Gipuzkoa chunk boundaries; run the xlsx parser on 2–3 real files) **require Pedram to supply the real corpus** at build-authorization — specifically the **Gipuzkoa bilingual convenio PDF**, the **Estatuto PDF**, and **2–3 salary `.xlsx`** (COEAS Andalucía + a Deporte/Navarra + an Estatal). Without them, §2 (sanity test) and §3/§5 cannot be validated. **Default if unavailable:** build the pipeline against the Sprint-1-style generated stand-ins, mark §2 and the gates **pending real files**, and do **not** authorize a bulk embed.

**Salary xlsx (needs the real files):**
2. **Per-format column maps (§5.2).** I have the COEAS Andalucía map from the docs (`TOTAL`/`14`/`12`/`SB`/`COMP`/`Comp. SMI`). The Deporte/Navarra and Estatal sheet shapes are **unknown** until I see them — the small per-format map set is finalized at the stress gate. Confirm: are there exactly the formats the spec names, or more?
3. **`salary_tables.year` source.** Assumed from the parser `year`, falling back to the filename `file_year` (`FilenameParser` already extracts a trailing 4-digit year, e.g. `…_Tablas_2026`). Confirm a salary xlsx can contain **multiple years/validity** (→ multiple `salary_tables` rows from one document) or is one-year-per-file.

**Chunking (§3):**
4. **HNSW + scope btree indexes on `document_chunks`** — do they exist from Sprint 0, or is an additive `hr-backend` migration needed this sprint? (Required for §7.1; hr-ai cannot migrate.) Also confirm the acceptable **filtered-ANN** strategy (iterative scan vs over-fetch) at our scale.
5. **No `language` column on chunks** — confirmed deliberate (data-model §5). Confirm 2b does **not** need a display-only (non-filtering) `language` column on `document_chunks`; if it does, that is a future `hr-backend` migration, not something `hr-ai` can add.
6. **`hr-ai` write path** — OK to grant `hr-ai` a Postgres role with write privileges **only** on `document_chunks` (+ read on registry/scope), enforcing ADR-0007 at the DB? (Today `db.py` is read-only.)
7. **Embedding runtime** — OK to self-host BGE-M3 in-process (`FlagEmbedding`/`sentence-transformers`, CPU) as a new `hr-ai` dependency, given embedding is a background admin path? Any preference for a served endpoint instead?
8. **Article-boundary + size cap specifics (§3.3)** — confirm the concrete token cap (proposing ~400–512) and that `Artículo`/`artikulua` are the only article anchors in the real corpus (the Estatuto’s heading style in particular).
9. **De-spacing threshold (§3.2)** — the glyph-gap clustering threshold is tuned on the real Gipuzkoa file at the eyes-on gate; the geometry-first/dictionary-free approach is the proposal, confirm it’s acceptable over a lexicon-driven merge.

**Real-file findings the spec didn’t fully anticipate (from reading the code + Sprint-1 review):**
- **A salary xlsx has no citation surface.** It produces **no `document_pages`** (those are prose/PDF). The plan keeps `document_pages` PDF-only and lets a salary `documents` row exist without pages; its “source view” (if any in a later UI) would render the xlsx differently. Flagged so the ingestor change is deliberate, not accidental.
- **Duplicate-numero convenios are already merged** (Sprint-1 Correction 3): `20000785011981` exists once with `aliases`. Salary categories for it import under the single canonical convenio — no ambiguity, but worth noting the resolver already handles both spellings.
- **The same `document_id` carries both `eu` and `es` chunks** (a bilingual doc has `documents.language = 'es'` but yields both streams). This is consistent (language is not a chunk filter), but means a chunk’s language is **not** recoverable from the schema — acceptable for 2a; relevant to Q5.

**Default if a question is unanswered:** proceed with the stated assumption, record it in `review.md`, validate every real-file-dependent item against the supplied corpus at the stress gate, and **never** create unverified vocabulary or authorize a bulk embed on unvalidated extraction.

---

## 10. Docs to update during the build (same change)

- **`data-model.md`** — §5 `document_chunks` now **populated** (record the chunking rule + the **embedding additionally requires `tagging_status ≠ under_review`** note from ADR-0013, tightening the §11 read-path that keys only on `retrieval_status`); §6 `salary_table_rows` typed-column ↔ `raw_values` mapping notes; `convenio_job_categories` population-from-salary note (deliberate/logged/idempotent, per-convenio). If a chunk index migration is added (Q4), record it.
- **`architecture.md`** — §5 only if the retrieval-primitive shape (the `/retrieve` contract) needs recording.
- **ADR-0013** — referenced/honoured; **ADR-0014 (salary-source format policy) is NOT written this turn** — it lands with the build-authorization prompt (spec §F).
- **READMEs** (`hr-ai`, `hr-backend`) — the embed endpoint + `/extract-salary` contract + `chunks:embed`/`salary:import`/`retrieval:probe` commands + new deps, after the build.
- **`sprint-02a/review.md`** — written **after** the build is reviewed (the §2 sanity-test result, the eyes-on chunk/xlsx findings, any forced doc/ADR changes). **Not this turn.**

---

## 11. Mapping to the spec’s acceptance criteria

| # | Criterion | Covered by |
|---|---|---|
| 1 | BGE-M3/1024 sanity test passes on real ES+EU chunks (or is escalated); result in `review.md` | §2 |
| 2 | In-scope prose chunked column-aware + normalized + article-aware, embedded, written with correct denormalized scope; `eu`/`es` never share a chunk; idempotent re-embed | §3, §4 |
| 3 | Salary `.xlsx` ingest as `salary_tables`-type docs via the **existing** parser/conflict path; rows populate `salary_tables`/`salary_table_rows` with typed-column mapping + `raw_values`; `convenio_job_categories` per-convenio, logged + idempotent | §5 |
| 4 | Coverage gaps visible — in-PDF-only salary shows “no salary rows yet,” not a silent blank | §6 |
| 5 | Prefilter path returns the correct eligible chunk set (scope-prefiltered before ranking) + correct salary rows, via the harness on real profiles | §7, §8 |
| 6 | `hr-ai` writes only `document_chunks` + S3; never migrates / never writes other tables; all salary/category/document writes are `hr-backend`’s | §0, §3.4, §5, §7 |
| 7 | Nothing out-of-scope built; `data-model.md` reflects chunk population + `under_review`-exclusion + salary/category mapping; ADR-0013 honoured | §10, §12 |

---

## 12. Out of scope (re-affirmed — not built this sprint)

The employee chat UI, the **router**, the **answer/synthesis LLM**, citations-in-chat, the guardrail/grounding check, chat history — all **2b**. Scope **precedence** between territory levels (provincial vs national override) — deferred (data-model open item). **In-PDF salary-grid extraction** (salary embedded inside convenio PDFs — surfaced as a coverage gap, §6, not extracted) and **`.doc/.docx` prose** convenios (e.g. Deporte Navarra) — both deferred. The **LLM tagging tier** / vocabulary-proposal UI (Sprint 7); the **lens hierarchy UI** + full document card (Sprint 3). **Language as any kind of retrieval filter/facet/lens** (ADR-0006) — explicitly not built. `hr-ai` still **never** writes the DB beyond `document_chunks`, **never** migrates; the parser/import **never** mints controlled vocabulary autonomously.

---

**Plan ready for review.** No code, migrations, or config have been written — only this `plan.md`. On approval (and answers to §9, especially supplying the real corpus for the §2 sanity test and the stress gates), I will implement in the build order of §1 and record the `data-model.md` / `architecture.md` edits in the same change.







