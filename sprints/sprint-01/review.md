# Sprint 1 — Review

**Goal (from `spec.md`):** stand up the knowledge-ingestion backbone — territories
restructure, convenio registry import, PDF ingestion (text + page images),
deterministic filename parser, conflict detection, tag provenance, and a basic
admin verification UI. Built in the order of `plan.md §1`, honoring the resolved
§9 decisions and ADR-0011.

**Status:** complete and self-verified end-to-end (registry import + a mixed-sample
ingestion run through the real HTTP API → hr-ai `/extract` → S3 → DB). Not committed
(awaiting review).

---

## 1. What was built

### hr-backend (system of record — owns all migrations & DB writes)
- **Territories restructure** (3 migrations): renamed `provinces → territories`;
  replaced `is_national` with `level (national|regional|provincial)`; added
  self-ref `parent_territory_id`; relaxed `code` to `varchar(8) NULL`; renamed the
  FK columns `convenios.province_id`, `employees.province_id`,
  `document_chunks.province_id` → `territory_id`. New `Territory` model (+ `parent`/
  `children`); `Convenio`/`Employee` relations and `IdentityPresenter` updated;
  `ProvinceSeeder` → `TerritorySeeder` (canonical 12 scopes + curated aliases via a
  shared `TerritoryCatalog`).
- **`documents.content_hash`** (`varchar(64)`, indexed) — sha256 idempotency key.
- **`document_review_tasks.reason`** (`unresolved|conflict`) + **`raw_unmatched_values`**
  (`jsonb`) — ADR-0011 data foundation.
- **`registry:import`** artisan command — parses `01_listado_convenios.xlsx`
  (`LABOUR AGREEMENTS` sheet) by header name; idempotent; multi-value preservation;
  alias population; DEV-FIXTURE supersession. (PhpSpreadsheet added.)
- **Ingestion pipeline**: `POST /admin/documents/upload` (folder upload, PDF-only)
  → `DocumentIngestor` (hash → store original to S3 → call hr-ai `/extract` →
  `FilenameParser` + `DocumentTagger` → write `documents`, `document_pages`,
  `tag_events`, `document_review_tasks`). `ExtractionClient` calls hr-ai with the
  shared `X-Internal-Token`.
- **Deterministic parser + tagger** (`FilenameParser`, `DocumentTagger`,
  `VocabularyResolver`, `TextNormalizer`): numero/prefix, both validity formats
  (`YYYYYYYY` and `YYYY_YYYY`, provisional dates), `Antiguo → historical`,
  national-law (`ESTATUTO…`), accent/case-insensitive vocabulary matching, conflict
  vs unresolved routing, provenance.
- **Admin API**: list (with facet + conflict + empty-text flags), detail
  (tags/provenance/review/pages), confirm, re-assign facet, page-image URL,
  vocabulary endpoints. New `admin` middleware + CORS for `admin/*`. Flysystem S3
  adapter added.

### hr-ai (document processing — writes only S3, never the DB, never migrates)
- **`POST /extract`** (ADR-0010): reads the original PDF from S3, extracts per-page
  text (PyMuPDF), renders each page to a JPEG written back to S3, returns
  `{ page_count, pages:[{page_number, text, image_key}] }`. Guarded by
  `X-Internal-Token`. Added `storage.py` (boto3) and `extract.py`; new deps
  `pymupdf`, `boto3`.

### hr-frontend (admin)
- **Knowledge → Documents** module: folder upload, filterable table (status,
  conflicts-only) with conflict/review/empty-text/national badges, and a detail
  panel (tags, review tasks with reason + raw values, provenance timeline, source
  pages with text + page image, confirm, re-assign facet). `province → territory`
  rename in the employee profile view + API types. Typecheck clean.

---

## 2. Registry findings (resolved Q1–Q4)

The real sheet was read directly. **Workbook has two sheets** — `FAQs` and
`LABOUR AGREEMENTS`; the registry is the latter. **Headers (verbatim):**
`NUMERO`, `CONVENIO`, `PROVINCIA`, `HORAS ANUALES`, `HORAS SEMANA`, `NUMERO A3`,
`COMPLEMENTO IT`. Parsing is by header name.

