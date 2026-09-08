# Corpus coverage ledger

**Query date/time:** 2026-09-08 19:17 UTC, live against `hr-staging`'s DB + S3 (source of truth — not `deploy.md`). *(Previous run: 2026-09-08 02:03 UTC.)*
**Commit SHAs staging is running at query time:** `hr-backend` `6bb58e4`, `hr-ai` `3b614af`, `hr-frontend` `c082163`, `hr-docs` `3b5aba6` (all on `main` — Sprint 7e is merged; the OCR fallback and the `salary:pdf-to-xlsx` follow-up are both live).
**Corpus size at query time:** 104 documents (101 ingested + **3 machine-derived `.xlsx`**, ids 102–104), 26 registry convenios, 4 employees seeded, 3,534 chunks in `document_chunks`, 12 `salary_tables` (10 `xlsx_native` + **2 `ocr_pdf`**) holding 119 `salary_table_rows` across 63 `convenio_job_categories`, 0 rows in `reference_facts`.

**What changed since the 02:03 run:** the salary-PDF path opened. `salary:pdf-to-xlsx` (Sprint 7e follow-up) OCR'd the table pages of documents 12 and 27 (Gestores Información Gipuzkoa, convenio 15), wrote one reviewed, human-approved derived `.xlsx` each (documents 102/103), and the unmodified `salary:import` then imported them: **+2 `salary_tables` (`source = ocr_pdf`), +10 rows, +5 job categories**, moving convenio 15 from "prose-only" to salary-answerable (verified end-to-end through the chat: `floor_decision.path = salary_sql`). Document 57's derived `.xlsx` (document 104) exists but is deliberately **not** imported — its grid is year-columned, a structure the importer cannot read (see its row in §2). One employee was added (convenio 15 test profile, for that chat verification). Nothing else moved: chunk count, reference facts, and every other reason code are unchanged.

This ledger consolidates what was previously scattered across `deploy.md` §4b/§5, the sprint reviews, and ad-hoc staging queries. Maintain it from here forward (see "how to keep this current" at the end).

---

## Part 1 — the flows

1. **Prose** — PDF/text with a native text layer or OCR'd text → `/extract` → `document_pages` → (`tagging_status` ≠ `under_review` **and** `retrieval_status` ∈ {active, historical} **and** `document_type` ∈ {convenio_text, national_law, partial_agreement, internal_hr_ruling}) → `chunks:embed` → `/embed` (hr-ai) → `document_chunks` → answerable via the prose path **only when the chunk's `retrieval_status` is additionally `active`** — `chunks:embed`'s selection is broader (active + historical) than what a live employee query can ever retrieve (active only); historical chunks exist in the table for provenance/semantic-fence comparison (ADR-0024) but are permanently inert to employees. **Live on `main` and on `sprint-7e`.**
2. **Salary** — `.xlsx` (only `.xlsx` — a PDF typed `salary_tables` is parsed for pages/OCR but `salary:import` cannot read it) tagged `salary_tables` + assigned convenio → `salary:import` → `salary_tables` / `salary_table_rows` / `convenio_job_categories` → answerable via `SalaryAnswerService`. **Live on `main`.**
2b. **Salary via OCR'd PDF** (Sprint 7e follow-up, `salary:pdf-to-xlsx`) — PDF typed `salary_tables` → OCR its table pages (Claude vision, the pinned table contract) → each page's sidecar `table_rows` written verbatim into a **derived** `.xlsx` (`documents/{uuid}/derived/salary.xlsx`, one sheet per grid page, named `<year> (page N)`) as a NEW `documents` row linked by `derived_from_document_id` → **human reviews the sheet against the page image** → `--apply-header-mapping` (an explicitly approved header-row rewrite, needed because `salary.py` matches header cells exactly and gazette headers carry units) → `--verify` (read-only parse) → the **unmodified** `salary:import` on the derived document → `--mark-provenance` stamps `salary_tables.source = 'ocr_pdf'` and restores each mapped column's original header text verbatim into `raw_values`. **Live on `main`.** Four human gates, no cell is ever guessed or normalized, and `salary:import`/`salary.py` are untouched. First use: documents 12/27 → convenio 15.
3. **Reference facts** — `.docx`/`.xlsx` tagged `reference_source` → `/read-structured` → `/segment-facts` → `reference_facts` (`needs_review`) → human verify → answerable via `ReferenceFactAnswerService` (7c), group-scoped facts escalate until 7f. **Built (7b/7c), but zero rows exist in this corpus** — no reference-source file has been run through this pipeline yet.
4. **HR ruling** — escalation resolved → publish (fence) → generated PDF → re-enters the Prose flow as `internal_hr_ruling`. **Live on `main`**; 3 rulings (docs 99–101, all convenio 18) currently sit `retrieval_status = draft` — resolved and tagged `verified`, but not yet published/fenced, so not yet in the Prose flow.
5. **OCR** (7e, **merged to `main`**) — text-less PDF page → `ocr_pending` → queued `OcrPage` job → hr-ai `/ocr-page` (Claude vision) → S3 sidecar + `document_pages.text` written back → page re-enters flow 1 at `/extract`'s output, `extraction_source = ocr`, document held `under_review` until a human verifies → same `chunks:embed` gate as flow 1. 9 documents OCR'd on staging in the 7e backfill (see §2 rows 14, 31, 35, 50, 68, 69, 85 + 2 more historical below); one (doc 18) additionally bound + embedded + verified in this session's acceptance test.

---

## Part 2 — the ledger

Legend for **flow(s)**: `P`=Prose, `S`=Salary, `F`=Reference facts, `R`=HR ruling, `O`=OCR (7e). "rows produced" is chunks for Prose/OCR-into-Prose, salary rows for Salary, fact count for Facts.

