# Sprint 7a — Messy-tail document intelligence · PLAN (plan-gate)

> Location: `hr-docs/sprints/sprint-07a/plan.md`
> Status: **plan for review — no tagging/vocabulary/expiry code written this turn.**
> Read-first done: `data-model.md`, ADR-0011/0007/0012/0013, the Sprint-1 review, the Sprint-3 Knowledge Center (`DocumentController` + `DocumentDetailPanel` + the token system), how `hr-ai` is called today (`ExtractionClient` ↔ `hr-ai/app/main.py`), `roadmap.md` Sprint 7a, and the spec.
> This is the **AI-proposes → human-verifies** sprint. The AI is **upstream and inert**; the embedding gate keeps every proposal unretrievable until a human verifies it. Nothing here touches the answer loop (2b frozen).

---

## 0. TL;DR of the substrate (what I verified against the running dev DB)

| Thing | Reality (verified) | Implication for 7a |
|---|---|---|
| Total documents | **101** | the working corpus |
| `tagging_status` | `under_review` **55** · `auto_proposed` **42** · `verified` **4** | 55 docs sit behind the gate |
| Open `tag_review` / `unresolved` tasks | **49** (across 49 distinct docs) | **the real LLM-eligible backlog 7a serves** |
| Open `conflict` tasks | **7** | human-adjudicated; AI may *suggest*, never auto-apply |
| `tag_events` by source | `filename_parse` 359 · `system` 106 · `admin_manual` 10 · **`ai_agent` 0** | the `ai_agent` lane is **reserved & unwritten** |
| `document_topics` source=`ai_agent` | **0** | AI has never proposed a topic |
| `documents.predecessor_document_id` set | **0 rows** | the lineage write-side is a **stub** |
| `topics` | `proposed` **0** / `approved` **11** | the propose/approve pattern exists, never exercised by AI |
| `--provenance-ai` token | exists in `index.css`, value **`#7c3aed`** (violet), dark `#b794f6` | must be **re-valued to fuchsia `#e879f9`** (D) |

The single most important verified fact (see §1.2): **the embedding gate is `tagging_status != under_review` only.** `auto_proposed` documents **are** embedded (42 docs → 3494 chunks); `under_review` documents are **not** (55 docs → **0 chunks**). This shapes the entire safety design: **AI proposals must keep the doc at `under_review`** — they must never flip it to `auto_proposed`.

---

## 1. What exists (reality check — the spine)

### 1.1 `reason` / `raw_unmatched_values` — laid in Sprint 1, *for this sprint*

`document_review_tasks` carries the two ADR-0011 fields (migration `2026_06_21_100005_add_reason_and_raw_value_to_document_review_tasks.php`):

```24:27:hr-backend/database/migrations/2026_06_21_100005_add_reason_and_raw_value_to_document_review_tasks.php
        Schema::table('document_review_tasks', function (Blueprint $table) {
            $table->enum('reason', ['unresolved', 'conflict'])->nullable()->after('type');
            $table->jsonb('raw_unmatched_values')->nullable()->after('reason');
        });
```

They are written at ingest by `DocumentTagger` → `DocumentIngestor`. The tagger routes **conflict-outranks-unresolved** and sets the gate accordingly:

```176:177:hr-backend/app/Support/DocumentTagger.php
            'tagging_status' => $review !== null ? 'under_review' : 'auto_proposed',
            'tagging_confidence' => $confidence,
```

So a `reason = unresolved` doc (parser had nothing — Rule 5, a no-numero PDF / scan) is created `under_review` with an open `tag_review` task and `raw_unmatched_values` recording the literal unresolved strings. **This is exactly the queue 7a's AI auto-populates.** Verified backlog: **49 open `unresolved` tasks**.

`raw_unmatched_values` shape (per `DocumentTagger::tag()`): `[{facet, value}, …]` where `facet ∈ {convenio, territory, sector}` and `value` is the raw string the parser couldn't resolve to vocabulary.

### 1.2 The embedding gate — the load-bearing safety property (verified, exact)

`chunks:embed` is the **only** path that writes `document_chunks`, and it selects:

