# Sprint 0 — Cursor kickoff prompt

> Paste the block below into Cursor with the `hr-platform/` workspace open (all four repos visible). It instructs Cursor to plan first and stop — do not let it start building until the plan is reviewed.

---

You are working in the `hr-platform` workspace, which contains four repos: `hr-frontend`, `hr-backend`, `hr-ai`, and `hr-docs`.

Before doing anything, read these files in full:
- `hr-docs/architecture/architecture.md`
- `hr-docs/architecture/data-model.md`
- every file in `hr-docs/architecture/decisions/`
- `hr-docs/glossary.md`
- `hr-docs/sprints/sprint-00/spec.md`
- the `AGENTS.md` in `hr-backend`, `hr-ai`, and `hr-frontend`

Your task this turn is to **plan Sprint 0 only — do not write any application code, migrations, or config yet.**

Produce a single file `hr-docs/sprints/sprint-00/plan.md` that lays out exactly how you will implement the Sprint 0 spec, then **STOP and wait for review.** The plan must cover:

1. The order you will build in, across the three code repos.
2. `docker-compose.yml`: the Postgres+pgvector, MinIO, and MailHog services (MailHog SMTP on 1025, web UI on 8025), versions, and how the app connects.
3. hr-backend: the exact list of migration files you will create (one line per table from `data-model.md`), the enum strategy, the seeders, the Sanctum setup, the Postmark setup, and the email-OTP request/verify endpoints with their rate-limiting and hashing approach.
4. hr-ai: the FastAPI scaffold, `/health`, and the read-only DB check — and an explicit statement that no AI logic is built this sprint.
5. hr-frontend: the app scaffold, the API client, the login flow screens, and the two empty protected shells.
6. Any assumptions or questions where the spec is ambiguous.

Hard constraints you must honour (from the docs above):
- hr-backend owns ALL migrations; hr-ai never migrates.
- Scoping facets are foreign keys into vocabulary tables, never free text.
- `document_chunks.embedding` is `vector(1024)`.
- Email OTP only — no passwords, no SSO; ~24h Sanctum tokens. Mail transport is MailHog for local dev (Postmark for prod), selectable by environment.
- Build nothing that is listed as out-of-scope in the spec (no ingestion, no embeddings, no chat answering, no real convenio import).

Do not create or modify any file other than `hr-docs/sprints/sprint-00/plan.md` this turn. After writing it, stop and say it is ready for review.
