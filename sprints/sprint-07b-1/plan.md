# Sprint 7b-1 — Structured Reference Knowledge: substrate + manual path — PLAN

> Location: `hr-docs/sprints/sprint-07b-1/plan.md`
> Status: **plan for review — no reference-fact code written this turn.**
> Read first: `sprint-07b-1-spec.md`, `roadmap.md` §Sprint 7b, ADR-0006/0007/0011/0020, `data-model.md` §6 (salary) + §10 (provenance).
> Scope guard: **NO** AI segmentation (7b-2), **NO** answering-from-facts (7c). The `ai_agent` source lane is reserved-but-unwritten; the manual path is the only writer. The 2b answer loop is **not** touched.

This plan generalizes the proven **salary** pattern into a new `reference_facts` store, builds the non-salary **docx/xlsx readers**, the **manual create/verify** path on the 7a inert-until-verified spine, and the **Knowledge Center** distinct-leaf display. Each design choice is mapped to its salary/7a/Sprint-3 precedent so the build copies discipline rather than inventing it.

---

## 1. What exists (reality check — the pattern + the gap)

### 1.1 The salary template (the pattern to generalize)

`salary_tables` and `salary_table_rows` are the real, shipped "structured, scoped, queried-not-vectorized" precedent. The actual columns:

```11:19:hr-backend/database/migrations/2026_06_20_131012_create_salary_tables_table.php
        Schema::create('salary_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->integer('year')->nullable();
            $table->date('validity_start')->nullable();
            $table->date('validity_end')->nullable();
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
        });
```

```11:23:hr-backend/database/migrations/2026_06_20_131013_create_salary_table_rows_table.php
        Schema::create('salary_table_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salary_table_id')->constrained('salary_tables')->cascadeOnDelete();
            $table->foreignId('job_category_id')->constrained('convenio_job_categories');
            $table->decimal('gross_annual', 10, 2)->nullable();
            $table->decimal('base_salary_monthly', 10, 2)->nullable();
            $table->decimal('extra_pay', 10, 2)->nullable();
            $table->integer('num_payments')->nullable();
            $table->decimal('hourly_rate', 8, 4)->nullable();
            $table->decimal('night_plus', 10, 2)->nullable();
            $table->jsonb('raw_values')->nullable();
            $table->timestamps();
        });
```

The five disciplines to carry over verbatim:

1. **Scope rides the convenio** — `convenio_id` FK; territory + sector are reached *through* the convenio, never stored independently (data-model §5 "Document scope is derived via the convenio"). `salary_table_rows.job_category_id` is the finer scope.
2. **Source traceability** — `source_document_id` FK → `documents` (the `.xlsx` it came from), `nullOnDelete`.
3. **Typed columns + `raw_values` jsonb verbatim** — common concepts are typed for reliable queries; everything original is kept in `raw_values` (nothing lost).
4. **Idempotent writes** — `salary:import` keys `updateOrCreate` on `convenio_id + year`, then deletes-and-rewrites that table's rows in one transaction:

```81:120:hr-backend/app/Console/Commands/SalaryImport.php
            DB::transaction(function () use ($doc, $tables, &$categoriesCreated, &$tablesWritten, &$rowsWritten) {
                foreach ($tables as $table) {
                    $year = $table['year'] ?? null;

                    $salaryTable = SalaryTable::updateOrCreate(
                        ['convenio_id' => $doc->convenio_id, 'year' => $year],
                        [
                            'validity_start' => $table['validity_start'] ?? null,
                            'validity_end' => $table['validity_end'] ?? null,
                            'source_document_id' => $doc->id,
                        ],
                    );

                    // Idempotent: replace this table's rows cleanly.
                    SalaryTableRow::where('salary_table_id', $salaryTable->id)->delete();
```

5. **`hr-ai` extracts-and-returns; `hr-backend` writes** — `hr-ai POST /extract-salary` parses the `.xlsx` and returns rows; it writes **no** salary DB rows (ADR-0007/0010/0014). `salary:import` is the deliberate, logged writer.

### 1.2 The salary citation (queried-not-embedded, ADR-0006)