```38:42:hr-backend/app/Console/Commands/ChunksEmbed.php
        $query = Document::query()
            ->with(['documentType', 'convenio'])
            ->whereHas('documentType', fn ($q) => $q->whereIn('code', self::IN_SCOPE_TYPES))
            ->whereIn('retrieval_status', ['active', 'historical'])
            ->where('tagging_status', '!=', 'under_review');
```

This is the structural gate `data-model.md §5` describes (*"Embedding requires `tagging_status ≠ under_review`"*). **Empirical proof against the dev DB:**

- `under_review` documents: **55** → chunks belonging to them: **0**
- `auto_proposed` documents: **42** → chunks belonging to them: **3494**

`document_chunks` is the only retrieval surface for prose; `/retrieve` ranks over it. So a doc at `under_review` is **genuinely not retrievable** — it has no chunks at all. Verifying flips `tagging_status → verified` (§1.5), which then admits it to the *next* `chunks:embed` run.

> **⚠ Critical nuance the plan must hold (and the spec slightly blurs):** the gate excludes **only** `under_review`, **not** `auto_proposed`. `auto_proposed` is the *clean-parse* state and **is embedded**. Therefore an AI proposal **must keep the document at `under_review`** until a human verifies. 7a's AI must **never** write `tagging_status = auto_proposed` (that would make a wrong AI guess instantly retrievable — the exact harm we forbid). Since `unresolved` docs are *already* `under_review`, the safe behaviour is the natural one: **the AI writes facets + provenance, leaves `tagging_status = under_review`, and only the human verify flips it.** This is the gate, restated as a rule for 7a, and it is preserved, never weakened.

### 1.3 The `ai_agent` provenance lane — reserved, empty

`tag_events.source` is the enum `filename_parse | ai_agent | admin_manual | system` (migration `2026_06_20_131023_create_tag_events_table.php`; data-model §10). `document_topics.source` carries the same `ai_agent` value. **Verified: 0 `ai_agent` rows in either table.** 7a is the first writer of this lane. No migration is needed — the enum value already exists.

### 1.4 `predecessor_document_id` — a displayed stub nothing writes

On `documents` since Sprint 0:

```23:23:hr-backend/database/migrations/2026_06_20_131008_create_documents_table.php
            $table->foreignId('predecessor_document_id')->nullable()->constrained('documents')->nullOnDelete();
```

It is `fillable` and has `predecessor()`/`successors()` relations on `Document`, and `DocumentController::show()` returns a `lineage` block read by the Sprint-3 card. **But grep confirms no writer** — it is only ever *read* (controller lines 70–71, 104–105). **Verified: 0 documents have it set.** The roadmap confirms by audit that *nothing auto-sets `retrieval_status = historical` as a side-effect of another upload* either. 7a implements the **write-side** on human-confirmed succession; it preserves the no-auto-retire property.

### 1.5 The Sprint-3 review/confirm UI = the verify action (reuse target)

`DocumentController::confirm()` is the existing verify action — it flips the gate and resolves the task, append-only:

```235:255:hr-backend/app/Http/Controllers/Admin/DocumentController.php
        DB::transaction(function () use ($document, $adminId) {
            $document->update(['tagging_status' => 'verified']);

            TagEvent::create([
                'entity_type' => 'document',
                'entity_id' => $document->id,
                'facet' => 'document',
                'old_value' => null,
                'new_value' => 'verified',
                'source' => 'admin_manual',
                'actor_id' => $adminId,
                'confidence' => null,
                'note' => 'tags confirmed',
            ]);

            $document->reviewTasks()->where('status', 'open')->update([
                'status' => 'resolved',
                'resolved_by' => $adminId,
                'resolved_at' => now(),
            ]);
        });
```

The bounded edits an admin uses before confirming already exist: `reassignFacet` (convenio/document_type, FK pickers, `confirm_scope_change` 409 gate), `updateLifecycle` (validity/retrieval/tagging, scope-affecting 409 gate), `addTopic`/`removeTopic` (approved-topic FK only). The frontend `DocumentDetailPanel.tsx` renders the **provenance timeline** (`.timeline` / `.timeline-dot.src-{source}`) and a **Confirm tags** button. **7a reuses all of this**; the AI proposal simply pre-fills these same facets (as `ai_agent` provenance) so the human's job is "review & verify," not "tag from scratch."

