# Sprint 3 — Cursor build-authorization prompt

> Paste the block below into the Sprint 3 Cursor thread (the one that wrote `plan.md`). The plan + substrate inspection are approved; this authorizes the **build**. Every `plan.md` §8.3 question is resolved below. Build in the §8.1 order, update the named docs, write `review.md`, and **STOP — do not commit.**

---

The Sprint 3 plan (`hr-docs/sprints/sprint-03/plan.md`) is approved — the provenance spine (`tag_events`) is confirmed real and populated, no migration is required, and the build extends existing patterns (`reassignFacet` provenance, `DocumentDetailPanel`, `pageImage()` presigned URLs). Build it in the §8.1 order. All `plan.md` §8.3 questions are resolved as follows.

## Resolved open questions (apply exactly)

1. **Q1 — graph rendering: hand-rolled SVG** (absolutely-positioned node boxes + SVG connector overlay), no new dependency (ADR-0012). The two-level + lazy shape keeps the drawn graph small; a layout lib is not justified.
2. **Q2 — topics editable, as planned.** The topic lens ships **empty-honest** ("No topics tagged yet — arrives with the AI tier, Sprint 7"); Sprint-3 human topic-tagging is the **first writer** of `document_topics` + `topic` provenance; **AI topic proposal stays Sprint 7**.
3. **Q3 — role seeding: approved.** Seed an `auditor` and a `knowledge_editor` admin from env (`SEED_AUDITOR_EMAIL` / `SEED_EDITOR_EMAIL`, mirroring `SEED_ADMIN_EMAIL`); add a `knowledge.edit` ability (granted `super_admin` + `knowledge_editor`, denied `auditor`/`hr_agent`) seeded in `RoleSeeder`; gate the **write** routes only via `EnsureCan('knowledge.edit')`. Reads (hierarchy, card, source viewer, sandbox) stay open to any admin.
4. **Q4 — sandbox: dedicated read-only `/sandbox-retrieve` AND keep `SandboxService` independent — do NOT refactor `ChatService`.** Add the additive, read-only hr-ai endpoint that ranks `document_chunks WHERE document_id = :id` (the employee `/retrieve` is **not** modified — it never passes `document_id`); `SandboxService` orchestrates retrieve → `/synthesise` → `/ground` with the same per-call answer-model key path, persisting **nothing**. **Do not extract a shared helper out of `ChatService`** — the 2b answer loop is frozen/durably-closed; accept the small orchestration duplication in `SandboxService` rather than touch the legal-weight path. (If sandbox-vs-real drift ever matters, a careful shared-helper refactor is its own later change.)
5. **Q5 — chunk-health: omit the es/eu split** (no `language` column on `document_chunks`; persisting it is an out-of-scope hr-ai change). Show count / token total / page span / embed-presence / 0-chunk flag, with a one-line note.
6. **Q6 — salary-mistag: approved.** Conservative `convenio_text + active + (title|source_filename ILIKE '%tabla%')` → flagged **suspected** mistag, inviting the §5 bounded retag; **never auto-applies** (ADR-0011 posture, human decides).

## Required verification — coverage-gap queries against the REAL data (do not skip)
The §3 queries are derivations (good), but the **ids listed in the plan are illustrative against a dev corpus**. Run the three queries (§3.1 active-0-chunk, §3.2 expired-no-active-successor, §3.3 suspected-mistag) **against the actual DB** and **report the real flagged set** in `review.md`, with this sanity check:
- A document that is **`retrieval_status = active`** (and was re-chunked in **2c**, e.g. ids 45/51/75/93/29) must **NOT** appear as **expired-no-successor**. If any does, the `retrieval_status`-vs-`validity_end` semantics are conflated — **report it and reconcile**: an `active` doc whose `validity_end` is past is a **staleness** signal, **not** a coverage *hole* (the scope is still answerable). §3.2's "no active prose doc" hole and a "date-expired-but-active" staleness flag are **distinct** — keep them distinct (a hole is `--warning`/`--danger`; staleness is `--neutral`), and do not let the map label an answerable scope as a gap. If a date-expired-but-active staleness flag is trivial to add, add it as its own `gap_kind`; otherwise note it as a follow-up.