- **No explicit `level` column exists.** We classify level from the `PROVINCIA`
  value directly (preferred over numero-prefix inference): `ESTATAL → national`,
  `ANDALUCIA → regional`, everything else → `provincial`. The 2-digit `code` is the
  numero prefix for provincial scopes; `71` for Andalucía; `99` for Estatal.
- **Andalucía (Q2) — confirmed REGIONAL, not a province.** Row
  `71103505012022 · OCIO EDUCATIVO Y ANIMACION ANDALUCIA · PROVINCIA "ANDALUCIA"`.
  The prefix `71` is outside the `01–52` Spanish provincial range and `ANDALUCIA`
  is an autonomous community → imported as a **regional** territory (code `71`),
  superseding the Sprint 0 placeholder code `AN`.
- **Imported:** 12 territories, 20 sectors, 26 convenios (27 data rows; one numero
  — `20000785011981`, LIMPIEZA Gipuzkoa — appears twice, so updateOrCreate yields
  26 distinct). Re-running the import creates nothing new (idempotent).
- **Territory spellings span three sources** — registry `PROVINCIA` (e.g. `ALABA`,
  `GUIPUZCOA`, `VIZCAIA`), folders (Spanish: `VIZCAYA`), filenames (Basque:
  `Bizkaia`, `Araba`). The import seeds aliases covering all three, e.g.
  Vizcaya ← `Bizkaia`/`Vizcaia`/`VIZCAIA`; Gipuzkoa ← `Guipúzcoa`/`GUIPUZCOA`;
  Álava ← `Araba`/`ALABA`. This is what prevents Basque-province false-conflicts.
- **Multi-value headline cells preserved** verbatim in `convenios.notes` (e.g.
  `HORAS ANUALES: 1742 (1698); HORAS SEMANA: 38,5 (37,5)`, and `39/35`); the typed
  `annual_hours`/`weekly_hours` columns are set only for single clean numbers.
- **`CONVENIOS 2026.xls` was NOT imported** (Q1) — it is a free-text human status
  note (convenio name + 2026 status), with no numbers/provinces/structure.

---

## 3. Eyes-on mixed-sample test

No real corpus ships in the repo, so a deliberately mixed sample of PDFs (+ one
salary `.xlsx`) was generated and ingested through the real upload API
(`POST /admin/documents/upload`) → hr-ai `/extract` → MinIO → DB. Per-file outcome:

| Sample (folder / filename) | Expected | Result |
|---|---|---|
| `VIZCAYA/48006185012006_…_Bizkaia_20232026_texto.pdf` (Basque, bilingual) | clean, no false territory conflict | ✅ `auto_proposed`, territory **Vizcaya**, active, conf 1.0 — folder `VIZCAYA` + filename `Bizkaia` both resolve to the same territory |
| `ALAVA/Antiguo/01003205012006_…_ARABA_20102013_texto.pdf` | historical | ✅ `auto_proposed`, **`retrieval_status = historical`** (Antiguo), territory Álava (filename `ARABA`, folder `ALAVA`) — no false conflict |
| `ESTATAL/Plan_Igualdad_texto.pdf` (no numero) | `under_review`, reason `unresolved` | ✅ `under_review`, **reason `unresolved`**, convenio NULL, `raw_unmatched_values` = sector `Plan Igualdad` + filename; 2 `system` tag_events |
| `ESTATAL/99000155011981_…_ESTATAL_texto.pdf` (national convenio) | tags as national | ✅ `auto_proposed`, territory **Estatal (level national)** |
| `ESTATAL/ESTATUTO TRABAJADORES_2015.pdf` (national law, no numero) | national, not a conflict | ✅ `auto_proposed`, **`authority_level = national_law`**, convenio NULL — a missing numero on a recognized national-law doc is **not** a conflict |
| `MADRID/48001455011981_…_Bizkaia_…_texto.pdf` (numero 48 in MADRID folder) | conflict | ✅ `under_review`, **reason `conflict`** (folder Madrid disagrees with Vizcaya), `system` tag_event on the `territory` facet |
| `HUESCA/22000175012004_…_escaneado.pdf` (image-only) | empty text, visibly flagged, still stored | ✅ `auto_proposed`, **`empty_text = true`** surfaced in the admin list; page image still produced & stored |
| `MADRID/28102145012018_…_Tablas_2026.xlsx` (salary) | skipped | ✅ **skipped** — "not a PDF (PDF-only this sprint)" |