### 1.6 `document_review_tasks` shape (the queue table)

`type ∈ {expiry, tag_review, conflict}`, `reason ∈ {unresolved, conflict}` (nullable), `raw_unmatched_values` jsonb, `status ∈ {open, resolved, dismissed}`, `due_date`, `resolved_by`, `resolved_at` (data-model §10; migration `…131024`). **Today only `tag_review` and `conflict` are written** (49 + 7 open). The `expiry` type is **defined but unused** — 7a is its first writer (§3).

### 1.7 The topic propose/approve pattern (the generalization seed)

`topics.status ∈ {approved, proposed}`, `proposed_by ∈ {ai_agent, admin}`, `approved_by` FK (data-model §4). This is the exact shape 7a generalizes to scoping vocabulary (§3). Verified: 11 approved (FAQ-seeded), 0 proposed.

---

## 2. The LLM tagging tier (A) — hr-ai proposes, hr-backend persists

### 2.1 The division of labour (clean, ADR-0007-compliant)

```
INGEST (DocumentIngestor)                     hr-ai (/propose-tags — NEW, no migration)
─ hash, store, /extract → document_pages  ──▶ reads the page text it ALREADY has
─ FilenameParser + DocumentTagger (today)      returns proposed facets + per-facet confidence
─ writes doc under_review + tag_review task    + variant hints + raw_unmatched_values
        │                                              │
        ▼                                              ▼
 reason == unresolved? ──auto-trigger──▶  TagProposalService (hr-backend — NEW)
                                          persists: tag_events(source=ai_agent),
                                          document_topics(source=ai_agent, unverified),
                                          tagging_confidence (min across facets),
                                          updates the tag_review task's raw_unmatched_values
                                          ── keeps tagging_status = under_review ──
```

**hr-ai never migrates and never writes any table** (it has no DB write grant except `document_chunks`). The tagging tier is **extract-and-return**, exactly like `/extract-salary`: hr-ai returns a JSON envelope; **hr-backend writes every row.**

### 2.2 The new hr-ai endpoint: `POST /propose-tags`

Mirrors the existing internal-token endpoints (`require_internal_token`, `X-Internal-Token`). It is an **LLM read-and-propose** call using the same per-call key path as `/synthesise`/`/route`/`/ground` (key in the body, never stored/logged — ADR-0015).

Request (proposed shape — see open questions §7):
```jsonc
{
  "document_id": 123,
  "page_text": "…concatenated/curated document_pages text hr-backend already holds…",
  "candidate_vocabulary": {            // hr-backend passes the CLOSED lists so hr-ai binds, never invents
    "convenios":  [{ "id", "numero", "name", "aliases" }],   // scoped/relevant subset
    "territories":[{ "id", "name", "aliases" }],
    "sectors":    [{ "id", "name", "aliases" }],
    "document_types":[{ "code", "name" }]
  },
  "provider_api_key": "…",             // hr-backend-owned, per call
  "provider_config": { "provider":"claude", "model":"…", "endpoint": null }
}
```

Response:
```jsonc
{
  "facets": [
    { "facet":"document_type", "value_code":"convenio_text", "confidence":0.91 },
    { "facet":"convenio", "value_id":42, "confidence":0.78 },
    { "facet":"territory", "value_id":7, "confidence":0.80 },  // derived via the convenio (data-model §5)
    { "facet":"sector", "value_id":3, "confidence":0.74 },
    { "facet":"validity", "value":"2024-01-01..2027-12-31", "confidence":0.66 }
  ],
  "topics": [ { "topic_id":5, "confidence":0.7 }, … ],          // existing APPROVED topics only
  "raw_unmatched_values": [ { "facet":"sector", "value":"Atención Sociosanitaria", "variant_of": { "id":3, "name":"Acción e Intervención Social", "similarity":0.62 } } ],
  "overall_confidence": 0.66,          // min across facets (drives the queue)
  "trace_fragment": { "model":"…", "notes":"…" }
}
```

