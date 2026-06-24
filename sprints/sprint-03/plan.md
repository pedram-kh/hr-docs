# Sprint 3 — Plan (Knowledge Center: lens-hierarchy admin UI)

> Location: `hr-docs/sprints/sprint-03/plan.md`
> Status: **plan for review — no UI/API code written.** Inspected the real substrate (schema, seeders, the Sprint 1 ingest/provenance writers, the 2b answer pipeline, hr-ai endpoints, the design system, and the existing `hr-frontend`).
> Read with `sprint-03-spec.md`, `data-model.md` (Groups A/B/G + §11), ADR-0001/0011/0012/0013, the Sprint 1 review, `roadmap.md` Sprint 3, and `deploy.md` §5.
> **Note on `frontend-design`:** the kickoff names a `frontend-design` skill; it is not present in this workspace or in the available skill set. This plan therefore takes its frontend discipline from the in-repo equivalents — `design-system.md` (the visual constitution) and ADR-0012 (vanilla CSS + tokens, no Tailwind, minimal deps) — which is what `design-system.md` calls the implemented source of truth. Flag for the builder to load `frontend-design` if it exists at build time.

---

## 0. TL;DR — what's real, what's thin, what we build

- **Provenance is real and populated.** `tag_events` (append-only) carries `filename_parse`, `system`, and `admin_manual` rows from Sprint 1 ingest + the Sprint 1 confirm/re-assign actions. The change-log timeline has real data **for territory/sector/convenio/document_type/validity**.
- **Two honest thinnesses the spec's "automation/agent/human" framing must absorb:**
  1. **No `ai_agent` rows exist yet** — the LLM tagging tier is Sprint 7. The "agent" lane of the timeline is **empty in today's corpus**; the component renders it, but it only fills from Sprint 7.
  2. **`document_topics` is unpopulated and there is no `topic` provenance** — nothing tags topics today (the deterministic parser does territory/sector/convenio/type/validity only; topics are seeded *vocabulary* with zero document associations). So **the topic lens has no leaves** and the card's "topics" facet is empty. Sprint 3's bounded edit becomes the **first writer** of `document_topics` (human topic-tagging), which is in scope per the spec's editable-facets list.
- **No additive migration is required.** `tag_events` (free-text `facet`/`old_value`/`new_value`) and `document_topics` (with `source`/`verified_by`/`verified_at`) already cover every Sprint-3 write. The only schema-adjacent change is an **optional, additive hr-ai read endpoint** for the single-document sandbox (no migration; the `hr_ai` role already has SELECT on `document_chunks`).
- **Two schema realities the card must respect:** (a) **chunk language split (es/eu) is not stored** — `document_chunks` deliberately has no `language` column (data-model §5), so chunk-health shows count/tokens/pages/embed-presence, **not** an es/eu split; (b) **scope rides on the convenio** — territory/sector are derived and **not editable** (already enforced by `DocumentController::REASSIGNABLE`).

---

## 1. Provenance reality check (the spine)

### 1.1 The table exists and is populated

`tag_events` (`2026_06_20_131023_create_tag_events_table.php`) is the append-only provenance log named in data-model §10. Real columns:

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `entity_type` | varchar | `document` today (designed for `document_topic` too) |
| `entity_id` | bigint | the `documents.id` (indexed with `entity_type`) |
| `facet` | varchar | `document_type` · `convenio` · `territory` · `sector` · `validity` · `document` (the confirm marker) |
| `old_value` / `new_value` | text NULL | display values (e.g. convenio `numero`, type `code`, `"2023-01-01..2026-12-31"`) |
| `source` | enum | `filename_parse` · `ai_agent` · `admin_manual` · `system` |
| `actor_id` | bigint FK → admins NULL | set only for `admin_manual` |
| `confidence` | numeric(4,3) NULL | set for parse/AI (1.0 / 0.8 / 0.5 in Sprint 1) |
| `note` | text NULL | human-readable reason |
| `created_at` | timestamp | the timeline order key |

**Who writes it today (confirmed in code):**
- **Ingest — `DocumentIngestor::ingest()`** writes one **`filename_parse`** row per resolved facet (`document_type`, `convenio`, `territory`, `sector`, `validity`), each with the coarse Sprint-1 `confidence`; plus **`system`** rows for each conflicting facet (`note: "conflict: …"`) and each unresolved value (`note: "unresolved value: …"`), with `new_value = null`.
- **Confirm — `DocumentController::confirm()`** writes an **`admin_manual`** row (`facet = "document"`, `new_value = "verified"`, `note = "tags confirmed"`, `actor_id` set).
- **Re-assign — `DocumentController::reassignFacet()`** writes an **`admin_manual`** row with `old_value`→`new_value` display strings (`note: "facet re-assigned by admin"`), for `convenio` / `document_type` only.

