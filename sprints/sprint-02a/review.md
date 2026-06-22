# Sprint 2a — Review (ingestion → vectors)

> Status: **ready for review**. Built per `plan.md` §1 build order, the resolved
> §9 decisions, and the five plan-review catches. Nothing committed.

This sprint built the **deterministic retrieval substrate**: prose → column-aware
bilingual chunks → BGE-M3/1024 vectors in `document_chunks`, and salary `.xlsx` →
exact relational rows in `salary_tables` / `salary_table_rows`, with a CLI
verification harness over both. No chat UI / router / answer LLM / guardrail /
citations-in-chat / history (all 2b); no in-PDF salary grids, no `.doc/.docx`
prose, no precedence logic, no LLM tagging tier, no lens hierarchy.

---

## 1. What shipped

**hr-ai (Python)**
- `app/embeddings.py` — BGE-M3 self-hosted in-process via `sentence-transformers`
  (CPU), lazy singleton, 1024-dim unit vectors + tokenizer-based token counts.
- `app/chunking/` — `extract_columns.py` (PyMuPDF rawdict; page-furniture
  stripping; two-column eu/es split), `normalize.py` (geometry-first de-spacing),
  `chunker.py` (article-aware chunking + size cap), and the `pipeline.py` glue.
- `app/salary.py` — salary `.xlsx` parser (junk-sheet skip, header-row detection,
  per-format header→field map, multi-year, 14/12 canonical mapping).
- `app/chunks_db.py` — the scoped write/read path to `document_chunks`
  (idempotent re-embed; full-recall exact-scan retrieval).
- `app/main.py` — `/embed`, `/extract-salary`, `/retrieve` (internal-token guarded).
- `scripts/sanity_test.py` — the BGE-M3/1024 go/no-go.
- Deps added: `sentence-transformers>=3.0`, `openpyxl>=3.1`.

**hr-backend (Laravel)**
- Migration `2026_06_22_100001_create_hr_ai_role_and_chunk_indexes` — the scoped
  `hr_ai` Postgres role (ADR-0007 at the DB) + additive `validity_start/end`
  btree indexes (Q4).
- `DocumentIngestor` + upload gate now accept salary `.xlsx` (typed `salary_tables`,
  no `/extract`, no pages).
- `ExtractionClient` — added `extractSalary()`, `embed()`, `retrieve()`.
- Models `SalaryTable`, `SalaryTableRow`.
- Commands: `documents:ingest-folder`, `chunks:embed`, `salary:import`,
  `retrieval:probe`.

**What 2b consumes:** the `/retrieve` vector primitive, the salary SQL surface,
the resolved-scope contract, the coverage-gap signal, and the `retrieval:probe`
output shape (which mirrors the `message_traces.trace` 2b will persist).

---

## 2. BGE-M3 / vector(1024) sanity test — **PASS** (go)

Ran **first**, on real ES + EU chunks read from S3 (Gipuzkoa `eu`+`es` +
`ESTATUTO TRABAJADORES.pdf` `es`), via `scripts/sanity_test.py`:

- **Same-language self-retrieval: 16/18 = 89%** (threshold 85%) → **PASS**. The
  only two misses were within-language adjacent chunks (`GIP-eu#5→GIP-eu#3`,
  `EST-es#1→EST-es#3`) — never cross-language leakage.
- **Cross-language alignment present but never filtered**: `GIP-eu#0`'s nearest
  `es` chunk sits at **0.745** cosine — confirms the shared multilingual space
  (ADR-0006) works without language ever being a filter.
- **De-spacing A/B**: the clean (de-spaced) query lands on its true target
  (`GIP-es#0`, 0.556) while an artifact-like re-spaced query misfires to an
  unrelated chunk (0.463) — normalization measurably helps retrieval.
- Decision: `vector(1024)` is **locked**; no model swap, no re-dimensioning.

---

## 3. Stress gate (a) — furniture stripping + chunk boundaries

Run on the real corpus from S3.

**Gipuzkoa `20000785011981_Limpieza Gipuzkoa_2019 2026.pdf` (bilingual):**
- 35 pages, all classified two-column → `es`=75, `eu`=80 chunks (155 total).
- **280 furniture blocks stripped.** The repeating-furniture detector caught
  exactly: `['boletín oficial de gipuzkoa', 'gipuzkoako aldizkari ofiziala',
  'www.gipuzkoa.eus lg.:s.s.1-1958', 'ek\\cvgao-i-2023-09587']`. This is the
  catch-1 correctness win: that BOG footer is **itself bilingual**, so leaving it
  in a column would have produced a blended-language chunk (ADR-0013 forbids).