Key rules encoded in the prompt + the response contract:
- **Binds into existing vocabulary only** — hr-ai returns `value_id`/`value_code` for matched facets. Where it cannot match, it returns a `raw_unmatched_value` (with an optional `variant_of` hint for the §3 propose flow). It **never invents** a value.
- **Document-level facet tagging only** (convenio / territory(derived) / sector(derived) / document_type / validity / approved-topics). **No multi-scope fact-segmentation** — that is 7b. The endpoint takes one document and returns one set of document facets. Boundary kept sharp.
- Per ADR-0006, language is **not** proposed/used as a facet.

### 2.3 How hr-backend persists (the `TagProposalService` — NEW)

For each returned facet, write an **append-only** `tag_events` row with `source = 'ai_agent'`, `confidence` = the facet confidence, `note` = e.g. `"AI proposal"`. Mirror the `DocumentIngestor` provenance loop, but `source=ai_agent` and `actor_id=null`:
- Proposed **topics** → `document_topics` rows with `source='ai_agent'`, `confidence` set, **`verified_by`/`verified_at` NULL** (unverified). They become verified on the human confirm (reuse the Sprint-3 pattern).
- `documents.tagging_confidence` = `overall_confidence` (min across facets) — this is what the queue sorts on (lowest-confidence first, per data-model §5 *"drives the review queue"*).
- **`tagging_status` stays `under_review`** (the gate). The AI does **not** write the FK columns (`convenio_id` etc.) onto `documents` directly — those are the human's verified decision via the existing `reassignFacet`/confirm path; the AI's proposals live as `ai_agent` `tag_events` + unverified `document_topics`, surfaced as *suggested values* in the review UI. (This keeps the AI strictly a proposer: the doc's authoritative FKs only change by a human action. See open question §7.4.)
- Unresolvable values → update the originating `tag_review` task's `raw_unmatched_values` (merge AI's `variant_of` hints in) so the §3 propose-vocabulary flow has the AI's suggestion. Also append `ai_agent` `tag_events` notes for the unmatched values (audit trail), paralleling the `system` rows the ingestor writes for `unresolved`.

### 2.4 Auto-trigger on ingest (the queue self-populates)

In `DocumentIngestor::ingest()`, **after** the existing transaction, when `tag['review']['reason'] === 'unresolved'` (and not `conflict` — conflicts are human-adjudicated, AI may suggest but per ADR-0011 never on the conflict path here), enqueue the AI proposal. Recommended: a **queued job** (`ProposeDocumentTags` job) rather than a synchronous ingest step, because (a) the LLM call is slow and ingest is a batch folder-upload loop, (b) it matches the `embed` background posture, and (c) a proposal failure must never fail or block ingest. The job calls `ExtractionClient::proposeTags(...)` → `TagProposalService::persist(...)`. A manual **"Re-suggest"** action (admin-triggered, same service) is additive. See §7.2 for the sync-vs-queued decision to confirm.

> Net effect: ingest a no-numero scan → it lands `under_review` (queue), the job runs the AI, the doc now shows AI-proposed facets in fuchsia, **still has 0 chunks (unretrievable)**, and waits for a human. Exactly the eyes-on script.

---

## 3. Propose-new-vocabulary (B) — managed growth, ADR-0011

### 3.1 The additive migration: `vocabulary_proposals`

A single proposal table generalizing the `topics` propose/approve pattern to **all** scoping vocabulary. **Additive, hr-backend-owned.**

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| facet | enum | `territory` \| `sector` \| `convenio` \| `topic` |
| proposed_value | varchar | the literal new value the agent/human suggests |
| variant_of_type | varchar NULL | when offered as a variant: the target vocab table |
| variant_of_id | bigint NULL | the existing value to fold into (alias) — **the default path** |
| resolution | enum | `alias` \| `new_value` — chosen at approval |
| source_document_id | bigint FK → documents NULL | the originating doc (resolves on approval) |
| review_task_id | bigint FK → document_review_tasks NULL | the task that surfaced it |
| status | enum | `proposed` \| `approved` \| `rejected` |
| proposed_by_source | enum | `ai_agent` \| `admin_manual` (who/what proposed) |
| proposed_by_admin_id | bigint FK → admins NULL | set for human proposals |
| approved_by | bigint FK → admins NULL | the human approver |
| created_at / updated_at | timestamps | |