A salary answer cites the **source document with `chunk_id = null`** — never a vector chunk. The shipped shape:

```273:294:hr-backend/app/Services/SalaryAnswerService.php
    private function salaryCitation(SalaryTable $table): array
    {
        if (! $table->source_document_id) {
            return [];
        }
        ...
        return [[
            'chunk_id' => null, // salary is structured data, never a vector chunk (ADR-0006)
            'document_id' => $doc->id,
            'document_uuid' => $doc->uuid,
            'document_title' => $doc->title,
            ...
            'page_number' => null,
            'snippet' => "Tabla salarial {$table->year}"...,
            'is_salary_table' => true,
        ]];
    }
```

`message_citations.chunk_id` is nullable precisely for this (data-model §8, ADR-0006). **7b-1 does not write citations** (no answering — 7c), but this is the citation shape a reference fact will reuse in 7c: point at the source document + a locator, never a chunk.

### 1.3 The format gap (confirmed — the new engineering)

- **PDF only** in `/extract`. `hr-ai POST /extract` calls `extract_pdf(...)` (PyMuPDF). There is **no docx branch** anywhere.

```201:215:hr-ai/app/main.py
@app.post("/extract", dependencies=[Depends(require_internal_token)])
def extract(req: ExtractRequest) -> JSONResponse:
    """PDF → per-page text + page-image S3 keys (ADR-0010).
    ...
    """
    try:
        result = extract_pdf(req.storage_key, req.document_uuid)
```

- **xlsx is read only via the salary path.** `salary.py` uses `openpyxl` and is salary-grid-specific (header-synonym maps for `total`/`€/hora`/`SB`/`14`/`12`…). It is invoked only by `salary:import` for `document_type = salary_tables` `.xlsx`.
- **Upload rejects everything but PDF + xlsx**, and the xlsx it accepts is salary-only:

```210:225:hr-backend/app/Http/Controllers/Admin/DocumentController.php
            // Sprint 2a accepts PDFs (prose) and salary .xlsx (ADR-0014 xlsx-first).
            // Other formats (.doc/.docx prose, .xls) remain out of scope.
            $ext = strtolower((string) $file->getClientOriginalExtension());
            $isPdf = $ext === 'pdf' || $file->getMimeType() === 'application/pdf';
            $isXlsx = $ext === 'xlsx' ...;
            if (! $isPdf && ! $isXlsx) {
                $results[] = [ ... 'reason' => 'unsupported format (PDF prose + salary .xlsx only this sprint)' ];
                continue;
            }
```

ADR-0010 ("Sprint 1 scope is **PDF only**") and ADR-0014 ("**`.doc/.docx` prose is deferred**") confirm the deferral. **Conclusion: a non-salary `.docx`/`.xlsx` reader is genuinely new** — nothing reads docx today, and the salary xlsx reader cannot be reused for arbitrary reference content (it hunts a salary grid and discards everything else).

### 1.4 The 7a inert-until-verified spine (reusable as-is)

ADR-0020 gives us the exact mechanism to reuse: an upstream proposer writes **`ai_agent` provenance** + leaves the row **unverified** (`under_review`); only a **human verify** makes it count; provenance is **append-only**. The verify action is the unchanged Sprint-3 confirm:

```248:276:hr-backend/app/Http/Controllers/Admin/DocumentController.php
    public function confirm(Request $request, string $uuid): JsonResponse
    {
        ...
        DB::transaction(function () use ($document, $adminId) {
            $document->update(['tagging_status' => 'verified']);
            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'document', 'old_value' => null, 'new_value' => 'verified',
                'source' => 'admin_manual', 'actor_id' => $adminId, ...
                'note' => 'tags confirmed',
            ]);
            $document->reviewTasks()->where('status', 'open')->update([...]);
        });
```

