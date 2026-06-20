# Sprint 0 — Implementation Plan

> Location: `hr-docs/sprints/sprint-00/plan.md`
> Status: **approved with corrections — implemented in the Sprint 0 build**
> Implements: `sprint-00/spec.md`. Read alongside `architecture.md`, `data-model.md`, `decisions/`, `glossary.md`.
> Scope of this plan: the walking skeleton only (infra + schema + seeders + email-OTP login into empty shells + `hr-ai` scaffold). Nothing on the spec's "out of scope" list is built.

---

## Corrections applied (review round 1)

The plan was approved with the following corrections; each is reflected in the relevant section below and mirrored in `spec.md`.

- **C1 — Compose topology (resolves Q1):** `docker-compose.yml` defines **only the three infra services** (Postgres+pgvector, MinIO, MailHog). The app services (`hr-backend`, `hr-ai`, `hr-frontend`) run **on the host** in dev, not in compose. All env connection values use `localhost` + host-exposed ports (mail `localhost:1025`, Postgres `localhost:5432`, MinIO `localhost:9000`, backend `localhost:8000`, frontend `localhost:5173`). App containerization is **out of scope** for Sprint 0. → §2
- **C2 — Compose location & image pinning (resolves Q7):** `docker-compose.yml` is committed in the **`hr-backend`** repo. **All image tags are pinned** (no floating `latest`). → §2
- **C3 — Province seeding (resolves Q2):** seed **only the territorial scopes present in Sedena's actual data** — Álava, Andalucía, Asturias, Cantabria, Estatal, Gipuzkoa, Huesca, Madrid, Navarra, Salamanca, Valencia, Vizcaya — using the 2-digit `numero` prefix as `code`. Do **not** seed all Spanish provinces (aligns with ADR-0002: vocabulary derived from the registry). → §3.4
- **C4 — Synchronous OTP mail:** OTP email is sent **synchronously**, not queued — no queue worker needed; it arrives immediately in MailHog. The queue dependency is removed from the login path. → §3.6
- **C5 — CORS:** `config/cors.php` allows the frontend dev origin `http://localhost:5173` for the auth and `/me` routes. Auth stays token-based (Bearer); **no cookie/CSRF setup**. → §3.8
- **C6 — Dev fixture labelling (confirms Q3):** the placeholder sector/convenio/job-category for the test employee is clearly labelled as a dev fixture (e.g. name `DEV FIXTURE — placeholder`). → §3.4

**Confirmed as-planned (no change):** HNSW index created in an `hr-backend` migration (Q5); `/me` returns raw profile facets without computed scope (Q6); the `varchar + CHECK` enum strategy (§3.3).

---

## 0. Guiding constraints (held throughout)

These are the hard constraints from the docs; every decision below honours them.

- **`hr-backend` owns ALL migrations. `hr-ai` never migrates** (ADR-0007, data-model §2). Every table — including `document_chunks` — is created by a Laravel migration.
- **Scoping facets are FKs into vocabulary tables, never free text** (ADR-0002). `convenio_id`, `province_id`, `sector_id`, `job_category_id` are foreign keys.
- **`document_chunks.embedding` is `vector(1024)`** (data-model §5, ADR-0006). EMBED_DIM = 1024 (BGE-M3).
- **Email OTP only — no passwords, no SSO** (ADR-0003). 6-digit, short-TTL, single-use, hashed at rest, rate-limited. Sanctum bearer tokens, **~24h TTL** (ADR-0005).
- **Mail transport selectable by environment**: MailHog SMTP for local dev, Postmark for prod, no code change between them (spec, ADR-0003).
- **Build nothing out-of-scope**: no ingestion, no filename parser, no tagging, no embeddings, no vector search, no routing, no chat answering, no real convenio/salary import, no escalation/guardrails/analytics UI, no HRIS/AD.

---

## 1. Build order across the three code repos

The backend is the dependency hub (it owns the schema and the auth the other two consume), so it is built first. Order:

1. **Infrastructure (in `hr-backend`)** — `docker-compose.yml` (Postgres+pgvector, MinIO, MailHog only), per-repo `.env.example`, `.gitignore`, READMEs. Brings the data/mail/object layers up so everything else has something to connect to. App services run on the host (C1).
2. **`hr-backend` (Laravel)** — scaffold → Postgres connection → enable `pgvector` → **all migrations** → enum strategy → seeders → Sanctum (24h) → mail transport → email-OTP request/verify endpoints → protected `GET /me`. This is the backbone; the schema and auth must exist before anything depends on them.
3. **`hr-ai` (FastAPI)** — scaffold → `GET /health` → read-only DB connectivity check → config placeholders. Depends only on Postgres being up; **no AI logic** (explicit, see §5). Can proceed in parallel with the tail of step 2.
4. **`hr-frontend` (React + Vite + TS)** — scaffold → centralized API client → login flow (email → request code → enter code → store token → redirect) → two empty protected shells calling `/me`. Built last because it consumes the backend's OTP endpoints and `/me`.

Rationale: frontend cannot be exercised end-to-end until the backend OTP endpoints exist; `hr-ai` is independent of the frontend and only needs the database, so it slots after the schema lands.

---

## 2. `docker-compose.yml` (infrastructure only) — C1, C2

`docker-compose.yml` is committed in the **`hr-backend`** repo (C2) and defines **only the three infra services**. The app services run on the host in dev (C1) — there are no app containers, no Dockerfiles, no shared compose network this sprint. App containerization is explicitly out of scope for Sprint 0.

### Services (all image tags pinned — C2)

| Service | Image (pinned) | Ports (host:container) | Notes |
|---|---|---|---|
| `postgres` | `pgvector/pgvector:pg16` | `5432:5432` | Postgres 16 with the `pgvector` extension preinstalled. Named volume `pgdata`. Healthcheck `pg_isready`. Env: `POSTGRES_USER`, `POSTGRES_PASSWORD`, `POSTGRES_DB`. |
| `minio` | `minio/minio:RELEASE.2024-01-16T16-07-38Z` | `9000:9000` (S3 API), `9001:9001` (console) | `command: server /data --console-address ":9001"`. Named volume `miniodata`. Env: `MINIO_ROOT_USER`, `MINIO_ROOT_PASSWORD`. |
| `minio-init` | `minio/mc:RELEASE.2024-01-13T08-44-48Z` | — | One-shot: waits for MinIO, creates the bucket (`hr-documents`). Exits 0. Keeps bucket creation reproducible from a clean checkout. |
| `mailhog` | `mailhog/mailhog:v1.0.1` | `1025:1025` (**SMTP**), `8025:8025` (**web UI**) | Local SMTP catcher. OTP emails viewable at `http://localhost:8025`. No auth, no TLS. |

No floating `latest` tags are used (C2).

### How the apps connect (host topology — C1)

The three infra services run in compose; the apps run on the host and connect over `localhost` to the host-exposed ports:

- `hr-backend` → Postgres `DB_HOST=localhost`, `DB_PORT=5432`. Mail `MAIL_HOST=localhost`, `MAIL_PORT=1025`. S3 `AWS_ENDPOINT=http://localhost:9000`.
- `hr-ai` → Postgres read-only DSN against `localhost:5432`.
- `hr-frontend` → backend `VITE_API_BASE_URL=http://localhost:8000`; the dev server runs on `localhost:5173`.
- Host ports in play: Postgres `5432`, MinIO `9000`/`9001`, MailHog `1025`/`8025`, backend `8000`, frontend `5173`.

`.env.example` in each repo carries these `localhost` values; the real `.env` is git-ignored.

---

## 3. `hr-backend` (Laravel — owns schema & migrations)

### 3.1 Scaffold & database

- Initialize latest stable Laravel, PHP 8.3+.
- Configure the `pgsql` connection from env (host/port/db/user/password as above).
- **Enable pgvector** via the first custom migration: `DB::statement('CREATE EXTENSION IF NOT EXISTS vector')`. This runs before any migration that uses the `vector` type.
- Remove Laravel's default `create_users_table` and `create_password_reset_tokens_table` migrations (we use `employees`/`admins`, and there are **no passwords**). Keep the framework `cache` and `jobs` tables (they ship with Laravel; OTP mail is sent **synchronously**, so no queue worker is required this sprint — see §3.6/C4).

### 3.2 Migration list (one file per table, in dependency order)

Laravel runs migrations in timestamp order; the timestamps below are sequenced so every FK target exists first. **Package-supplied** migrations (Sanctum, spatie) are published as-is and noted separately.

**Pre-step**
- `…_enable_pgvector_extension` — `CREATE EXTENSION IF NOT EXISTS vector`.

