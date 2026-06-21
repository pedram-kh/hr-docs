# Sprint 1 — Implementation Plan

> Location: `hr-docs/sprints/sprint-01/plan.md`
> Status: **draft — awaiting review. No code/migrations/config written yet.**
> Implements: `sprint-01/spec.md`. Read alongside `architecture.md`, `data-model.md`, `decisions/` (esp. **ADR-0001, ADR-0002, ADR-0007, ADR-0009, ADR-0010**), `glossary.md`, and `sprint-00/review.md`.
> Scope: the deterministic **tier-1 (filename) tagging path** only — territories restructure, registry import, PDF ingestion via `hr-ai /extract`, filename parser, conflict detection, provenance, and a basic admin Documents table + detail panel. Nothing on the spec's out-of-scope list is built.

---

## 0. Guiding constraints (held throughout)

- **`hr-backend` owns ALL migrations and ALL database writes.** `hr-ai` gains only `/extract`, writes only to **S3**, and **never** writes the DB or migrates (ADR-0007, ADR-0010).
- **Tags are foreign keys into controlled-vocabulary tables, never free text.** The parser **never creates** a vocabulary value — a missing value is a **conflict** (ADR-0002).
- **Conflict detection outranks confidence.** A conflicted document is **never** silently auto-tagged as correct; it goes to `under_review` with a `conflict` review task.
- **PDF ingestion only** this sprint.
- **Build nothing out-of-scope:** no chunking/embeddings/vector search/RAG, no chat, no LLM tagging tier, no structured salary-row extraction, no `convenio_job_categories` population, no lens/graph/card hierarchy UI, no non-PDF ingestion (beyond the registry `.xlsx` the import reads).
- **Docs move with code:** the `provinces`→`territories` restructure is reflected in `data-model.md` in the *same* change; any forced ADR/architecture change is recorded in `review.md`.

---

## 1. Build order across the repos (and dependencies)

`hr-backend` is the hub: it owns the schema, the vocabulary the parser looks up, and every DB write. `hr-ai` only needs S3 + a PDF library, so its `/extract` endpoint can be built **in parallel** with the backend schema work and is joined on the ingestion path. `hr-frontend` is built last because it consumes backend endpoints.

1. **Territories restructure (`hr-backend` migrations) + `data-model.md` edits — FIRST.** Everything references the vocabulary; the FK renames must land before the registry import writes `territory_id`. (§2)
2. **Registry import command (`hr-backend`).** Depends on (1). Populates `territories`, `sectors`, `convenios` from `01_listado_convenios.xlsx`; supersedes the Sprint 0 dev fixture. (§3)
3. **`hr-ai /extract` (`hr-ai`).** Independent of (1)/(2) — needs only S3 + PyMuPDF. Build in parallel; the backend ingestion path (4) depends on it. (§4)
4. **Document ingestion (`hr-backend`).** Depends on (2) (convenio/vocab to resolve FKs) and (3) (`/extract`). Upload → S3 → `/extract` → write `documents` + `document_pages`. (§4)
5. **Filename parser (`hr-backend`).** Depends on (2) (vocabulary to look up) and (4) (runs during ingestion). (§5)
6. **Conflict detection (`hr-backend`).** Depends on (5) + the registry. (§6)
7. **Provenance (`hr-backend`).** Cross-cutting; woven into (2)/(5)/(6) and the admin actions in (8). (§7)
8. **Admin Documents UI (`hr-frontend`) + its backend endpoints.** Built last; consumes list/detail/confirm/re-assign endpoints. (§8)

Critical path: 1 → 2 → 4 → 5 → 6 → 8, with 3 parallel to 1–2 and merging at 4. Provenance (7) is implemented as a small shared service used by 2/5/6/8.

---

## 2. Territories restructure (schema change — do first)

Goal: replace the flat, scope-conflating `provinces` table with a `territories` table that can express national → regional → provincial, **before** the registry import writes into it. All of this is `hr-backend` migrations + model/seeder updates, plus the `data-model.md` edits in the same change.

### 2.1 Migrations (three files, sequenced; reversible `down()`)

