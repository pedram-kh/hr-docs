# Sprint 0 — Review

> Location: `hr-docs/sprints/sprint-00/review.md`
> Status: **built, self-verified locally; ready for eyes-on review**
> Implements `sprint-00/spec.md` per the corrected `sprint-00/plan.md` (review round 1, corrections C1–C6).

---

## 1. What I built

### Infrastructure — `hr-backend/docker-compose.yml` (C1, C2)
- Three infra services only, image tags pinned (no `latest`):
  - `postgres` → `pgvector/pgvector:pg16` on `5432`
  - `minio` → `minio/minio:RELEASE.2024-01-16T16-07-38Z` on `9000`/`9001` + a one-shot `minio/mc` bucket-create (`hr-documents`)
  - `mailhog` → `mailhog/mailhog:v1.0.1` on `1025` (SMTP) / `8025` (UI)
- App services run on the **host** (no app containers); all connection values use `localhost` + host-exposed ports.

### hr-backend (Laravel 13, PHP 8.4)
- Postgres connection; `pgvector` enabled via the first migration.
- **All 24 data-model tables** as migrations (Groups A–G), in FK-dependency order, plus package migrations (Sanctum `personal_access_tokens`, spatie permission tables) and the framework `cache`/`jobs` tables. Default `users`/`password_reset_tokens`/`sessions` migration and the `User` model removed (no passwords).
- `document_chunks.embedding` = **`vector(1024)`** with a denormalized scope-column set (btree-indexed) and an **HNSW** index (created on the empty table by a backend migration — Q5).
- **Enums = `varchar` + `CHECK`** (via Laravel `enum()`), per plan §3.3.
- **Seeders:** `provinces` (the 12 territorial scopes — C3), `document_types` (8), `topics` (11, `approved`), 4 spatie roles, one test admin (`super_admin`) + one test employee, and a **`DEV FIXTURE — placeholder`** sector/convenio/job-category to satisfy the employee's NOT-NULL FKs (C6).
- **Sanctum** bearer tokens; TTL from `SANCTUM_TOKEN_TTL_MINUTES` (default **1440 min ≈ 24h**, ADR-0005).
- **Mail** transport selected by `MAIL_MAILER` (MailHog SMTP dev / Postmark prod, no code change); OTP email sent **synchronously** (C4 — `QUEUE_CONNECTION=sync`, no worker).
- **Email-OTP endpoints:** `POST /auth/request-code` (generic 200, 6-digit code, bcrypt **hash only**, prior codes invalidated, per-email rate limit) and `POST /auth/verify-code` (TTL + attempts-cap + single-use, per email+IP rate limit, issues the Sanctum token). Protected `GET /me` returns identity and, for employees, the **raw profile facets** (no computed scope — Q6).
- **CORS** (`config/cors.php`, C5): allows `http://localhost:5173` for `auth/*`, `me`, `api/*`; `supports_credentials = false` (Bearer only, no cookie/CSRF).

### hr-ai (FastAPI, scaffold only)
- `GET /health` (liveness), `GET /health/db` (read-only `SELECT 1` on a `read_only` session — proves the read path, no writes/migrations), `GET /health/config` (echoes `EMBED_MODEL`/`EMBED_DIM`/whether an Anthropic key is set).
- Config placeholders via `pydantic-settings`; `ruff` configured. **No AI logic** and not called by the backend this sprint.

### hr-frontend (React 19 + Vite + TS)
- Centralized API client (`VITE_API_BASE_URL`, Bearer header, 401 → clear token).
- Auth context (token in `localStorage`, `/me` on boot).
- Login flow: email → request code → enter 6-digit code → store token → redirect.
- **Two separate protected route trees**: `/app` (employee chat shell) and `/admin` (admin shell), both empty and calling `/me`.

### Housekeeping
- `.env.example`, `.gitignore`, and `README.md` in all three code repos; `AGENTS.md` left untouched. `hr-docs/.gitignore` added earlier.

---

## 2. Deviations and where they're recorded

No change was forced to `data-model.md`, `architecture.md`, or any ADR — the schema built cleanly as specified. The following are implementation choices/decisions, recorded as noted:

| # | Item | Recorded in |
|---|---|---|
| 1 | The six corrections C1–C6 | `plan.md` (Corrections + §§2,3.4,3.6,3.8) and `spec.md` |
| 2 | API routes mounted at root (`apiPrefix: ''`) so they read `/auth/*` and `/me` exactly as the spec names them (not `/api/...`) | this review |
| 3 | API-only exception handling: `AuthenticationException` renders a JSON **401** (there is no web `login` route to redirect to) | this review |
| 4 | `SESSION_DRIVER=file`, `QUEUE_CONNECTION=sync`; default `users`/`sessions`/`password_reset_tokens` migration removed (no passwords; synchronous mail) | this review; consistent with C4 |
| 5 | Sensible enum **defaults** added where data-model didn't specify one (e.g. `documents.retrieval_status=draft`, `tagging_status=under_review`, `*.status=active/new/open`, `topics.status=approved`) | this review (additive only; value sets unchanged) |
| 6 | Andalucía seeded with `code = 'AN'` (it's an autonomous community, not a single 2-digit province); to be confirmed at the Sprint 1 registry import | `plan.md` Q2 / `spec.md` C3 note |

---

## 3. What I tested (locally, self-verification)

All acceptance criteria were exercised end to end against a live Postgres + MailHog:

- **AC1 — infra:** `docker compose config` valid; images pulled and ran.
- **AC2 — schema:** `php artisan migrate` ran clean; **36 base tables** (24 data-model + spatie + framework); confirmed `document_chunks.embedding` is `vector(1024)` and the HNSW index exists.
- **AC3 — seeders:** provinces = **12**, document_types = **8**, topics = **11**, roles = **4**, 1 admin (role `super_admin`), 1 employee; `Estatal` `code=99 is_national=true`; dev fixture labelled `DEV FIXTURE — placeholder`.
- **AC4 — employee OTP:** request-code → generic 200; code received in MailHog; verify-code → Sanctum token + identity; `/me` reachable. **Negative:** reused/consumed code → 422, wrong code → 422, no token → 401, bad token → 401. **Rate-limit:** 2nd request-code within a minute → 429.
- **AC5 — admin OTP:** same flow into admin identity; `/me` returns `roles: [super_admin]`.
- **AC6 — token TTL:** `sanctum.expiration` = 1440 min (config verified; not waited out).
- **AC7 — hr-ai:** `/health` → `ok`; `/health/db` → `{connected:true, select_1:1}`; `ruff` clean.
- **AC8 — READMEs:** present in all three repos with clean-checkout steps.
- **CORS:** preflight from `Origin: http://localhost:5173` returns the expected `Access-Control-Allow-*` headers.
- **Frontend:** `tsc -b && vite build` clean; `eslint` clean; dev server serves `200` on `5173`.

---

## 4. Known gaps / caveats

1. **Local verification used alternate host ports.** This machine already runs other dev stacks occupying `5432`, `9000/9001`, `1025/8025`, so I verified the backend against a throwaway Postgres+MailHog on `55432`/`51025`. The **committed** `docker-compose.yml` and `.env.example` use the **spec ports** — a clean machine works as documented.
2. **Workspace path contains `[PROJECT]`.** PHP's `glob()` (used by Laravel's migrator) treats `[...]` as a character class, so `php artisan migrate` finds **no migrations** when run from the literal `…/[PROJECT]/…` path. I verified by running from a bracket-free copy. This is purely an environment-path quirk and is a **non-issue on any normal path**; flagging so the tester isn't surprised. (If you keep the bracketed folder name, run the backend from a bracket-free symlink/checkout, or rename the folder.)
3. **Token 24h expiry** is configured (1440 min) but not wall-clock tested.
4. **Frontend login was verified at the API + CORS + build/lint level**, not via an automated browser E2E. Manual check: `npm run dev`, log in with a seeded email, read the code in MailHog.
5. **Seed emails** default to `admin@example.com` / `employee@example.com`; set `SEED_ADMIN_EMAIL` / `SEED_EMPLOYEE_EMAIL` to real inboxes if testing real delivery (local dev shows codes in MailHog regardless).
6. Nothing on the spec's **out-of-scope** list was built; `hr-ai` contains no AI logic.

---

## 5. Questions for review — resolved

> All review questions below were resolved at Sprint 0 sign-off (review round 1). Resolutions recorded here.

1. **Andalucía `code` — RESOLVED.** `AN` is an **accepted placeholder for Sprint 0**. The `provinces` table will be restructured to handle national/regional/provincial scope levels during the **Sprint 1 registry import**; do not hard-code around `AN` further. (Limitation also flagged at the schema in `data-model.md`, near the `provinces` table.)
2. **Route prefix — RESOLVED.** API routes **stay at root** (`/auth/*`, `/me`) — **no `/api` prefix**.
3. **`account_type` resolution on collision (Q4) — RESOLVED.** **Employees-first** account_type resolution is **accepted for Sprint 0**.
4. **Folder name — RESOLVED.** Workspace folder renamed to a bracket-free path, so `artisan migrate` runs directly from the repo (gap #2 no longer applies on this machine).

---

## 6. Commit status

Code is built and self-verified but **not yet committed/pushed** this turn (the task was: build, write this review, and stop). On your go-ahead I'll commit each repo to its remote (`hr-backend`, `hr-ai`, `hr-frontend`, and the doc updates in `hr-docs`).