Provenance lives in **`tag_events`** (append-only; `entity_type` is a free varchar — `document` | `document_topic` | …) and the **fuchsia `--provenance-ai`** token is reserved for **unverified-AI only** (ADR-0020 consequence; `index.css` `--provenance-ai: #e879f9`). 7b-1 reuses tag_events, the verify pattern, the `verified_by`/`verified_at` shape (already on `document_topics`), and the Sprint-3 scope-edit confirm (409 `confirm_scope_change`). **No part of the spine needs to change** — `admin_manual` is the only writer in 7b-1; the `ai_agent` lane is reserved.

---

## 2. The `reference_facts` table (new, salary-patterned)

A **new dedicated** table (not overloaded onto `salary_tables` — different shape, same discipline). One row = **one scoped fact**. Proposed shape:

| column | type | salary/7a precedent | notes |
|---|---|---|---|
| `id` | bigint PK | salary | |
| `uuid` | uuid UNIQUE | documents | external ref (leaf key `fact:{uuid}`, card route) |
| `convenio_id` | bigint FK → convenios | `salary_tables.convenio_id` | **scope rides the convenio**; territory/sector **derived**, never stored |
| `job_category_id` | bigint FK → convenio_job_categories **NULL** | `salary_table_rows.job_category_id` | nullable (a fact may be convenio-wide, e.g. *periodo de prueba* by group) |
| `topic_id` | bigint FK → topics **NULL** | `document_topics.topic_id` | bind into **approved** topics only (ADR-0011) |
| `value` | text | the typed columns' analog | the human-readable rule, e.g. `"periodo de prueba: 90 días (indefinidos) / 75 (temporales)"` |
| `raw_values` | jsonb NULL | `salary_table_rows.raw_values` | source phrasing/structure **verbatim** (nothing lost) |
| `validity_start` | date NULL | `salary_tables.validity_start` | the periodo docx arrive in **versions** — which is current |
| `validity_end` | date NULL | `salary_tables.validity_end` | NULL = open-ended |
| `authority_level` | enum **`['structured_reference']`** default `structured_reference` | documents authority enum **minus** `official_convenio`/`national_law` | **`official_convenio` is structurally unselectable** (see §2.1) |
| `source` | enum `['admin_manual','ai_agent']` default `admin_manual` | `document_topics.source` / `tag_events.source` | `ai_agent` **reserved-but-unwritten** in 7b-1 |
| `status` | enum `['needs_review','verified']` default `needs_review` | 7a `under_review`→verify | **unverified = not answerable** (inert gate) |
| `verified_by` | bigint FK → admins NULL | `document_topics.verified_by` | set on human verify |
| `verified_at` | timestamp NULL | `document_topics.verified_at` | set on human verify |
| `source_document_id` | bigint FK → documents **NULL** `nullOnDelete` | `salary_tables.source_document_id` | the docx/xlsx it came from (traceability) |
| `source_locator` | varchar NULL | *new* (salary cites whole doc) | "which part" — `p.3 §2` / `sheet:smi26` / paragraph anchor |
| `created_by` | bigint FK → admins NULL | `documents.ingested_by` | who authored (manual path) |
| timestamps | | salary | |

**Non-vectorized by design (ADR-0006):** these are structured rows — **never** `document_chunks`, no `embedding`, no `chunk_index`. By construction they are excluded from `ChunksEmbed::IN_SCOPE_TYPES` (they are not documents at all), so there is **nothing to embed** — the same posture as salary.

**Idempotency.** Salary keys on `convenio_id + year`. A manual reference fact has no equally-natural key; for 7b-1 (single deliberate human create) we do **not** add a hard unique constraint, but we record a *logical* key (`convenio_id, topic_id, job_category_id, validity_start, validity_end`) for the 7b-2 AI writer to upsert on. Flagged as an open question (§7).

### 2.1 Authority defaults low — **structurally** (the load-bearing rule)

Two layers, both enforced (never convention):

1. **Schema** — the `authority_level` enum/CHECK contains **only** values *below* `official_convenio`. In 7b-1 that is the single value `structured_reference` (the column **cannot physically hold** `official_convenio` or `national_law`). This is a fresh-table `enum(...)` (Postgres CHECK), the same idiom the `documents` table uses for its authority enum:

```22:22:hr-backend/database/migrations/2026_06_20_131008_create_documents_table.php
            $table->enum('authority_level', ['national_law', 'official_convenio', 'internal_hr_ruling']);
```

2. **Validation** — a `StoreReferenceFactRequest` FormRequest accepts authority `in:structured_reference` only and **rejects (422) anything higher** — mirroring the Sprint-6 "reject below floor, never clamp" discipline (ADR-0019). The default is applied server-side; the client cannot raise it.

This makes the spec's invariant true by construction: *nothing the system generates can outrank the law.* The existing authority floor rule (data-model §5: `national_law < official_convenio`, internal must not override) extends cleanly — `structured_reference` sits below `official_convenio`, so on a future 7c conflict the convenio always governs.

---

## 3. The format readers (the new engineering)

### 3.1 Division of labor (ADR-0007) — hr-ai reads, hr-backend persists

- **docx MUST be hr-ai.** PHP has no mature docx reader; Python's `python-docx` is the right tool. So a **new hr-ai endpoint** (proposed `POST /read-structured`, guarded by the internal token) reads the docx **and** xlsx bytes from S3 and **returns structured content** — it writes nothing and **never migrates** (exact mirror of `/extract-salary`).
- **xlsx also goes through hr-ai** in the same endpoint, reusing the already-present `openpyxl` dependency — one reader, one division, no PHP spreadsheet dependency. (PhpSpreadsheet server-side is the alternative; rejected for consistency + because docx forces Python anyway — see §7.)

Returned shape (a **content-extraction utility only** — it returns content, it does **not** decide scope or segment; that is 7b-2):

```
POST /read-structured  { storage_key, document_uuid, format: "docx"|"xlsx" }
→ docx: { format, blocks: [{ kind: "paragraph"|"table_row"|"heading", text, locator }] }
→ xlsx: { format, sheets: [{ name, rows: [[cell,…]], locator }] }
```

`hr-backend` calls this via a new `ExtractionClient::readStructured(...)` (mirrors `extractSalary()`), and **persists nothing automatically as facts**. For display it stores the surfaced text into **`document_pages`** (one row per docx section / xlsx sheet) — reusing the existing citation/display surface, **never** `document_chunks` (no embed). The human reads this content and manually creates facts (7b-1); 7b-2's AI will consume the same endpoint.

### 3.2 Ingesting a reference source (so a fact can link to it)

A reference fact's `source_document_id` must point at a real `documents` row. Today upload rejects docx and treats all xlsx as salary. 7b-1 extends upload to accept a **non-salary docx/xlsx** tagged with a **new `document_type` code** (proposed `reference_source`; alternative: reuse `other` — §7). On ingest: store the file to S3, create the `documents` row (type = `reference_source`, `tagging_status = under_review`, scope assigned by the human like any messy-tail doc), call `/read-structured` → store display `document_pages`, and **never** call `/embed`. Because `reference_source ∉ ChunksEmbed::IN_SCOPE_TYPES`, it is non-vectorized by construction.

### 3.3 Salary-vs-reference routing (the Alhambra test)

**Routing rides the `document_type` tag — the existing, deliberate, human-confirmed signal — not content sniffing.** This is exactly how salary already routes: `salary:import` selects *only* `document_type = salary_tables` `.xlsx`:

```43:46:hr-backend/app/Console/Commands/SalaryImport.php
        $query = Document::query()
            ->with('documentType')
            ->whereHas('documentType', fn ($q) => $q->where('code', 'salary_tables'))
            ->where('storage_path', 'like', '%.xlsx');
```

The rule for 7b-1:

- A **salary** `.xlsx` is tagged `salary_tables` → stays on `salary:import` → `salary_table_rows`. The reference reader **never** touches a `salary_tables` document.
- A **non-salary** reference `.docx`/`.xlsx` is tagged `reference_source` → goes to `/read-structured` → manual fact entry. `salary:import` **never** picks it up (it filters on `salary_tables`).
- **`Tablas_acuerdo_parcial_Alhambra.xlsx`** (partly SMI/salary-ish, partly reference) is the test: it is tagged **once**. Salary figures enter `salary_table_rows` **only** if tagged `salary_tables` and run through `salary:import`; reference content becomes `reference_facts` **only** if tagged `reference_source`. The reference reader/writer **never** writes a `salary_table_rows` row — even if the source contains SMI numbers, they land as a fact's `value`/`raw_values`, not as salary. So salary content is **structurally** not swallowed into `reference_facts`, and reference content is not swallowed into salary. The boundary is the deliberate type tag + which writer runs. (Per ADR-0014, a doc that is genuinely both would be split at tagging time; 7b-1 does not auto-route by content.)

---

## 4. The manual create/verify path (no AI)

### 4.1 Endpoints (`hr-backend` owns all writes)

A new `Admin/ReferenceFactController` under the `admin` group:

| route | ability | purpose |
|---|---|---|
| `GET /admin/reference-facts` | open admin (read) | list/filter (by convenio/topic/status) — auditor browses |
| `GET /admin/reference-facts/{uuid}` | open admin (read) | the fact card payload (value, scope, source, provenance timeline) |
| `POST /admin/reference-facts` | `knowledge.edit` | **create** a scoped fact → lands `needs_review` |
| `PATCH /admin/reference-facts/{uuid}` | `knowledge.edit` | bounded edit (value/validity/topic/scope) — scope-affecting → 409 confirm gate |
| `POST /admin/reference-facts/{uuid}/verify` | `knowledge.edit` | **verify** → `needs_review` → `verified`, append provenance |
| `GET /admin/reference-sources/{uuid}/content` | open admin (read) | the `/read-structured` output for manual entry |

Reads open to any admin; writes gated by `EnsureCan` (`ability:knowledge.edit`) — mirroring Sprint-3 (reads open, writes gated):

```119:124:hr-backend/routes/api.php
    Route::middleware('ability:knowledge.edit')->group(function () {
        Route::patch('/documents/{uuid}/facets/{facet}', [DocumentController::class, 'reassignFacet']);
        Route::patch('/documents/{uuid}', [DocumentController::class, 'updateLifecycle']);
        Route::post('/documents/{uuid}/topics', [DocumentController::class, 'addTopic']);
        Route::delete('/documents/{uuid}/topics/{topicId}', [DocumentController::class, 'removeTopic']);
    });
```

### 4.2 Create — FK-picker scope (existing vocabulary only, ADR-0011)

The create form picks **scope via the existing FK-picker endpoints** — no free text, no vocabulary creation:
- `GET /admin/vocabulary/convenios` (convenio → its territory/sector **derive** and are shown read-only/"(derived)"),
- `GET /admin/job-categories?convenio_id=…` (convenio-scoped categories — already built for the directory),
- `GET /admin/vocabulary/topics` (approved topics only).

```50:61:hr-backend/app/Http/Controllers/Admin/VocabularyController.php
    public function jobCategories(Request $request): JsonResponse
    {
        $data = $request->validate([
            'convenio_id' => ['required', 'integer', 'exists:convenios,id'],
        ]);
        $items = ConvenioJobCategory::where('convenio_id', $data['convenio_id'])
            ->orderBy('name')->get(['id', 'name', 'group_code']);
        return response()->json(['items' => $items]);
    }
```

The form fields: convenio (required FK) → derived territory/sector display, job category (optional FK, filtered by convenio), topic (optional approved FK), `value` (text), `raw_values` (optional), validity start/end, authority (**defaulted + locked to `structured_reference`**, no `official_convenio` option), optional source-document link + `source_locator`. `StoreReferenceFactRequest` validates all FKs `exists:`, derives nothing the client sends (territory/sector are never accepted from the client — they ride the convenio), and rejects any authority above the floor.

### 4.3 Verify + append-only provenance (the 7a spine)