> Append-only spirit: a proposal row is created `proposed` and transitions to `approved`/`rejected` (with `approved_by`); the *vocabulary write itself* is logged separately (below). No existing table changes shape.

### 3.2 The flow (variant→alias offered first; create-new is deliberate)

1. A `raw_unmatched_value` (from the parser or the AI, carrying an optional `variant_of` hint) surfaces in the review UI.
2. The UI offers, **in this order**: **(a) Fold into an existing value's `aliases`** (the strong default — the safe `Bizkaia`/`Vizcaya` case; pre-selected when the AI/`VocabularyResolver` finds a close match), then **(b) Create a genuinely new value** (the deliberate, less-default action, ADR-0011).
3. On **approve**:
   - `resolution = alias` → append `proposed_value` to the target row's `aliases` jsonb (territories/sectors/convenios already have `aliases`).
   - `resolution = new_value` → insert a new vocabulary row (the `registry:import`-owned tables) with provenance.
   - In both cases, write a provenance record (a `tag_events` row `source = admin_manual`/`ai_agent`+approver, or a dedicated note) and **resolve the originating document's `raw_unmatched_value`** so the doc becomes resolvable (its tagging can complete + verify). The `vocabulary_proposals` row → `approved`, `approved_by` set.

### 3.3 Authorization (the two-step; super_admin shortcut)

- **The AI can only *propose*** (`proposed_by_source = ai_agent`). A human always approves AI-proposed vocabulary — **two-step, never one**.
- A non-super_admin with `knowledge.edit` (e.g. `knowledge_editor`) may **propose**, but **not approve** (two-step preserved).
- A **`super_admin` may propose-and-approve** in one action (a human is still the approver — themselves).
- **New ability: `vocabulary.approve`** (super_admin only — the most-guarded action, ADR-0011). Gate the approve endpoint with `EnsureCan`/`ability:vocabulary.approve`, exactly like `ability:knowledge.edit`/`ability:admin.manage`. Proposing is gated by `knowledge.edit`. (Seed the permission additively, like `2026_06_25_100002_seed_sprint5_permissions.php` / the guardrails-manage seed.)
- The AI **never** creates vocabulary — it only flags `raw_unmatched_values` and (optionally) suggests "this looks like a variant of X." Vocabulary *creation* stays the most-guarded human action.

---

## 4. Expiry queue + lineage write-side (C)

### 4.1 The expiry queue

- **The query** (data-model §10): documents with `validity_end` approaching, `retrieval_status = active` (the prose set). Surface as `document_review_tasks` `type = 'expiry'`, `due_date = validity_end` (or a lead window, e.g. expiring within 90 days). **A queue, not a popup** — a new tab/section alongside the existing Documents table, listing the expiring doc, its window, and the successor-handoff confirmation.
- **Population** by a scheduled command (`reviews:scan-expiry` — NEW, idempotent: upsert one open `expiry` task per expiring doc; never duplicate). This is a **query + task-materialization**, no schema change to drive it. The `expiry` enum value already exists (§1.6); 7a is its first writer.
- Each `expiry` task offers the human the succession decision (§4.2). Resolving/dismissing uses the existing task `status` machinery.

### 4.2 Lineage write-side (the stub, implemented) + scope-based succession

- **AI proposes succession** (additive, optional): for an expiring/new doc, the AI may compare meaning against same-scope candidates and propose one of **successor / coexisting-sibling / conflict**. Candidates are **scope-restricted to the same `convenio`** (the roadmap rule — succession is scope-based, never topic-based). This proposal is surfaced in the expiry task, fuchsia-marked, **never applied automatically.**
- **A human confirms** *retire-or-coexist-or-escalate*. On **confirmed succession**, write the link — implement the stub:
  - Set the **successor's** `predecessor_document_id = <old doc id>` (FK already exists; this is the new write).
  - Optionally flip the **predecessor's** `retrieval_status → historical` — but **only as an explicit human action** in the same confirm (reuse `updateLifecycle`'s `confirm_scope_change` gate). The default offered is *coexist*; retire is deliberate.
  - Write provenance: a `tag_events` row recording the lineage decision (`facet = 'predecessor'` or a `lineage` note, `source = admin_manual`, actor set), and resolve the `expiry` task.