**Group A — controlled vocabulary & registry**
1. `…_create_provinces_table` — `id`, `code char(2) UNIQUE`, `name`, `aliases jsonb`, `is_national bool`, timestamps.
2. `…_create_sectors_table` — `id`, `name varchar UNIQUE`, `aliases jsonb`, timestamps.
3. `…_create_convenios_table` — `id`, `numero varchar UNIQUE`, `name`, `province_id FK→provinces`, `sector_id FK→sectors`, `annual_hours numeric(7,2) NULL`, `weekly_hours numeric(5,2) NULL`, `numero_a3 varchar NULL`, `it_complement varchar NULL`, `notes text NULL`, timestamps.
4. `…_create_convenio_job_categories_table` — `id`, `convenio_id FK→convenios`, `name`, `group_code varchar NULL`, `annual_hours numeric(7,2) NULL`, `weekly_hours numeric(5,2) NULL`, timestamps.
5. `…_create_document_types_table` — `id`, `code varchar UNIQUE`, `name`, timestamps.
6. `…_create_admins_table` — `id`, `uuid UNIQUE`, `email varchar UNIQUE`, `full_name`, `status enum(active|inactive)`, timestamps. *(Created before `topics`/`documents` because they FK into `admins`.)*
7. `…_create_topics_table` — `id`, `name varchar UNIQUE`, `status enum(approved|proposed)`, `proposed_by enum(ai_agent|admin) NULL`, `approved_by FK→admins NULL`, timestamps.

**Group B — documents, pages, chunks**
8. `…_create_documents_table` — `id`, `uuid UNIQUE`, `title`, `source_filename varchar NULL`, `storage_path varchar`, `convenio_id FK→convenios NULL`, `document_type_id FK→document_types`, `validity_start date NULL`, `validity_end date NULL`, `retrieval_status enum(draft|active|historical)`, `authority_level enum(national_law|official_convenio|internal_hr_ruling)`, `predecessor_document_id FK→documents NULL` (self-ref), `language varchar`, `tagging_status enum(auto_proposed|under_review|verified)`, `tagging_confidence numeric(4,3) NULL`, `ingested_at timestamp`, `ingested_by FK→admins NULL`, timestamps.
9. `…_create_document_pages_table` — `id`, `document_id FK→documents`, `page_number int`, `text text`, `image_path varchar NULL`, timestamps.
10. `…_create_document_topics_table` — `id`, `document_id FK→documents`, `topic_id FK→topics`, `source enum(filename_parse|ai_agent|admin_manual)`, `confidence numeric(4,3) NULL`, `verified_by FK→admins NULL`, `verified_at timestamp NULL`, timestamps.
11. `…_create_document_chunks_table` — `id`, `document_id FK→documents`, `chunk_index int`, `page_from int NULL`, `page_to int NULL`, `content text`, `token_count int`, **`embedding vector(1024)`** (added via raw `DB::statement` since Laravel has no native vector type), denormalized scope columns `convenio_id NULL`, `province_id NULL`, `sector_id NULL`, `validity_start date NULL`, `validity_end date NULL`, `retrieval_status enum`, `authority_level enum`, timestamps. Indexes: btree on each scope column; **HNSW index on `embedding`** created here on the (empty) table so all DDL stays in `hr-backend` and `hr-ai` never migrates (see Q5).

**Group C — salary tables (structured, never vectorized)**
12. `…_create_salary_tables_table` — `id`, `convenio_id FK→convenios`, `year int NULL`, `validity_start date NULL`, `validity_end date NULL`, `source_document_id FK→documents NULL`, timestamps.
13. `…_create_salary_table_rows_table` — `id`, `salary_table_id FK→salary_tables`, `job_category_id FK→convenio_job_categories`, `gross_annual numeric(10,2) NULL`, `base_salary_monthly numeric(10,2) NULL`, `extra_pay numeric(10,2) NULL`, `num_payments int NULL`, `hourly_rate numeric(8,4) NULL`, `night_plus numeric(10,2) NULL`, `raw_values jsonb`, timestamps.