- Create writes the row `status = needs_review`, `source = admin_manual`, plus an append-only `tag_events` row (`entity_type = 'reference_fact'`, `entity_id`, `facet = 'value'|'scope'|'validity'|'authority'|'topic'`, `source = 'admin_manual'`, `actor_id`). **No new provenance table** — `tag_events` is reused exactly as Sprint-3 added new facet strings "with no schema change" (data-model §10). 
- Verify flips `status → verified`, sets `verified_by`/`verified_at`, and appends a `tag_events` `facet = 'status'`, `new_value = 'verified'` row (the `confirm()` shape).
- **Edits never rewrite** — each create/verify/edit appends; history is immutable (append-only, the tag_events posture).

### 4.4 Scope-edit confirm gate (reuse Sprint-3)

Editing a fact's **scope** (convenio / job_category / validity — the columns that decide *which employees a fact would answer* once 7c exists) is **scope-affecting** → requires `confirm_scope_change=true`, else **409**, reusing the exact bounded-edit gate:

```435:443:hr-backend/app/Http/Controllers/Admin/DocumentController.php
        $scopeAffecting = isset($changes['retrieval_status']) || $validityChanged;
        if ($scopeAffecting && ! $request->boolean('confirm_scope_change')) {
            return response()->json([
                'message' => 'This changes which employees receive this document as an answer. Re-send with confirm_scope_change=true to apply.',
                'scope_affecting' => true,
            ], 409);
        }
```

Editing `value`/`raw_values`/`source_locator` is not scope-affecting (no gate).

### 4.5 Abilities

Both **create/propose and verify** are gated by **`knowledge.edit`** (super_admin + knowledge_editor) in 7b-1 — mirroring Sprint-3, where the human is the author and the verify is the same human action. The `ai_agent` lane (7b-2) will raise the verify bar (an AI proposal is the risky case). Whether 7b-1 verify should already require a stricter/separate ability is an open question (§7) — recommendation: reuse `knowledge.edit` now, revisit when the AI writes.

---

## 5. The Knowledge Center display (distinct, badged leaf)

### 5.1 Leaf in the lens hierarchy (graph + list)

`reference_facts` are **not** documents, so `HierarchyController::leaves()` (which queries `documents`) will not surface them. 7b-1 extends the hierarchy so a verified-or-needs-review fact appears as a leaf **under its scope** (territory→sector, sector→territory, and topic lenses), beside the document leaves:

- New leaf node `key = "fact:{uuid}"`, `child_kind = "leaf"`, carrying a `knowledge_type = "reference_fact"` marker, `status`, `validity_*`, `topic`. Built from a `reference_facts` query joined to `convenios` (territory/sector) — reusing the same lens-key grammar (`t:{id}|s:{sid}`, `tp:{id}`).
- The `Hierarchy.tsx` leaf renderer already badges per attribute (e.g. `lens-row--ruling`); 7b-1 adds a **distinct knowledge-type badge** (a new `.badge-reference` token in `index.css`, vanilla-CSS per ADR-0012/0013) so a reference fact is visually separate from `convenio_text`, `salary`, and `internal_hr_ruling`. Leaf click opens the fact card (a new `ReferenceFactCard`, or a typed branch of the detail panel).

```282:294:hr-backend/app/Http/Controllers/Admin/HierarchyController.php
        $nodes = $docs->map(fn (Document $d) => [
            'key' => "doc:{$d->uuid}",
            'label' => $d->title,
            'child_kind' => 'leaf',
            'doc_uuid' => $d->uuid,
            ...
        ])->all();
```

### 5.2 The fact card

A new card (reusing the `detail panel` shell + `facets`/`kv`/`timeline` markup of `DocumentDetailPanel`) showing: the scoped **value** + `raw_values`, the **source reference** (file link via the existing presigned-S3 source endpoint + the `source_locator`), the **topic**, **validity/version**, **authority** (`structured_reference`, with the lock made visible), and the **provenance + review-state timeline** (manual-created → human-verified) rendered from the `tag_events` rows — exactly the existing timeline component:

```319:341:hr-frontend/src/pages/admin/DocumentDetailPanel.tsx
      <section>
        <h4>Provenance</h4>
        <ol className="timeline">
          {doc.provenance.map((e, i) => (
            <li key={i} className="timeline-item">
              <span className={`timeline-dot src-${e.source}`} aria-hidden="true" />
              ...
```