**M1 — `…_rename_provinces_to_territories`**
- `Schema::rename('provinces', 'territories')`.
- Postgres FK constraints follow the table by identity, so the existing `convenios.province_id → provinces(id)` etc. remain valid (now pointing at `territories`). Column renames happen in M3.
- `down()`: rename back.

**M2 — `…_restructure_territories_columns`** (on `territories`)
- Add `level` — `varchar` + `CHECK (level IN ('national','regional','provincial'))` (the Sprint 0 `varchar+CHECK` enum strategy), `NOT NULL` after backfill.
- Add `parent_territory_id` — `bigint NULL`, FK → `territories(id)` (self-ref; populate where obvious, **no precedence logic** this sprint).
- Relax `code` — `char(2)` → `varchar(8) NULL` (regions/national may lack a 2-digit code). Laravel 13 supports native `->change()` on Postgres.
- **Backfill (raw `DB::statement`, before dropping `is_national`):** `is_national = true` → `level = 'national'`; the Andalucía placeholder row (`code = 'AN'`) → `level = 'regional'`; everything else → `level = 'provincial'`.
- Drop `is_national`.
- `down()`: re-add `is_national` (backfill from `level = 'national'`), drop `level` + `parent_territory_id`, restore `code` to `char(2)`.

**M3 — `…_rename_province_fk_columns_to_territory`**
- `convenios`: `renameColumn('province_id', 'territory_id')`.
- `employees`: `renameColumn('province_id', 'territory_id')`.
- `document_chunks`: `renameColumn('province_id', 'territory_id')`.
- The denormalized `document_chunks.territory_id` stays nullable and unused this sprint (no chunking), but the rename keeps the schema consistent for the RAG sprint.
- `down()`: rename each back to `province_id`.

> Ordering rationale: rename the table (M1) → reshape its columns (M2) → rename the three referencing FK columns (M3). Keeping them in separate files makes each step independently reversible and easy to review.

### 2.2 Model / seeder / code updates (same PR, no behaviour change)
- Rename Eloquent model `Province` → `Territory`; add `parent`/`children` self-relations and a `level` cast.
- Update `Convenio::territory()`, `Employee::territory()` relations and any `province_id` references in controllers/resources/`MeController`/`IdentityPresenter` to `territory_id` / `territory`. (`/me` employee payload key `province` → `territory`; note this is a small frontend-facing rename — flagged in §8 and to `review.md`.)
- Replace the Sprint 0 `ProvinceSeeder` with a minimal `TerritorySeeder` **only** if needed for tests; the **registry import is the real source** of territories (§3). The seeder, if kept, sets `level`/`code` correctly (Álava `provincial`/`01`, Andalucía `regional`, Estatal `national`/`99`).
- `document_chunks` has no Eloquent model (owned by `hr-ai`); nothing to change there beyond the migration.

### 2.3 Exact `data-model.md` edits (in the same change)
1. **§3 mermaid ER diagram:** rename `PROVINCES` node → `TERRITORIES`; relabel the `PROVINCES ||--o{ CONVENIOS` and `… EMPLOYEES` edges to `TERRITORIES`.
2. **§4 `### provinces` → `### territories`:** replace the intro sentence and **delete the "Known limitation" Sprint-0 callout** (the restructure now resolves it). Replace the column table with:
   - `id bigint PK`
   - `code varchar(8) NULL` — `01`, `20`, `99`…; NULL where a region/national scope has no 2-digit code
   - `name varchar` — canonical spelling
   - `level enum(national|regional|provincial)` — replaces `is_national`
   - `parent_territory_id bigint FK → territories NULL` — self-ref; for future scope precedence
   - `aliases jsonb`
   - timestamps
   - Add a short note: *"Scope **precedence** between levels (does a provincial convenio override a regional one?) is an **open question deferred to the scoping/RAG sprint** — `parent_territory_id` is populated where obvious but no precedence logic is implemented now."*
3. **§4 `### convenios` table:** `province_id bigint FK → provinces` → `territory_id bigint FK → territories`.
4. **§7 `### employees` table:** `province_id bigint FK → provinces` → `territory_id bigint FK → territories`; update the note to "employee's **own** location territory (may differ from an Estatal convenio)".
5. **§5 `### document_chunks` table:** `province_id bigint NULL` → `territory_id bigint NULL` (denormalized scope filter).
6. **§11 (scoping read path):** update `province_id` references to `territory_id`.
7. Bump the doc's status note to record the Sprint 1 restructure.

