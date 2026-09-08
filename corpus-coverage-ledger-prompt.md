# Corpus coverage ledger — Cursor prompt

> Paste into any Cursor thread (staging must be up). **Read-only against the corpus; writes ONE file: `hr-docs/corpus-coverage.md`.** No data changes, no OCR runs, no confirms.

---

Build `hr-docs/corpus-coverage.md`: a single ledger that answers, for **every source file in the corpus**, *which flow it went through, where it stopped, and whether its data can reach an employee today*. Today this is scattered across `deploy.md` §4b/§5, the sprint reviews, and staging queries — consolidate it into one place we maintain from now on.

**Source of truth = the live staging DB + S3**, not the docs. Query it; don't restate what deploy.md says. Include the query date/time and the commit SHAs staging is running.

## Part 1 — the flows (define them once, at the top)
List the pipelines a file can travel, each in one line with the tables it writes and the gate that makes its output answerable:
1. **Prose** — PDF with text layer → `/extract` → `document_pages` → (tagging_status ≠ under_review) → `/embed` → `document_chunks` → answerable via the prose path.
2. **Salary** — `.xlsx` tagged `salary_tables` + assigned convenio → `salary:import` → `salary_tables`/`salary_table_rows`/`convenio_job_categories` → answerable via `SalaryAnswerService`.
3. **Reference facts** — `.docx`/`.xlsx` tagged `reference_source` → `/read-structured` → `/segment-facts` → `reference_facts` (needs_review) → human verify → answerable via `ReferenceFactAnswerService` (7c), **group-scoped facts escalate until 7f**.
4. **HR ruling** — escalation resolved → publish (fence) → PDF → prose flow.
5. **OCR** (7e, on `sprint-7e`, not yet run on staging) — text-less PDF → `ocr_pending` → `/ocr-page` → sidecar → under_review → verify → `/embed`.
State explicitly which flows are live on staging `main` right now and which exist only on a branch.

## Part 2 — the ledger (one row per source file; every file in the ingest folder / `documents` table, no exceptions)
Columns, fixed order:
`doc id | filename | document_type | convenio (id + name, or NULL) | territory/sector (derived) | retrieval_status | tagging_status | validity | pages / pages_with_text | flow(s) taken | rows produced (chunks / salary rows / reference facts) | ANSWERABLE TODAY? (yes / no) | why not (one of the reason codes below) | what unblocks it (the exact action + who: human-verify / registry / sprint N / accept-gap)`

Reason codes (use exactly these, so they can be counted):
- `OK` — answerable now.
- `SCAN_NO_TEXT` — no text layer; awaits 7e OCR run + verify.
- `UNDER_REVIEW_SCOPE` — has text; scope/tags unverified so the embedding gate holds it (e.g. COEAS Estatal).
- `NO_CONVENIO_MATCH` — tagged but no registry convenio matched (the 6 salary xlsx, any others).
- `SALARY_PDF_NOT_IMPORTED` — salary content in PDF form; importer is xlsx-only (the 15).
- `HISTORICAL` — deliberately not retrievable (expired/superseded); state whether an active successor exists.
- `EXPIRED_NO_SUCCESSOR` — active-scope hole (registry-expired, nothing loaded).
- `GROUP_SCOPED_FACT` — reference fact exists + verified but escalates until 7f.
- `FACT_NEEDS_REVIEW` — reference facts proposed, not yet human-verified.
- `MISTAG` — typed wrong (e.g. the salary-table PDF typed `convenio_text`, id 94-family).
- `UNKNOWN` — you couldn't determine; say what you'd need.

Also include the **reference facts** as their own sub-table (one row per fact: convenio, topic, group_label, status, validity, answerable-today?, reason) — they're the one knowledge type where "the file was ingested" and "the data is usable" diverge most.

## Part 3 — the dead-end summary (the answer to "what data didn't we use")
A table: `reason code | count of files | total pages | convenios affected (names) | employees in those scopes (count from `employees`, if any seeded) | unblocking action | owner`. Sort by count. Then three short lists:
- **Data we have but can't use yet**, and the single action per group that unlocks it.
- **Data we chose not to use** (historical, duplicates) and why that's correct.
- **Data we can't use with current code** (salary PDFs, group-scoped facts) and which sprint/decision fixes it.

## Part 4 — the coverage picture by scope
For every **active convenio in the registry**: does it have ≥1 answerable prose document, salary rows, reference facts? A grid: `convenio | prose ✓/✗ | salary ✓/✗ | facts ✓/✗ (group-only?) | notes`. This is the Sprint-8 coverage-gap map in embryo — say so.

## Rules
- Every number comes from a query you ran; show the queries in an appendix so the ledger is reproducible (`corpus:coverage` as an artisan command is welcome if it's cheap — read-only, prints the ledger — but the markdown is the deliverable).
- Don't fix anything. Don't guess a convenio for an unmatched file. Where deploy.md and the DB disagree, the DB wins and you note the discrepancy in a "doc corrections" list at the end (we'll fold them into deploy.md separately).
- Add a one-paragraph "how to keep this current" note: re-run after every ingest, OCR run, verify session, or salary import; the ledger date at the top is the freshness signal.

Write the file, report the Part 3 summary table inline, and stop.