### 5.3 Unverified treatment (fuchsia is AI-only)

- A `needs_review` **`admin_manual`** fact uses the **normal "needs review"** treatment (e.g. `.badge-review`, the warning tint) — **not** fuchsia.
- The **fuchsia `--provenance-ai`** treatment is **reserved for `ai_agent`-sourced** unverified facts (7b-2), exactly as 7a scopes it to unverified-AI only (ADR-0020). The card's `is_ai_proposed`-style flag will be `source === 'ai_agent' && status === 'needs_review'` — always **false** in 7b-1 (no AI writer), so no fuchsia appears this slice. This keeps the fuchsia signal honest (unverified-**AI** specifically).

---

## 6. Migrations & build order

### 6.1 Additive migrations (hr-backend owns schema)

1. **`create_reference_facts_table`** — the one new table (§2). Fresh-table `enum(...)` for `authority_level` (`['structured_reference']`), `source` (`['admin_manual','ai_agent']`), `status` (`['needs_review','verified']`). FKs `nullOnDelete` where nullable. Additive.
2. **`seed_reference_source_document_type`** — add the `reference_source` code to `document_types` (additive vocabulary), landed both in `DocumentTypeSeeder` **and** an idempotent data migration — the established lockstep pattern (cf. the Sprint-5/6/7a permission seed + data-migration pairs). *(If §7 lands on reusing `other`, this migration is dropped.)*

> **No new provenance table** (reuse `tag_events`), **no new permission** (reuse `knowledge.edit`), **no enum-CHECK introspect-readd idiom** (that idiom is only for *altering* an existing enum, e.g. the `escalation_cards.reason` migration; `reference_facts` is a fresh table so its enums are declared inline). **hr-ai gains an endpoint, not a migration** (ADR-0007), exactly as 7a did.

### 6.2 Build order

1. **hr-backend** — `create_reference_facts_table` migration + `ReferenceFact` model (casts, `convenio`/`jobCategory`/`topic`/`sourceDocument` relations, derived territory/sector accessors) + the `reference_source` document_type seed.
2. **hr-ai** — `POST /read-structured` (python-docx + reuse openpyxl); add `python-docx` to `requirements`. Returns structured content; writes nothing; never migrates.
3. **hr-backend** — `ExtractionClient::readStructured()`; extend upload to accept a `reference_source` docx/xlsx → ingest as a `documents` row → call `/read-structured` → store display `document_pages`; **never** `/embed`. Enforce the **salary-vs-reference routing** (by `document_type`) so `salary:import` and the reference path never overlap.
4. **hr-backend** — `Admin/ReferenceFactController` (create/list/show/verify/edit) + `StoreReferenceFactRequest`/`UpdateReferenceFactRequest` (authority-floor reject, FK `exists:`, scope-edit 409 gate) + append-only `tag_events` provenance + routes under `admin` with `EnsureCan('knowledge.edit')` on writes.
5. **hr-backend** — extend `HierarchyController` (roots counts + `leaves()`) to include `reference_facts` leaves under territory/sector/topic lenses.
6. **hr-frontend** — `api.ts` types + calls; the **create form** (FK pickers + derived scope + locked authority); the **reference-source reader view** (surface `/read-structured` content for manual entry); the **distinct-badged leaf** in `Hierarchy.tsx` (+ `.badge-reference` token); the **`ReferenceFactCard`** (value/source/topic/validity/authority/timeline) + the **verify** button (reusing the confirm + `ScopeWarningModal` patterns).
7. **docs** — `data-model.md` (the `reference_facts` table + reserved `ai_agent` lane), `architecture.md` (the new knowledge type + readers + manual path + authority-low rule + "answering is 7c"), `roadmap.md` (7b → 7b-1 done / 7b-2 next), the new ADR, and `sprint-07b-1/review.md`. **Stop — no commit until review.**

### 6.3 ADR — warranted (recommend **ADR-0021**)