- **De-spacing works**: chunk text reads cleanly (`Artículo 3.º Ámbito personal`,
  `artículo 32,4 del estatuto…`), i.e. the `hi tzar men → hitzarmen` artifact is
  resolved by the geometry-first pass (`chunk_space_gap_ratio = 0.30`, tunable);
  only **1** residual long-token flag on the whole doc.
- **Article splits sensible**: chunks begin on `Artículo N.º <title>` boundaries
  (`GIP-es#2` = `Artículo 3.º Ámbito personal`, `GIP-es#5` = `Artículo 7.º
  Vacaciones`).
- **`pages_not_cleanly_split = [34, 35]`** — the final two pages (the in-PDF
  **salary grid** + signature block) were flagged for eyes-on rather than
  force-split. Correct outcome: salary is deferred to the `.xlsx` path (ADR-0014),
  not prose we must cleanly column-split.

**Monolingual Spanish (`ESTATUTO TRABAJADORES.pdf`, the sanity-test doc):**
- → **205 `es` chunks, 0 `eu`**, **87/87 pages single-column**, **262 furniture
  stripped** (the BOE footer `boletín oficial del estado legislación consolidada`),
  **`pages_not_cleanly_split = []`**, 6 long-token flags. The clusterer correctly
  saw a single column on every page and produced **zero** spurious `eu` chunks —
  the monolingual safety check.
- `Disposición adicional/transitoria/…` handled alongside `Artículo N` as anchors.

**Note on Estatuto editions:** the corpus has three Estatuto files. The sanity test
above used the clean base edition. The **harness (§6)** instead embedded the
consolidated `ESTATUTO TRABAJADORES_julio2025.pdf` → **240 chunks (es=233, eu=7)**,
**369 furniture stripped**; its 7 `eu` chunks and its `pages_not_cleanly_split`
(190,191,193,…,231) are all on the alphabetical *índice analítico* + cross-reference
**back-matter**, i.e. **the flag fires precisely where the layout stops being
prose** — the desired eyes-on signal, not a silent blend. Andalucía COEAS
(`71103505012022…pdf`, also embedded for the harness) → **148 chunks (es=146,
eu=2)**, **258 furniture**, one back-matter page (64) flagged.

**Verdict:** clean eu/es separation on prose, no furniture/footer bleed, de-spacing
effective, sensible article splits, and the not-cleanly-split flag fires only on
genuine non-prose (salary grids, signature blocks, consolidated-law indices) →
**cleared for bulk embed**.

---

## 4. Stress gate (b) — xlsx parser per-format maps

Ran `hr-ai /extract-salary` live against the required differently-shaped files
(plus several extras) and inspected the parsed rows. **Header-row detection,
junk-sheet skipping, multi-year splitting, and the 14/12 canonical mapping
(`base_salary_monthly = gross_annual / 14`, `num_payments = 14`, decimal-comma
normalized, everything verbatim in `raw_values`) are confirmed on the gate trio:**

| file | sheets → tables | rows/yr | category | gross | hourly | 14/12 sample (yr/cat) |
|---|---|---|---|---|---|---|
| **ANDALUCIA / TABLAS COEAS Andalucia.xlsx** | `smi 26`+`smi 25` → **2** (3 junk sheets skipped) | 27 | descriptive name (`Director/a Gerente`) | `TOTAL` | annual-hours col `1742` | 2026 22058.76 → 1575.63 ×14, €/h 12.66 ✓ |
| **CANTABRIA / Tabla Salarios Deporte Cantabria.xlsx** | `2026`+`2025` → **2** | 6 / 5 | group **code** (`2.1'`) | `Bruto anual` | `€/hora` | 2026 37131.50 → 2652.25 ×14, €/h 21.79 ✓ |
| **ESTATAL / TABLAS SALARIALES COEAS Estatal.xlsx** | 3 sheets → **3** | 32 / 27 / 27 | `Grupo …` code + name | `Total anual`/`TOTAL` | `€/hora`/`1742` | 2026 22186.61 → 1584.76 ×14, €/h 12.74 ✓ |