Other end-to-end checks: all 7 documents have both an `original.pdf` (written by
hr-backend) and a `pages/0001.jpg` (written by hr-ai) in MinIO; the page-image
presigned URL returns `200`; `confirm` resolves open review tasks and writes an
`admin_manual` provenance row; `reassign` of `convenio`/`document_type` works and
is rejected (`422`) for a non-reassignable facet (`territory`); `conflicts_only`
filter returns only the conflict doc.

---

## 4. Forced / notable doc changes

- **`data-model.md`** updated for: `provinces → territories` (with `level` +
  `parent_territory_id`, replacing `is_national`); the three FK renames
  (`convenios`/`employees`/`document_chunks` → `territory_id`);
  `documents.content_hash`; `document_review_tasks.reason` +
  `raw_unmatched_values`; the **deferred-precedence** note (parent/level carry no
  rollup logic this sprint); the **bilingual** language note; `tag_events.facet`
  `province → territory`; ER diagram and read-path prose. The Sprint 0 "known
  limitation" box on `provinces` is now the "Sprint 1 restructure" note.
- **READMEs** (`hr-backend`, `hr-ai`) updated with the registry import, the admin
  ingestion/verification API, and the hr-ai `/extract` contract + new deps.
- No ADRs were changed. ADR-0010 (extraction in hr-ai) and ADR-0011 (two-reason
  routing + raw value) were implemented as written.

---

## 5. Design decisions worth flagging for review

- **Territory/sector are not columns on `documents`** — they are reached via
  `convenio` (the faceted model: a convenio fixes territory + sector). So the
  parser's territory/sector resolution is used for **conflict detection and
  provenance**, and the authoritative scope is the convenio. Re-assignable facets
  on a document are therefore `convenio` and `document_type` (re-assigning the
  convenio re-assigns its territory + sector). National-law docs carry scope via
  `authority_level = national_law` (convenio + territory NULL).
- **`empty_text` is derived, not stored** — computed from `document_pages`
  (pages exist but all text blank) and surfaced in the admin list/detail. No extra
  column was needed.
- **Conflict outranks unresolved** when both could apply; a `conflict` always
  writes a `system` tag_event on the conflicting facet (Q11). Sprint-1 confidence
  is coarse (1.0 clean / 0.8 partial / 0.5 in-review) — fine until the LLM tier.

---

## 6. Local verification environment (note)

