# Sprint 3 — Knowledge Center · Review

The lens-hierarchy admin UI built to `plan.md` (approved), with the §8.3 open
questions resolved as instructed (Q1 hand-rolled SVG, Q2 topics editable +
empty-honest, Q3 role seeding + `knowledge.edit`, Q4 dedicated `/sandbox-retrieve`
+ independent `SandboxService`, Q5 chunk-health no es/eu split, Q6 conservative
salary-mistag heuristic). No migration was required — the `tag_events` provenance
spine and `document_topics` table already existed; Sprint 3 is the first **writer**
of `document_topics`.

> **Status:** built and self-tested (DB queries, tinker, and over-the-wire HTTP).
> The live eyes-on pass (below) is for Pedram to run. **Not committed.**

---

## 1. Coverage-gap real-data report (the required verification)

The §3 queries are derivations over existing data (no scan, no pipeline). Run
against the **actual** dev DB (`hr_platform`, 99 ingested docs). These are the
real flagged sets — the ids in `plan.md` §3 were illustrative; **these supersede
them**.

### 3.1 — Active but 0-chunk (unanswerable) — **7 documents**

Prose-type (`convenio_text` / `national_law` / `partial_agreement`), `active`,
with **0** rows in `document_chunks`.

| id | title | tagging_status | why |
|----|-------|----------------|-----|
| 24 | 28102145012018 COEAS Madrid 2023 2027 | under_review | not yet embedded (under_review excluded from embed) |
| 28 | Plan Igualdad texto | under_review | no convenio + under_review |
| 32 | ACUERDO PARCIAL COEAS ANDALUCIA Alhambra | under_review | under_review |
| 34 | Acuerdo fin de huelga UBIK - SEDENA SL 2019-20 | under_review | under_review |
| 72 | 99100055012011 COEAS Estatal 2025 2027 | under_review | under_review |
| 89 | CONVENIO DEPORTE NAVARRA 2025 A 2028 | under_review | scanned / pending |
| 91 | PACTO CULTURA NAVARRA | under_review | scanned / pending |

All 7 are `under_review` — i.e. they are honestly *not yet retrievable*. The map
flags them `--danger` (unanswerable): an `active` retrieval_status with no chunks
is a real "scanned/unanswerable" hole.

### 3.2 — Expired with no active successor (coverage hole) — **9 convenios**

A convenio that has **historical** prose but **no active** prose — the scope has
no current document to answer from.

| convenio_id | numero | territory | sector |
|----|--------|-----------|--------|
| 4 | 71103505012022 | Andalucía | OCIO EDUCATIVO Y ANIMACION |
| 5 | 33000325011978 | Asturias | DEPORTE |
| 9 | 99015105012005 | Estatal | INSTALACIONES DEPORTIVAS Y GIMNASIO |
| 14 | 20100025012011 | Gipuzkoa | INTERVENCION SOCIAL |
| 16 | 22000175012004 | Huesca | HOSTELERIA Y TURISMO HUESC |
| 20 | 31008235012003 | Navarra | GESTIÓN DEPORTIVA |
| 21 | 31003805011981 | Navarra | HOSTELERIA |
| 24 | 37000375011982 | Salamanca | OFICINAS Y DESPACHOS |
| 26 | 48006185012006 | Vizcaya | INTERVENCION SOCIAL |

The map flags these `--warning` and surfaces them at the territory/sector group
node (a territory whose convenio is in this set is badged `expired_no_successor`).

### 3.3 — Suspected salary-table mistag — **1 document**

`convenio_text` + `active` + title/filename matches `%tabla%` (conservative, Q6).

| id | source | rs | tagging_status |
|----|--------|----|----------------|
| 94 | `31005105011984_OFICINAS Y DESPACHOS NAVARRA_Tabla 2025.pdf` | active | auto_proposed |

The map flags it `--warning` (suspected mistag) and the card invites the §5
bounded retag → **Tablas salariales**. It never auto-applies (ADR-0011 posture —
the human decides).

### Sanity check — staleness vs hole (the required reconciliation)

> *"A document that is `retrieval_status = active` (and was re-chunked in 2c, e.g.
> ids 45/51/75/93/29) must NOT appear as expired-no-successor."*

**Confirmed clean.** The "active prose doc whose `validity_end` is past" query
returns **0 rows** in this corpus — so the conflation the warning guards against
does **not** manifest. Cross-checking the named ids:

| id | retrieval_status | so it is… |
|----|------------------|-----------|
| 45, 51, 75, 93, 29 | **historical** | correctly surfaced via their convenios in §3.2; **none** is an active doc mislabeled as a gap |
| 72, 24, 89, 91 | active | correctly in §3.1 (unanswerable, 0-chunk) — not in §3.2 |

So no answerable (2c-active) scope is mislabeled as a gap.

**I kept the staleness flag distinct anyway, as its own gap_kind** (it was trivial
to add): `date_expired_active` — an `active` prose doc with a past `validity_end`.
It renders `--neutral` (a staleness signal, the scope is still answerable), never
`--warning`/`--danger`. It is **empty today** but the distinction is now first-class
in `coverage-gaps`, so it can never be conflated with a hole.