**Header-synonym set** (normalized): gross ∈ {`total`, `total anual`, `bruto
anual`, `bruto año/ano`, …}; hourly ∈ {`€/hora`, `precio/hora`, `bruto/hora`,
`salario hora`, bare annual-hours number}; extra ∈ {`pagas extra`…}; night ∈
{`plus nocturno`, `plus nocturnidad`, `plus hora nocturna`…}. **Label columns =
everything left of the first money-header column** (robust even when the label is
a numeric-looking code like Cantabria `2.1'`).

**Live import result** (`salary:import` on the three gate convenios after
assignment): **7 tables, 151 rows, 63 job categories** — Andalucía COEAS
(smi 25+26), Cantabria Deporte (2025+26), COEAS Estatal (3 sheets); 14/12 +
`raw_values` verified in the DB (Andalucía Director/a Gerente: gross 22058.76,
base/14 1575.63, n 14, €/h 12.66; `raw_values` keeps the `/12` figure, `sb`,
`total`, `1742`).

**Spot-checks beyond the trio (parse-only, not imported — surfaced two
per-format quirks the eyes-on gate must catch before authorizing those imports):**
- **Category is often a bare code, not a descriptive name** (Cantabria `2.1`,
  Deporte Estatal `1`, Agencias Pamplona `3`). The parser stores it faithfully; a
  human just sees a code instead of a label. Acceptable, but flagged. *(Post-gate
  fix: the Cantabria code originally landed as `2.1'` with a literal trailing
  apostrophe from the Excel text cell; the parser now strips wrapping
  quotes/apostrophes from `name` and `group_code`, and the Cantabria salary doc
  was re-imported — see §9.)*
- **Year not always on the sheet name** — `TABLAS SALARIALES Deporte Estatal.xlsx`
  (`Hoja1`) parses 8 rows with **`year = null`**; that document would need its year
  set at assignment time.
- **Secondary/auxiliary tables on a sheet can mis-map** — `Tabla Limpieza
  Navarra.xlsx` parses its **primary 2026 table cleanly** (gross 24748.65 → 1767.76
  ×14) but a second block on the file yields `gross = null` (hourly-only columns not
  in the map). COEAS Navarra / Intervención Social Navarra show the same partial
  shape, and the Alhambra acuerdo-parcial sheet is free-text notes (junk).

These extras are **deferred, not silently wrong**: they are numero-less, so they
stay `under_review` and import nothing until a human assigns a convenio and
eyeballs the parse — exactly the ADR-0014 gate. The per-format map is finalized
only for the three convenios actually imported this sprint.

---

## 5. Coverage-gap list (ADR-0014 — visible, never silent)

Most salary `.xlsx` are numero-less and land `under_review` for deliberate
convenio assignment (catch 4) — the **intended** controlled path. After importing
the three gate convenios, `salary:import` reported **23 convenios with no salary
rows yet**, including the canonical example **`20000785011981` — Limpieza
Gipuzkoa** (salary lives only in the convenio PDF, pages 33–35; in-PDF grids are
deferred). Pending-assignment `.xlsx` still awaiting a convenio include: Tabla
2026 (Vizcaya), Alhambra acuerdo parcial, TABLAS SALARIALES 2021 2022 COEAS,
Deporte Estatal, Agencias Pamplona, COEAS Navarra, Intervención Social Navarra,
Limpieza Navarra. The harness prints "no salary rows yet" for a gap convenio
instead of an empty result; the same signal seeds 2b's coverage-gap surface.

---

## 6. Verification harness — `retrieval:probe`

Inputs are a profile (`--email`) **or** an explicit scope (`--convenio` +
optional `--job-category`) + a `--question` + a `--date`; `--mode` (both/prose/
salary) and `--include-historical` tune it. Outputs: the **resolved scope**, the
**eligible prose chunks** (similarity score + source doc + page span + snippet,
after the scope prefilter), and the **eligible salary rows** (convenio + year +
job category). The query is embedded by `hr-ai /retrieve`; the prefilter is the
full-recall exact scan, and the harness asserts no eligible chunk is dropped.