- **Never auto-retire.** Verified today: nothing auto-sets `historical` on another doc's ingest, and `predecessor_document_id` is never written. 7a adds a write path that is **only** reachable by an explicit human confirm — preserving the property. No code path sets `predecessor_document_id` or flips `historical` as a side effect of ingest or of the AI proposal.

> A new endpoint `POST /admin/documents/{uuid}/succession` (gated `knowledge.edit`) takes `{ predecessor_uuid, action: coexist|retire|escalate }`, writes the link + provenance + (on retire, with `confirm_scope_change`) the status flip, in one transaction.

---

## 5. The `--provenance-ai` fuchsia signal (D)

### 5.1 Define / re-value the token (vanilla CSS, not Tailwind — ADR-0012/0013)

The token **already exists** but at the wrong value. 7a re-values it to fuchsia-400 in `hr-frontend/src/index.css`:

```32:32:hr-frontend/src/index.css
  --provenance-ai: #7c3aed;
```
→ set light `--provenance-ai: #e879f9;` (fuchsia-400), and pick a dark-theme sibling under `[data-theme="dark"]` (currently `#b794f6`). It stays a `:root` token referenced only via `var(--provenance-ai)` — **no Tailwind, no raw hex in component rules** (the design-system rule, `index.css` header). The timeline dot is **already wired**:

```793:795:hr-frontend/src/index.css
.timeline-dot.src-ai_agent {
  background: var(--provenance-ai);
}
```

### 5.2 Where the token is used (unverified-AI **only**)

Fuchsia means exactly one thing: **"unverified AI — your attention needed."** Applied to **unverified-AI content only**, across:
- **The review/tag queue** — rows/cards with AI-proposed facets and `tagging_status = under_review` carry a fuchsia accent (a `--provenance-ai` left-border / badge), distinct from the `--warning` "under review" and `badge-conflict`.
- **Knowledge Center document cards/leaves** — a doc whose current proposal is AI-originated and unverified shows the fuchsia marker; the AI-proposed facet chips in `DocumentDetailPanel` render with the `--provenance-ai` dot (the `src-ai_agent` class already does this).
- **The proposed-vocabulary list** — `vocabulary_proposals` with `proposed_by_source = ai_agent` and `status = proposed` are fuchsia.
- **The AI-proposed-succession marker** — a successor/sibling/conflict the AI proposed but a human hasn't confirmed.

### 5.3 The verified → normal transition

On **verify** (the existing `confirm`, §1.5): `tagging_status → verified`, and the **live UI reverts to normal confirmed styling** (the fuchsia accent/border/badge is dropped). The AI origin is **not lost** — it persists as the `ai_agent` rows in the **provenance timeline** (the fuchsia `.timeline-dot.src-ai_agent` in the *history*, which is a record, not a live alert). Same for an approved vocabulary proposal (it leaves the fuchsia "proposed" list once approved) and a confirmed succession. So **fuchsia in the live UI always = work that still needs a human**; verified-AI-origin content keeps only a fuchsia history dot. This is the load-bearing semantic: the colour must not bleed onto confirmed content or it stops meaning "needs attention."

---

## 6. Migrations & build order

### 6.1 Additive migrations (hr-backend only — ADR-0007)

1. **`create_vocabulary_proposals_table`** (§3.1) — the propose→approve record.
2. **`seed_vocabulary_approve_permission`** (§3.3) — the `vocabulary.approve` ability (super_admin), spatie seed, idempotent.
- **No migration for the `ai_agent` lane** — the `tag_events.source` / `document_topics.source` enum value already exists (§1.3).
- **No migration for `predecessor_document_id`** — the column exists; 7a only adds a *writer* (§4.2).
- **No migration for the `expiry` task type** — the enum value already exists; 7a only adds a *writer* (§4.1).
- **No `hr-ai` migration** — `/propose-tags` is extract-and-return; hr-ai writes nothing (ADR-0007).

