# Sprint 1 — Cursor kickoff prompt

> Paste the block below into Cursor with the `hr-platform/` workspace open. As with Sprint 0, it must plan first and stop — do not let it build until the plan is reviewed.

---

You are working in the `hr-platform` workspace (`hr-frontend`, `hr-backend`, `hr-ai`, `hr-docs`). Sprint 0 is built and committed.

Before doing anything, read these files in full:
- `hr-docs/architecture/architecture.md`
- `hr-docs/architecture/data-model.md`
- every file in `hr-docs/architecture/decisions/` — note the **new ADR-0010** (document extraction in hr-ai)
- `hr-docs/glossary.md`
- `hr-docs/sprints/sprint-00/review.md`
- `hr-docs/sprints/sprint-01/spec.md`
- the `AGENTS.md` in each code repo

Your task this turn is to **plan Sprint 1 only — do not write any code, migrations, or config yet.**

Produce a single file `hr-docs/sprints/sprint-01/plan.md` laying out exactly how you will implement the Sprint 1 spec, then **STOP and wait for review.** The plan must cover:

1. Build order across the repos, and which parts depend on which.
2. **Territories restructure:** the migration(s) to rename `provinces`→`territories`, add `level` + `parent_territory_id`, relax `code`, and rename the three FK columns (`convenios`, `employees`, `document_chunks`) — plus the exact edits you will make to `data-model.md`.
3. **Registry import:** how you parse `01_listado_convenios.xlsx` (which sheet, which columns map to which table), your prefix→territory-`level` mapping rule (stated as an assumption to confirm against the real sheet), idempotency strategy, and how you preserve multi-value headline cells.
4. **Ingestion:** the upload endpoint(s) and how folder grouping is preserved; the `hr-ai` `/extract` endpoint contract (input S3 key → output per-page text + image keys; images written to S3 by hr-ai; **document/document_pages rows written only by hr-backend**); the extraction library you will use.
5. **Filename parser:** the parse rules (filename + folder → facets), how values are looked up as FKs into the controlled vocabulary, and what happens when a value is not found.
6. **Conflict detection:** the exact rules that send a document to `under_review` with a `conflict` review task.
7. **Provenance:** what `tag_events` rows are written, by whom, and when.
8. **Admin UI:** the Documents table, the filters, the detail panel, and the confirm-tags / re-assign-facet actions (basic table + panel only — NOT the lens hierarchy/graph).
9. Assumptions and any questions where the spec or the real `.xlsx` is ambiguous.

Hard constraints (from the docs):
- `hr-backend` owns ALL migrations and ALL database writes. `hr-ai` gains only `/extract`, writes only to S3, never to the DB, never migrates (ADR-0010, ADR-0007).
- Tags are foreign keys into controlled-vocabulary tables, never free text. The parser NEVER creates vocabulary values — a missing value is a conflict (ADR-0002).
- Conflict detection outranks confidence; conflicted documents are never silently auto-tagged.
- Build nothing on the spec's out-of-scope list: no embeddings/chunking/RAG, no chat, no LLM tagging tier, no structured salary-row extraction, no job-category population, no lens hierarchy/graph UI.
- PDF ingestion only this sprint.

Do not create or modify any file other than `hr-docs/sprints/sprint-01/plan.md` this turn. After writing it, stop and say it is ready for review.