**Probe A — Gipuzkoa Limpieza (prose; bilingual; coverage gap), `2026-01-15`**
_"¿Cuántos días de vacaciones tengo y cómo se calcula la jornada anual?"_
```
RESOLVED SCOPE  convenio 20000785011981 — LIMPIEZA EDIFICIOS Y LOCALES / Gipuzkoa
ELIGIBLE PROSE CHUNKS  eligible set size: 395; returned top-6
 full-recall check: OK (no eligible chunk dropped by the ANN layer)
 [0.633] …Limpieza Gipuzkoa… p3-4 (chunk 10): Artículo 7.º Vacaciones. Durante el disfrute…
 [0.624] ESTATUTO TRABAJADORES… p62-64 (chunk 67): Artículo 34. Jornada…
 [0.597] …Limpieza Gipuzkoa… p3-4 (chunk 13): 7. artikulua. Oporrak. Oporraldiak iraun…
ELIGIBLE SALARY ROWS  no salary rows yet for convenio 20000785011981 (coverage gap, not an error)
```
This single probe demonstrates **four** invariants at once: (1) scope prefilter +
**full-recall OK**; (2) the Spanish `Artículo 7.º Vacaciones` and its Basque twin
`7. artikulua. Oporrak` **both** surface — language is recorded but never filters
(ADR-0006); (3) the national-law Estatuto `Artículo 34. Jornada` rides in as
universal; (4) the salary line is a **coverage gap** message, not a blank.

**Probe B — Andalucía COEAS (salary; the catch-4 sequence), `2026-01-15`**
_"¿Cuál es el salario anual del Director Gerente?"_ with `--job-category="Director/a Gerente"`
```
RESOLVED SCOPE  convenio 71103505012022 — OCIO EDUCATIVO Y ANIMACION ANDALUCIA
ELIGIBLE PROSE CHUNKS  eligible set size: 240; full-recall check: OK
 (only the universal Estatuto returns — the COEAS convenio doc is *historical*
  (2022–2025) so as-of 2026 it is correctly out of scope without --include-historical)
ELIGIBLE SALARY ROWS  salary_table convenio 71103505012022, year 2026, Director/a Gerente
 - gross_annual=22,058.76  base/mes(14)=1,575.63  €/h=12.6629
```
Confirms the catch-4 order end-to-end: ingest `TABLAS COEAS Andalucia.xlsx`
(landed `under_review`, numero-less) → assign convenio `71103505012022` →
`salary:import` → harness returns the typed row with the **14/12 mapping**
(`22058.76 / 14 = 1575.63`, `num_payments = 14`), and the **retrieval_status
filter** correctly excludes the historical convenio prose while keeping universal
national law.

---

## 7. Forced documentation changes

- **`data-model.md`** — `document_chunks`: model **locked** (sanity passed),
  `territory_id` now **populated**, the Sprint-2a index migration, and a
  **Population** note (scoped `hr_ai` role; column-aware bilingual extraction;
  furniture stripping; de-spacing; article chunking; selection excludes
  `draft`/`under_review`; idempotent re-embed; eu/es never share a chunk;
  language not stored). `convenio_job_categories`: population-from-salary note.
  `salary_tables`/`salary_table_rows`: one-doc→many-year-tables, the **14/12
  canonical mapping**, `raw_values`, xlsx-first/coverage gaps. §11: the
  **full-recall exact-scan** prefilter. §12: embedding decision marked confirmed.
- **`architecture.md` §5** — recorded the Sprint-2a substrate primitives
  (`/retrieve` request/response shape, salary SQL, scope resolver, `retrieval:probe`)
  and that the router/synthesise/guardrail steps are 2b.
- **READMEs** — hr-ai (`/embed`, `/extract-salary`, `/retrieve`, the sanity test,
  new deps); hr-backend (`.xlsx` ingestion, the four new commands, the scoped
  `hr_ai` role migration).

No ADR changes were forced. No schema column was added beyond the additive
validity indexes and the role (no `language` column on `document_chunks`, per Q5).

---

## 8. Notes / decisions taken during the build

- **No HNSW reliance yet.** Retrieval forces an exact flat scan (full recall,
  catch 2). The HNSW index exists (Sprint 0) but is **not warranted** at this
  corpus size; it is the growth-path upgrade (then pgvector iterative scan).