### Notable interaction (worth flagging)

The id-94 mistag currently **masks** a coverage hole: convenio **23** (Navarra,
Oficinas y Despachos) has only one `active` prose doc — id 94 — which is really a
salary table. Because it is *tagged* `convenio_text` + `active`, convenio 23 does
**not** appear in §3.2. **Retagging id 94 → salary table will reveal convenio 23
as expired-no-successor** (its real prose, id 93, is historical). This is correct
behavior — the bounded retag both fixes the type and unmasks the true gap — and a
good thing to observe live during the eyes-on retag.

---

## 2. The build (in the §8.1 order, hard constraints enforced)

### hr-backend — reads
- **`KnowledgeMap` support** (`app/Support/KnowledgeMap.php`): one source of truth
  for the prose-type set, `chunkHealth()`, and `coverageGaps()` (the four kinds).
- **Hierarchy** (`HierarchyController`): `GET /admin/hierarchy?lens=` (roots) and
  `GET /admin/hierarchy/children?lens=&parent=` (lazy). Four lenses
  (territory / sector / validity / topic), two-level + lazy, leaf = a document.
  Synthetic territory nodes for **national law** (no territory) and **unscoped**
  (the scope-rides-on-convenio limitation). Coverage-gap markers overlaid on nodes.
- **Coverage gaps** (`CoverageGapController`): `GET /admin/coverage-gaps`.
- **`show()` extended**: `topics` (+ provenance source), `lineage`
  (predecessor / successors), `chunk_health` (raw aggregate), `is_unscoped` flag.
- **Source viewer**: `GET /admin/documents/{uuid}/source` — presigned S3 URL,
  mirroring `pageImage()` (10-min temporary URL, browser fetches S3 directly).

### hr-ai — additive, read-only
- **`POST /sandbox-retrieve`** only (`main.py` + `chunks_db.retrieve_by_document`):
  ranks `document_chunks WHERE document_id = :id`. The employee `/retrieve` and
  the whole answer loop are **untouched** (they never pass `document_id`). hr-ai
  still writes only `document_chunks` + S3 and never migrates. Verified live
  (HTTP 200, ranked chunks with scores).

### hr-backend — sandbox + bounded edit
- **`SandboxService`** (independent; **does not** touch `ChatService`): orchestrates
  `/sandbox-retrieve` → `/synthesise` → `/ground` with the same per-call
  answer-model key path, **persisting nothing** (verified: `chat_messages` /
  `escalation_cards` counts unchanged). Accepts the small orchestration
  duplication rather than refactor the frozen 2b loop (resolved Q4). On escalate
  it surfaces the model's **draft** + which gate stopped it (informational only).
- **Bounded-edit writes** (each in `DB::transaction`, each appending an
  `admin_manual` `tag_events` row — old→new, `actor_id`, never UPDATE/DELETE):
  - topics add/remove → first writer of `document_topics`;
  - `PATCH /admin/documents/{uuid}` (`updateLifecycle`) for validity /
    retrieval_status / tagging_status;
  - existing facet `PATCH …/facets/{facet}` reused for convenio / document_type.
- **Scope-affecting gate** (`confirm_scope_change=true`, else **409**): convenio
  reassign, retrieval_status flip, validity edit. `tagging_status` and a
  `document_type` retype are not scope-gated server-side (a `document_type` →
  salary-table change still warns in the **UI** because it moves the doc to the
  SQL path).
- **Roles/access**: `knowledge.edit` ability seeded in `RoleSeeder` (granted
  super_admin + knowledge_editor; denied auditor + hr_agent); write routes gated
  by `ability:knowledge.edit` (new `EnsureCan` middleware); reads open to any
  admin. `Admin` now implements `Authorizable` so `$admin->can('knowledge.edit')`
  works and is surfaced on `/me` as `abilities`. `TestUserSeeder` seeds an
  env-driven `SEED_EDITOR_EMAIL` (knowledge_editor) and `SEED_AUDITOR_EMAIL`
  (auditor).

### hr-frontend (last)
- **`api.ts`**: hierarchy / coverage-gap / source / sandbox / lifecycle / topic
  types + functions; `abilities` on `Identity` + `canEditKnowledge()`;
  `reassignFacet` extended with `confirmScopeChange`.
- **`<Hierarchy>`** (`Hierarchy.tsx`): one component, two forms — an indented
  **list** and a hand-rolled **SVG graph** (absolutely-positioned HTML node boxes
  + an SVG connector overlay with deterministic coordinates, no layout lib —
  Q1). Lens-driven, lazy children, leaf-opens-card, coverage-gap node badges.
- **Knowledge → Map** (`KnowledgeMapPage.tsx`, new top-level admin view): lens +
  graph/list segmented controls, a coverage-gap summary bar, the hierarchy, and
  the document card.