**Group D — people, auth, roles**
14. `…_create_employees_table` — `id`, `uuid UNIQUE`, `email varchar UNIQUE`, `full_name`, `employee_external_id varchar NULL`, `convenio_id FK→convenios`, `job_category_id FK→convenio_job_categories NULL`, `province_id FK→provinces`, `work_location varchar NULL` (free text per data-model §12.4), `employment_type enum(full_time|part_time)`, `start_date date NULL`, `status enum(active|inactive)`, `profile_last_reviewed_at timestamp NULL`, timestamps.
15. `…_create_employee_audit_log_table` — `id`, `employee_id FK→employees`, `field_changed varchar`, `old_value text NULL`, `new_value text NULL`, `changed_by FK→admins`, `changed_at timestamp`, timestamps.
16. `…_create_login_codes_table` — `id`, `account_type enum(employee|admin)`, `email varchar` (indexed for rate-limit-by-email), `code_hash varchar` (**hash only, never plaintext**), `expires_at timestamp`, `consumed_at timestamp NULL`, `attempts int default 0`, timestamps.

**Group E — chat & traces**
17. `…_create_chat_sessions_table` — `id`, `uuid UNIQUE`, `employee_id FK→employees`, `started_at timestamp`, `last_activity_at timestamp`, timestamps.
18. `…_create_chat_messages_table` — `id`, `session_id FK→chat_sessions`, `role enum(user|assistant)`, `content text`, `created_at` (+ `updated_at`).
19. `…_create_message_citations_table` — `id`, `message_id FK→chat_messages`, `document_id FK→documents`, `chunk_id FK→document_chunks NULL`, `page_number int NULL`, timestamps.
20. `…_create_message_traces_table` — `id`, `message_id FK→chat_messages`, `trace jsonb`, timestamps.

**Group F — escalations**
21. `…_create_escalation_cards_table` — `id`, `uuid UNIQUE`, `chat_session_id FK→chat_sessions NULL`, `source_message_id FK→chat_messages NULL`, `employee_id FK→employees`, `reason enum(low_confidence|sensitive_topic|off_domain|explicit_request|conflict)`, `status enum(new|assigned|in_progress|resolved|closed)`, `assigned_to FK→admins NULL`, `topic_id FK→topics NULL`, `created_at`, `resolved_at timestamp NULL` (+ timestamps).
22. `…_create_escalation_resolutions_table` — `id`, `card_id FK→escalation_cards`, `resolved_by FK→admins`, `resolution_text text`, `converted_to_document_id FK→documents NULL`, `created_at` (+ timestamps).

**Group G — tag provenance & review (cross-cutting)**
23. `…_create_tag_events_table` — `id`, `entity_type varchar`, `entity_id bigint`, `facet varchar`, `old_value text NULL`, `new_value text NULL`, `source enum(filename_parse|ai_agent|admin_manual|system)`, `actor_id FK→admins NULL`, `confidence numeric(4,3) NULL`, `note text NULL`, `created_at` (+ timestamps).
24. `…_create_document_review_tasks_table` — `id`, `document_id FK→documents`, `type enum(expiry|tag_review|conflict)`, `status enum(open|resolved|dismissed)`, `due_date date NULL`, `resolved_by FK→admins NULL`, `resolved_at timestamp NULL`, timestamps.

**Package-supplied migrations** (not hand-written, but run by `hr-backend`):
- **Sanctum** — `personal_access_tokens` (morphable `tokenable`, so the same table issues tokens to both `employees` and `admins`).
- **spatie/laravel-permission** — `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`. We do **not** hand-roll these (data-model §7).

`uuid` columns are `$table->uuid('uuid')->unique()`, populated via a model `creating` hook (`Str::uuid()`).

### 3.3 Enum strategy

**Decision:** implement every enum as a **string column with a `CHECK` constraint**, generated via Laravel's `$table->enum('col', [...])` helper (on Postgres this produces `varchar` + a `CHECK`). Reasons: native Postgres `ENUM` types are painful to evolve (adding/removing values needs `ALTER TYPE`, can't always run inside a transaction, ordering is fixed), whereas a checked varchar is trivial to extend in a later migration. Data-model §2 explicitly permits either ("decide per Laravel migration style"), so this is in-spec and needs **no doc change**. The documented value lists are authoritative and are enforced by the CHECK. Application-layer PHP `enum` casts mirror the same values for type safety.

### 3.4 Seeders

Run after migrations (`php artisan db:seed`). One seeder per concern, orchestrated by `DatabaseSeeder`.