The read side already exists: `DocumentController::show()` returns the events ordered by `created_at,id` as `provenance[]`, and `api.ts` types them as `ProvenanceEvent`. **The timeline is not starting from zero.**

### 1.2 A real sample (reconstructed from the Sprint 1 review eyes-on run)

For the bilingual Vizcaya convenio `48006185012006_…_Bizkaia_20232026_texto.pdf` (auto_proposed, clean, confidence 1.0), `tag_events` holds rows shaped like:

```text
facet=document_type  old=∅ new=convenio_text       source=filename_parse confidence=1.000 note="parsed from filename"
facet=convenio       old=∅ new=48006185012006       source=filename_parse confidence=1.000 note="parsed from filename"
facet=territory      old=∅ new=48                    source=filename_parse confidence=1.000 note="parsed from filename"
facet=validity       old=∅ new=2023-01-01..2026-12-31 source=filename_parse confidence=1.000 note="parsed from filename"
```

…and for the Madrid-folder conflict doc (numero `48…` in `MADRID/`), an extra **`system`** row:

```text
facet=territory  old=∅ new=∅  source=system  note="conflict: filename/folder territory Madrid disagrees with Vizcaya"
```

After a Sprint-1 admin confirm, an **`admin_manual`** `facet=document / new=verified` row joins the tail. So the timeline reads exactly the spec's story: **parsed → flagged → confirmed by a human.**

### 1.3 Where it is thinner than the spec assumes (say it now)

1. **`ai_agent` = 0 rows.** No AI tagging tier ships before Sprint 7, so the "agent (AI tagging)" lane is **structurally empty** today. The timeline component must render three sources gracefully and **not imply a missing agent step is an error**. Net: today's lanes are **automation (`filename_parse`) · system (`system`) · human (`admin_manual`)**; agent appears later. (This matches `design-system.md` §5's timeline dot legend: `filename_parse` neutral · `system` warning · `admin_manual` accent — there is no agent dot yet; Sprint 3 adds an `ai_agent` dot color reserved for Sprint 7.)
2. **Topics carry no provenance and no associations.** `document_topics` is empty; no `facet=topic` events exist. The **topic lens renders empty-honest** ("No topics tagged yet — topic tagging arrives with the LLM tier, Sprint 7"), and the card's topics facet shows the same. **Sprint 3's human topic edit is the first writer** (see §5.3).
3. **`retrieval_status` / `tagging_status` were never facet events.** At ingest they are set as **document columns**, not logged as `tag_events` facets (only the confirm marker touches `tagging_status` via the `document/verified` row). So the historical timeline shows the *initial* status implicitly (via the parse rows + confirm), and **Sprint 3 edits to these become the first explicit `retrieval_status`/`tagging_status` facet rows** (§1.4).
4. **Validity is a single combined string** (`"start..end"`). Sprint 3 keeps that convention on edit so old→new reads cleanly in one row.

### 1.4 How a Sprint-3 human edit appends provenance (append-only, old→new, who/when)

**One rule, reused for every editable facet:** an edit is a `documents` (or `document_topics`) column write **plus** a new `tag_events` row — never an update or delete of an existing event. Exactly the shape `confirm()`/`reassignFacet()` already use, extended to the full editable set:

```text
tag_events:
  entity_type = 'document'            (or 'document_topic' for a topic add/remove)
  entity_id   = documents.id
  facet       ∈ {convenio, document_type, topic, validity, retrieval_status, tagging_status}
  old_value   = prior display value (null if none)
  new_value   = new display value (null on a topic removal)
  source      = 'admin_manual'
  actor_id    = the editing admin's id
  confidence  = null                  (human edits assert, they don't score)
  note        = short reason / 'scope-affecting change confirmed' where relevant
  created_at  = now()
```

- **Append-only is guaranteed by construction** — Sprint 3 adds no UPDATE/DELETE path on `tag_events`; the model has no such method and the controller only `create()`s.
- **`actor_id`** is `request()->user()->id` (the Sanctum-authenticated admin), so "who" is always recorded; "when" is `created_at`.
- **Topic edits** also write the `document_topics` row itself with `source = 'admin_manual'`, `verified_by = adminId`, `verified_at = now()` (the columns already exist).
- **No migration needed** — `facet`, `old_value`, `new_value` are free text; adding the facet strings `topic`/`validity`/`retrieval_status`/`tagging_status` is a convention, not a schema change.