- **Document card** (`DocumentDetailPanel` extended): topics (+ provenance dot),
  chunk-health, lineage, the provenance timeline (now with old→new and the
  reserved **`ai_agent`** dot color), the real-PDF viewer, the read-only sandbox
  panel, and the bounded-edit UI (FK pickers, the scope-warning modal, the id-94
  retag flow).
- **Role-gated affordances**: edit controls render only with `knowledge.edit`;
  an auditor sees a "read-only" notice and **color-and-text** state (not
  color-only). Derived facets (territory/sector) are labelled "(derived)" and
  never editable.
- **Design system**: reused tokens/components (ADR-0012/0013). The only new visual
  primitive is the reserved `--provenance-ai` timeline-dot color.

### Self-test evidence
- Coverage-gap counts via `KnowledgeMap` match the raw SQL exactly
  (7 / 9 / 1 / 0).
- Write paths (tinker, rolled back — nothing persisted): scope-affecting save
  without confirm → **409**; with confirm → **200** + appended `admin_manual`
  event (old→new, actor); `addTopic` → writes `document_topics`; `document_type`
  retype (non-scope) → 200; convenio reassign without confirm → 409.
- Ability gate: super_admin / knowledge_editor `can('knowledge.edit')` = true;
  auditor / hr_agent = false.
- Over-the-wire (HTTP): `/me` abilities correct per role; hierarchy + gaps +
  presigned source OK; scope-gate **409**; auditor write **403**; sandbox runs
  and **persists nothing** for both editor and auditor.
- Frontend `tsc --noEmit` clean; all new/edited Sprint-3 files pass `eslint`.
  (One pre-existing `eslint` error remains in `DocumentsPage.tsx:33` — a Sprint-1
  `useEffect(refresh, …)` pattern flagged by the newer react-hooks rule; not
  touched by Sprint 3, left in place to respect scope.)

---

## 3. Eyes-on (Pedram runs live)

Log in as the seeded admins (request an OTP for `SEED_EDITOR_EMAIL` /
`SEED_AUDITOR_EMAIL` / `SEED_ADMIN_EMAIL`). Frontend dev server is already up.

- [ ] **Lenses × forms.** Knowledge → Map. Switch all four lenses (Territory /
  Sector / Validity / Topic) in both **Graph** and **List** forms. Expand/collapse,
  lazy children load, a leaf opens the card. The **Topic** lens is empty-honest
  ("No topics tagged yet — arrives with the AI tier, Sprint 7").
- [ ] **Coverage-gap nodes render flagged** (the real set above), distinguishing
  unanswerable (`--danger`) / expired-no-successor (`--warning`) / suspected-mistag
  (`--warning`) / unscoped (`--neutral`) — and confirm an answerable (2c-active)
  scope is **not** mislabeled as a gap.
- [ ] **Navarra Limpieza card** (id 95). Provenance timeline (parse → confirm),
  chunk-health (75 chunks, ~30.5k tokens, pages 1–53, embeddings present),
  lineage, the **real PDF** in the viewer, and run the **scoped sandbox** on it
  ("¿cuántos días de vacaciones tengo?"). *Note: the sandbox uses single-document
  retrieval (no recall-hardening union), so its decision can differ run-to-run and
  from the employee loop; both `answer` (37 días) and an honest `escalate` (a
  secondary claim ungrounded in this doc's top-k) are correct outcomes — the draft
  + the stopping gate are shown either way.*
- [ ] **Retag id 94** (`document_type` → **Tablas salariales**). The system-behavior
  warning modal shows, the save requires confirmation, and the edit lands as an
  `admin_manual` entry in that document's timeline. **Then re-open the Territory
  lens** and confirm convenio 23 (Navarra · Oficinas y Despachos) now surfaces as
  `expired_no_successor` (the mistag was masking it).
- [ ] **Roles.** As `auditor`: browse + inspect + run the sandbox, but **no edit
  affordances** (read-only notice shown). As `knowledge_editor` / `super_admin`:
  edit controls present and writes succeed.

---

## 4. Constraints honoured

- No vocabulary restructuring through the UI — FK pickers into existing vocabulary
  only; territory/sector not editable (derived); topic picker offers only
  `approved` topics (creation/approval stays in `registry:import`, ADR-0011).
- No re-ingestion / re-embedding / OCR from the UI — the card shows chunk health,
  never re-chunks; the sandbox is read-only and persists nothing; no answer-loop
  change.
- hr-backend owns all writes + schema; **no migration needed** (confirmed — flag
  was never tripped). hr-ai read-only (one additive endpoint).
- Every label edit appends human provenance; history is never rewritten.

## 5. Follow-ups (not this sprint)
- AI topic proposal + `ai_agent` provenance rows → **Sprint 7** (the reserved dot
  is ready).
- Language (es/eu) split on `document_chunks` → deferred (out-of-scope hr-ai
  change; chunk-health omits it with a one-line note).
- Full role/permission + directory management → **Sprint 5**.
- The `date_expired_active` staleness gap_kind is wired but empty today; it will
  populate as active docs age past their `validity_end`.