- **`ProvinceSeeder`** — **C3:** seed **only the territorial scopes present in Sedena's actual data**, using the 2-digit `numero` prefix as `code` (vocabulary derived from the registry, ADR-0002): Álava (`01`), Huesca (`22`), Madrid (`28`), Navarra (`31`), Salamanca (`37`), Asturias (`33`), Cantabria (`39`), Gipuzkoa (`20`), Vizcaya (`48`), Valencia (`46`), Andalucía (`AN`/regional), and **Estatal** (`99`, `is_national = true`). `aliases` carries known alternates (e.g. Álava ← `Araba`, `ALABA`; Gipuzkoa ← `Guipúzcoa`; Vizcaya ← `Bizkaia`). The full provincial set is **not** seeded. (Andalucía is an autonomous community rather than a single 2-digit province; its code is recorded as it appears in the source data — flagged for review, see Q2.)
- **`DocumentTypeSeeder`** — the 8 closed codes: `convenio_text`, `salary_tables`, `changes`, `partial_agreement`, `summary`, `national_law`, `internal_hr_ruling`, `other`.
- **`TopicSeeder`** — the 11 FAQ categories, all `status = approved`, `proposed_by = NULL`: jornada, vacaciones, festivos, permisos retribuidos, bajas médicas, conciliación, excedencias, retribución, permisos no retribuidos, normativa/derechos, formación.
- **`RoleSeeder`** — the 4 spatie roles: `super_admin`, `hr_agent`, `knowledge_editor`, `auditor`. (Roles only this sprint; granular permissions are deferred to the sprints that need them — spec asks for roles.)
- **`TestUserSeeder`** —
  - One **admin** (real email the tester controls, from `.env`), `status = active`, assigned `super_admin`.
  - One **employee** (real email the tester controls), `status = active`, `employment_type = full_time`.
  - **C6 (confirms Q3):** `employees.convenio_id` / `province_id` are NOT NULL, but the real convenio registry is Sprint 1. So this seeder also creates a **single clearly-labelled dev fixture**: a sector, convenio, and job category whose names carry the literal marker **`DEV FIXTURE — placeholder`** (e.g. sector `DEV FIXTURE — placeholder`, convenio `name = "DEV FIXTURE — placeholder"` with a sentinel `numero` and province `01`, job category `DEV FIXTURE — placeholder`), purely to satisfy the test employee's FKs. This is a dev fixture, **not** the real registry, and is unmistakable in any UI or query. No schema relaxation is done silently.

### 3.5 Sanctum setup

- Install `laravel/sanctum`; publish config + run its migration (`personal_access_tokens`).
- **24h TTL:** set `config('sanctum.expiration') = 1440` (minutes) in `config/sanctum.php`, driven by env `SANCTUM_TOKEN_TTL_MINUTES` (default 1440). Expired tokens are rejected by Sanctum automatically (ADR-0005: "a single config value").
- Both `Employee` and `Admin` models use `HasApiTokens`; the morphable `tokenable` lets one guard serve both account types.
- Protected routes use the `auth:sanctum` middleware.

### 3.6 Mail setup (environment-selectable)

- The transport is chosen by the `MAIL_MAILER` env var — **no code branch**:
  - **Local dev:** `MAIL_MAILER=smtp`, `MAIL_HOST=mailhog`, `MAIL_PORT=1025`, no encryption/auth. OTP emails land in MailHog UI at `http://localhost:8025`.
  - **Production:** `MAIL_MAILER=postmark` (install `symfony/postmark-mailer`; `POSTMARK_TOKEN` in env).
- A single `LoginCodeMail` Mailable renders the 6-digit code; the same Mailable is used regardless of transport.
- **C4 — synchronous send:** the OTP email is sent **synchronously** on the request (`Mail::to(...)->send(...)`, not `->queue(...)`). No queue worker is needed and the code appears in MailHog immediately. The login path has **no queue dependency**. (Cost: the request blocks on SMTP to MailHog, which is local and instant; acceptable for this flow.)

### 3.7 Email-OTP endpoints

Routes (unauthenticated except `/me`):

- **`POST /auth/request-code`** — body `{ email }`.
  1. Resolve `account_type` by looking up the email in `employees` then `admins` (Q4 covers the collision case).
  2. Generate a 6-digit code: `random_int(0, 999999)` zero-padded.
  3. **Invalidate prior outstanding codes** for that email (mark `consumed_at = now` / delete unconsumed rows).
  4. Store a new `login_codes` row with **`code_hash = Hash::make(code)`** (bcrypt, salted) — plaintext is never persisted or logged. `expires_at = now + 10 min`, `attempts = 0`.
  5. Dispatch `LoginCodeMail` (via the env-selected transport).
  6. **Always return a generic 200** ("if the email exists, a code has been sent") so the endpoint does not reveal which emails are registered.
  - **Rate-limiting:** named limiter `otp-request` keyed by **normalized email** — e.g. max 5 requests/hour plus a ~60s minimum interval between requests. Applied as `throttle:` middleware.