> Two migrations total, both additive. Nothing changes the shape of an existing column.

### 6.2 hr-backend (the persist side)

- `ExtractionClient::proposeTags()` — new client method (mirror `synthesise`/`route`, key in body).
- `TagProposalService` — persists AI facets as `ai_agent` `tag_events` + unverified `document_topics`, sets `tagging_confidence`, **keeps `under_review`** (§2.3).
- `ProposeDocumentTags` queued job + auto-trigger hook in `DocumentIngestor` for `reason = unresolved` (§2.4); a manual re-suggest endpoint.
- `VocabularyProposalService` + controller: propose / approve (alias|new_value) / reject; writes aliases or new vocab rows + provenance; resolves the source doc (§3). Routes gated `knowledge.edit` (propose) and `vocabulary.approve` (approve).
- `reviews:scan-expiry` command (materialize `expiry` tasks) + the succession endpoint writing `predecessor_document_id` (§4).
- Reuse `EnsureCan` + the append-only `tag_events` audit pattern throughout. The verify path is the **unchanged** `confirm()`.

### 6.3 hr-ai (the read-and-propose endpoint — no migration)

- `POST /propose-tags` in `app/main.py` (guarded by `require_internal_token`), a provider call returning the §2.2 envelope. Reuses the page text hr-backend passes; no DB access. Optionally a small `propose.py` module + provider method, paralleling `classify`/`ground`.

### 6.4 hr-frontend (review queue, verify reuse, propose-vocab UI, expiry queue, fuchsia)

- **Review queue view** (extend `DocumentsPage` or a sibling tab): the `under_review` + `unresolved` backlog sorted by `tagging_confidence`, fuchsia-marked, opening into the existing `DocumentDetailPanel`.
- **Verify** reuses the Sprint-3 **Confirm tags** button + bounded-edit controls — the human accepts/adjusts the AI-proposed facets, then confirms. No new verify mechanism.
- **Propose-vocabulary UI**: from a `raw_unmatched_value`, the variant→alias-first chooser (§3.2), with the propose vs approve actions shown per the caller's abilities (`knowledge.edit` proposes; `vocabulary.approve` approves; super_admin gets propose-and-approve in one).
- **Expiry queue**: a queue listing near-expiry docs + the succession-handoff confirm (§4).
- **Fuchsia token** re-value + apply to the four unverified-AI surfaces (§5).

### 6.5 ADR — warranted? **Yes — write one ADR.**