- **Bulk embed is CPU-bound.** BGE-M3 on CPU embeds a ~150-chunk doc in roughly
  ~10 min. **Four documents were embedded to validate the end-to-end path** and
  drive the harness: Gipuzkoa Limpieza (155), the Estatuto (240), Andalucía COEAS
  (148), and — as the **clean single-column monolingual check** — Vizcaya
  Intervención Social (`48006185012006…`, **98 chunks es=97/eu=1, 125 furniture,
  `pages_not_cleanly_split=none`**), which confirms the column clusterer does not
  fabricate a second column on genuine single-column prose. **541 chunks across
  4 docs.** The identical `chunks:embed` (no `--document`) performs the full-corpus
  run after your eyes-on sign-off.
- **`documents:ingest-folder`** is an ops convenience over the HTTP upload for the
  ~90-file corpus — it reuses the exact `DocumentIngestor` (same tagging/conflict
  machinery), it does not reimplement anything.
- Corpus ingested: **98 documents** (65 convenio_text, 26 salary_tables, 3
  national_law, 2 partial_agreement, …), **2876 pages**, 0 errors.

---

## 9. Post-eyes-on fixes (after the gates, before bulk-embed sign-off)

- **Quote/apostrophe stripping on salary labels.** The Cantabria group code was
  stored as `2.1'` (a literal trailing apostrophe from an Excel text-typed cell).
  Two changes: (1) `hr-ai/app/salary.py` now strips wrapping quotes/apostrophes
  (`' ’ ‘ " \``) from both `name` and `group_code` after the whitespace/newline
  collapse; (2) `salary:import`'s `resolveCategory` now **heals** a matched
  category's stored `name`/`group_code` to the cleaned value (previously it only
  back-filled an empty `group_code`, so a re-import left the stale `2.1'` in
  place). Re-imported the Cantabria doc (idempotent — same category ids 2–5, no
  duplicates). **Verified:** the four codes are now `2.1 / 3.1 / 3.2 / 4.1` and
  `convenio_job_categories` has **0** rows containing an apostrophe corpus-wide.
  Documented in `data-model.md` (`convenio_job_categories` Population note).
- **Full corpus embed.** `chunks:embed` (no `--document`) ran over all **31**
  in-scope prose documents. **Result: 2777 chunks, 0 failed.** Spot-check
  (`retrieval:probe --convenio=48006185012006`, Vizcaya Intervención Social) is
  clean readable Spanish — doc 18's chunks split on the convenio's own em-dash
  heading style (`Artículo 1.—Ámbito funcional`, `Artículo 2.—Ámbito territorial`,
  …), **no half-words, no mixed-language, no furniture bleed**. (That convenio is
  `historical`, so it only surfaces with `--include-historical` — the scope filter
  working as designed.)
- **⚠ New finding — furniture over-stripping on a subset (needs tuning before
  these docs are trusted).** 11 of 31 docs are low-yield (<20 chunks). Two
  distinct causes:
  - *Scanned/old PDFs* with little selectable text (e.g. `DEPORTES ASTURIAS
    2009 2012` → 7 chunks) — genuine; OCR is out of scope this sprint.
  - *Over-aggressive furniture stripping*, concentrated in the **Navarra
    cluster** and `Intervención Social Gipuzkoa` (doc 45): they report **300–1207
    furniture blocks stripped** against only single-digit/teens chunk counts and
    nearly every page flagged `not_cleanly_split`. That block count is far too
    high to be real masthead/footer — the repeating-furniture / full-width-block
    heuristic is matching **legitimate body blocks** on those particular layouts.
    These chunks are **not** trustworthy yet. Recommendation: before relying on
    Navarra/doc-45 retrieval, tune the furniture thresholds
    (`chunk_furniture_width_ratio`, `chunk_repeat_furniture_min_page_fraction`)
    against those files and re-embed just them (`chunks:embed --document=…`). The
    clean modern docs (Gipuzkoa Limpieza, Alava, Valencia, Agencias, the Estatuto
    editions, Andalucía COEAS, Bizkaia Intervención Social) are unaffected.
