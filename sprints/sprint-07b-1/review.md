# Sprint 7b-1 — Review (build + acceptance proof)

**Structured Reference Knowledge: the substrate + the manual path (ADR-0021).**
Built per the approved plan's §6.2 order, every resolved open question (Q1–Q10) applied exactly. 7b-1 makes a **third knowledge class** real, storable, reviewable, and visible — *without* the AI segmentation agent (7b-2) and *without* answering from facts (7c). It generalizes the salary pattern (scoped via the convenio, source-linked, `value` + `raw_values` verbatim, queried-not-embedded), enforces **authority-low by construction**, and routes salary-vs-reference by the deliberate `document_type` tag. Status: **built; pending eyes-on + commit** (not committed — awaiting your review).

---

## 1. What was built

### hr-backend (owns all writes + schema — additive migrations only)

1. **`create_reference_facts_table`** — the salary-patterned store. Fresh-table **inline enums**: `authority_level ['structured_reference']`, `source ['admin_manual','ai_agent']`, `status ['needs_review','verified']`. `convenio_id` (scope; derives territory/sector), nullable `job_category_id`/`topic_id`/`source_document_id`/`verified_by`/`created_by` (FKs, `nullOnDelete` where nullable), `value` text + `raw_values` jsonb, `validity_start/end`, `source_locator`. The logical key `(convenio_id, topic_id, job_category_id, validity_start, validity_end)` is documented (model `LOGICAL_KEY`) for the 7b-2 AI upsert — **no hard unique** in 7b-1 (Q7).
2. **`seed_reference_source_document_type`** — idempotent data migration adding the **`reference_source`** code; `DocumentTypeSeeder` updated in lockstep (Q2). **No** new provenance table (reuse `tag_events`), **no** new permission (reuse `knowledge.edit`), **no** hr-ai migration.
3. **`ReferenceFact` model** — casts, `convenio`/`jobCategory`/`topic`/`sourceDocument`/`verifier`/`creator` relations, **derived** `territory()`/`sector()` accessors (ride the convenio), `AUTHORITY_LEVEL` + `LOGICAL_KEY` constants.
4. **`ExtractionClient::readStructured()`** — the internal HTTP call to hr-ai `/read-structured` (`internal-token` posture, parallels the other hr-ai calls).
5. **Upload extended** — `POST /admin/documents/upload` accepts `as_reference=1`; `DocumentIngestor::ingest(..., asReference)` ingests a `.docx`/`.xlsx` as a **`reference_source`** document, reads content via `/read-structured`, stores display **`document_pages`** (one row per docx section / xlsx sheet, label-prefixed), and **never embeds** (reference_source ∉ `ChunksEmbed::IN_SCOPE_TYPES`) and **never** runs the salary path. (The 7a auto-propose-on-unresolved is deliberately skipped for reference sources — they are inherently multi-scope.)
6. **`ReferenceFactController`** — `index`/`show` (reads open), `store`/`update`/`verify` (`knowledge.edit`), plus `sources`/`sourceContent` for the reader. Provenance is **append-only `tag_events`** (`entity_type = 'reference_fact'`): created → verified → per-field edits. The scope-edit **409** gate (`confirm_scope_change`) and the job-category-belongs-to-convenio / source-is-reference 422 checks live here.
7. **`Store`/`UpdateReferenceFactRequest`** — `authority_level in: structured_reference` (**422** otherwise — INVARIANT 1b), every scope FK `exists:` (topic must be **approved**), `territory_id`/`sector_id` **`prohibited`** (derived, never client-set).
8. **`HierarchyController` extended** — `reference_facts` appear as distinct leaves (`knowledge_type = reference_fact`, `fact:{uuid}`) under their territory→sector / sector→territory / topic scope, **and** contribute to the branch counts (a scope with only facts still draws). The validity/national_law/unscoped branches are document-only (facts are convenio-scoped).
9. **Routes** — reads open, writes `ability:knowledge.edit`; `sources`/`{uuid}/content` declared before `{uuid}` so the literal path wins.

### hr-ai (read-and-return, no migration — ADR-0007)