This machine already had other Docker stacks bound to the canonical ports, so the
eyes-on run used alternates: **MinIO on host `9900`** (→ container `9000`) and
**hr-ai on host `8011`**. hr-ai also runs in a `python:3.11-slim` container (the
host only has Python 3.9, below the service's 3.11+ requirement). The committed
`.env`/`.env.example`, `docker-compose.yml`, and READMEs all use the canonical
ports (`9000`/`9001`, hr-ai `8001`); the alternates were transient and not
persisted. Postgres is the existing host instance on `55432` (Sprint 0).

---

## 7. Out of scope (not built — per spec & hard constraints)

Embeddings / chunking / vector search / retrieval / answer synthesis; LLM-assisted
tagging and the LLM-rescue path; the propose-new-vocabulary UI; salary-table /
`convenio_job_categories` population; non-PDF formats; territory precedence/rollup;
employee chat. hr-ai still **never** writes the DB or migrates; the parser **never**
creates vocabulary values.

---

## 8. Addendum — pre-commit corrections (approved)

Two corrections were applied after the eyes-on review, before commit.

### Correction 1 — robust territory-level classifier (code + data-model)
The registry import classified a territory's `level` by matching the `PROVINCIA`
string (`ESTATAL`→national, `ANDALUCIA`→regional, else provincial). That hardcoded
the two known cases by name: a *second* region appearing in the registry (another
autonomous-community convenio) would fall into "else" and be silently mislabeled
provincial.

- **New rule (authoritative): numero-prefix range.** `99 → national`;
  `01–52 → provincial`; any other 2-digit prefix (autonomic range, e.g. Andalucía's
  `71`) `→ regional`. The `PROVINCIA` string is now only a **cross-check**.
- **No silent defaulting.** If the prefix-derived level disagrees with the
  `PROVINCIA`-implied level, or the prefix is outside all known ranges (e.g. `00`),
  the row is **logged + surfaced** in the import summary as a **flagged**
  classification for human confirmation (ADR-0011 managed-growth). A new region
  (regional prefix + an unknown `PROVINCIA` name) now flags instead of mislabeling.
- **Files:** `app/Support/TerritoryCatalog.php` (`levelFromPrefix` +
  `levelFromProvincia` replacing `levelForProvincia`), `app/Console/Commands/RegistryImport.php`
  (cross-check + flag collection + summary/`Log::warning`).
- **Re-run result:** the 12 existing territories classify **identically** to before
  — Andalucía `regional`/`71`, Estatal `national`/`99`, all others `provincial`;
  **zero flagged rows** (every prefix-derived level agreed with the `PROVINCIA`
  cross-check). Classifier spot-checks: `71/73/53/98 → regional`, `99 → national`,
  `01/52 → provincial`, `00 / 9 / 7A → null` (flagged).
- **data-model.md:** added the "Level classification — numero-prefix range rule"
  note under `territories`, and updated the `convenios.territory_id` note (was
  "`level` derived from the `PROVINCIA` column …").

### Correction 2 — record the convenio-derived-scope decision (data-model only)
Documented (no schema change) near the `documents` table that **territory + sector
are derived via the document's `convenio`** (not columns on `documents`); the only
re-assignable document facets are `convenio` and `document_type`; the parser's
territory/sector resolution is used only for conflict detection + provenance with
the convenio authoritative; national-law docs carry universal scope via
`authority_level = national_law` (`convenio_id`/territory NULL). Recorded the
**known limitation**: a territory-scoped *non-convenio* policy doc cannot currently
carry its scope (no such case in today's corpus — the only no-convenio docs are
national; revisit if one appears). This is a deliberate normalization and a noted
deviation from ADR-0001's independent-facet framing.

### Correction 3 — duplicate-numero merge in registry:import (code + data-model)
The registry has a real duplicate-`numero` case (`20000785011981`, Gipuzkoa cleaning,
A3 `100008`) on two rows that differ only by name + `COMPLEMENTO IT`: row A
`LIMPIEZA DE GIPUZKOA` / `Grupo Complementos IT 001`, row B `LIMPIEZA EDIFICIOS Y
LOCALES` / NULL. The previous row-by-row `updateOrCreate` collapsed them **silently**
and the last row won — losing the populated `COMPLEMENTO IT`.

- **Schema:** added `convenios.aliases` (jsonb NULL), consistent with
  `territories.aliases` / `sectors.aliases`
  (`database/migrations/2026_06_21_100006_add_aliases_to_convenios.php`; `Convenio`
  model `aliases` fillable + array cast).
- **Merge rule (`registry:import`):** rows are now grouped by `numero` and merged into
  one convenio — canonical name = the more formal/longer title (longest wins,
  alphabetical tie-break for stable re-runs); every other distinct spelling is folded
  into `aliases` (no name lost); each remaining field takes the **first non-null /
  more-complete** value. Every merge is logged in the summary + `Log::warning`
  (ADR-0011 — surfaced, not silent).
- **Resolver:** `VocabularyResolver::convenioByName()` now matches the canonical `name`
  **and** `aliases` (same alias-aware matching as territories/sectors), so either
  spelling resolves to the one convenio.
- **Re-run result** (`migrate:fresh --seed` + `registry:import`): 27 rows →
  **26 distinct convenios** (unchanged); `20000785011981` exists **once** with
  `name = "LIMPIEZA EDIFICIOS Y LOCALES"`, `aliases = ["LIMPIEZA DE GIPUZKOA"]`, and
  `it_complement = "Grupo Complementos IT 001"` **retained**; the merge is reported in
  the summary/log. The resolver maps both `LIMPIEZA EDIFICIOS Y LOCALES` and `LIMPIEZA
  DE GIPUZKOA` → `20000785011981`. A second run changed nothing (0 created, 26 updated,
  26 total, same aliases/`it_complement`, same logged merge) — idempotent.
- **data-model.md:** added the `aliases` column under `convenios` and a
  "Duplicate-numero rows are merged" note (one-convenio-per-numero; canonical name +
  folded aliases; non-null field preference; every merge logged).