- **⚠ New finding — small `eu` counts on Spanish-only docs are mis-split Spanish,
  not Basque (same root cause).** Eyeballed the Álava OCIO EDUCATIVO doc
  (`01100635012017…2023-2026.pdf`, es=87 eu=2, flagged pp.2,3,15,38). The doc is
  **monolingual Spanish** (an Araba/BOTHA convenio — no Basque at all), yet it
  produced 2 `eu` chunks. Both are **clean Spanish that was mis-routed to the `eu`
  stream**: `eu#0`/`eu#1` are the document's **article index (table of contents)**
  (`Artículo 1. Ámbito funcional…` → `Artículo 54…`) plus the **professional-
  classification group list** (`Grupo I: Personal directivo…`). They are not
  garbled and not Basque — they landed in `eu` only because pp.2/3/15/38 have
  index/table layouts (two visual columns of short lines, or a `GRUPO/CATEGORÍA`
  table) that the two-column detector misreads as the eu/es split, sending the
  left side into `eu`.
  - The flagged pages, judged from raw text: **p3 = genuine non-prose** (pure
    article-title index/TOC); **p38 = genuine non-prose** (Anexo I salary table —
    belongs to the relational salary path, ADR-0006); **p2 & p15 = mixed** — real
    prose (the *preámbulo*; the vacaciones paragraphs) sitting next to non-prose
    (the index; the classification table). So the flag is correct, but on p2/p15
    it is *partially* mishandled prose, and a full-width preamble block can be
    furniture-stripped.
  - **Why retrieval is still safe today:** ADR-0006/0013 — **language is never a
    retrieval filter**, and `document_chunks` stores **no** language column. A
    chunk mis-tagged `eu` internally is still retrieved for a Spanish query
    (it *is* Spanish), and its content here is duplicated elsewhere (the TOC and
    the classification list both recur in body/salary). So **no eligible content
    is silently dropped** — but the `eu`/`es` split counts are **not** a reliable
    bilingual signal, and the column/furniture heuristic should be tuned (same
    fix as the over-stripping item above) so monolingual table/index pages aren't
    read as two columns. Re-run the eyeball with
    `docker exec hr_ai python scripts/inspect_doc.py <storage_key> <pages>`
    (added this sprint as the eyes-on diagnostic).

---

## Correction-01 — column/furniture heuristic (pre-commit, post eyes-on gate)

The §9 findings were two faces of **one** root cause in
`hr-ai/app/chunking/extract_columns.py`: the heuristic failed in the *unsafe*
direction (delete content) instead of the safe one (keep content). Fixed at the
root, re-verified, and the affected docs re-embedded.

### Root cause

1. **Bare “full-width ⇒ furniture”.** Any block wider than 70 % of the page was
   stripped as furniture *before* column assignment. On single-column convenios
   whose body paragraphs span the page (the **Navarra cluster**; `Intervención
   Social Gipuzkoa` doc 45 — a modern, single-column, monolingual-Spanish PDF, not
   the two-column BOG), this ate the **entire body**: doc 45 reported **1207**
   furniture blocks and **18** chunks; Limpieza Navarra **469 / 17**; Intervención
   Social Navarra **818 / 11**. Silent legal-content loss.
2. **Two-column from bimodal block centres.** A page split into `eu`/`es` whenever
   it had *some* left blocks and *some* right blocks. Article indexes/TOCs (titles
   left, page-numbers right) and `GRUPO/CATEGORÍA`/salary tables (short row-paired
   cells) tripped it, dumping left-side **Spanish** into the `eu` stream — spurious
   `eu` on monolingual docs (Álava 2, Estatuto 7, Andalucía 2, Bizkaia 1, …).

### The fix (general — no per-document special-casing)

- **Change 1 — two-column needs positive evidence of a real two-column prose
  body, AND a language check before tagging `eu`.** A page is two-column only when
  there are two columns that are **tall** (each spans ≥ 50 % of page height),
  **vertically overlapping** (≥ 40 % of page height), **balanced** in text volume
  (weaker side ≥ 55 % of the stronger), with a **clear central gutter** (straddling
  text ≤ 15 % of column text), **and not a row-aligned short-cell table**. The
  table guard is row-pairing **plus short cells** (> 50 % of cells < 30 chars) —
  critical so it rejects `GRUPO/CATEGORÍA` and salary tables but **not** the
  Gipuzkoa parallel-bilingual page, whose rows are translation-aligned but whose
  cells are long prose. Weak evidence → **single column** (one Spanish stream).
  - **Language gate (the second face of the spurious-`eu` bug).** A *monolingual*
    two-column Spanish layout (e.g. Valencia, newspaper-style) is **geometrically
    identical** to a bilingual page, so geometry alone cannot stop it tagging
    left-column Spanish as `eu`. So when a page is two-column we measure each
    column's **Spanish-function-word ratio** (`de/la/el/que/en/…` — words Basque
    essentially never uses: Spanish prose ≈ 0.12–0.20, Basque ≈ 0). It is split
    `eu`/`es` **only** when one column reads as Basque (< 0.05) and the other as
    Spanish (> 0.07); otherwise both columns stay in the **Spanish** stream
    (ordered left-column-then-right so reading order is preserved, never
    interleaved, never blended). This is a layout/extraction aid, **not** a
    retrieval filter (ADR-0006 still holds — language is never stored or filtered).
    Result: `eu` now appears **only** on the genuinely bilingual Gipuzkoa BOG docs.
