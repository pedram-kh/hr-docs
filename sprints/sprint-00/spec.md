# Sprint 0 — Foundation & walking skeleton

> Location: `hr-docs/sprints/sprint-00/spec.md`
> Reviewer: Claude (architecture) · eyes-on test: Pedram
> Read first: `architecture.md`, `data-model.md`, all `decisions/`, `glossary.md`.

## Goal
Stand up the project end to end as a **walking skeleton**: a local dev environment, the full database schema, the seeded controlled vocabulary, and **email-OTP login working end to end** into empty employee and admin shells. No feature logic yet — the point is that the three services exist, the schema is real, and a user can log in.

"Done" means: from a clean checkout, `docker compose up`, run migrations + seeders, and a seeded employee and a seeded admin can each log in by email OTP and land on a protected (empty) shell.

## In scope

### Infrastructure (local dev)
- `docker-compose.yml` bringing up **PostgreSQL + pgvector**, **MinIO** (S3-compatible, per ADR-0009), and **MailHog** (local SMTP catcher; SMTP on 1025, web UI on 8025).
  - **Topology (review C1):** compose defines **only these three infra services**. The app services (`hr-backend`, `hr-ai`, `hr-frontend`) run **on the host** in dev — no app containers this sprint. App containerization is out of scope for Sprint 0.
  - **Location & pinning (review C2):** `docker-compose.yml` is committed in the **`hr-backend`** repo, with **all image tags pinned** (no floating `latest`).
  - **Connection values:** apps connect over `localhost` to host-exposed ports — Postgres `localhost:5432`, MinIO `localhost:9000` (console `9001`), MailHog SMTP `localhost:1025` (UI `localhost:8025`), backend `localhost:8000`, frontend `localhost:5173`.
- `.env.example` in each repo; READMEs with setup steps.
- `.gitignore` in each repo (node_modules, vendor, .venv, .env, build artefacts).
- Each code repo already contains its `AGENTS.md` (provided) — keep it.

### hr-backend (Laravel) — owns schema & migrations
- Initialize the Laravel app; connect to Postgres; enable the pgvector extension.
- **Implement the full schema from `data-model.md` as migrations** — every table, with the exact names, types, FKs, and enums specified. The `document_chunks.embedding` column is `vector(1024)`.
- Seeders:
  - `provinces` — **(review C3)** seed **only the territorial scopes present in Sedena's actual data** (Álava, Andalucía, Asturias, Cantabria, Estatal, Gipuzkoa, Huesca, Madrid, Navarra, Salamanca, Valencia, Vizcaya), using the 2-digit `numero` prefix as the `code`. Do **not** seed all Spanish provinces (vocabulary derived from the registry, ADR-0002). `document_types`, and `topics` (seeded `approved` from the FAQ categories listed in `data-model.md`/`glossary`).
  - One test `employee` and one test `admin` (real emails the tester controls), plus `spatie/laravel-permission` roles (`super_admin`, `hr_agent`, `knowledge_editor`, `auditor`).
  - **(review C6)** Because `employees.convenio_id`/`province_id` are NOT NULL and the real registry import is Sprint 1, also seed a single sector/convenio/job-category labelled **`DEV FIXTURE — placeholder`** to satisfy the test employee's FKs. This is a dev fixture, not the real registry.
- Install Sanctum; configure bearer tokens with **~24h TTL**.
- Configure the mail transport: **MailHog over SMTP for local dev** (host `mailhog`, port `1025`), with **Postmark** as the production transport — selectable by environment, so no code change between dev and prod. Dev OTP emails are viewable in the MailHog UI at `localhost:8025`.
- **Email-OTP endpoints:** request-code (generate 6-digit code, store only its hash in `login_codes`, send the mail **synchronously** — **(review C4)** not queued, so no queue worker is needed and the code lands in MailHog immediately — invalidate prior codes for that email, rate-limit per email) and verify-code (check hash, expiry, attempts; on success issue a Sanctum token). Works for both `account_type` values.
- A protected `GET /me` returning the authenticated identity + (for employees) their resolved profile fields.
- **CORS (review C5):** configure `config/cors.php` to allow the frontend dev origin `http://localhost:5173` for the auth and `/me` routes so browser requests succeed. Auth stays token-based (Bearer) — **no cookie/CSRF setup** (`supports_credentials = false`).

### hr-ai (FastAPI) — scaffold only this sprint
- Initialize the FastAPI app; `GET /health`.
- Read-only DB connectivity check against Postgres (proves the read path; no writes, no migrations).
- Config placeholders for BGE-M3 and the Anthropic API key (not exercised yet).
- **No retrieval, embedding, routing, or answering logic this sprint.**

### hr-frontend (React + Vite + TS)
- Initialize the app; centralized API client reading the backend URL from env.
- **Login flow:** enter email → request code → enter code → store token → redirect.
- Two separate protected route trees: an empty **employee chat shell** and an empty **admin shell** (placeholders that call `/me`).

## Out of scope (do NOT build this sprint)
- Document ingestion, the filename parser, tagging, the Knowledge Center UI.
- Any embeddings, vector search, routing, chat answering, or `hr-ai` reasoning.
- Importing the real convenio registry / salary tables (that is Sprint 1).
- Escalation board, guardrails UI, analytics, the lens/hierarchy UI.
- Any HRIS/AD integration (there is none — ADR-0004).

## Acceptance criteria
1. `docker compose up` (run in `hr-backend`, where the compose file lives) from a clean state brings up Postgres+pgvector, MinIO, and MailHog.
2. Migrations run clean; **every table in `data-model.md` exists** with matching columns/types; `document_chunks.embedding` is `vector(1024)`.
3. Seeders populate provinces, document_types, topics, the two test users, and the roles.
4. A seeded **employee** can: request a code, receive the email (visible in MailHog at `localhost:8025`), enter it, receive a token, and reach `/me` + the employee shell. An expired/used code is rejected; attempts are capped; rate-limiting works.
5. The same works for a seeded **admin** into the admin shell.
6. Tokens expire at ~24h.
7. `hr-ai` `/health` responds and its read-only DB check passes.
8. READMEs let a newcomer reproduce all of the above.

## Definition of done
All acceptance criteria pass; each repo has its `AGENTS.md`, `.gitignore`, `.env.example`, and README; no feature logic beyond the skeleton; `hr-ai` contains no AI logic. Cursor writes `review.md` in this folder.

## Docs to update if anything deviates
If implementation forces a change from the specs, update the **named** canonical doc (`data-model.md`, `architecture.md`, or the relevant ADR) in the same change, and note the deviation in `sprint-00/review.md`. Do not silently diverge.