- **`POST /read-structured`** (guarded by `require_internal_token`) — body `{ storage_key, document_uuid, format }` (`docx`|`xlsx`). `app/read_structured.py`: docx via **python-docx** (split into sections at Heading-styled paragraphs; tables flattened), xlsx via the salary path's **openpyxl** (one page per sheet). Returns `{ format, pages:[{page_number, label, text, locator}] }`. A **content-extraction utility only** — no scope, no segmentation (that is 7b-2). **Writes nothing, never migrates.** `python-docx>=1.1` added to `requirements.txt`.

### hr-frontend (reuse the Sprint-3 Knowledge Center)

- **`Hierarchy.tsx`** — a `reference_fact` leaf is a **distinct-badged** leaf (new `.badge-reference` — info-toned, **not** fuchsia) that opens the fact card (`onOpenFact`); document leaves still open the document card. List + graph forms both updated.
- **`ReferenceFactPanel.tsx`** — the fact card: value, `raw_values` (verbatim), derived scope, source link + locator, topic, validity, the visible **authority lock**, the append-only provenance timeline; the **verify** button (the 7a/Sprint-3 spine) and a bounded **edit** with the scope-change **409 → confirm** modal.
- **`ReferenceFactCreatePanel.tsx`** — a two-column reader + form: the **reader** (pick/upload a `reference_source`, read its `/read-structured` content) on the left; the **form** (convenio → derived territory/sector display, convenio-scoped job category, approved topic, value, raw text, validity, optional source link + locator, authority **locked** to `structured_reference`) on the right. A new fact lands **needs review**.
- **`KnowledgeMapPage.tsx`** — wires the fact card + the "+ New reference fact" toolbar action (gated on `knowledge.edit`). New `api.ts` types + calls; `uploadDocuments(files, asReference)`; `.badge-reference` + panel layout tokens in `index.css` (vanilla CSS, ADR-0012/0013).
- **No fuchsia this slice** — `is_ai_proposed` is always false in 7b-1 (no `ai_agent` writer); a manual unverified fact uses the neutral *needs-review* treatment.

### Docs

ADR-0021 (the new class + the authority-low rule + the `document_type` routing + `/read-structured` as the ADR-0010 format extension + the ADR-0020 reuse), `architecture.md` (§3 — the third knowledge class), `data-model.md` (the `reference_facts` table, the `reference_source` code, the reserved `ai_agent` lane, the logical key, the `tag_events` `reference_fact` entity_type), `roadmap.md` (7b split: 7b-1 **DONE** / 7b-2 **NEXT**; the carried document-confirm-route note), and all three READMEs.

---

## 2. The two safety invariants (non-negotiable — enforced + proven)

**INVARIANT 1 — a reference fact can NEVER outrank a convenio.** Enforced **two** ways: **(a)** the `authority_level` enum/CHECK holds **only** `structured_reference` — the column physically rejects `official_convenio`/`national_law`; **(b)** the FormRequests accept `structured_reference` only and **reject 422** anything higher (reject-not-clamp, ADR-0019). "Nothing the system generates outranks the law," by construction.

