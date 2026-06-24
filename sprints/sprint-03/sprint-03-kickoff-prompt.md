# Sprint 3 — Cursor kickoff prompt (plan-gate)

> Paste the block below into a **fresh** Cursor thread (the `hr-platform/` workspace). It must **inspect the substrate, plan, and stop** — no UI code until the plan is reviewed.

---

You are in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprints 0–2c are built and committed (the answer engine is complete; 2b is durably closed). **This is Sprint 3 — the Knowledge Center (lens-hierarchy admin UI)** — the first admin/console sprint, the visibility-and-labeling payoff of the Sprint 1 faceted model.

Before anything, read in full:
- `hr-docs/architecture/data-model.md` — Group A (vocabulary/registry: `territories`, `sectors`, `convenios`, `convenio_job_categories`, `document_types`, `topics`), the `documents` table (`tagging_status`, `tagging_confidence`, `retrieval_status`, validity, lineage, `authority_level`), `document_topics` **and its provenance**, and the rule **"document scope is derived via the convenio"** + its known limitation
- **ADR-0001** (faceted scoping, rendered as lenses), **ADR-0011** (managed vocabulary growth — vocabulary changes are deliberate/logged, never a tagging side-effect), ADR-0012/0013 (the design system)
- the **Sprint 1 review** (the faceted restructure: `provinces`→`territories`, the registry import, the provenance model) and `roadmap.md` Sprint 3
- `hr-docs/deploy.md` §5 (the coverage gaps to surface)
- `hr-docs/sprints/sprint-03/spec.md`
- the `frontend-design` skill, the design-system tokens, and the existing `hr-frontend` structure (the chat UI, `api.ts`, `CitationList`, components)

Your task this turn is to **inspect the real substrate and plan — write no UI/API code.**

Produce `hr-docs/sprints/sprint-03/plan.md`, then **STOP and wait for review.** The plan must cover:

1. **Provenance reality check (do this first — it's the spine).** Confirm against the actual schema + seeded data: **does the tag-provenance history actually exist and is it populated** (who set each facet, how — filename/AI/human — when, confidence) from the Sprint 1 registry import + 2a tagging? Show the real table(s)/columns and a sample row. If provenance is thinner than the spec assumes, say so now — the change-log timeline depends on it. Define exactly how a **Sprint-3 human edit appends** a provenance entry (append-only, old→new, who/when).
2. **Read API + the lens queries.** The `hr-backend` endpoints to drive the hierarchy: how each **lens** (province/sector/validity/topic) is queried from facets, two-level + lazy children, and the leaf→document-card payload (facets+provenance, validity/status/lineage, chunk/health from `document_chunks`, the change-log). Reuse, don't duplicate, the scope/registry queries.
3. **Coverage-gap detection.** How the map flags **active-but-0-chunk** (scanned/unanswerable) and **expired-no-active-successor** scopes, and the **salary-table mistag** (id 94) — derived from existing data (deploy.md §5), not a new pipeline.
4. **The document card.** The component: facet tags w/ inline provenance, validity+countdown, retrieval status, authority, lineage, tagging status/confidence, chunk/health, the **change-log timeline** (automation/agent/human), the **real-document viewer** (serving the S3 source — state the mechanism), and the **read-only scoped sandbox** (reuse the answer pipeline against one document, persist nothing).
5. **Bounded edit (write path).** The edit API for the allowed facets only — `convenio`, `document_type`, `topics`, `validity_*`, `retrieval_status`, `tagging_status` — via **FK pickers from existing vocabulary** (no free-text, no vocabulary creation); territory/sector **not** editable (derived). The **system-behavior warning** on a scope-affecting save; the **append-only `human` provenance write**. All writes `hr-backend`; confirm whether any **additive migration** is needed (e.g. a generic edit/provenance log) or the existing provenance tables suffice.
6. **The reusable hierarchy component (frontend).** The single component in **branching-graph** and **indented-list** forms, lens-driven, expand/collapse, lazy load, leaf-opens-card — on the design system (`frontend-design`). Note the graph-rendering approach.
7. **Roles/access.** How the Sprint 0 seeded roles gate this surface (read-only `auditor` browses+inspects, editor edits labels) — using what exists; full role management is Sprint 5.
8. **Build order, assumptions & questions** — across `hr-backend` (read API, lens queries, edit API, coverage-gap queries), `hr-frontend` (hierarchy component, card, sandbox, edit UI), and any additive migration. Flag anything the schema/provenance reality leaves ambiguous.

Hard constraints:
- **Vocabulary restructuring is out of scope** — no creating/renaming/merging/re-scoping convenios/territories/sectors, no approving topics, through the UI (that's `registry:import`, ADR-0011). The UI labels documents *into* existing vocabulary via FK pickers only.
- **No re-ingestion / re-embedding / OCR** from the UI; the card *shows* chunk health, never re-chunks. The sandbox is **read-only** reuse of the answer pipeline (nothing persisted) — **no answer-loop change**.
- **`hr-backend` owns all writes + the schema** (additive migrations only, if any); `hr-ai` is read-only here (sandbox). Territory/sector are **derived, not editable**. Every label edit **appends** `human` provenance — never rewrites history.
- Reuse the **design system** (ADR-0012/0013) and existing frontend patterns; follow `frontend-design`.

Do not create or modify any file other than `hr-docs/sprints/sprint-03/plan.md` this turn. After writing it, stop and say it is ready for review.