- **Change 2 — furniture = repetition, not width.** A block is furniture only when
  it **repeats** in a top/bottom margin band across ≥ `chunk_repeat_furniture_min_page_fraction`
  of pages, or matches a narrow gazette-boilerplate regex in that band. The bare
  full-width rule is gone; `chunk_furniture_width_ratio` is demoted to a soft
  reference (documented in `config.py`). A non-repeating full-width body paragraph
  is **kept**.
- **Change 3 — over-strip is self-reporting.** `extract_language_streams` now
  reports `blocks_total` and `column_blocks_kept`; `chunks:embed` flags a doc when
  **stripped > kept** or **chunk yield < 1 per 3 pages** on a non-scanned doc
  (text present), printing a **`⚠ SUSPICIOUS DOCUMENTS`** block and a
  `Log::warning` — audit-first, the same way `salary:import` surfaces coverage
  gaps, so this can never silently recur.

### Regression guard (verified BEFORE re-embedding — via `scripts/regress_chunks.py`, no DB writes)

The genuinely bilingual Gipuzkoa case is **preserved and improved**, and the
clean monolingual docs are **unchanged or improved (fewer spurious `eu`, more body
kept)** — no body text newly lost:

| doc | file | before (es/eu) | after (es/eu) | verdict |
|----|------|----------------|---------------|---------|
| 46 | Limpieza **Gipuzkoa** (bilingual) | 155 (75/80) | **158 (80/78)** | two-column split preserved; bilingual footer still stripped; `eu` is **genuine Basque** (`AUTONOMIA ERKIDEGOKO ADMINISTRAZIOA`, `…artikulua`, `hitzarmen`); `not_cleanly_split` now only pp.1,35 (masthead, salary grid) |
| 41 | Alojamientos **Gipuzkoa** (bilingual) | ~148 (75/73) | **146 (81/65)** | two-column preserved; genuine Basque `eu` |
| 73 | Estatuto (julio 2025) | 240 (233/7) | **273 (273/0)** | spurious `eu`→0; +33 chunks of recovered body |
| 29 | Andalucía COEAS | 148 (146/2) | **147 (147/0)** | spurious `eu`→0 |
| 18 | Bizkaia Intervención Social | 98 (97/1) | **98 (98/0)** | spurious `eu`→0 |
| 20 | Oficinas Valencia 2024–26 | 138 (135/3) | **137 (137/0)** | monolingual two-column Spanish; `eu`→0 via language gate |
| 21 | Oficinas Valencia 2021–23 | 123 (121/2) | **123 (123/0)** | `eu`→0 via language gate |

> Two iterations were needed, each caught by re-running `regress_chunks.py` before
> any re-embed:
> 1. The row-aligned-table guard initially fired on Gipuzkoa’s parallel-bilingual
>    pages (translation rows ARE row-aligned), collapsing them to single-column
>    `eu=0`. Fixed by adding the **short-cell** condition (tables have short cells;
>    parallel prose does not).
> 2. Valencia still emitted `eu` (it is a genuine two-column layout — but
>    *monolingual Spanish*). Fixed by the **language gate**: geometry says
>    two-column, but the Spanish-function-word ratio says both columns are Spanish,
>    so no `eu`. Gipuzkoa (Basque left, Spanish right) is unaffected.

### Re-embed result (affected docs)

Because Change 2 is **corpus-wide** (every doc that previously lost a full-width
body block or emitted spurious `eu` is affected — the regression showed even the
“clean” docs change), **all 31 in-scope docs were re-embedded** (`chunks:embed`,
idempotent per-doc replace), not just the Navarra/doc-45 subset. The fix targets:

| doc | file | before chunks (es/eu) | after chunks (es/eu) |
|----|------|-----------------------|----------------------|
| 45 | Intervención Social **Gipuzkoa** | 18 (2/16) | **91 (91/0)** |
| 95 | Limpieza **Navarra** 2024–2027 | 17 (8/9) | **87 (87/0)** |
| 96 | Intervención Social **Navarra** 2025 | 11 (—) | **128 (128/0)** |
| 2  | Álava OCIO EDUCATIVO | 89 (87/2) | **89 (89/0)** |

doc 45 confirmed by raw-text eyeball: modern single-column **Spanish** prose
(`CAPÍTULO I … Artículo 1. Ámbito funcional …`), no Basque — so single-column
`es=91 / eu=0` is correct (no language blending), and the old 18-chunk result was
the full-width rule eating its body. The whole Navarra cluster recovered:

| doc | file | before chunks | after chunks |
|----|------|---------------|--------------|
| 95 | Limpieza Navarra 2024–2027 | 17 | **87** |
| 96 | Intervención Social Navarra 2025 | 11 | **128** |
| 81 | Intervención Social Navarra 2021–2024 | (low) | **114** |
| 99 | COEAS Navarra 2023–2026 | (low) | **32** |

Navarra/doc-45 `not_cleanly_split` now fires only on genuine non-prose (e.g.
Limpieza Navarra p46 = salary table); spurious `eu` is gone corpus-wide.

**Full-corpus re-embed (`chunks:embed`, all 31 in-scope docs):**
- **3509 chunks written, 0 failed** (vs **2777** before — **+732** chunks of body
  text that the bare full-width rule had been silently discarding).
- DB confirms 3509 rows in `document_chunks`; per-doc counts match the run log.
- **`eu` now appears on only the four genuinely bilingual Gipuzkoa BOG docs** —
  Limpieza (eu=78), Alojamientos 2020–24 (eu=65) & 2025–28 (eu=74), Gestores
  información (eu=69). **Every other document is `eu=0`.** The bilingual footer is
  still stripped on all of them (catch-1 win intact).
- **Over-strip sanity flag (Change 3) fired on 1 doc:** `[29] COEAS Andalucía`
  (`322 stripped vs 277 kept`). **Reviewed → benign:** BOJA is a verbose gazette
  with many short *repeating* header/footer lines per page (masthead, depósito
  legal, número, the per-page CVE code), so furniture count legitimately exceeds
  the dense body-block count; the doc still yields **147** healthy clean-Spanish
  chunks (2.3/page, well above the 1-per-3-pages floor) with no body loss. This is
  exactly the intended behaviour — the flag **surfaces** a doc for a human glance
  rather than auto-failing it; the audit caught a true “stripped > kept” case and
  the human confirmed it is fine. No doc tripped the under-chunking floor.

### Residual risk (language gate)

The language gate splits `eu`/`es` **only when one column reads as Basque** (low
Spanish-function-word ratio). The known edge: a future bilingual convenio whose
Basque column doesn't read "Basque enough" (e.g. heavily code-/number-laden, very
short, or Spanish-loanword-dense) could fall back to a **single stream** instead
of splitting. **No text is lost either way** (the fallback keeps every block) —
but the risk is **blend, not loss**: read as a single column, that page's Basque
and Spanish would be interleaved into the same stream and could land in a
**blended Basque/Spanish chunk**, which ADR-0013 forbids. **All four current
bilingual Gipuzkoa docs split correctly**, so there is no issue today. **Trigger
to remember** (this is exactly what it guards against): whenever a new bilingual
convenio is ingested, re-run `hr-ai/scripts/regress_chunks.py <id>:<storage_key>`
and check its `eu` count is non-zero — if a known-bilingual doc comes back
`eu=0`, the gate's ratio thresholds need a look before trusting its chunks.

### Dev-env note

A `docker stop/start` of `hr_ai` during this correction broke Docker Desktop’s
`host.docker.internal` routing for this session (it resolves to `192.168.65.2` but
TCP times out); the bridge gateway `172.17.0.1` reaches the same host-published
MinIO/Postgres ports and was used as a workaround (`hr-ai/.env` DSN + an in-container
`/etc/hosts` remap). This is environment-only — no app/code dependency on it; a full
Docker Desktop restart restores `host.docker.internal`.