- **`POST /auth/verify-code`** — body `{ email, code }`.
  1. Load the latest **unconsumed, unexpired** `login_codes` row for that email.
  2. If none, or expired → reject.
  3. **Increment `attempts`**; if `attempts` exceeds the cap (e.g. 5) → invalidate the code and reject (brute-force guard).
  4. Verify with **`Hash::check(code, code_hash)`** (constant-time compare via bcrypt). On mismatch → reject (attempt already counted).
  5. On success → set `consumed_at = now` (single-use), issue a **Sanctum token with ~24h expiry**, return `{ token, account_type, identity }`.
  - **Rate-limiting:** named limiter `otp-verify` keyed by **email + IP** (e.g. 10 attempts / 10 min) on top of the per-row `attempts` cap.

- **`GET /me`** — `auth:sanctum`. Returns the authenticated identity (`uuid`, `email`, `full_name`, `account_type`). For **employees**, also the resolved profile fields used by scoping later: `convenio`, `province`, `job_category`, `employment_type`. (Scope *resolution logic* itself is a later sprint; here `/me` just echoes the profile so the shells have something to show and the contract is established.)

Hashing summary: **6-digit codes are hashed with bcrypt (`Hash::make`) and verified with `Hash::check`; plaintext never touches the DB or logs.** TTL 10 min, single-use, attempts-capped, prior codes invalidated on re-request, rate-limited per email and per email+IP.

### 3.8 CORS — C5

The frontend dev server (`http://localhost:5173`) is a different origin from the backend (`http://localhost:8000`), so the browser needs CORS to call the auth and `/me` routes. Configure `config/cors.php`:

- `paths`: `auth/*` and `me` (plus `api/*` for future use).
- `allowed_origins`: `http://localhost:5173` (the Vite dev origin), driven by a `FRONTEND_URL` env var.
- `allowed_methods` / `allowed_headers`: permit `GET, POST, OPTIONS` and the `Authorization` + `Content-Type` headers.
- `supports_credentials`: **false** — auth is **token-based (Bearer)**, so there is **no cookie/CSRF setup** (no Sanctum SPA stateful-domain cookies, no `withCredentials`). The token is sent in the `Authorization` header.

---

## 4. `hr-ai` (FastAPI — scaffold only)

- Initialize a FastAPI app (Python 3.11+, async), `uvicorn` entrypoint, `pyproject.toml` (deps: `fastapi`, `uvicorn`, `pydantic-settings`, an async PG driver such as `asyncpg`/`sqlalchemy[asyncio]`), `ruff` configured for lint/format, type hints throughout.
- **`GET /health`** — returns `{ "status": "ok" }` (liveness).
- **Read-only DB connectivity check** — a check (e.g. `GET /health/db` or a field within `/health`) that opens a connection to Postgres using a read-only DSN and runs a trivial `SELECT 1`. It proves the read path only: **no writes, no DDL, no migrations** (data-model §2, ADR-0007). Using `SELECT 1` avoids coupling to any specific table while the schema settles.
- **Config placeholders** (via `pydantic-settings`, from env, **unused this sprint**): `DATABASE_URL` (read-only), `EMBED_MODEL=BGE-M3`, `EMBED_DIM=1024`, `ANTHROPIC_API_KEY`. Present so the contract/config shape is visible; not exercised.
- **Explicit statement:** **No AI logic is built this sprint.** No embeddings, no vector search, no router, no answer synthesis, no tagging tier, and `hr-ai` is **not called by `hr-backend`** in Sprint 0. The BGE-M3 retrieval/dimension-lock test (data-model §12.1) is the *first AI task of a later sprint*, not Sprint 0.

---

## 5. `hr-frontend` (React + Vite + TS)