| id | filename | type | convenio | territory/sector | retr. | tag. | validity | pages (text) | flow | rows | answerable? | reason | unblock |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Tablas Intervencion Social Navarra.xlsx | salary_tables | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | active | verified | — | 0(0) | S | 12 rows | **yes** | OK | — |
| 2 | Tabla Limpieza Navarra.xlsx | salary_tables | 22 LIMPIEZA EDIFICIOS Y LOCALES | Navarra | active | verified | — | 0(0) | S | 3 rows | **yes** | OK | — |
| 3 | Tablas COEAS Navarra.xlsx | salary_tables | 19 COEAS NAVARRA | Navarra | active | verified | — | 0(0) | S | 32 rows | **yes** | OK | — |
| 4 | Tabla Salarios Deporte Cantabria.xlsx | salary_tables | 6 DEPORTE CANTABRIA | Cantabria | active | verified | — | 0(0) | S | 6 rows | **yes** | OK | — |
| 5 | Tabla 2026.xlsx | salary_tables | — | — | active | under_review | — | 0(0) | — | 0 | no | `NO_CONVENIO_MATCH` | registry: identify convenio from content, tag, re-run `salary:import` |
| 6 | TABLAS SALARIALES COEAS Estatal.xlsx | salary_tables | — | — | active | under_review | — | 0(0) | — | 0 | no | `NO_CONVENIO_MATCH` | tag to convenio 11 (COEAS Estatal — already active-blocked itself, see below); tagging item only |
| 7 | TABLAS SALARIALES Deporte Estatal.xlsx | salary_tables | — | — | active | under_review | — | 0(0) | — | 0 | no | `NO_CONVENIO_MATCH` | tag to convenio 9 (Instalaciones Deportivas y Gimnasios) or confirm mismatch |
| 8 | TABLAS SALARIALES 2021 2022 COEAS.xlsx | salary_tables | — | — | historical | under_review | 2021–2022 | 0(0) | — | 0 | no | `HISTORICAL` (+ untagged) | none needed — expired period, correctly inert |
| 9 | Tabla Agencias de viajes_Oficina de Turismo Pamplona.xlsx | salary_tables | 10 AGENCIAS DE VIAJES | Estatal | active | verified | — | 0(0) | S | 5 rows | **yes** | OK | — |
| 10 | Tablas acuerdo parcial_Alhambra.xlsx | salary_tables | — | — | active | under_review | — | 0(0) | — | 0 | no | `NO_CONVENIO_MATCH` | pairs with doc 90 (partial agreement, also unbound); registry: identify convenio |
| 11 | TABLAS COEAS Andalucia.xlsx | salary_tables | — | — | active | under_review | — | 0(0) | — | 0 | no | `NO_CONVENIO_MATCH` | tag to convenio 4 (Ocio Educativo Andalucía) |
| 12 | 20104415012022_Gestores informacion Gipuzkoa_Tablas 2025.pdf | salary_tables | 15 INFORMACIÓN Y DOCUMENTACIÓN | Gipuzkoa | active | auto_proposed | — | 3(3, OCR) | O→S (via doc 102) | **5 salary rows** (year 2025) | **yes** | OK | — (was `SALARY_PDF_NOT_IMPORTED`; resolved via flow 2b — the PDF itself stays the human-viewable original, doc 102 is what the importer read) |
| 13 | 20000785011981_Limpieza Gipuzkoa_2019 2026.pdf | convenio_text | 13 LIMPIEZA EDIFICIOS Y LOCALES | Gipuzkoa | **active** | auto_proposed | 2019–2026 | 35(35) | P | **177 chunks** | **yes** | OK | — |
| 14 | Acuerdo fin de huelga UBIK - SEDENA SL 2019-2022.pdf | partial_agreement | — | — | active | under_review | — | 12(12, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill) | registry: identify the affected convenio/employer scope, tag, confirm |
| 15 | limpieza.pdf | convenio_text | — | — | historical | under_review | — | 28(28) | — | 0 | no | `HISTORICAL` + untagged | none needed unless a successor claim surfaces |
| 16 | Resolución_de_13_de_novi....pdf | convenio_text | — | — | historical | under_review | — | 23(23) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 17 | TEXTO-VIII-CONVENIO-COLECTIVO-NO-REGLADA-steilas.pdf | convenio_text | — | — | historical | under_review | — | 35(**2**) | — | 0 | no | `SCAN_NO_TEXT` (partial — 33/35 pages are scanned) | **not caught by the 7e backfill's `pages_with_text = 0` filter** (it has 2 pages with text) — needs a widened, per-page backfill selector or a manual `--ocr` re-ingest |
| 18 | ConvenioLimpiezaEdificiosLocalesGipuzkoa2019-2026.pdf | convenio_text | 13 LIMPIEZA EDIFICIOS Y LOCALES | Gipuzkoa | **historical** | **verified** | — | 36(36, OCR) | O→P | **170 chunks** (91 es / 79 eu) | no | `HISTORICAL` — **see note** | none — genuine duplicate, doc 13 already covers this convenio fully |
| 19 | 20100035012014_Alojamientos Gipuzkoa_Tablas 2023.pdf | salary_tables | 12 ALOJAMIENTOS | Gipuzkoa | historical | auto_proposed | — | 3(3) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` + `HISTORICAL` | superseded by doc 24 anyway |
| 20 | TABLAS SALARIALES HOSTELERIA.pdf | salary_tables | — | — | historical | under_review | — | 2(2) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 21 | HOSTELERIA 2008 2010.pdf | convenio_text | — | — | historical | under_review | 2008–2010 | 25(25) | — | 0 | no | `HISTORICAL` | none needed |
| 22 | CONVENIO COLECTIVO BIBLIOTECAS GUIPUZCOA 2022.pdf | convenio_text | — | — | historical | under_review | — | 29(29) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 23 | 20100035012014_Alojamientos Gipuzkoa_2020 2024.pdf | convenio_text | 12 ALOJAMIENTOS | Gipuzkoa | historical | auto_proposed | 2020–2024 | 37(37) | P (inert) | 146 chunks | no | `HISTORICAL`, successor exists | successor = doc 28 (active, 2025–2028) |
| 24 | 20100035012014_Alojamientos Gipuzkoa_Tablas 2024.pdf | salary_tables | 12 ALOJAMIENTOS | Gipuzkoa | historical | auto_proposed | — | 5(5) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` + `HISTORICAL` | superseded period anyway |
| 25 | 20100025012011_Intervencion Social Gipuzkoa_2023 2025.pdf | convenio_text | 14 INTERVENCION SOCIAL GIPUZKOA | Gipuzkoa | historical | auto_proposed | 2023–2025 (**expired**) | 50(50) | P (inert) | 95 chunks | no | `EXPIRED_NO_SUCCESSOR` | **active-scope hole** — no successor loaded for convenio 14 |
| 26 | 20104415012022_Gestores informacion Gipuzkoa_2024 2026.pdf | convenio_text | 15 INFORMACIÓN Y DOCUMENTACIÓN | Gipuzkoa | **active** | auto_proposed | 2024–2026 | 33(33) | P | **160 chunks** | **yes** | OK | — |
| 27 | 20104415012022_Gestores informacion Gipuzkoa_Tablas 2026.pdf | salary_tables | 15 INFORMACIÓN Y DOCUMENTACIÓN | Gipuzkoa | active | auto_proposed | — | 3(3, OCR) | O→S (via doc 103) | **5 salary rows** (year 2026) | **yes** | OK | — (was `SALARY_PDF_NOT_IMPORTED`; resolved via flow 2b. This is the year an employee asking today gets — `year_selection = exact` for as-of 2026) |
| 28 | 20100035012014_Alojamientos Gipuzkoa_2025 2028.pdf | convenio_text | 12 ALOJAMIENTOS | Gipuzkoa | **active** | auto_proposed | 2025–2028 | 42(42) | P | **157 chunks** | **yes** | OK | — |
| 29 | 22000175012004_HOSTELERIA HUESCA_2022 2024.pdf | convenio_text | 16 HOSTELERIA Y TURISMO HUESCA | Huesca | historical | under_review | 2022–2024 (expired) | 32(32) | — | 0 | no | `UNDER_REVIEW_SCOPE` + expired | verify tags (moot — dates already expired; only matters if a successor arrives) |
| 30 | 31005105011984_OFICINAS Y DESPACHOS NAVARRA_2019 2025.pdf | convenio_text | 23 OFICINAS Y DESPACHOS | Navarra | historical | auto_proposed | 2019–2025 (**just expired**) | 24(24) | P (inert) | 53 chunks | no | `EXPIRED_NO_SUCCESSOR` — **see note** | see doc 33 note below (mistag, doesn't really fill the gap) |
| 31 | PACTO CULTURA NAVARRA.pdf | convenio_text | — | — | active | under_review | — | 2(2, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill, Pedram-verified text) | registry: no "Cultura Navarra" convenio exists yet — see Part 3 |
| 32 | 31004605011982_Limpieza Navarra_2024 2027_Tablas 2025.pdf | salary_tables | 22 LIMPIEZA EDIFICIOS Y LOCALES | Navarra | active | auto_proposed | 2024–2027 | 5(5) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` | same convenio's salary already OK via doc 2 (xlsx) |
| 33 | 31005105011984_OFICINAS Y DESPACHOS NAVARRA_Tabla 2025.pdf | convenio_text | 23 OFICINAS Y DESPACHOS | Navarra | **active** | auto_proposed | — | 3(3) | P | 3 chunks | **partial** | `MISTAG` (suspected) | filename says "Tabla" (a rate table) but typed `convenio_text`, not `salary_tables` — 3 chunks is too thin to be full convenio prose; likely the same mistag pattern the prompt's own reason-code list calls out. Retype + retag if content-check confirms |
| 34 | 31102195012024_COEAS Navarra_2023 2026.pdf | convenio_text | 19 COEAS NAVARRA | Navarra | **active** | auto_proposed | 2023–2026 | 16(16) | P | **30 chunks** | **yes** | OK | — |
| 35 | 31004605011982_Limpieza Navarra_2024 2027_Tablas 2026.pdf | salary_tables | 22 LIMPIEZA EDIFICIOS Y LOCALES | Navarra | active | auto_proposed | 2024–2027 | 1(1, OCR) | O | 0 | no | `SALARY_PDF_NOT_IMPORTED` (+ OCR'd) | **"Limpieza Navarra Tablas 2026" — checked this session: confirmed a 1-page salary-table PDF, correctly excluded from `chunks:embed`'s scope by `document_type`; not embeddable as prose, and not `.xlsx` so `salary:import` can't read it either** |
| 36 | 31004605011982_Limpieza Navarra_2024 2027.pdf | convenio_text | 22 LIMPIEZA EDIFICIOS Y LOCALES | Navarra | **active** | auto_proposed | 2024–2027 | 53(53) | P | **75 chunks** | **yes** | OK | — |
| 37 | HOSTELERIA 20137 2014.pdf | convenio_text | — | — | historical | under_review | — | 21(21) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 38 | oficinas-despachos-navarra2017.pdf | convenio_text | — | — | historical | under_review | — | 25(25) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 39 | GESTIÓN DEPORTIVA 2012.pdf | convenio_text | — | — | historical | under_review | — | 13(13) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 40 | 31008235012003_DEPORTE NAVARRA_2016 2020.pdf | convenio_text | 20 GESTIÓN DEPORTIVA NAVARRA | Navarra | historical | under_review | 2016–2020 (long expired) | 27(27) | — | 0 | no | `EXPIRED_NO_SUCCESSOR` | **active-scope hole** — see doc 50 below, the likely successor |
| 41 | 31101815012021_Intervención Social de Navarra_2021 2024.pdf | convenio_text | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | historical | auto_proposed | 2021–2024 (expired) | 56(56) | P (inert) | 94 chunks | no | `HISTORICAL`, successor exists | successor = doc 52 (active) |
| 42 | OFICINAS Y DESPACHOS.pdf | convenio_text | — | — | historical | under_review | — | 15(15) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 43 | 31101815012021_Intervención Social de Navarra_Tablas Definitivas 2024.pdf | salary_tables | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | historical | auto_proposed | — | 3(3) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` + `HISTORICAL` | convenio 18's salary already OK via doc 1 (xlsx) |
| 44 | convenio hosteleria navarra 2019.pdf | convenio_text | — | — | historical | under_review | — | 29(29) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 45 | Convenio Hosteleria 2015 - 2017.pdf | convenio_text | — | — | historical | under_review | — | 39(39) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 46 | TABLAS OFICINAS Y DESPACHOS.pdf | salary_tables | — | — | historical | under_review | — | 1(1) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 47 | Pacto de la empresa.pdf | convenio_text | — | — | historical | under_review | — | 2(2, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill) | registry: identify convenio |
| 48 | DEPORTE NAVARRA 2013-2015 (S).pdf | convenio_text | — | — | historical | under_review | — | 28(28) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 49 | oficinas-despachos-navarra revisió salarial.pdf | convenio_text | — | — | historical | under_review | — | 6(6) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 50 | CONVENIO DEPORTE NAVARRA 2025 A 2028.pdf | convenio_text | — | — | active | under_review | — | 27(27, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill, Pedram-verified text) | **strong content match to convenio 20** (title cites "gestoras de servicios y equipamientos deportivos... de Navarra"), but registry name mismatch ("Gestión Deportiva" vs. the doc's fuller entity description) held it back from auto-match; human registry review needed |
| 51 | 31003805011981_Hosteleria Navarra_2022 2025.pdf | convenio_text | 21 HOSTELERIA NAVARRA | Navarra | historical | auto_proposed | 2022–2025 (expired) | 30(30) | P (inert) | 52 chunks | no | `EXPIRED_NO_SUCCESSOR` | active-scope hole for convenio 21 |
| 52 | 31101815012021_Intervención Social de Navarra_2025.pdf | convenio_text | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | **active** | auto_proposed | — | 74(74) | P | **171 chunks** | **yes** | OK | — |
| 53 | 39100935012024_DEPORTE CANTABRIA_2024-2028.pdf | convenio_text | 6 DEPORTE CANTABRIA | Cantabria | **active** | auto_proposed | — | 50(50) | P | **81 chunks** | **yes** | OK | — |
| 54 | Resumen.pdf | summary | — | — | active | under_review | — | 3(3) | — | 0 | no | *(not in enum — see note)* | `document_type = summary` is not in `chunks:embed`'s `IN_SCOPE_TYPES`; no flow currently ingests a standalone summary doc type at all, independent of tagging |
| 55 | Plan_Igualdad_texto.pdf | convenio_text | — | — | active | under_review | — | 78(78) | — | 0 | no | `UNDER_REVIEW_SCOPE` | verify tags; likely needs a convenio/scope decision (an equality plan may be cross-convenio) |
| 56 | 01100635012017 OCIO EDUCATIVO ALAVA 2023-2026.pdf | convenio_text | 3 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | Álava | **active** | auto_proposed | — | 43(43) | P | **134 chunks** | **yes** | OK | — |
| 57 | 01100635012017 OCIO EDUCATIVO ALAVA 2023-2026_Tablas 2026.pdf | salary_tables | 3 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | Álava | active | auto_proposed | — | 3(3, OCR) | O (derived doc 104 written, **NOT imported**) | 0 | no | `SALARY_PDF_NOT_IMPORTED` — **structural, not a mapping problem** | flow 2b OCR'd it correctly, but its grid is `CATEGORÍA \| 2025 \| 2026` (one column per YEAR, values like `36.343,74 euros`), not one column per salary concept. No header mapping can express that: `salary.py` would read `2025`/`2026` as the "annual hours" hourly-rate marker (`_looks_like_hours_header`, `\d{3,4}`), and every cell fails `_is_number` because of the `euros` suffix — importing as-is is a silent 0-row no-op. Needs an approved per-year sheet split + unit-suffix decision (human, then sprint-8) |
| 58 | 01003205012006_ACT DEPORTIVAS ALAVA_2024 2026.pdf | convenio_text | 2 ACTIVIDADES DEPORTIVAS | Álava | **active** | auto_proposed | 2024–2026 | 38(38) | P | **132 chunks** | **yes** | OK | — |
| 59 | 01100635012017 OCIO EDUCATIVO ALAVA 2023-2026_Tablas 23-25.pdf | salary_tables | 3 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | Álava | active | auto_proposed | — | 3(3) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` | **deliberately skipped** by the flow-2b run: doc 57 is the current (2026) table for the same convenio, so this 23–25 table is stale in substance while still flagged `retrieval_status = active` — converting it would create a competing table for the same convenio/years. Fix the status, don't convert |
| 60 | Subida salarial según convenio 2023.pdf | convenio_text | — | — | historical | under_review | — | 2(2) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 61 | INTERVENCIÓN SOCIAL ALAVA.pdf | convenio_text | — | — | historical | under_review | — | 20(20) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 62 | 2022_101_02474_C.pdf | convenio_text | — | — | historical | under_review | — | 33(33) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 63 | 01100635012017_OCIO EDUCATIVO ALAVA_2019 2022.pdf | convenio_text | 3 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | Álava | historical | auto_proposed | 2019–2022 (expired) | 39(39) | P (inert) | 78 chunks | no | `HISTORICAL`, successor exists | successor = doc 56 |
| 64 | 01100635012017 OCIO EDUCATIVO ALAVA 2023-2026 Cambios.pdf | changes | 3 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | Álava | active | auto_proposed | — | 2(2) | — | 0 | no | *(not in enum — see note)* | `document_type = changes` is not in `IN_SCOPE_TYPES` either; an amendment/errata type has no flow of its own today — either fold into the base convenio_text or add a flow |
| 65 | 48006185012006_Intervencion Social Bizkaia_2022 2025.pdf | convenio_text | 26 INTERVENCION SOCIAL | Vizcaya | historical | auto_proposed | 2022–2025 (expired) | 41(41) | P (inert) | 90 chunks | no | `EXPIRED_NO_SUCCESSOR` — **see note** | duplicate pair with doc 67 (same convenio, same dates); neither is active; active-scope hole for convenio 26 |
| 66 | LOCALES Y CAMPOS DEPORTIVOS DE BIZKAIA.pdf | convenio_text | — | — | historical | under_review | — | 16(16) | — | 0 | no | `NO_CONVENIO_MATCH` (+ historical) | registry: no "Locales y Campos Deportivos" **Vizcaya** convenio row exists to bind to (see convenio 27, which has zero docs) |
| 67 | 48006185012006_Intervencion Social Bizkaia_2022 2025_.pdf | convenio_text | 26 INTERVENCION SOCIAL | Vizcaya | historical | auto_proposed | 2022–2025 (expired) | 42(42) | P (inert) | 51 chunks | no | `EXPIRED_NO_SUCCESSOR` — duplicate of doc 65 | same as doc 65 — a duplicate ingest, not two different periods |
| 68 | CONDICIONES LABORALES DEL PERSONAL SUBROGADO BARAKALDO.pdf | convenio_text | — | — | historical | under_review | — | 1(1, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill) | registry: identify convenio |
| 69 | PACTO.pdf | convenio_text | — | — | historical | under_review | — | 7(7, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, 7e backfill) | registry: identify convenio |
| 70 | ACCIÓN E INTERVENCIÓN SOCIAL VIZCAYA.pdf | convenio_text | — | — | historical | under_review | — | 22(22) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 71 | LOCALES Y CAMPOS DEPORTIVOS.pdf | convenio_text | — | — | historical | under_review | — | 23(23) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 72 | V Convenio Intervención social 2017-2021.pdf | convenio_text | — | — | historical | under_review | — | 33(33) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 73 | 99015105012005_Deporte Estatal_2023 2025.pdf | convenio_text | 9 INSTALACIONES DEPORTIVAS Y GIMNASIO | Estatal | historical | under_review | 2023–2025 (expired) | 43(43) | — | 0 | no | `EXPIRED_NO_SUCCESSOR` + `UNDER_REVIEW_SCOPE` | active-scope hole for convenio 9; see doc 85 (its amendment, also unbound) |
| 74 | 99008825011994_Enseñanza no reglada Estatal_2024 2027.pdf | convenio_text | 8 ENSEÑANZA Y FORMACION NO REGLADA | Estatal | **active** | auto_proposed | 2024–2027 | 39(39) | P | **74 chunks** | **yes** | OK | — |
| 75 | ESTATUTO TRABAJADORES_julio2025.pdf | national_law | — | — | **active** | auto_proposed | — | 232(232) | P | **235 chunks** | **yes** | OK (national_law, cross-scope) | — |
| 76 | 99100055012011_COEAS Estatal_2025 2027.pdf | convenio_text | 11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (COEAS Estatal) | Estatal | active | under_review | 2025–2027 | 74(74) | — | 0 | no | `UNDER_REVIEW_SCOPE` | **"COEAS Estatal" — confirmed this session: correctly excluded from the OCR/scans list (it has a native text layer, not a scan); this is a tagging-only item, unrelated to 7e** |
| 77 | 99000155011981_Agencias de viajes_2025.pdf | convenio_text | 10 AGENCIAS DE VIAJES | Estatal | **active** | auto_proposed | — | 74(74) | P | **115 chunks** | **yes** | OK | — |
| 78 | deporte estatal 2023.pdf | convenio_text | — | — | historical | under_review | — | 17(17) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 79 | 99008825011994_Enseñanza no reglada Estatal_2020 2023.pdf | convenio_text | 8 ENSEÑANZA Y FORMACION NO REGLADA | Estatal | historical | auto_proposed | 2020–2023 (expired) | 26(26) | P (inert) | 57 chunks | no | `HISTORICAL`, successor exists | successor = doc 74 |
| 80 | ESTATUTO TRABAJADORES.pdf | national_law | — | — | historical | auto_proposed | — | 87(87) | P (inert) | 298 chunks | no | `HISTORICAL`, successor exists | successor = doc 75 |
| 81 | ESTATUTO TRABAJADORES_2024.pdf | national_law | — | — | historical | auto_proposed | — | 228(228) | P (inert) | 234 chunks | no | `HISTORICAL`, successor exists | successor = doc 75 |
| 82 | acción e intervención.pdf | convenio_text | — | — | historical | under_review | — | 58(58) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 83 | II_CC_OCIO_EDUCATIVO...FIRMADO.pdf | convenio_text | — | — | historical | under_review | — | 79(79) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 84 | 99000155011981_Agencias de viajes_2024.pdf | convenio_text | 10 AGENCIAS DE VIAJES | Estatal | historical | auto_proposed | — | 71(71) | P (inert) | 112 chunks | no | `HISTORICAL`, successor exists | successor = doc 77 |
| 85 | CONVENIO DEPORTE ESTATAL.pdf | convenio_text | — | — | historical | under_review | — | 17(17, OCR) | O→ | 0 | no | `NO_CONVENIO_MATCH` (OCR'd, checked this session) | **content is "Acta de modificación parcial del IV Convenio colectivo estatal de instalaciones deportivas y gimnasios" — binds cleanly to convenio 9 by name** (not chosen this session; doc 18 was picked instead per instruction). Ready to bind whenever convenio 9's gap (doc 73, expired) is prioritized |
| 86 | 99008825011994_Enseñanza no reglada Estatal_Tablas 2023.pdf | salary_tables | 8 ENSEÑANZA Y FORMACION NO REGLADA | Estatal | historical | auto_proposed | — | 3(3) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` + `HISTORICAL` | superseded period anyway |
| 87 | 99100055012011_COEAS Estatal_2020 2023.pdf | convenio_text | 11 OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL (COEAS Estatal) | Estatal | historical | under_review | 2020–2023 (expired) | 62(62) | — | 0 | no | `HISTORICAL` + `UNDER_REVIEW_SCOPE` | superseded by doc 76 anyway (once doc 76's tags are verified) |
| 88 | Tablas salariales Accion e intervencion Social.pdf | salary_tables | — | — | historical | under_review | — | 7(7) | — | 0 | no | `NO_CONVENIO_MATCH` (+ `SALARY_PDF_NOT_IMPORTED`) | convenio 18's salary is already covered via doc 1; low priority |
| 89 | 71103505012022_COEAS Andalucía_2022 2025.pdf | convenio_text | 4 OCIO EDUCATIVO Y ANIMACION ANDALUCIA | Andalucía | historical | auto_proposed | 2022–2025 (expired) | 65(65) | P (inert) | 151 chunks | no | `EXPIRED_NO_SUCCESSOR` | active-scope hole for convenio 4 |
| 90 | ACUERDO PARCIAL COEAS ANDALUCIA_Alhambra.pdf | partial_agreement | — | — | active | under_review | — | 11(11) | — | 0 | no | `NO_CONVENIO_MATCH` | pairs with doc 10 (salary xlsx, also unbound); registry: convenio 4 |
| 91 | 37000375011982_OFICINAS Y DESPACHOS SALAMANCA_2023 2025.pdf | convenio_text | 24 OFICINAS Y DESPACHOS SALAMANCA | Salamanca | historical | auto_proposed | 2023–2025 (expired) | 23(23) | P (inert) | 57 chunks | no | `EXPIRED_NO_SUCCESSOR` | active-scope hole for convenio 24 |
| 92 | 46000805011981_Oficinas y despachos Valencia_2024 2026.pdf | convenio_text | 25 OFICINAS Y DESPACHOS VALENCIA | Valencia | **active** | auto_proposed | 2024–2026 | 86(86) | P | **117 chunks** | **yes** | OK | — |
| 93 | 46000805011981_Oficinas y despachos Valencia_2021 2023.pdf | convenio_text | 25 OFICINAS Y DESPACHOS VALENCIA | Valencia | historical | auto_proposed | 2021–2023 (expired) | 25(25) | P (inert) | 92 chunks | no | `HISTORICAL`, successor exists | successor = doc 92 |
| 94 | 33000325011978_DEPORTES ASTURIAS_TABLAS 2013.pdf | salary_tables | 5 DEPORTE ASTURIAS | Asturias | active | auto_proposed | — | 2(2) | — | 0 | no | `SALARY_PDF_NOT_IMPORTED` | **deliberately skipped** by the flow-2b run and logged: convenio 5's only prose (doc 95) expired in 2012 and has **no successor loaded**, so importing a 2013 table would give employees 13-year-old pay figures with no current convenio text behind them. Registry/successor decision first (`EXPIRED_NO_SUCCESSOR`, see §3) |
| 95 | 33000325011978_DEPORTES ASTURIAS_2009 2012.pdf | convenio_text | 5 DEPORTE ASTURIAS | Asturias | historical | auto_proposed | 2009–2012 (long expired) | 14(14) | P (inert) | 43 chunks | no | `EXPIRED_NO_SUCCESSOR` | active-scope hole for convenio 5 — nothing newer than 2012 loaded |
| 96 | 28102145012018_COEAS Madrid_2023 2027.pdf | convenio_text | 17 OCIO EDUCATIVO Y ANIMACIÓN MADRID | Madrid | active | under_review | 2023–2027 | 49(49) | — | 0 | no | `UNDER_REVIEW_SCOPE` | verify tags |
| 97 | tablas salariales ocio educativo 21 23.pdf | salary_tables | — | — | historical | under_review | — | 5(5) | — | 0 | no | `NO_CONVENIO_MATCH` (+ `SALARY_PDF_NOT_IMPORTED`) | superseded period anyway |
| 98 | I Convenio Ocio Educativo y Animación... Madrid.pdf | convenio_text | — | — | historical | under_review | — | 47(47) | — | 0 | no | `HISTORICAL` + untagged | none needed |
| 99 | *(generated ruling PDF)* | internal_hr_ruling | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | **draft** | verified | since 2026-09-06 | 0(0) | R (pending) | 0 | no | *(pre-publish — see note)* | fence + `RulingPublisher` publish step not yet run for this resolution |
| 100 | *(generated ruling PDF)* | internal_hr_ruling | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | **draft** | verified | since 2026-09-06 | 0(0) | R (pending) | 0 | no | *(pre-publish)* | same as doc 99 |
| 101 | *(generated ruling PDF)* | internal_hr_ruling | 18 ACCIÓN E INTERVENCIÓN SOCIAL | Navarra | **draft** | verified | since 2026-09-06 | 0(0) | R (pending) | 0 | no | *(pre-publish)* | same as doc 99 |
| 102 | …Gestores informacion Gipuzkoa_Tablas 2025.**derived.xlsx** | salary_tables | 15 INFORMACIÓN Y DOCUMENTACIÓN | Gipuzkoa | active | verified | — | 0(0) | **machine-derived** (from doc 12) → S | **5 salary rows** (2025, `source = ocr_pdf`) | **yes** | OK | — |
| 103 | …Gestores informacion Gipuzkoa_Tablas 2026.**derived.xlsx** | salary_tables | 15 INFORMACIÓN Y DOCUMENTACIÓN | Gipuzkoa | active | verified | — | 0(0) | **machine-derived** (from doc 27) → S | **5 salary rows** (2026, `source = ocr_pdf`) | **yes** | OK | — |
| 104 | …OCIO EDUCATIVO ALAVA 2023-2026_Tablas 2026.**derived.xlsx** | salary_tables | 3 OCIO EDUCATIVO Y ANIMACION SOCIOCUL | Álava | active | verified | — | 0(0) | **machine-derived** (from doc 57), **not imported** | 0 | no | *(awaiting the doc-57 structural decision)* | see doc 57's row — the file is written and reviewable, the import is what's blocked |

**On documents 102–104 (the derived `.xlsx` rows).** These are not corpus files; they are machine-written artefacts of flow 2b, present in `documents` only because `salary:import` reads a `documents` row. Each carries `derived_from_document_id` → the PDF a human actually reads, and `tagging_status = verified` reflects that a human approved the derived sheet, not an independent tagging decision. They are typed `salary_tables`, so they never enter the prose/embedding flow. Count the corpus as **101 files**; count these three as pipeline state.

**Reference facts sub-table:** empty — `reference_facts` has **0 rows** in this corpus. No `.docx`/`.xlsx` has been tagged `reference_source` and run through `/read-structured` → `/segment-facts` yet. This is the one knowledge type with literally nothing to report; flagging it here rather than omitting it, since "zero rows" is itself the finding.

---

## Part 3 — the dead-end summary

| reason code | files | pages | convenios affected | employees in scope (seeded) | unblocking action | owner |
|---|---|---|---|---|---|---|
| `HISTORICAL` (untagged, no active claim possible) | 30 | ~640 | none (never tagged) | 0 | accept-gap — these are superseded/duplicate/unrelated scans with no successor claim; correct to leave inert | — |
| `HISTORICAL`, successor exists (chunked, correctly inert) | 8 (23,41,63,80,81,84,93 + duplicate 67) | ~640 | 12,18,3,8(×2),10,25,26 | varies | none — working as designed | — |
| `EXPIRED_NO_SUCCESSOR` (active-scope hole) | 9 (25,30\*,40,51,65\*,67\*,73,89,91,95) | ~370 | 14,23,20,21,26,9,4,24,5 | 0 today | sprint-8 coverage-gap map (Part 4); several have an OCR'd, human-verified **candidate** already sitting in the corpus (doc 50→convenio 20, doc 85→convenio 9) needing only a registry/scope decision | registry + human-verify |
| `NO_CONVENIO_MATCH` (untagged prose/salary, incl. 7e OCR'd) | 17 (5,6,7,10,11,14,31,47,50,66,68,69,85,88,90,97 + doc 10/90 pair) | ~110 | 4,9,11,20 (candidates); rest unknown | 0 | registry: identify + tag; doc 31 needs a **new** "Cultura Navarra" convenio row (none exists — see below); doc 66/convenio 27 also has no docs at all | registry owner |
| `SALARY_PDF_NOT_IMPORTED` (PDF-format salary table) | **13** (19,20,24,32,35\*,43,46,57,59,86,88\*,94,97\*) — was 15; **12 and 27 are now resolved** via flow 2b | ~35 | 12,22(×2),18,3(×2),8,5, + 4 untagged (several already salary-OK via a separate `.xlsx`) | 0 | flow 2b (`salary:pdf-to-xlsx`) now converts these, but each needs its own human review pass; 4 of the 13 are additionally blocked for a *different* reason (57 structural, 59 stale-but-active, 94 no current convenio, 88/97/20/46 untagged) | human-review, per document |
| `MISTAG` (suspected — doc_type likely wrong) | 1 confirmed candidate (doc 33) | 3 | 23 | 0 | content-check + retype `convenio_text`→`salary_tables` if confirmed; this is the doc's whole reason it doesn't fill convenio 23's expired gap | human-verify |
| `UNDER_REVIEW_SCOPE` (has text, tags unverified) | 6 (29,55,76,87,96 + doc 14/document-id, not convenio-id) | ~230 | 11 (COEAS Estatal ×2), 17, 16, 9, none | 0 | human tag-verify via the admin Documents page | tagging queue |
| not in any current flow (`document_type` outside every `IN_SCOPE_TYPES`/importer) | 2 (54 `summary`, 64 `changes`) | 5 | 3 (doc 64) | 0 | either fold into the parent convenio_text on ingest, or add a dedicated flow — currently a true blind spot, not a review-gate | sprint-8 decision |
| pre-publish (`internal_hr_ruling`, drafted not fenced) | 3 (99,100,101) | 0 | 18 | 1 (in-scope) | run the fence/publish step (`RulingPublisher`) for this resolution | escalation owner |
| `GROUP_SCOPED_FACT` / `FACT_NEEDS_REVIEW` | 0 | 0 | — | — | n/a — 0 reference facts exist in this corpus at all | — |
| `OK` (answerable today) | **28 prose + 7 salary source files (5 native `.xlsx` + 2 OCR-derived) = 35**, plus the 2 source PDFs (12, 27) those derived files stand for | — | prose: 2,3,6,8,10,12,13,15,18,19,22,25 · salary: 6,10,15,18,19,22 | 4 | — | — |

\* appears in two reason-code rows because it's blocked two ways (e.g. doc 35 is both OCR'd *and* PDF-format-salary-not-imported).

**Data we have but can't use yet, with the one action that unlocks each group:**
- 8 `NO_CONVENIO_MATCH` prose/partial-agreement OCR'd docs (14, 31, 47, 68, 69, 85, plus 50 & the doc-90/doc-10 salary pair) — all have real, human-verified-quality text now (7e). Action: registry tagging pass. Two (50, 85) already have an identified likely target convenio (20, 9 respectively).
- 13 PDF-format salary tables — action: a `salary:pdf-to-xlsx` review pass each (flow 2b), which is now the supported route; no re-supply needed. The first two (docs 12/27) took one round of human review each. Three have a prior blocker to clear first: doc 57 (structure), doc 59 (`retrieval_status` says active but doc 57 supersedes it), doc 94 (no current convenio text behind it).
- 1 partial-scan doc (17, 2/35 pages have text) — action: the 7e backfill's `pages_with_text = 0` selector doesn't catch it; needs a per-page or `--force` variant.

**Data we chose not to use (historical, duplicates) and why that's correct:**
- 30 untagged historical scans with no successor claim, plus 8 chunked-but-superseded documents (23,41,63,80,81,84,93, and the 65/67 duplicate pair) — correctly inert; an active successor exists (or the period is simply over) and employees must never see stale terms.
- Doc 18 (this session's OCR target) — chunked and verified, but doc 13 (an existing native-text ingest of the identical convenio/date-range) already answers everything it would; doc 18 sits `historical` deliberately, not a bug.

**Data we can't use with current code, and which decision fixes it:**
- 1 PDF-format salary table whose *structure* the importer cannot represent: doc 57's year-columned grid (`CATEGORÍA | 2025 | 2026`). `salary.py`'s model is one column per pay concept, so a year-column table needs a per-year sheet split (and a decision on the `euros` suffix in every cell) before any import. Sprint-8 decision; flow 2b deliberately refuses to guess it.
- The remaining 12 PDF salary tables are no longer a *code* gap — they are a review-queue item (flow 2b).
- 2 documents (`summary`, `changes` doc types) — no flow reads them at all; needs a sprint-8 decision on whether they get their own flow or fold into `convenio_text`.
- 0 reference facts — the whole flow-3 pipeline is unexercised on this corpus; not a defect, just not yet run.

---

## Part 4 — the coverage picture by scope

| convenio | prose ✓/✗ | salary ✓/✗ | facts ✓/✗ | notes |
|---|---|---|---|---|
| 2 Actividades Deportivas (Álava) | ✓ (doc 58) | ✗ | ✗ (none) | — |
| 3 Ocio Educ. Y Anim. Sociocul. (Álava) | ✓ (doc 56) | ✗ (PDF only — doc 57 OCR'd + derived, import blocked on its year-column structure) | ✗ | doc 64 "Cambios" not embeddable (no flow) |
| 4 Ocio Educ. Y Anim. (Andalucía) | ✗ `EXPIRED_NO_SUCCESSOR` | ✗ (untagged) | ✗ | doc 89 expired; docs 10/11/90 unbound |
| 5 Deporte (Asturias) | ✗ `EXPIRED_NO_SUCCESSOR` (2012) | ✗ (PDF only, doc 94 — conversion deliberately withheld) | ✗ | **full gap**; converting doc 94's 2013 table would produce pay figures with no current convenio text behind them — the successor question comes first |
| 6 Deporte (Cantabria) | ✓ (doc 53) | ✓ (doc 4) | ✗ | fully covered |
| 7 Acción e Intervención Social Estatal | ✗ (no docs at all) | ✗ | ✗ | **full gap — zero documents reference this registry row** |
| 8 Enseñanza no Reglada (Estatal) | ✓ (doc 74) | ✗ (PDF only) | ✗ | — |
| 9 Instalaciones Deportivas y Gimnasio (Estatal) | ✗ `EXPIRED_NO_SUCCESSOR` | ✗ | ✗ | **full gap**; doc 85 (OCR'd amendment) ready to bind |
| 10 Agencias de Viajes (Estatal) | ✓ (doc 77) | ✓ (doc 9) | ✗ | fully covered |
| 11 Ocio Educ. Y Anim. Sociocul. (COEAS Estatal) | ✗ `UNDER_REVIEW_SCOPE` | ✗ | ✗ | tagging-only block (doc 76 has text, not OCR-blocked) |
| 12 Alojamientos (Gipuzkoa) | ✓ (doc 28) | ✗ (PDF only) | ✗ | prose solid, salary blocked |
| 13 Limpieza Edificios y Locales (Gipuzkoa) | ✓ (doc 13) | ✗ (none) | ✗ | doc 18 (this session) duplicates doc 13, adds nothing |
| 14 Intervención Social (Gipuzkoa) | ✗ `EXPIRED_NO_SUCCESSOR` (2025) | ✗ | ✗ | **full gap** |
| 15 Información y Documentación (Gipuzkoa) | ✓ (doc 26) | **✓ (docs 12→102 for 2025, 27→103 for 2026, `source = ocr_pdf`)** | ✗ | **fully covered** — the first convenio made salary-answerable from a PDF; verified through the chat (`floor_decision.path = salary_sql`, `year_selection = exact` on 2026) |
| 16 Hostelería y Turismo (Huesca) | ✗ `UNDER_REVIEW_SCOPE`+expired | ✗ | ✗ | **full gap** |
| 17 Ocio Educ. Y Anim. (Madrid, COEAS) | ✗ `UNDER_REVIEW_SCOPE` | ✗ | ✗ | **full gap**, tagging-only |
| 18 Acción e Intervención Social (Navarra) | ✓ (doc 52) | ✓ (doc 1) | ✗ | fully covered + 3 rulings pre-publish |
| 19 COEAS (Navarra) | ✓ (doc 34) | ✓ (doc 3) | ✗ | fully covered |
| 20 Gestión Deportiva (Navarra) | ✗ `EXPIRED_NO_SUCCESSOR` (2020) | ✗ | ✗ | **full gap**; doc 50 (OCR'd, Pedram-verified) is the likely successor, unbound |
| 21 Hostelería (Navarra) | ✗ `EXPIRED_NO_SUCCESSOR` (2025) | ✗ | ✗ | **full gap** |
| 22 Limpieza Edificios y Locales (Navarra) | ✓ (doc 36) | ✓ (doc 2) | ✗ | fully covered |
| 23 Oficinas y Despachos (Navarra) | ⚠ `EXPIRED_NO_SUCCESSOR` + suspect mistag (doc 33) | ✗ | ✗ | doc 30 (real prose, 53 chunks) just expired; doc 33's 3 chunks are too thin to really cover it |
| 24 Oficinas y Despachos (Salamanca) | ✗ `EXPIRED_NO_SUCCESSOR` (2025) | ✗ | ✗ | **full gap** |
| 25 Oficinas y Despachos (Valencia) | ✓ (doc 92) | ✗ (none) | ✗ | prose solid |
| 26 Intervención Social (Vizcaya) | ✗ `EXPIRED_NO_SUCCESSOR` (2025, duplicate pair) | ✗ (untagged) | ✗ | **full gap** despite 90+51 chunks sitting inert |
| 27 Locales y Campos Deportivos (Vizcaya) | ✗ (no docs at all) | ✗ | ✗ | **full gap — zero documents reference this registry row**; doc 66 is the obvious unbound candidate |

**10 of 26 registry convenios are a full gap today** (7, 9, 14, 16, 17, 20, 21, 24, 26, 27 — no answerable prose, salary, or facts at all), plus convenio 5 and 23 are effectively gaps too (23 has only a too-thin mistag candidate). This is the Sprint-8 coverage-gap map in embryo.

---

## Doc corrections (where `deploy.md` / prior docs disagreed with the live DB)

- `deploy.md` referred to stale OCR target ids "89/91" in an early draft; the corrected, verified list is documents 14, 31, 35, 50, 68, 69, 85 + two more historical (already fixed in `deploy.md` itself during 7e).
- The reason-code prompt's own example ("the salary-table PDF typed `convenio_text`, id 94-family") is **stale**: document 94 is now correctly typed `salary_tables` in the live DB. The *current* live example of that exact pattern is **doc 33** (see Part 2/3), not 94.
- Two Sprint-7e pre-existing frontend gaps (Documents-page pagination cap, no deep link) — already recorded in `sprint-07e/review.md` §2.9 and `deploy.md`'s doc-corrections list; not re-litigated here.
- **This ledger's own 02:03 run had two counting errors, corrected in this run.** (a) The `SALARY_PDF_NOT_IMPORTED` file list read "15 (12,19,24,27,32,35\*,43,46,57,59,86,88\*,94,97\*,12)" — doc 12 twice and doc 20 missing. The live set of PDFs typed `salary_tables` is exactly 15: 12,19,20,24,27,32,35,43,46,57,59,86,88,94,97. (b) "8 salary" files answerable was wrong: only **5** native `.xlsx` files (docs 1,2,3,4,9) have ever produced salary rows, between them 10 `salary_tables` and 109 rows; the 2 OCR-derived files bring it to 7 files / 12 tables / 119 rows.
- **`convenios` has no `status` column** (checked against the live schema). "Active convenio" in Part 4 is therefore *derived*, from the `retrieval_status` of the documents pointing at each convenio — not read off the registry row. Anyone re-running these queries should not expect `where status = 'active'` to work.

## How to keep this current

Re-run this ledger after every ingest, OCR run, verify session, salary import, **`salary:pdf-to-xlsx` conversion**, or reference-fact batch — the query date/time at the top is the freshness signal; if it's more than a few days old relative to the last ingest, treat the numbers as stale. All figures above came from direct queries against the live staging DB (documents, document_pages, document_chunks, salary_tables, salary_table_rows, reference_facts, convenios, employees), not from any markdown doc. Practical note for the next run: `php artisan tinker` is not usable on staging (`docker compose exec` skips the entrypoint that supplies the secrets, and `tinker --execute` drops into interactive mode in a non-TTY container). What works is a standalone PHP file that bootstraps the framework itself, run inside `docker compose run --rm hr-backend` with the same env exports `deploy-run.sh` uses — see `deploy.md`'s runbook.