An ADR is warranted for the **propose-approve authorization + the AI-inert-until-verified rule**, because it is a managed-growth *and* safety decision that future sprints (7b especially) will lean on:
- The exact embedding-gate semantics (`under_review` is the inert state; AI must never write `auto_proposed`).
- The two-step vocabulary authorization (`vocabulary.approve`, super_admin propose-and-approve shortcut).
- Succession is scope-based + human-confirmed, never auto-retire; the lineage write-side is human-gated.
Proposed: **ADR-0020 — AI-proposed tagging is inert until verified; managed vocabulary approval authorization.** (7b's structured-fact agent reuses the same "inert until verified" spine, so pinning it now pays forward.)

---

## 7. Assumptions & open questions

1. **hr-ai proposal endpoint shape (§2.2).** Assumed `POST /propose-tags`, extract-and-return, key-in-body, hr-backend passes a *scoped candidate vocabulary* subset so the model binds to real IDs rather than echoing free text. **Open:** does hr-backend pass the full closed lists (small enough — ~hundreds of convenios) or a pre-filtered candidate set? Recommendation: pass the full territory/sector/document_type lists (tiny) + a candidate convenio shortlist (by any parser hint), to keep the prompt bounded while letting the model resolve.
2. **Sync ingest step vs queued job (§2.4).** Recommended **queued job** (slow LLM, batch ingest, must not block/fail ingest, matches the `embed` background posture). **Open for confirmation** — if eyes-on wants the proposal visible the instant ingest returns, a synchronous call on single-file upload is possible, but folder batches argue for the queue.
3. **Embedding-gate confirmation (§1.2).** Confirmed exact: gate = `tagging_status != under_review`; `auto_proposed` is embedded. **Decision needed (and assumed):** AI proposals **keep `under_review`** and never write `auto_proposed`. Please confirm this reading of the gate — it is the whole safety argument. (Alternative considered and rejected: tightening the gate to `= verified` would change which 42 `auto_proposed` docs are currently retrievable — a behaviour change to the frozen answer loop, out of scope.)
4. **Does the AI write the document FK columns, or only `ai_agent` `tag_events`? (§2.3)** Assumed **only `tag_events` + unverified `document_topics`** — the authoritative FKs (`convenio_id`, etc.) change only by the human verify/reassign action. This keeps the AI a strict proposer and means *no* AI write can alter scope/eligibility. **Open:** if reviewers want the AI's high-confidence facets pre-filled into the FK columns (still inert behind the gate) to make verify one click, that's a variant — but it muddies "the AI never writes authoritative scope." Recommend the strict-proposer version.
5. **Variant-vs-new heuristic (§3.2).** Assumed the AI/`VocabularyResolver` returns a `variant_of` hint with a similarity score; the UI pre-selects "fold into alias" above a threshold and defaults to "propose new" only when nothing is close. **Open:** the exact similarity signal (string/alias match vs embedding similarity) and threshold — propose starting with the existing `VocabularyResolver` alias/name matching (deterministic, no new model dependency) and letting the AI add a soft suggestion.
6. **Expiry lead window (§4.1).** Assumed "expiring within 90 days OR already past `validity_end` while still `active`." **Open:** confirm the window (and whether already-expired-but-active docs — the `date_expired_active` staleness signal from Sprint 3 — should feed the same queue).
7. **AI-proposed succession scope (§4.2).** Assumed candidates are restricted to the **same `convenio`** (different-convenio docs are never succession candidates — naturally protected today). The AI's successor/sibling/conflict suggestion is optional polish; the queue + human-confirmed write-side is the core. **Open:** is AI succession-proposal in 7a's scope, or is the human-confirmed lineage write-side (no AI suggestion) enough for 7a, deferring meaning-comparison to 7d's semantic work?

---

## 8. Hard-constraint compliance check

- **AI upstream & inert; gate preserved.** AI writes only proposals (`ai_agent` `tag_events`, unverified topics); doc stays `under_review` → 0 chunks → unretrievable until human verify. Gate `tagging_status != under_review` untouched. ✔
- **No answer-loop change (2b frozen).** No change to `/retrieve`/`/route`/`/synthesise`/`/ground` or `ChatService`. `/propose-tags` is a separate upstream call. ✔
- **Document-level facet tagging only — no fact-segmentation.** `/propose-tags` returns one document's facets; multi-scope segmentation is explicitly 7b. ✔
- **AI never creates vocabulary.** It flags `raw_unmatched_values` + variant hints; growth is the propose→approve flow (variant→alias default); approve is human (`vocabulary.approve`), super_admin may propose-and-approve. ✔
- **hr-backend owns all writes + schema; additive migrations only; hr-ai never migrates.** Two additive migrations (proposal table + permission); hr-ai is extract-and-return. ✔
- **Succession scope-based + human-confirmed, never auto-retire.** Lineage write-side only via explicit human confirm; same-convenio candidates; no ingest side-effect. ✔
- **`--provenance-ai` = vanilla-CSS token, fuchsia `#e879f9`, unverified-AI only; verified → normal + history dot.** Re-value the existing token; apply to the four surfaces; revert on verify. ✔
- **Reuse design system + Sprint-3 review/confirm + provenance timeline + `EnsureCan` + audit.** Verify = existing `confirm()`; provenance via `tag_events`; gating via `EnsureCan`/`ability:*`. ✔

---

**Status: ready for review.** No tagging/vocabulary/expiry code written. On approval, build order: (1) the two migrations, (2) `/propose-tags` + `ExtractionClient` + `TagProposalService` + auto-trigger, (3) the vocabulary-proposal flow, (4) the expiry queue + lineage write-side, (5) the frontend (review queue, verify reuse, propose-vocab UI, expiry queue, fuchsia token), (6) the ADR + docs updates. Then write `hr-docs/sprints/sprint-07a/review.md` and stop for eyes-on — no commit until reviewed.