## Build (the §8.1 order, hard constraints enforced)
- **hr-backend reads:** lens/hierarchy queries (`GET /admin/hierarchy` + `/children`, lazy), `GET /admin/coverage-gaps`, extend `show()` (lineage relations, `chunk_health` raw aggregate, topics + provenance, gap/unscoped flags), `GET …/source` (presigned, mirroring `pageImage()`).
- **hr-ai:** the additive, read-only `POST /sandbox-retrieve` only — **the employee `/retrieve` and the whole answer loop are untouched**, `hr-ai` still writes only `document_chunks` + S3 and never migrates.
- **hr-backend sandbox + edit:** `SandboxService` (independent, persists nothing); the bounded-edit write paths (topics add/remove first writes `document_topics`; `PATCH …/{uuid}` for validity/`retrieval_status`/`tagging_status`; reuse the existing facet PATCH for convenio/document_type), each in a `DB::transaction`, each **appending an `admin_manual` `tag_events` row (old→new, actor_id, never UPDATE/DELETE)**; the `scope_affecting` gate requiring `confirm_scope_change=true` (409/422 without) on a convenio reassign / `retrieval_status` flip / validity edit.
- **hr-frontend (last):** `api.ts` types/functions; the `<Hierarchy>` component (list + graph forms, lens config, lazy, coverage-gap nodes) in a new **Knowledge → Map** view; the document card (extend `DocumentDetailPanel` — provenance timeline with the reserved `ai_agent` dot, chunk-health, lineage, the real-document viewer, the sandbox panel); the edit UI (FK pickers, the scope-warning modal, the id-94 retag flow); **role-gated edit affordances** (hidden/disabled without `knowledge.edit`, color-and-text not color-only).

## Hard constraints (carry)
- **No vocabulary restructuring** through the UI — FK pickers into **existing** vocabulary only; **territory/sector not editable** (derived); no create/rename/merge/re-scope; topic *approval* stays Sprint 7 (only existing approved topics are pickable). That stays in `registry:import` (ADR-0011).
- **No re-ingestion / re-embedding / OCR** from the UI — the card *shows* chunk health, never re-chunks; the sandbox is **read-only**, persists nothing, **no answer-loop change**.
- **`hr-backend` owns all writes + schema; additive migrations only** (confirmed **none needed** — flag immediately if the build discovers one is). `hr-ai` read-only. **Every label edit appends `human` provenance, never rewrites history.**
- Reuse the **design system** (`design-system.md` + ADR-0012/0013) and existing components; the only new visual primitive is the reserved `ai_agent` timeline-dot color.

## Eyes-on (report in review.md; Pedram runs live)
- Navigate **all four lenses in both forms** (graph + list), expand/collapse, lazy children, leaf-opens-card.
- **Coverage-gap nodes** render flagged (the real set from the verification above), distinguishing unanswerable / expired-no-successor / suspected-mistag / unscoped — and an answerable (2c-active) scope is **not** mislabeled as a gap.
- Open the **Navarra Limpieza** card → provenance timeline (parse→confirm), chunk-health, lineage, the **real PDF** in the viewer, and run the **scoped sandbox** on it.
- **Retag id 94** (`document_type` → salary-table) → the **system-behavior warning** shows, the save requires confirmation, and the edit lands as an **`admin_manual` entry in that document's timeline**.
- An **`auditor`** can browse + inspect + run the sandbox but **cannot edit** (no edit affordances); a `knowledge_editor`/`super_admin` can.

## Docs at close
`architecture.md` (the Knowledge Center surface + the bounded-edit/append-only-provenance rule + the vocabulary-restructuring-stays-in-import boundary + the sandbox-is-read-only/ChatService-frozen note), `data-model.md` (no new column; the `tag_events` facet-string conventions for the new editable facets; the coverage-gap derivations), `roadmap.md` (Sprint 3 done; AI topic tagging → 7, language-split-in-chunks deferred, the directory/role-mgmt → 5), the frontend README. Write `hr-docs/sprints/sprint-03/review.md` (the coverage-gap real-data report, the build, the eyes-on results). Then **STOP — do not commit until I review.**