- Initialize React + Vite + TypeScript (functional components + hooks).
- **Centralized API client** — one module wrapping `fetch`/`axios`, base URL from `import.meta.env.VITE_API_BASE_URL` (**never hardcoded**, per `AGENTS.md`). Attaches the `Authorization: Bearer <token>` header when a token is stored; centralizes 401 handling (clear token → back to login).
- **Auth state** — a small auth context/provider storing the Sanctum token (e.g. `localStorage`) and the `/me` result; a `ProtectedRoute` wrapper that redirects unauthenticated users to login.
- **Login flow screens:**
  1. **Email screen** — enter email → `POST /auth/request-code` → advance (generic confirmation; copy notes "check your email", and in dev the code is in MailHog at `localhost:8025`).
  2. **Code screen** — enter the 6-digit code → `POST /auth/verify-code` → on success store the token → call `/me` → redirect to the correct shell.
  - Uses glossary terms in copy where relevant (email OTP / daily session).
- **Two empty protected route trees** (kept as **separate route trees** per `AGENTS.md`):
  - **Employee chat shell** (`/app`) — placeholder that calls `/me` and shows the identity/profile; no chat logic.
  - **Admin shell** (`/admin`) — placeholder that calls `/me` and shows the identity; no console modules.
  - Routing target after login is chosen by `account_type` (and/or role) from `/me`.
- No lens/hierarchy component, no chat, no admin modules this sprint (out of scope).

---

## 6. Per-repo housekeeping (all repos)

- **`.env.example`** in each repo with every variable referenced above (DB, mail, S3/MinIO, Sanctum TTL, API base URL, Anthropic placeholder). Real `.env` git-ignored.
- **`.gitignore`** per repo: `node_modules`, `vendor`, `.venv`, `.env`, build artefacts (`dist`, `build`), `.DS_Store`.
- **READMEs** per repo: from a clean checkout → `docker compose up` → run migrations + seeders → start each service → log in as the seeded employee/admin via MailHog. Enough for a newcomer to reproduce all acceptance criteria.
- Keep the existing **`AGENTS.md`** in each code repo untouched.
- `review.md` (per the spec's Definition of Done) is written **after** implementation is reviewed/built — not this turn.

---

## 7. Mapping to the spec's acceptance criteria

| # | Criterion | Covered by |
|---|---|---|
| 1 | `docker compose up` (in `hr-backend`) brings up Postgres+pgvector, MinIO, MailHog | §2 |
| 2 | Migrations run clean; every data-model table exists; `embedding` is `vector(1024)` | §3.1–3.2 |
| 3 | Seeders populate provinces, document_types, topics, two test users, roles | §3.4 |
| 4 | Employee OTP login end-to-end; expired/used rejected; attempts capped; rate-limited | §3.5–3.7, §5 |
| 5 | Same for admin into admin shell | §3.7, §5 |
| 6 | Tokens expire at ~24h | §3.5 |
| 7 | `hr-ai` `/health` + read-only DB check passes | §4 |
| 8 | READMEs let a newcomer reproduce everything | §6 |

---

## 8. Question resolutions (review round 1)

- **Q1 — compose topology → RESOLVED (C1).** Infra-only compose; apps on host; `localhost` connection values. See §2.
- **Q2 — province seed set → RESOLVED (C3).** Seed only the territorial scopes present in Sedena's data (12 listed), not all Spanish provinces. See §3.4. *Remaining nuance:* Andalucía is an autonomous community, not a single 2-digit province; its `code` is recorded as it appears in the source data and re-confirmed at the Sprint 1 registry import.
- **Q3 — dev fixture → RESOLVED (C6).** Seed one fixture sector/convenio/job-category labelled `DEV FIXTURE — placeholder` to satisfy the test employee's NOT-NULL FKs. See §3.4.
- **Q4 — email in both account types → standing assumption.** Resolve `account_type` by lookup (employees first); if an email exists in both, an explicit `account_type` can be supplied. Not contradicted by review; no collision expected in seed data.
- **Q5 — ANN index ownership → CONFIRMED as planned.** HNSW index created on the empty `embedding` column by an `hr-backend` migration. See §3.2.
- **Q6 — `/me` depth → CONFIRMED as planned.** `/me` returns raw profile facets, no computed scope. See §3.7.
- **Q7 — compose location & pinning → RESOLVED (C2).** `docker-compose.yml` lives in `hr-backend`; all image tags pinned. See §2.

Plus **C4** (synchronous OTP mail, §3.6) and **C5** (CORS for the dev origin, §3.8).

---

**Plan approved with corrections and now implemented.** The build is recorded in `review.md`; any deviations forced during the build are noted there and in the named canonical doc.