---

## 3. Registry import (`hr-backend` artisan command)

A re-runnable, idempotent command that imports the master index into the real vocabulary + registry and supersedes the Sprint 0 dev fixture.

### 3.1 Command & library
- `php artisan registry:import {path? : path to 01_listado_convenios.xlsx}` (default path resolved from config/env `REGISTRY_XLSX_PATH`).
- Library: **`phpoffice/phpspreadsheet`** (mature, no extra abstraction layer) read in read-only/`setReadDataOnly(true)` mode for memory. (`maatwebsite/excel` is an alternative wrapper but adds a dependency we don't otherwise need.)
- Everything runs inside a single DB transaction; the command prints a summary (territories/sectors/convenios created vs updated) and is safe to re-run.

### 3.2 Sheet & column mapping — **ASSUMPTION, confirm against the real file**
The data-model says the registry comes from the **`LABOUR AGREEMENTS`** sheet. The exact header strings must be confirmed against `01_listado_convenios.xlsx`; the planned map (header → column) is:

| Sheet column (assumed) | Target |
|---|---|
| `NUMERO` / `Nº CONVENIO` | `convenios.numero` (natural key) |
| sector/title column (`NOMBRE` / `CONVENIO`) | `convenios.name` **and** the source for `sectors` derivation |
| province/scope column | territory cross-check (name/region) |
| `NUMERO A3` | `convenios.numero_a3` |
| `COMPLEMENTO IT` | `convenios.it_complement` |
| annual-hours column (`JORNADA ANUAL` / `HORAS AÑO`) | `convenios.annual_hours` (+ raw → `notes`) |
| weekly-hours column (`JORNADA SEMANAL`) | `convenios.weekly_hours` (+ raw → `notes`) |

I will key parsing on **header names** (read row 1, build a header→index map) rather than fixed column letters, so column reordering in the sheet doesn't break the import. Unmapped columns are ignored this sprint.

### 3.3 Territory derivation + prefix→level rule — **ASSUMPTION, confirm against the real file**
For each row, derive the territory from the **first two digits of `numero`** plus the row's province/scope column:
- Prefix `99` → **national** (`Estatal`).
- Prefix in the **provincial code range `01`–`52`** → **provincial**; `code` = the 2-digit prefix; `name` from the province/scope column (normalized).
- Rows whose scope column names an **autonomous community** (e.g. **Andalucía**) → **regional**; `code` = as it appears in the sheet if present, else `NULL`; `name` = the community name.

> **Flagged assumption (spec §B explicitly asks to confirm):** that Andalucía COEAS `711035…` resolves to a **regional** territory, not a province. The 14-digit `numero` prefix semantics for regional/autonomic convenios are not yet verified against the sheet. I will confirm: (a) which prefixes/`numero` patterns indicate regional vs provincial, and (b) whether a separate scope column already states the level (preferred — use it directly rather than inferring from the prefix). **No territory is created from an unconfirmed inference without the scope column agreeing.**

`parent_territory_id` is set where obvious (provincial → its region if the sheet states one; regional/provincial → national is **not** auto-linked without confirmation). No precedence logic.

### 3.4 Sectors derivation
- Normalize the sector/title text (uppercase, strip accents, collapse whitespace) and dedup against the controlled `sectors` list.
- `updateOrCreate` by normalized name; spelling variants are absorbed into `sectors.aliases` (so `OCIO EDUCATIVO` / `Ocio Educativo` collapse to one row). New genuinely-distinct sectors are created **by this deliberate admin-run import** (permitted — it is not the AI/parser creating vocabulary).

### 3.5 Idempotency (natural keys)
- `territories`: `updateOrCreate` on (`level`,`code`) for national/provincial; on (`level`,`name`) for regional (where `code` may be NULL).
- `sectors`: `updateOrCreate` on normalized `name`.
- `convenios`: `updateOrCreate` on `numero` (UNIQUE). Re-runs update headline fields in place; never duplicate.
- Re-running the whole command produces zero new rows on an unchanged sheet.

### 3.6 Multi-value headline cells (preserve verbatim)
- For each headline hours cell, **always** store the **raw cell text verbatim** appended into `convenios.notes` (e.g. `annual_hours raw: "1742 (1698)"; weekly_hours raw: "38,5 / 36,5"`).
- Set the typed numeric column (`annual_hours` / `weekly_hours`) **only when the cell is a single, unambiguous number** (after normalizing the decimal comma `1652,13` → `1652.13`). If the cell is multi-value (`/`, `()`, etc.), leave the numeric column **NULL** and rely on `notes`.
- **No splitting into job categories** — `convenio_job_categories` is explicitly not populated this sprint (the split values belong to categories, which come from salary tables later).

### 3.7 Superseding the Sprint 0 dev fixture
- After a successful import, re-point the seeded **test employee** from the `DEV FIXTURE — placeholder` convenio to a **real** convenio + territory (chosen by env/config, default a stable real `numero`), then delete the fixture sector/convenio/job-category rows.
- Guarded and idempotent: if the fixture rows are already gone, the step is a no-op; if the test employee already points at a real convenio, leave it.
- **Provenance note:** registry/vocabulary creation is an admin-run data load, **not** a document facet decision, so it does **not** write `tag_events` (those are reserved for document tagging — see §7). The command logs its own summary for auditability instead.

---

## 4. Document ingestion (folder/batch upload + `hr-ai /extract`)

### 4.1 Upload endpoint(s) (`hr-backend`, admin-only)
- `POST /admin/documents/upload` — `auth:sanctum` + admin role; `multipart/form-data` accepting **multiple PDFs** as `files[]`, each paired with its **relative path** `relative_paths[]` (e.g. `Álava/01100635012017_OCIO_EDUCATIVO_ALAVA_20232026_Tablas_2026.pdf`). The frontend folder picker (`<input type="file" webkitdirectory>`) yields these relative paths.
- **Folder grouping preserved:** the **top-level folder name** of each relative path is captured as the file's **folder territory signal** and passed to the parser (§5) and conflict detection (§6). We do **not** add a `folders` table — the grouping is preserved as (a) a parse/conflict signal and (b) the resulting `territory_id` FK + a provenance note recording the source folder. The full original filename is stored in `documents.source_filename`.
- Validation: reject non-PDF (`mime = application/pdf`, `.pdf` extension) this sprint; each file processed independently so one bad file doesn't fail the batch (it is reported in the response).
- Processing model: **synchronous per file** within the request for verifiability (mirrors Sprint 0's synchronous choice). A queued job is noted as the obvious scaling path but is **not** built now (no queue worker this sprint).

### 4.2 Per-file ingestion flow (all DB writes in `hr-backend`)
1. Compute a `sha256` content hash of the bytes (for idempotency, see §4.3).
2. Store the **original** to S3 via the Laravel `s3` disk (MinIO in dev) at `documents/{uuid}/original.pdf` → `storage_path`. (`uuid` generated up front.)
3. Call **`hr-ai POST /extract`** with the S3 key (§4.4).
4. Run the **filename parser** (§5) on `source_filename` + folder signal → resolved facet FKs + validity/status/authority/language + per-facet confidence.
5. Run **conflict detection** (§6).
6. In a **DB transaction**: write the `documents` row (`tagging_status = auto_proposed`, or `under_review` if conflicted) and one `document_pages` row per returned page (`page_number`, `text`, `image_path = image_key`).
7. Write `tag_events` provenance rows for each resolved facet (`source = filename_parse`) (§7).
8. If conflicted: create a `document_review_tasks` row (`type = conflict`, `status = open`) with a note describing the mismatch(es).

### 4.3 Idempotency on re-upload
- Match key: **`source_filename` + resolved `convenio_id`** (for universal docs with `convenio_id = NULL`, use `source_filename` + `document_type_id = national_law`). On match, `updateOrCreate` the `documents` row rather than inserting a duplicate; replace its `document_pages` (delete + re-insert) and re-extract.
- The `sha256` is computed and could key idempotency more robustly, **but `documents` has no hash column today.** Adding `content_hash` would be an *additive* migration + a `data-model.md` edit beyond the territories restructure — **flagged as a question in §9** (recommended, but not assumed). Until decided, the filename+convenio key (allowed by spec §C) is used.

### 4.4 `hr-ai /extract` contract (ADR-0010)
- `POST /extract`
- **Request:** `{ "storage_key": "documents/{uuid}/original.pdf", "document_uuid": "{uuid}" }` — the S3 key of the uploaded original; `document_uuid` namespaces the page images.
- **`hr-ai` behaviour:** read the original from S3 (read-only); for each page extract text and render a page image; **write each page image to S3** (object storage — permitted, not a DB write) at `documents/{uuid}/pages/{page_number:04d}.jpg`; **return** the per-page data. It **never** writes the DB and **never** migrates.
- **Response:**
  ```json
  {
    "page_count": 12,
    "pages": [
      { "page_number": 1, "text": "…", "image_key": "documents/{uuid}/pages/0001.jpg" }
    ]
  }
  ```
- `hr-backend` writes the `documents` + `document_pages` rows from this response (DB-write ownership stays clean).
- **Errors:** `400` for a missing/non-PDF key, `502/500` for extraction failure; `hr-backend` marks that file failed in the batch response and continues. 
- **Service auth:** an internal shared-secret header (`X-Internal-Token`) between `hr-backend` and `hr-ai`, value from env on both sides; localhost in dev. (Proposed — confirm in §9.)

### 4.5 Extraction library (`hr-ai`)
- **PyMuPDF (`pymupdf` / `fitz`)** for both per-page text (`page.get_text()`) and per-page image rendering (`page.get_pixmap(dpi=150)` → JPEG/PNG). ADR-0010 names PyMuPDF/pdfplumber; PyMuPDF does text **and** rasterization in one mature dependency.
- `pdfplumber` noted as a fallback for pages where PyMuPDF text quality is poor; not added unless needed.
- S3 access from `hr-ai` via **`boto3`** against the MinIO endpoint (config: `AWS_ENDPOINT`, key/secret, `AWS_BUCKET`) — reuses the same bucket as `hr-backend`.
- New `hr-ai` deps (plan only): `pymupdf`, `boto3`. Config placeholders added to `hr-ai` settings.
- **Limitation:** scanned/image-only PDFs have no text layer → `text` will be empty for those pages; **OCR is out of scope** this sprint. The page image is still stored so the source view works. (Confirm acceptable — §9.)

---

## 5. Deterministic filename parser (tier-1 tagging)

A pure function in `hr-backend` (a `FilenameParser` service): `(source_filename, folder_label) → ParsedFacets`. No LLM, no network. It **resolves** values to controlled-vocabulary FKs and **never creates** vocabulary rows.

### 5.1 Tokenization & parse rules
Example: `01100635012017_OCIO_EDUCATIVO_ALAVA_20232026_Tablas_2026.pdf`. Strip the extension, split on `_`, then classify tokens:

| Signal | Rule | Target |
|---|---|---|
| **`numero`** | the leading all-digit token (e.g. `01100635012017`) | `convenios.numero` lookup; first 2 digits = territory code prefix |
| **validity window** | an 8-digit token `YYYYYYYY` (e.g. `20232026`) → start year `2023`, end year `2026` | `validity_start = YYYY-01-01`, `validity_end = YYYY-12-31` (date semantics **assumed**, §9) |
| **file year** | a trailing 4-digit token (e.g. `2026`) | recorded as a secondary signal (e.g. salary-table year); not a facet FK this sprint |
| **document type** | keyword match: `Tablas`→`salary_tables`, `Cambios`→`changes`, `Acuerdo`/`Parcial`→`partial_agreement`, `Resumen`→`summary`, Estatuto markers→`national_law`; else default `convenio_text` | `document_types.code` lookup |
| **sector** | the contiguous uppercase word tokens that aren't the territory name (e.g. `OCIO_EDUCATIVO`) | `sectors` lookup (name/aliases) |
| **territory name token** | a place-name token (e.g. `ALAVA`) | cross-checks the prefix-derived territory |
| **folder label** | top-level folder (e.g. `Álava`, `Andalucía`) | the **folder territory signal** for cross-check (§6) |
| **language** | default `es`; `eu` if the filename/folder signals Euskara (exact signal **assumed**, §9) | `documents.language` (metadata only) |

### 5.2 Vocabulary lookup (FK resolution)
Normalization helper for all lookups: uppercase, strip accents/diacritics, collapse separators. Compared against each vocabulary row's canonical `name` **and** its `aliases`.
- **Territory:** resolve primarily from the **`numero` prefix** → `territories.code`; the name token + folder label are cross-checks (§6), not the primary key. → `territory_id`.
- **Convenio:** exact `convenios.numero == parsed numero` → `convenio_id` (also yields the registry's authoritative `territory_id` + `sector_id` for conflict cross-check).
- **Sector:** normalized sector phrase → `sectors.name`/`aliases` → `sector_id`.
- **Document type:** keyword → `document_types.code` → `document_type_id`.

### 5.3 Derived lifecycle facets
- `retrieval_status`: default `active`; if `validity_end` is already in the past at ingest → `historical`. (`draft` is not auto-assigned.)
- `authority_level`: `national_law` for the Estatuto / national-law docs; else `official_convenio`. (`internal_hr_ruling` only via the escalation flywheel — not this sprint.)
- `tagging_status`: `auto_proposed` for a clean parse; `under_review` if conflicted (§6); `verified` only after human confirmation (§8).
- `tagging_confidence`: the **min** confidence across the auto-assigned facets (spec/data-model). `1.0` for a clean deterministic parse where every facet resolved and agreed; lower (e.g. `0.5`) for partial parses (missing validity, defaulted doc-type).

### 5.4 Missing-value handling (parser NEVER creates vocabulary)
A parsed value that doesn't resolve to an existing FK is **never invented**:
- `numero` not in `convenios` → **conflict** (§6); `convenio_id = NULL`; `under_review`.
- territory code/name not in `territories` → **conflict**; `territory_id = NULL`; `under_review`.
- sector phrase matches no `sectors` row/alias → **conflict**; `sector_id = NULL`; `under_review`.
- `document_type` keyword unknown → **not a conflict** (closed, fully-seeded set): default to `convenio_text` with reduced confidence, recorded in provenance.
- validity unparseable → leave `validity_*` NULL, `retrieval_status = active`, reduced confidence; not a conflict on its own.

The document is **always created and visible** even when conflicted (so an admin can find and fix it) — it is just `under_review` with NULLs for the unresolved facets, never silently auto-tagged.

---

## 6. Conflict detection (outranks confidence — ADR-0002)

After parsing + registry lookup, a `ConflictDetector` service evaluates the rules below. **If any rule fires**, the document is set `tagging_status = under_review` and a `document_review_tasks` row (`type = conflict`, `status = open`) is created with a note enumerating the specific mismatch(es). Conflict beats confidence: even a high-confidence parse is sent to review if a rule fires, and a conflicted document is **never** marked `verified`/auto-tagged as correct.

**Conflict rules (exact):**
1. **Unknown convenio** — the parsed `numero` is **not present** in `convenios`.
2. **Territory disagreement** — the territory implied by the `numero` prefix (and/or the registry convenio's `territory_id`) **disagrees** with the territory parsed from the filename name-token or the containing folder. (e.g. an `…_ALAVA_…` file dropped in the `Andalucía` folder.)
3. **Sector disagreement** — the parsed sector **disagrees** with the registry convenio's `sector_id` for that `numero`.
4. **Unresolvable controlled value** — a parsed territory or sector value has **no vocabulary row** (cannot be resolved to an FK). Because the parser may not create vocabulary, an unmappable value is treated as a conflict.

For a **clean** document (no rule fires, all FKs resolved and agree): `tagging_status = auto_proposed`, facets stored as FKs, awaiting human confirmation in the admin UI (§8).

---

## 7. Provenance (`tag_events`) — what, by whom, when

A small `Provenance` helper writes append-only `tag_events` rows. `entity_type = 'document'`, `entity_id = documents.id`, `facet ∈ {convenio, territory, sector, document_type, validity}` (no `topic` this sprint — topics are not parsed from filenames).

| When | Trigger | Rows written |
|---|---|---|
| **Ingestion (parse)** | each facet the parser **resolves** to a value | one row per facet: `source = filename_parse`, `actor_id = NULL`, `old_value = NULL`, `new_value = <resolved code/name>`, `confidence = <facet confidence>` (`1.0` clean, lower for partial), `note = "parsed from filename token …"`, `created_at = now` |
| **Conflict** | any §6 rule fires | one `source = system` row on the conflicting facet, `note = <mismatch description>` (keeps the document timeline coherent), **plus** the `document_review_tasks` conflict row (not a `tag_events` row) |
| **Admin confirm tags** | admin clicks *Confirm tags* (§8) | one `source = admin_manual` row, `actor_id = <admin>`, `note = "tags confirmed"`; sets `documents.tagging_status = verified`; resolves any open `tag_review` task |
| **Admin re-assign facet** | admin changes one facet to another controlled-vocab value (§8) | one `source = admin_manual` row on that facet, `actor_id = <admin>`, `old_value = <prev>`, `new_value = <new>`, `note` optional; updates the FK on `documents`; if it resolves the conflict, the admin can then confirm (closing the conflict task) |

- **Registry import does NOT write `tag_events`** (it loads vocabulary, not document facet decisions — §3.7).
- The timeline on the detail panel (§8) is simply `tag_events` for that document, ordered by `created_at`, so it reads e.g. *"parsed (filename_parse, 1.0) → confirmed by admin (admin_manual)."*

---

## 8. Basic admin verification UI (`hr-frontend`) — table + panel only

A **Knowledge → Documents** area inside the existing `/admin` route tree (Sprint 0). **Plain table + detail panel — explicitly NOT the lens/graph/card hierarchy** (that is a later sprint).

### 8.1 Supporting `hr-backend` endpoints (admin-only, `auth:sanctum` + role)
- `GET /admin/documents` — paginated list with filter query params: `territory_id`, `sector_id`, `convenio_id`, `document_type_id`, `tagging_status`, and `conflicts_only` (has an open `conflict` task). Returns per row: `uuid`, `title`, territory, sector, convenio `numero`, document_type, `validity_start/end`, `tagging_status`, `has_open_conflict`.
- `GET /admin/documents/{uuid}` — detail: resolved facet tags (id + display), the `tag_events` timeline, `validity`/`retrieval_status`/`authority_level`, and `document_pages` (`page_number`, text, `image_path`).
- `POST /admin/documents/{uuid}/confirm` — sets `tagging_status = verified`, writes the `admin_manual` provenance row, resolves the open `tag_review` task (§7).
- `PATCH /admin/documents/{uuid}/facets/{facet}` — body `{ value_id }`; **validates the target id exists in the corresponding vocabulary table** (controlled-vocab only — never free text), updates the FK, writes provenance.
- `GET /admin/vocabulary/{territories|sectors|convenios|document_types}` — options for the re-assign dropdowns.
- `GET /admin/documents/{uuid}/pages/{n}/image` — returns a short-lived **pre-signed S3 URL** (or proxies the bytes) for the page image. (`hr-backend` reads S3; no DB writes.)

### 8.2 Frontend pieces (React, in the admin tree)
- `DocumentsPage` — a **filter bar** (selects for territory / sector / convenio / document_type / `tagging_status`, plus a *Conflicts only* toggle) above a **table** with the columns above and a visible **conflict flag** badge. Rows open the detail panel.
- `DocumentDetailPanel` — a side panel/drawer showing: facet tags, the **provenance timeline** (from `tag_events`, each entry showing source / actor / confidence / time), validity + status + authority, and **source pages** (image thumbnails + extracted text from `document_pages`).
- Actions in the panel: **Confirm tags** (button → `POST …/confirm`) and **Re-assign** per facet (a dropdown of controlled-vocab options → `PATCH …/facets/{facet}`). These exercise the human-in-the-loop validation and write provenance.
- Upload entry: a simple **folder upload** control (`webkitdirectory`) posting to `POST /admin/documents/upload` (§4.1) with a per-file result summary.
- Reuse the Sprint 0 centralized API client (`src/lib/api.ts`), auth context, and admin route tree; add the new calls there. Keep it **separate** from the employee tree.
- **Note:** the `/me` employee payload key renames `province` → `territory` (§2.2); update the Sprint 0 `EmployeeShell` field accordingly (small, recorded in `review.md`).

---

## 9. Assumptions & questions (to confirm before/at build)

**Registry `.xlsx` (highest priority — needs the real file):**
1. **File location** of `01_listado_convenios.xlsx` (path in the repo / a `data/` folder / supplied at import time)? The command defaults to `REGISTRY_XLSX_PATH`.
2. **Exact sheet name** (`LABOUR AGREEMENTS`?) and **exact header strings** for the columns in §3.2 — I parse by header name, so I need the real headers.
3. **Prefix→level mapping** (§3.3): confirm the rule, especially that Andalucía COEAS `711035…` is **regional**. **Preferred:** is there a scope/level column in the sheet I can read directly instead of inferring from the prefix?
4. **Multi-value cells** (§3.6): confirm we only store raw text in `notes` + typed numeric for single clean values this sprint (no category splitting).

**Ingestion / parsing:**
5. **Idempotency key** (§4.3): OK to use `source_filename` + `convenio_id`, or should I add a `content_hash` column to `documents` (additive migration + `data-model.md` edit)? I recommend `content_hash`.
6. **Validity date semantics** (§5.1): an `20232026` token → `2023-01-01`…`2026-12-31`? Or convenio-specific dates from the registry?
7. **Language signal** (§5.1): what exactly marks `eu` (Euskara) — a filename token, a Basque-province folder, or always `es` this sprint?
8. **Folder naming** (§4.1): are all uploads province/region-named folders? How are national/Estatal docs and the Estatuto foldered?
9. **Scanned PDFs** (§4.5): acceptable that image-only pages yield empty `text` (no OCR this sprint)?
10. **Service auth** (§4.4): OK to add a shared `X-Internal-Token` between `hr-backend` ↔ `hr-ai`?
11. **System conflict provenance** (§7): OK to also write a `source = system` `tag_events` row on conflict (in addition to the review task)?

Default behaviour if unanswered: I proceed with the assumption as stated and record it in `review.md` (and confirm the `.xlsx`-dependent ones against the real sheet at build time, creating no unverified vocabulary).

---

## 10. Mapping to the spec's acceptance criteria

| # | Criterion | Covered by |
|---|---|---|
| 1 | Territories restructure migrates cleanly; correct `level`s; three FKs renamed; `data-model.md` updated | §2 |
| 2 | Registry import populates `convenios`/`territories`/`sectors`; idempotent; preserves multi-value cells; supersedes dev fixture | §3 |
| 3 | Folder upload ingests every PDF: original in S3, page text+images via `/extract`, `documents` + `document_pages` written | §4 |
| 4 | Parser assigns territory/sector/convenio/document_type/validity/status/authority **as FKs**; appears in `tag_events` (`filename_parse`) | §5, §7 |
| 5 | A mismatched/unknown file is flagged `under_review` + `conflict` task, not auto-tagged | §6 |
| 6 | Admin Documents table lists docs+tags+status, filters work, detail panel shows provenance timeline + source pages | §8 |
| 7 | Confirm tags → `verified` + `admin_manual` provenance; re-assign facet → controlled-vocab change + provenance | §7, §8 |
| 8 | Nothing out-of-scope built; `hr-ai` gains only `/extract` and never writes the DB | §0, §4.4 |

---

## 11. Out of scope (re-affirmed — not built this sprint)
Chunking/embeddings/vector search/RAG/chat; the LLM tagging tier; structured salary-row extraction into `salary_table_rows`; `convenio_job_categories` population; the lens/graph/expandable-tree hierarchy and the full document card (expiry countdown/lineage UI); escalation board, guardrails UI, analytics, employee chat UI; non-PDF document ingestion (beyond the registry `.xlsx`).

---

## 12. Docs to update during the build
- **`data-model.md`** — the `provinces`→`territories` restructure + three FK renames + deferred-precedence note (§2.3); and (if Q5 is approved) the `documents.content_hash` addition.
- **READMEs** — `hr-backend` (registry import + folder upload steps), `hr-ai` (the `/extract` endpoint + new deps).
- Any forced ADR/architecture change recorded in `sprint-01/review.md`. ADR-0010 honoured (extraction in `hr-ai`, DB writes only in `hr-backend`).
- `sprint-01/review.md` written **after** the build is reviewed — not this turn.

---

**Plan ready for review.** No code, migrations, or config have been written — only this `plan.md`. On approval (and answers to §9, especially the `.xlsx` specifics), I will implement in the build order of §1 and record the `data-model.md` edits in the same change.