Yes. A new ADR is justified: this introduces a **third structured knowledge class** beside ADR-0006's salary tables (a new queried-not-embedded store) **and** a new **structural authority rule** (`structured_reference` can never express `official_convenio` — enforced in schema + validation). It also records the `document_type`-driven salary-vs-reference routing and the hr-ai `/read-structured` reader (the docx/xlsx format extension to ADR-0010, non-vectorized). Proposed: **ADR-0021 — Structured Reference Knowledge: a non-vectorized scoped-fact class with structurally-bounded authority.** It cites ADR-0006 (queried-not-embedded), ADR-0007 (reads in hr-ai, writes in hr-backend), ADR-0011 (bind into existing vocabulary), ADR-0020 (inert-until-verified, reused).

---

## 7. Assumptions & open questions

1. **docx/xlsx reader location.** *Recommend:* both formats via a single hr-ai `/read-structured` (docx forces Python; openpyxl already present; clean ADR-0007 division). *Alternative:* xlsx server-side via PhpSpreadsheet (a second reader, a new PHP dep) — rejected unless review prefers keeping non-salary xlsx out of hr-ai.
2. **Source `document_type`.** *Recommend:* add a new closed-vocabulary code `reference_source` (deliberate, admin-seeded — additive, consistent with the closed `document_types` set). *Alternative:* reuse `other` (no seed migration, but loses the explicit routing signal and the distinct map labeling). Which?
3. **`reference_facts.value` typing.** *Recommend:* a single `value` text + `raw_values` jsonb (the rules are prose — "5 meses", "90/75 días" — with no universal numeric to type, unlike salary). The salary analog of "typed columns" here is the human-readable `value`; verbatim everything in `raw_values`. Confirm we are **not** adding typed numeric columns in 7b-1.
4. **The multi-value line** (`Navarra, Hostelería, Grupo 2: 60 indefinidos / 45 temporales >3m / 30 ≤3m`). In the **manual** path the human chooses granularity — one fact with all three in `value` + structured `raw_values`, or three facts. *Recommend:* leave it to the author in 7b-1; 7b-2's AI segmentation owns the systematic split. Confirm.
5. **Salary-vs-reference routing rule.** *Recommend:* route strictly by `document_type` (the deliberate human tag), never by content sniffing — so Alhambra is tagged once and only the matching writer runs. Confirm this is the intended boundary (rather than, say, allowing one file to feed both paths).
6. **Verify authorization.** *Recommend:* `knowledge.edit` for both create and verify in 7b-1 (human is author; mirrors Sprint-3 confirm). Open: should verify require a stricter/separate ability (a new `reference.verify`, or super_admin) given scope-assignment is the central risk — or is that deferred to 7b-2 where the AI is the proposer? Note also: the current `documents` `confirm` route is in the open admin block, **not** under `ability:knowledge.edit` (the UI hides it via `canEdit`); 7b-1 should gate the fact verify route on the server (recommend `knowledge.edit`) and we may want to tighten the document confirm route to match — flagged, not changed here.
7. **Idempotency / dedupe.** Manual create has no natural key (unlike salary's `convenio + year`). *Recommend:* no hard unique constraint in 7b-1 (manual create is a single deliberate action), but record the logical key (`convenio_id, topic_id, job_category_id, validity_start, validity_end`) so the 7b-2 AI writer can upsert idempotently. Confirm we don't want a unique index now.
8. **Reader output persistence.** *Recommend:* store `/read-structured` text into `document_pages` (display reuse via the existing page/source viewer), never `document_chunks`. *Alternative:* fetch on-demand only (no persistence). Persisting gives free display; confirm.
9. **`source_locator` format.** *Recommend:* a free-form string (`p.3 §2`, `sheet:smi26`, paragraph anchor) in 7b-1; structure it only if 7b-2 needs machine anchors. Confirm.
10. **Scope coverage of the lens.** The "scope rides the convenio" limitation (data-model §5) means a fact whose only scope is a territory with no convenio cannot be placed — same known limitation as documents. The named fixtures are all convenio-scoped, so 7b-1 inherits the limitation without new risk. Confirm acceptable.

---

**Ready for review.** No reference-fact code (or any file other than this plan) was written this turn.