---

## 2. Read API + the lens queries

All new routes live under the existing `Route::middleware(['auth:sanctum','admin'])->prefix('admin')` group (`routes/api.php`) and reuse the established facet joins. **The hierarchy is computed from facets at query time — no stored tree** (ADR-0001). Every lens query reuses the same `convenio.territory` / `convenio.sector` relations the Sprint-1 `DocumentController::index()` already joins, so we **reuse, not duplicate**, the scope/registry access.

### 2.1 The lens model (two-level default, lazy children, leaf = document)

A **lens** = a `GROUP BY` ordering of facets. Four lenses, each a level-1 → level-2 → leaf shape; **level 1 + level 2 render eagerly (counts only), leaves load lazily on expand**:

| lens | level 1 | level 2 | leaf |
|---|---|---|---|
| **province (territory)** | `territories` (by `level`: national / regional / provincial) | `sectors` present under that territory's convenios | `documents` (via convenio) |
| **sector** | `sectors` | `territories` present under that sector's convenios | `documents` |
| **validity** | `retrieval_status` (active / historical / draft) | validity-end year bucket (or "open-ended") | `documents` |
| **topic** | `topics` (approved) | — (one level) | `documents` via `document_topics` — **empty today** (§1.3) |

- **National-law docs** (`convenio_id = null`, `authority_level = national_law`, e.g. the Estatuto) have no territory/sector; they appear under a dedicated **"Estatal / Ley nacional"** node in the province and sector lenses (ADR-0001's cross-cutting case, never duplicated).
- The **"scope rides on convenio" limitation**: a non-national doc with no convenio cannot be placed in the territory/sector lens. There is no such doc in today's corpus (Sprint 1 review §5). It surfaces as an **"Unscoped (no convenio)"** flagged node rather than being silently dropped — the graceful display the spec's Risks note asks for.

### 2.2 Endpoints

- **`GET /admin/hierarchy?lens={territory|sector|validity|topic}`** → level-1 nodes: `{ key, label, level?, count, child_kind, has_gap_flag }`. Counts are document counts under the node (a single grouped query over `documents` joined to `convenios`).
- **`GET /admin/hierarchy/children?lens=…&parent={key}`** → level-2 nodes (same shape) **or** leaf documents when the node is a leaf parent. Lazy — fired on expand only.
- **`GET /admin/documents/{uuid}`** → **extend the existing `show()`** (already returns facets + provenance + pages + review tasks) with: `lineage` (predecessor + successor), `chunk_health` (§2.3), `topics` (+ their provenance), and `is_unscoped`/gap flags. Reuses everything `show()` already builds.
- **`GET /admin/documents/{uuid}/source`** → presigned URL for the **original** file (`documents.storage_path`), the same `Storage::disk('s3')->temporaryUrl(...)` mechanism the existing `pageImage()` uses (see §4.4).
- **`GET /admin/coverage-gaps`** → the three gap classes as flagged nodes (§3).

Lens grouping queries are thin Eloquent/`selectRaw` aggregations on `documents ⋈ convenios` — the join already present in `index()`. Vocabulary level-1 lists (territories/sectors/topics/document_types) reuse `VocabularyController` (add a `topics` case to it).

### 2.3 The leaf → document-card payload (chunk/health)

`document_chunks` has **no hr-backend Eloquent model** (it is hr-ai's write table), so chunk-health is a **read-only raw aggregate** from hr-backend's own (full-privilege) connection — not the scoped `hr_ai` role:

```sql
SELECT count(*) AS chunk_count,
       coalesce(sum(token_count),0) AS token_total,
       min(page_from) AS first_page, max(page_to) AS last_page,
       bool_or(embedding IS NOT NULL) AS has_embeddings
FROM document_chunks WHERE document_id = :id;
```

- `chunk_count = 0` on an `active` prose doc ⇒ the **unanswerable** signal (§3).
- **es/eu split is intentionally not derivable** (no `language` column on `document_chunks`, data-model §5). Chunk-health shows count / token total / page span / 0-chunk flag and **omits** the es/eu split, with a one-line note. (If the split is later wanted, it is an hr-ai change to persist the chunker's per-chunk language — out of scope here.)
- **Lineage:** `predecessor_document_id` exists on `documents` but the `Document` model has no `predecessor`/`successor` relation yet — add both (code only): `predecessor()` = `belongsTo(self)`, `successors()` = `hasMany(self,'predecessor_document_id')`. No migration.
- **Expiry countdown** is pure client arithmetic from `validity_end`; no API field needed beyond the existing `validity_end`.

---

## 3. Coverage-gap detection (derived from existing data — no new pipeline)

`GET /admin/coverage-gaps` returns three derivable classes, each rendered as a **flagged node/marker** in the map (the gaps from `deploy.md` §5 become first-class, visible holes). All are read-only SQL over data Sprint 1/2a/2c already wrote — **no scan, no pipeline.**

### 3.1 Active but 0-chunk (scanned / unanswerable)

```sql
SELECT d.* FROM documents d
WHERE d.retrieval_status = 'active'
  AND d.document_type_id IN (prose-eligible types: convenio_text, national_law, partial_agreement)
  AND NOT EXISTS (SELECT 1 FROM document_chunks c WHERE c.document_id = d.id);
```
This is the embedding-eligibility set (data-model §5) minus anything that actually produced chunks. It captures the `deploy.md` §5 scanned image-only PDFs (e.g. **Deporte Navarra id 89**, **Pacto Cultura Navarra id 91**, both also `under_review`). Cross-display the **`empty_text`** signal the card already derives from blank `document_pages`, so "scanned" vs "thin" is distinguishable.

### 3.2 Expired with no active successor

A territory×sector cell whose only prose docs are `historical` with no `active` doc:

```sql
-- per convenio: does it have an active prose doc?
SELECT cv.id, cv.numero, cv.territory_id, cv.sector_id
FROM convenios cv
WHERE EXISTS (SELECT 1 FROM documents d WHERE d.convenio_id = cv.id
              AND d.document_type_id IN (prose types) AND d.retrieval_status = 'historical')
  AND NOT EXISTS (SELECT 1 FROM documents d WHERE d.convenio_id = cv.id
              AND d.document_type_id IN (prose types) AND d.retrieval_status = 'active');
```
Surfaces the `deploy.md` §5 expired-no-successor set (Andalucía COEAS id 29, Vizcaya Intervención Social id 18, Huesca Hostelería id 33, Salamanca Oficinas id 51, Gipuzkoa Intervención Social id 45, Navarra Hostelería id 75 / Oficinas-prose id 93, Deporte Estatal id 69, Asturias id 52, Navarra Deporte id 80). The two stuck-`under_review` successors (COEAS Estatal id 72, COEAS Madrid id 24) surface as a related "successor exists but not trustworthy" sub-flag (a doc with an `active`-eligible successor that is `under_review`).

### 3.3 Salary-table mistag (id 94)

The known instance (`OFICINAS Y DESPACHOS NAVARRA_Tabla 2025`, typed `convenio_text` + `active`, really a salary-table PDF — roadmap §7) is **derivable, not hand-listed**:

```sql
SELECT d.* FROM documents d
JOIN document_types t ON t.id = d.document_type_id
WHERE t.code = 'convenio_text' AND d.retrieval_status = 'active'
  AND (d.source_filename ILIKE '%tabla%' OR d.title ILIKE '%tabla%');
```
i.e. an active doc typed as prose whose own filename/title says it is a salary *Tabla*. This is a **suspected-mistag** flag inviting the bounded retag in §5 (convenio→keep, `document_type` → `salary_tables`). The eyes-on "retag id 94" lands as a `human` provenance entry through the §5 edit path. (We keep the heuristic conservative — `Tabla`/`Tablas` token on a `convenio_text`+`active` doc — and label it "suspected", because it invites a human decision, never auto-applies; ADR-0011 posture.)

### 3.4 Rendering

Gap nodes carry a `gap_kind` (`unanswerable` / `expired_no_successor` / `suspected_mistag` / `unscoped`) and render with the existing semantic badges (`design-system.md` §2/§5): `--warning` for unanswerable/under-review, `--neutral` for historical-only, `--danger` reserved for hard conflicts. A scope with no usable doc reads as a **flagged hole**, not an empty branch.

---

## 4. The document card (leaf)

The card **extends the existing `DocumentDetailPanel.tsx`** (Sprint 1 already renders tags, review tasks, a provenance timeline, and source pages) into the full Sprint-3 card. Reuse the `.card`, `.panel`, `.facet`, `.badge-*`, `.timeline`, and `.well` classes from `design-system.md` §5 — no new visual primitives beyond a reserved `ai_agent` timeline dot color.

### 4.1 Facet tags with inline provenance
Each facet chip (`.facet`) — convenio, territory, sector, document_type, topics — shows its current value plus an inline "set by {source} · {when} · conf {confidence}" drawn from the **latest `tag_events` row for that facet** (the `provenance[]` already in `show()`). Territory/sector chips are marked **derived (read-only)**.

### 4.2 Status / validity / authority / lineage / tagging
- Validity window + **expiry countdown** (client math on `validity_end`).
- `retrieval_status` (draft/active/historical), `authority_level`, `tagging_status` + `tagging_confidence` — all already in the `show()` payload; rendered as badges.
- **Lineage:** predecessor + successors (§2.3 relations), each a link opening that document's card.

### 4.3 Chunk / health summary
From §2.3: chunk count, token total, page span, embed-presence, and the **0-chunk unanswerable** flag. **No es/eu split** (schema reality, §0). This is *display only* — the card never re-chunks (hard constraint).

### 4.4 The change-log timeline
The `provenance[]` rendered chronologically via `.timeline`, dots colored by `source`: **automation** (`filename_parse`, neutral) · **system** (`system`, warning) · **agent** (`ai_agent`, reserved color — empty today) · **human** (`admin_manual`, accent). Shows action text, actor + relative time, and `old→new` where present. This is the single "how did this doc end up in this scope, and who changed it?" surface, and **Sprint-3 human edits append into the same stream** (§1.4).

### 4.5 The real document viewer (serving the S3 source)
- **Mechanism:** `GET /admin/documents/{uuid}/source` returns a short-lived **presigned S3 URL** for `documents.storage_path` (the original PDF/xlsx), via `Storage::disk('s3')->temporaryUrl($key, now()->addMinutes(10))` — identical to the working Sprint-1 `pageImage()` endpoint. The browser fetches the file directly from S3; the bytes never proxy through hr-backend.
- The card renders the PDF in an `<iframe>`/`<embed>` against that URL, **and** keeps the Sprint-1 per-page image+text view (already built) as the always-available fallback (page images exist even for scanned docs). For a salary `.xlsx` (no pages), the viewer offers a download link only.

### 4.6 Read-only scoped sandbox ("test a question against this document")
Reuse the **answer pipeline primitives** against **one document**, persisting nothing:

- **The reuse problem:** `ChatService.handleMessage()` is employee- and persistence-bound, and `hr-ai /retrieve` scopes by **convenio**, not by a single `document_id` (`hr-ai/app/chunks_db.py::retrieve`). So we cannot literally call the chat path.
- **Proposed path (minimal, answer-loop untouched):**
  1. **hr-ai:** add a small **additive, read-only** endpoint `POST /sandbox-retrieve` (or an **optional `document_id` filter** on `/retrieve`) that embeds the query (`embed_query`, already there) and ranks `document_chunks` **WHERE `document_id = :id`**. The existing `/retrieve` is **not modified in behavior** (the employee pipeline never passes `document_id`). The `hr_ai` role already has SELECT on `document_chunks`; **no migration, no answer-loop change.**
  2. **hr-backend:** a `SandboxService` (or a thin controller method) that calls the new retrieve → `/synthesise` → `/ground` with the **same decrypted answer-model key path** as `ChatService` (`AnswerModelSetting::current()->decryptKey()`, passed per call, dropped after), reusing the authority-ordering + grounding logic. It returns the answer/citations/trace **as a response only** — it writes **no** `chat_sessions`/`chat_messages`/`message_citations`/`message_traces`/`escalation_cards`.
  3. **Endpoint:** `POST /admin/documents/{uuid}/sandbox` `{ question }` → `{ answer, citations, trace }`.
- **Recommendation:** prefer the **dedicated `/sandbox-retrieve`** over overloading `/retrieve`, so the legal-weight employee primitive is provably untouched. Either way hr-ai stays read-only.
- To avoid duplicating ChatService's synthesis/ordering/grounding, factor the shared steps into a reusable helper both call (a light refactor with **no behavior change** to the employee loop) — or, if that risks the frozen 2b path, have `SandboxService` re-implement the thin orchestration against the same hr-ai endpoints. **Flagged as a build-time call (§8 Q4).**

---

## 5. Bounded edit (the write path)

### 5.1 What is editable (and what is not)
Editable, by design, **only** the re-assignable document facets (spec D, data-model rule): **`convenio`**, **`document_type`**, **`topics`**, **`validity_start`/`validity_end`**, **`retrieval_status`**, **`tagging_status`**. **Territory and sector are not editable** — they derive from the convenio (already enforced: `DocumentController::reassignFacet()` returns `422` for any facet outside `REASSIGNABLE = ['convenio','document_type']`). Re-assigning the **convenio** re-assigns its territory+sector transitively, keeping a document-vs-convenio scope contradiction structurally impossible.

### 5.2 FK pickers, never free text, never vocabulary creation
All choosable values come from existing controlled vocabulary via `VocabularyController` (extended with a `topics` case returning **approved** topics only): convenio, document_type, topic pickers are FK dropdowns. **No free text; no create/rename/merge/re-scope of any vocabulary** (that stays in `registry:import`, ADR-0011 — out of scope). The server validates every `value_id` exists (the existing `reassignFacet` already rejects an unknown id with `422`).

### 5.3 The endpoints (extend, don't fork)
- **`convenio` / `document_type`** → the **existing** `PATCH /admin/documents/{uuid}/facets/{facet}` already does this and already appends `admin_manual` provenance. Reused as-is for the id-94 retag.
- **`topics`** → `POST /admin/documents/{uuid}/topics {topic_id}` and `DELETE …/topics/{topic_id}`. Writes/removes the `document_topics` row (`source='admin_manual'`, `verified_by`, `verified_at`) **and** appends a `tag_events` row (`entity_type='document_topic'` or `'document'`, `facet='topic'`, old→new = topic name). **This is the first code to populate `document_topics`.**
- **`validity_start`/`validity_end`, `retrieval_status`, `tagging_status`** → `PATCH /admin/documents/{uuid}` `{ validity_start?, validity_end?, retrieval_status?, tagging_status? }`. For each **changed** field, update the `documents` column and append one `tag_events` row (`facet` = the field, old→new). `retrieval_status` ∈ {draft,active,historical}; `tagging_status` ∈ {auto_proposed,under_review,verified} — validated against the enums.

### 5.4 System-behavior warning on a scope-affecting save
A save is **scope-affecting** when it changes which employees get this doc as an answer: **reassigning `convenio`**, **flipping `retrieval_status`** (esp. →`active` or away from it), or **editing validity** (changes the as-of-date eligibility window). The server computes a `scope_affecting: true` flag and **requires an explicit `confirm_scope_change=true`** on such saves (returns `409`/`422` without it). The UI shows the clear warning copy ("This changes which employees receive this document as an answer") and the actioned button keeps its name through the flow (`design-system.md` §7 voice). Non-scope edits (e.g. `document_type` cosmetic retype, a topic add) save without the gate.

### 5.5 All writes hr-backend; append-only human provenance
Every edit runs in hr-backend in a `DB::transaction` (the established pattern), writes the column(s), and **appends** the `admin_manual` `tag_events` row(s) per §1.4 — **never** rewriting history. `hr-ai` is not involved in any edit (only the read-only sandbox).

### 5.6 Migration check (AC #7)
**No additive migration is required.** `tag_events` already stores arbitrary `facet`/`old_value`/`new_value`/`actor_id`/`note`; `document_topics` already has `source`/`confidence`/`verified_by`/`verified_at`. The full Sprint-3 write set is expressible on the existing schema. The only candidate schema-adjacent changes are **non-migration**: the optional hr-ai `/sandbox-retrieve` (read-only, no DDL) and **seeder/role** additions (§7). If review prefers a *generic* edit log distinct from facet provenance, `tag_events` already is that log — adding a second table would duplicate it and is **not recommended**.

---

## 6. The reusable hierarchy component (frontend)

**One** component, `<Hierarchy lens={…} form={…} />`, driven by a **lens definition** (ADR-0001: new lenses are config, not code). Built on the design system (vanilla CSS + tokens, ADR-0012 — no Tailwind, minimal deps).

### 6.1 Lens definition (config)
```ts
interface LensDef {
  key: 'territory' | 'sector' | 'validity' | 'topic';
  label: string;                       // "By province", …
  fetchRoots: () => Promise<Node[]>;        // GET /admin/hierarchy?lens=…
  fetchChildren: (parentKey) => Promise<Node[]>; // GET /admin/hierarchy/children?…
  renderNodeBadge?: (node) => …;       // gap/flag badges
}
type Node = { key; label; count; childKind: 'group'|'leaf'; gapKind?; docUuid? };
```
The same data model feeds both forms; switching lens or form is state, not a new component.

### 6.2 Two interchangeable forms
- **Indented-list:** nested disclosure rows (the dense, scan-first default — aligns with `design-system.md`'s table/disclosure idiom). Expand/collapse with the lazy `fetchChildren`.
- **Branching-graph:** the same tree laid out left→right in level columns with connector lines.
  - **Rendering approach (ADR-0012 minimal-deps):** **hand-rolled SVG** — absolutely-positioned node boxes (reusing `.card`/badge styles) with an SVG overlay drawing orthogonal connector paths between a parent and its (lazily loaded) children. Because the default is **two-level + lazy**, the drawn graph is always small (one parent's children at a time), so a layout library (d3/react-flow, a new dep) is **not justified**. Keyboard-navigable and `prefers-reduced-motion`-safe per `design-system.md` §6.
  - If review wants a denser auto-laid-out graph later, adopting a small lib is a localized swap (the lens/data layer is unaffected). **Flagged §8 Q1.**

### 6.3 Behavior
Two-level default, expand/collapse, **lazy children on expand**, **leaf opens the document card** (§4, reusing/extending `DocumentDetailPanel`). Coverage-gap nodes (§3) render inline with their flag badge. Empty lenses (topic, today) show the honest empty state, not a blank panel (`design-system.md` §7).

### 6.4 Where it lives
A new **Knowledge → Map** view alongside the existing **Knowledge → Documents** table in `AdminShell.tsx` (extend the `View` union + sub-nav). The table stays; the map is the new lens surface. New `api.ts` functions/types for hierarchy, coverage-gaps, the extended card payload, source URL, sandbox, and the edit calls.

---

## 7. Roles / access (using what exists; full role mgmt is Sprint 5)

### 7.1 What exists
- `spatie/laravel-permission` is installed; `RoleSeeder` seeds the four roles (`super_admin`, `hr_agent`, `knowledge_editor`, `auditor`). `Admin` uses `HasRoles`. `IdentityPresenter` returns `roles` on `/me`, and `api.ts`'s `Identity` already has `roles?: string[]`.
- **But:** `EnsureAdmin` middleware only checks `instanceof Admin` (admin-or-not) — there is **no per-role gating yet**, and `TestUserSeeder` assigns only `super_admin` (no `auditor`/`knowledge_editor` admin is seeded). No granular permissions are seeded (`RoleSeeder` defers them).

### 7.2 What Sprint 3 adds (minimal, no Sprint-5 role UI)
- **A `knowledge.edit` ability**, granted to `super_admin` + `knowledge_editor`, **denied** to `auditor` (and `hr_agent`). Implement via spatie permission seeded in `RoleSeeder` + a tiny gate/middleware (`EnsureCan('knowledge.edit')`) on the **write** routes only (§5). **Reads** (hierarchy, card, source viewer, sandbox) stay open to **any admin** — the sandbox persists nothing, so a read-only `auditor` running it is consistent with "browse + inspect".
- **Frontend:** hide/disable every edit affordance (pickers, save, retag) when `identity.roles` lacks edit capability; the map/card/sandbox remain fully usable. Color-and-text, never color-only, per `design-system.md` §6.
- **Seeder (not a migration):** seed an `auditor` and a `knowledge_editor` admin from env (mirroring `SEED_ADMIN_EMAIL`) so the eyes-on "confirm an auditor can browse but not edit" is testable. **Flagged §8 Q3** (env names).

### 7.3 Boundary
Full role management, role-scoped conversation access, and the directory UI stay in **Sprint 5**. Sprint 3 only *consumes* the seeded roles to gate one ability.

---

## 8. Build order, assumptions & open questions

### 8.1 Build order

**hr-backend (read first — the map needs data before the card, the card before the edit):**
1. Lens/hierarchy queries + `GET /admin/hierarchy` and `/hierarchy/children` (reuse the `documents ⋈ convenios` joins from `index()`); add `topics` to `VocabularyController`.
2. `GET /admin/coverage-gaps` (the three derivable classes, §3).
3. Extend `DocumentController::show()`: lineage relations (add `predecessor()`/`successors()` to `Document`), `chunk_health` raw aggregate, `topics` + their provenance, gap/unscoped flags.
4. `GET /admin/documents/{uuid}/source` (presigned original, mirrors `pageImage()`).
5. Bounded-edit write paths (§5): topics add/remove, `PATCH …/{uuid}` for validity/retrieval_status/tagging_status, scope-affecting confirmation gate; all appending `admin_manual` provenance. `knowledge.edit` permission + gate (§7) + seeder.

**hr-ai (one small, additive, read-only touch):**
6. `POST /sandbox-retrieve` (single-document, read-only ranking) — answer loop untouched.

**hr-backend (sandbox orchestration):**
7. `SandboxService` + `POST /admin/documents/{uuid}/sandbox` reusing the answer-model key path + synthesise/ground, persisting nothing.

**hr-frontend (last — consumes the API):**
8. `api.ts` types/functions for all of the above.
9. `<Hierarchy>` component (list + graph forms) + lens config + coverage-gap rendering; new Knowledge → Map view in `AdminShell`.
10. Document card (extend `DocumentDetailPanel`): provenance timeline (reuse `.timeline`, add `ai_agent` dot), chunk-health, lineage, real-document viewer, sandbox panel.
11. Edit UI: FK pickers, scope-warning modal, the id-94 retag flow; role-gated affordances.

**Docs at close (DoD, not this turn):** `architecture.md` (Knowledge Center surface + bounded-edit/provenance-append rule + vocabulary-stays-in-import boundary), `data-model.md` (note: no new column needed; the facet-string conventions for provenance), `roadmap.md` (Sprint 3 done; topic-tagging deferred to 7, language-split-in-chunks deferred), the frontend README, and `sprint-03/review.md`.

### 8.2 Assumptions
- The seeded test corpus referenced by `deploy.md` §5 (ids 89/91/94/29/… ) exists in the eyes-on database (dev convenience corpus, not in the repo). The coverage-gap queries are derivations, so they hold for whatever corpus is loaded; the named ids are the current instances.
- The 2b answer pipeline is frozen/committed; the sandbox must not alter its behavior — hence the additive-only hr-ai endpoint and the no-persistence sandbox.
- `tagging_status`/`retrieval_status` enums are enforced at the DB; the edit endpoints validate against the same value sets.
- Two-level "default" means: render level 1 + level 2 group counts eagerly, lazy-load leaves (documents) on expand. For the 3-deep territory/sector lenses the third level (documents) is the lazy leaf tier.

### 8.3 Open questions for review
1. **Graph rendering (Q1):** hand-rolled SVG connectors (recommended, zero new deps, fits the two-level/lazy shape) vs. adopting a small graph lib (denser auto-layout, but a new dependency against ADR-0012's minimal-deps posture). OK to proceed hand-rolled?
2. **Topic lens / topic tagging (Q2):** confirm we ship the topic lens **empty-honest** today and that Sprint-3's human topic edit (writing `document_topics` + `topic` provenance) is the **first** topic writer — i.e., human topic tagging is in scope now, AI topic proposal stays Sprint 7. (The spec lists `topics` as editable, so this is the reading I've planned to.)
3. **Sandbox shape (Q4):** dedicated hr-ai `/sandbox-retrieve` (recommended — proves the employee `/retrieve` is untouched) vs. an optional `document_id` param on `/retrieve`. And: extract a shared synthesis/ground helper for ChatService + SandboxService, or keep SandboxService independent to avoid touching the frozen 2b path?
4. **Chunk-health language split (Q5):** confirm we **omit** the es/eu split (not stored on `document_chunks`, data-model §5) rather than add an hr-ai change to persist per-chunk language. (Recommended: omit + note.)
5. **Role seeding (Q3):** confirm seeding an `auditor` and `knowledge_editor` admin from env for eyes-on, and the env var names (e.g. `SEED_AUDITOR_EMAIL`, `SEED_EDITOR_EMAIL`).
6. **Salary-mistag heuristic (Q6):** confirm the conservative "`convenio_text` + `active` + filename/title contains *Tabla*" derivation for the suspected-mistag flag (id 94), surfaced as *suspected* (human decides, never auto-applies).

### 8.4 Hard-constraint compliance (self-check)
- **No vocabulary restructuring** — pickers are FK-into-existing only; territory/sector non-editable; no create/rename/merge/re-scope; topic approval stays Sprint 7. ✅
- **No re-ingestion / re-embedding / OCR from the UI** — the card *shows* chunk health, never re-chunks; sandbox is read-only reuse, persists nothing, no answer-loop change. ✅
- **hr-backend owns all writes + schema (additive only)** — confirmed **no migration needed**; the one hr-ai touch is a read-only endpoint (no DDL, no DB write); hr-ai stays read-only. ✅
- **Every label edit appends `human` provenance, never rewrites** — single append rule (§1.4), no UPDATE/DELETE path on `tag_events`. ✅
- **Design system reused** — vanilla CSS + tokens (ADR-0012); reuse `.card`/`.facet`/`.timeline`/`.badge-*`; one reserved `ai_agent` dot color is the only addition. ✅

---

**Plan is ready for review. No UI/API code has been written; only this `plan.md` was created. Awaiting review before building.**
