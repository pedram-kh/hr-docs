# Sprint 3 — Knowledge Center (lens-hierarchy admin UI)

> Location: `hr-docs/sprints/sprint-03/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `data-model.md` (Group A vocabulary/registry — `territories`/`sectors`/`convenios`/`convenio_job_categories`/`document_types`/`topics`; the `documents` table incl. `tagging_status`/`tagging_confidence`/`retrieval_status`/validity/lineage; `document_topics` + **provenance**; the "document scope is derived via the convenio" rule and its known limitation), **ADR-0001** (faceted scoping, lens-rendered), **ADR-0011** (managed vocabulary growth — vocabulary changes are deliberate/logged, never a tagging side-effect), `roadmap.md` Sprint 3, the Sprint 1 review (the faceted restructure), and `deploy.md` §5 (the coverage gaps to surface).
> **This is the first admin/console sprint** — the visibility-and-labeling payoff of the Sprint 1 faceted model. Lower legal-weight than the answer engine, **but** it can write scope-affecting labels, so it is audit-first throughout.

## Goal
Give an admin a navigable **map of the scoped corpus** — browse the hierarchy through any lens, open any document as a rich card, see exactly **how every tag got there** (automation / agent / human), view the real source document, and **fix a document's labeling where it's wrong or missing** — with a clear system-behavior warning and every edit written to the append-only provenance history. Coverage gaps (scanned-unanswerable, expired-no-successor) become **visible holes in the map** instead of a deploy.md footnote.

## In scope

### A. One reusable hierarchy component, two forms (ADR-0001)
- A single component rendered from a **lens definition**, in two interchangeable forms: **branching-graph** and **indented-list**.
- **Lenses:** by province (territory), by sector, by validity, by topic.
- **Two-level default, expand/collapse, lazy load** (don't draw the whole corpus at once — fetch children on expand). **Leaf opens the document card** (C).
- The hierarchy is rendered *from facets*, never a stored tree (it's a view of the faceted model — same scoping the answer engine uses).

### B. Coverage gaps visible in the map
- Surface the gaps already logged in `deploy.md` §5 as **first-class, visible nodes/markers**: convenios/scopes that are **active but unanswerable** (scanned PDFs with no extractable text → 0 chunks), and **expired with no active successor**. A scope with no usable document reads as a flagged hole, not an empty branch the admin has to notice is missing.
- Also surface the **salary-table mistag** class (id 94 — a `convenio_text`/`active` row that is really a salary-table PDF) as a flagged node inviting a retag (the bounded edit in D).

### C. The document card (leaf)
Opening a leaf shows, for that document:
- **Facet tags with inline provenance** — convenio, territory, sector, document type, topics — each showing **who set it, how (filename-parse / AI / human), when, with what confidence** (from the tag provenance history).
- **Validity window + expiry countdown**, **retrieval status** (draft / active / historical), **authority level**, **version lineage** (predecessor / successor), **tagging status** (`auto_proposed` / `under_review` / `verified`) + `tagging_confidence`.
- **Chunk/health summary** — chunk count, language split (es/eu), embed status — enough to spot a 0-chunk (unanswerable) or anomalously-thin document.
- **The change-log timeline** — the append-only provenance history rendered chronologically: each tag's origin and every change since, labelled **automation (filename/registry import) · agent (AI tagging) · human (an edit made here)** — the single place to answer "how did this document end up in this scope, and who changed it?"
- **The real document viewer** — view the actual source PDF/page in the card (read the S3-stored source).
- **"Test a question against this document" sandbox** — run a question scoped to just this document and see what it would answer/cite (reuses the answer pipeline read-only; no turn persisted).

### D. Bounded label editing (warned + human-logged)
- **Editable, by design, only the re-assignable document facets** (per the data-model rule): **`convenio`**, **`document_type`**, **`topics`**, **`validity_start`/`validity_end`**, **`retrieval_status`**, **`tagging_status`**. Territory/sector are **not** editable — they derive from the convenio (a document-vs-convenio scope contradiction stays structurally impossible).
- **Convenio/document_type are chosen from the existing controlled vocabulary** (FK pickers) — never free-text, never creating vocabulary. (Creating/renaming/re-scoping convenios/territories/sectors stays in the deterministic `registry:import` path, ADR-0011 — **out of scope here**, see Out of scope.)
- **System-behavior warning before save:** any scope-affecting edit (esp. reassigning `convenio` or flipping `retrieval_status`) shows a clear warning that it **changes which employees get this document as an answer** and must be confirmed.
- **Every edit is written to the append-only provenance history as a `human` change** (who, when, old→new), appearing in the same change-log timeline (C) as the automation/agent entries. Edits **never** rewrite history — they append.
- All writes are **`hr-backend`** (system of record); the UI calls the API, `hr-ai` is not involved except the read-only sandbox.

## Out of scope (do NOT build)
- **Vocabulary restructuring** — creating/renaming/merging/re-scoping convenios, territories, sectors, or creating approved topics through the UI. That is the deterministic, logged `registry:import` path (ADR-0011). The UI labels documents *into* existing vocabulary; it does not edit the vocabulary.
- **Re-ingestion / re-embedding / OCR** from this UI (uploading the corpus + processing is the deploy-time admin-upload flow; OCR for the scanned docs is the deploy.md item). The card *shows* chunk health; it doesn't re-chunk.
- The **escalation board / knowledge-article flow** (Sprint 4), the **directory/roles UI + role-scoped access** (Sprint 5 — Sprint 3 uses the Sprint 0 seeded roles for access; full role management is later), **guardrails config** (Sprint 6), the **LLM tagging tier / review queue** (Sprint 7 — Sprint 3 *shows* `tagging_status`/confidence and lets a human verify, but the AI tagging tier that populates `auto_proposed` is Sprint 7), **analytics** (Sprint 8).
- Any **answer-loop change**. The sandbox is read-only reuse.

## Acceptance criteria
1. The hierarchy renders from facets in **both** graph and list forms, switchable, with the four lenses (province/sector/validity/topic), two-level default, expand/collapse, lazy children, leaf-opens-card.
2. Coverage gaps (scanned-unanswerable, expired-no-successor) and the salary-table mistag are **visibly flagged** in the map.
3. The document card shows all facets **with inline provenance**, validity+countdown, status, authority, lineage, chunk/health, the **change-log timeline** (automation/agent/human), the **real document**, and the **scoped sandbox**.
4. Bounded edit works for the allowed facets only (FK pickers, no free-text vocabulary); territory/sector are not editable; **every edit appends a `human` provenance entry** and surfaces in the timeline; a **system-behavior warning** gates a scope-affecting save.
5. No vocabulary creation/restructuring is possible from the UI; no re-ingestion/OCR; no answer-loop write.
6. Access respects the Sprint 0 seeded roles (a read-only `auditor` can browse + inspect but not edit; an editor can edit labels).
7. All writes are `hr-backend`; the schema is unchanged or extended only by additive migration if the provenance/edit log needs a field (state it in the plan).

## Eyes-on
Navigate each lens in both views; open the Navarra Limpieza card (provenance timeline shows the 2c re-chunk's chunk health + the tag history); open a coverage-gap node (a scanned convenio → flagged unanswerable); **retag id 94** convenio→salary-table/type with the warning shown and confirm the edit lands as a `human` entry in its timeline; open the real PDF in a card; run the scoped sandbox on one document; confirm an `auditor` role can browse but not edit.

## Risks / notes
- **The "scope rides on convenio" limitation** (data-model): a territory-scoped non-convenio doc can't carry scope. The corpus has no such case; the card should **show** the limitation gracefully (a no-convenio non-national doc, if one ever appears, is flagged, not silently mis-scoped) rather than solve it here.
- **Provenance is the spine of this sprint** — the change-log timeline is only as good as the existing tag-provenance history; the plan must confirm the provenance records (filename/AI/human, when, confidence) are actually populated from Sprint 1/2a so the timeline isn't empty, and define how a Sprint-3 human edit is appended.
- **Lazy-load + lens rendering** is the main frontend complexity; the graph form especially. Reuse the design system (ADR-0012/0013); follow `frontend-design`.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` (the Knowledge Center surface + the bounded-edit/provenance-append rule + the vocabulary-restructuring-stays-in-import boundary), `data-model.md` (any additive provenance/edit-log field), `roadmap.md` (Sprint 3 done; note what's deferred to 5/7), and the frontend README. Cursor writes `hr-docs/sprints/sprint-03/review.md` and **stops — no commit until I review**.