**INVARIANT 2 — routing rides `document_type`, never content.** A `reference_source`-tagged `.docx`/`.xlsx` reaches `reference_facts` **only** via the manual path; a `salary_tables`-tagged `.xlsx` reaches `salary_table_rows` **only** via `salary:import` (which filters `document_type = salary_tables`). The reference writer never writes a salary row (SMI figures land as a fact's `value`/`raw_values`); `salary:import` never picks up a reference source.

**Plus the inert-until-verified reuse (ADR-0020 spine):** a fact lands `needs_review`, not answerable until a human verifies (and since 7c is unbuilt, **no fact is answerable yet — correct**); provenance is append-only (`tag_events`, no rewrite).

---

## 3. Acceptance proof — the invariant tests (server is the boundary)

Run against the dedicated Postgres test DB (`hr_platform_test`; the schema is Postgres-specific — enums/jsonb), configured in `phpunit.xml`. Drives the endpoints + ingestor **directly** (the hr-ai reader is stubbed — no network).

```
php artisan test --filter=Sprint7b1ReferenceFactInvariantTest --testdox

 ✔ Invariant 1a authority column cannot store official convenio
 ✔ Invariant 1b store rejects higher authority 422 and writes nothing
 ✔ Invariant 1b update rejects higher authority 422
 ✔ Invariant 2 reference source ingest writes no salary rows and salary import ignores it
 ✔ Creating facts never touches salary tables
 ✔ Fact lands needs review then human verify flips it with appended provenance
 ✔ Territory and sector are prohibited from the request
 ✔ Scope affecting edit requires confirm else 409
 ✔ Job category must belong to the convenio
 ✔ Writes require knowledge edit reads are open

OK (10 tests, 47 assertions)
```

Mapped to the non-negotiable acceptance criteria:

| Required proof | Test | Result |
|---|---|---|
| **INVARIANT 1a** — the column physically rejects a higher authority | `invariant_1a_authority_column_cannot_store_official_convenio` | ✅ a raw insert of `authority_level = official_convenio` throws `QueryException` (the enum CHECK) — nothing written |
| **INVARIANT 1b** — a POST with `authority_level = official_convenio` → 422, nothing written | `invariant_1b_store_rejects_higher_authority_422_and_writes_nothing` | ✅ 422 with `authority_level` validation error; `ReferenceFact::count() === 0` |
| **INVARIANT 1b** — a PATCH attempting `national_law` → 422 | `invariant_1b_update_rejects_higher_authority_422` | ✅ 422; the stored level stays `structured_reference` |
| **INVARIANT 2 (Alhambra)** — a `reference_source` file produces only `reference_facts` content, **zero `salary_table_rows`**; `salary:import` ignores it | `invariant_2_reference_source_ingest_writes_no_salary_rows_and_salary_import_ignores_it` | ✅ the doc is `reference_source`, content stored as `document_pages`, **0 `document_chunks`**, **0 `salary_table_rows`/`salary_tables`**; after `salary:import` still 0 salary rows |
| **INVARIANT 2** — the reference writer never writes a salary row | `creating_facts_never_touches_salary_tables` | ✅ creating a fact leaves `salary_table_rows`/`salary_tables` at 0 |
| **inert-until-verified + append-only** | `fact_lands_needs_review_then_human_verify_flips_it_with_appended_provenance` | ✅ lands `needs_review`/`admin_manual`/no verifier; verify flips to `verified` + sets `verified_by`/`verified_at`; the verify event is **appended** (count grows; created + verified both present) |
| territory/sector never client-accepted | `territory_and_sector_are_prohibited_from_the_request` | ✅ a request with `territory_id` → 422 |
| scope-edit 409 confirm gate | `scope_affecting_edit_requires_confirm_else_409` | ✅ changing convenio without `confirm_scope_change` → 409 `scope_affecting`; with it → applied |
| FK binds into existing vocabulary (job category belongs to convenio) | `job_category_must_belong_to_the_convenio` | ✅ a foreign convenio's category → 422; nothing written |
| writes gated by `knowledge.edit`, reads open (Q6) | `writes_require_knowledge_edit_reads_are_open` | ✅ auditor reads 200; auditor write → 403 |

> Full backend suite: `php artisan test` → **50 passed, 192 assertions** — no regressions from the additive build. Frontend: `tsc --noEmit` clean; `eslint` clean on the new/edited files; hr-ai `py_compile` clean.

---

## 4. Why the invariants are true by construction

- **INVARIANT 1** — the enum column cannot express a higher level (DB CHECK), and `store()` forces `authority_level = ReferenceFact::AUTHORITY_LEVEL` regardless of input while the FormRequest already rejects anything higher (422). Two independent walls; either alone suffices.
- **INVARIANT 2** — `as_reference` is the **only** path that tags `reference_source`, and it never calls the salary extractor; `salary:import` filters `document_type = salary_tables`, which a reference source is not. The reference writer (`ReferenceFactController::store`) writes only `reference_facts` + `tag_events` — there is no code path from it to `salary_table_rows`. Routing is **by tag, never content** (Q5).
- **Inert-until-verified** — `store()` hardcodes `status = needs_review`; the only writer of `verified` is the human `verify()` action. Nothing in 7b-1 makes a fact answerable (no retrieval hook — that is 7c).
- **Vocabulary** — every scope FK is `exists:`; the topic must be `status = approved`; no fact-entry path mints vocabulary (ADR-0011).

---

## 5. Eyes-on checklist (Pedram runs live)

Apply the migrations first (`php artisan migrate`; the `reference_source` seed is idempotent and `DocumentTypeSeeder` adds it on fresh installs). Ensure hr-ai is up (with `python-docx` installed) and the queue worker is running.

- [ ] **Create a fact** — Knowledge → Map → **+ New reference fact**: e.g. {Navarra, Hostelería, Grupo 1} → *periodo de prueba 90/75 días*, validity set, authority **reference-level (locked)**, linked to a source doc → it lands **needs review**.
- [ ] **Verify it** → **verified**; the provenance shows **manual-created → verified** (who/when), append-only.
- [ ] **Find it in the Knowledge Center** — open Navarra → Hostelería: it is a **distinctly-badged leaf** (info-toned `dato`, not fuchsia). Open its card (value, source link + locator, topic, validity, the authority lock, the timeline).
- [ ] **Try to raise authority** — attempt `authority_level = official_convenio` (via API/devtools): **not possible / 422** (the invariant, visible). The form offers no higher option.
- [ ] **Open a non-salary docx/xlsx via the reader** — upload it as a reference source in the create panel → its extracted content is surfaced per section/sheet for manual entry.
- [ ] **Salary still routes to the salary path** — upload a salary `.xlsx` the normal way (tagged `salary_tables`) → `salary:import` writes salary rows; the reference path ignores it. A `reference_source` file produces **zero** salary rows.
- [ ] **No fact is answerable in chat yet** — ask a question a fact would answer → it does **not** answer from `reference_facts` (correct — that's 7c).

How to peek live:

```sql
-- the facts, inert until verified
SELECT id, convenio_id, topic_id, value, status, authority_level, source
FROM reference_facts ORDER BY id DESC LIMIT 20;

-- append-only provenance for a fact
SELECT facet, old_value, new_value, source, actor_id, note, created_at
FROM tag_events WHERE entity_type = 'reference_fact' ORDER BY id DESC LIMIT 20;

-- INVARIANT 2: a reference_source produced ZERO salary rows
SELECT count(*) FROM salary_table_rows;   -- 0 from the reference path
SELECT d.uuid, dt.code FROM documents d
JOIN document_types dt ON dt.id = d.document_type_id WHERE dt.code = 'reference_source';
```

---

## 6. Notes / decisions worth flagging

- **CARRIED — server-gating gap (logged, not fixed here).** The new fact **verify** route is server-gated (`ability:knowledge.edit`) as designed. The existing **document** confirm route (`POST /admin/documents/{uuid}/confirm`) still sits in the open-admin block — UI-hidden via `canEdit`, **not server-gated**. Tightening it to server-side `knowledge.edit` to match is a real gap, the same family as the Sprint-5 access findings. **Per the resolved Q6, this is deferred and only logged** here + in `roadmap.md` + (to add at deploy) `deploy.md` — the document route is **not changed** in 7b-1.
- **The `ai_agent` lane is reserved but UNWRITTEN.** The manual path is the only writer in 7b-1. No AI is pre-wired (7b-2 adds the proposer; that is when the verify bar gets the harder look). `is_ai_proposed` is always false → no fuchsia this slice.
- **No answer-loop change (2b frozen).** Nothing teaches `/retrieve`·`/synthesise` to query `reference_facts`. A verified fact being not-yet-answerable is correct — answering-from-facts is 7c (and it touches the frozen loop, so it is its own deliberate step). No retrieval hook was added.
- **Accepted limitation (Q10):** scope rides the convenio, so a territory-only / no-convenio fact can't be placed — the same limitation documents already have; the fixtures are all convenio-scoped.
- **Single text value, no auto-split (Q3/Q4):** `value` + `raw_values`; the human author chooses granularity (one fact or several). The systematic multi-value split is 7b-2's AI job.
- **Nothing committed** — awaiting your review.
