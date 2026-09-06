# HR Platform — Deployment / Go-Live Checklist

> Canonical location: `hr-docs/deploy.md`
> Status: **living document.** Standing checklist of things that must be true **before the platform serves real employees** — distinct from what each sprint builds. Items here are *deploy-time*, not code to write in a sprint; a sprint may **add** an item here when it introduces something that needs operational/compliance handling at go-live.
> Read with `roadmap.md` (the pre-go-live phase) and `architecture.md` §11 (privacy).

This file exists so deploy-time obligations are **on the record and can't be lost to a chat compaction or a sprint hand-off**. Nothing here blocks a build; everything here blocks (or gates) go-live.

---

## 1. External LLM answer model — GDPR / data-processing (added: Sprint 2b)

The employee chat (Sprint 2b) sends the **employee's question + retrieved convenio text** to an external LLM provider (default **Claude API**) to synthesise the answer. That means personal data of Sedena staff is processed by a third party on every answer, which triggers EU/Spanish GDPR obligations. **All three must be satisfied before go-live:**

- [ ] **EU / approved endpoint.** Use the provider's EU-region (or otherwise approved-for-EU) API endpoint, so data isn't processed in a region without an adequate-protection basis. *(Sprint 2b-1 wired this as non-secret config: `HR_AI_ANSWER_MODEL` / `HR_AI_ANSWER_ENDPOINT` on `hr-backend` — passed to `hr-ai /synthesise` per call. These **must** point at an EU-available model/endpoint before go-live.)*
- [ ] **EU / approved endpoint for the ROUTER and GROUNDING calls (added: Sprint 2b-2).** 2b-2 added two more provider calls that see the employee's **question** (`/route`, ADR-0016) and the **question + answer + cited convenio text** (`/ground`, the per-claim grounding check). `/ground` uses the answer model (same `HR_AI_ANSWER_ENDPOINT`, already covered above). `/route` uses a **separate router model** (`HR_AI_ROUTER_MODEL`) and a **`HR_AI_ROUTER_ENDPOINT`** that **defaults to the answer endpoint** — *but if it is set to a distinct endpoint, that endpoint must independently satisfy all three obligations here (EU region, signed DPA, zero-retention).* Verify the router endpoint is EU before go-live, or leave it defaulted to the (already-vetted) answer endpoint.
- [ ] **Signed Data Processing Agreement (DPA).** A signed DPA must be in place with the answer-model provider — the contract (legally required when a third party processes personal data on your behalf) committing them to: use the data only to provide the service (**no training on it**), keep it secure, not retain it, and act only on Sedena's instructions as controller. Anthropic offers a standard DPA — sign it. *(Covers the answer, router, and grounding calls when they share a provider/endpoint; a distinct router endpoint needs its own DPA.)*
- [ ] **Zero data retention enabled.** Turn on the provider's zero-retention / no-logging option so the question + convenio text are not stored by the provider after the answer is returned. (DPA + zero-retention go together; applies to the router + grounding calls too.)

**Why:** Spain's data-protection authority (**AEPD**) and Sedena's **comité de empresa (works council)** will expect a signed DPA and a clear data-flow story; a missing DPA is a common go-live blocker (see `roadmap.md` Sprint 9 — privacy/GDPR hardening, and the works-council sign-off that can gate deployment).

**Note (not a deploy item — design fact, built in Sprint 2b-1, extended 2b-2):** the API key is **never** in the browser and **never** in plaintext — it's set once in the admin "Answer model" screen, encrypted at rest (`Crypt`, app-key, in `answer_model_settings`), shown masked (`••••1234`, reconstructed from `key_last_four` without decrypting), and rotatable but not readable back. The frontend never calls the provider directly; only `hr-backend` does, decrypting the key in `ChatService` for the turn and passing it in the request **body** (never a header) to `/route`, `/synthesise`, and `/ground` (the same key path for all three — ADR-0015/0016), never persisted by `hr-ai`.

---

## 2. Secrets & configuration (placeholder — expand as built)

- [ ] Answer-model API key configured (admin "Answer model" screen; encrypted at rest).
- [ ] `X-Internal-Token` (the `hr-backend` ↔ `hr-ai` shared secret) set from environment on both services, not the dev default.
- [ ] Email/OTP transactional provider (Postmark) configured with SPF/DKIM; **MailHog is dev-only** (ADR-0003).
- [ ] The scoped `hr_ai` Postgres role (write only on `document_chunks`) is provisioned in the production database (Sprint 2a migration).
- [ ] S3-compatible storage pointed at the production bucket (not local MinIO); storage adapter env set (ADR-0009).

## 3. Data bootstrap (placeholder)

- [ ] The corpus is loaded into **production** through the **admin upload UI** — *not* a folder committed to git (`hr-backend/data/all-files` is a **dev convenience only**; real HR documents must not live in the repo).
- [ ] Registry import (`registry:import`) run against production.
- [ ] Chunk embed (`chunks:embed`) and salary import (`salary:import`) run against production (they populate the prod database; dev vectors do **not** carry over).
- [ ] Employee directory loaded (CSV bulk upload / manual), per ADR-0004 (Sprint 5).
- [ ] ⚠️ **Precondition (added: Sprint 7c eyes-on):** do **not** seed `convenio_job_categories` with clean digit `group_code`s for a convenio that has **verified group-scoped reference facts** (e.g. convenio 21 Hostelería Navarra) **before Sprint 7f lands** — the 7c group-matcher (`ReferenceFactAnswerService::factMatchesGroup`) would then make a latent confident-wrong-group answer live. See `roadmap.md` Sprint 7f and `sprints/sprint-07c/review.md` §7.

## 4. Pre-go-live cleanup — privacy / confidentiality hygiene (added: Sprint 5)

Some dev seed/test fixtures (and at least one doc string) carry **real-world identifiers** that must not ship to production. This is a privacy/confidentiality hygiene item, **not a functional bug** — nothing misbehaves, but real names and the internal project codename must be scrubbed before the platform serves real employees (and before the works council / AEPD see the repo).

- [ ] **Scrub test fixtures and doc strings of real identifiers before go-live.** Some test/seed data — and at least one doc string — contain real-world identifiers (a real company name in a test escalation's source question; the internal project codename, which by convention must **never** appear in code or docs). Before go-live: **(a)** replace any real company/person names in seed + test fixtures with **obviously-fake placeholders**; **(b)** `grep` the codebase + docs for the internal codename and any real client identifiers and remove them; **(c)** confirm **no real `@`-domain emails or personal data** sit in committed fixtures. *(The specific offending values are intentionally not reproduced here — that would defeat the scrub; locate them with the grep in (b).)*

## 4a. Known coverage gaps — documents the system can't yet answer from (added: Sprint 7a)

Surfaced concretely during the Sprint 7a eyes-on. Four distinct cases — named here so go-live tracks each explicitly rather than discovers them in production:

- **Scanned PDFs (no text layer) → unanswerable.** The AI tagging tier can't tag them (`no_extractable_text` skip), and the embed pipeline can't chunk them, so they produce 0 chunks and the chat cannot answer from them. Fix = OCR at the extraction step (Sprint 7e). *Pre-go-live action:* OCR the active-scope scans (e.g. the scanned convenios already flagged in §5 below — `CONVENIO DEPORTE NAVARRA 2025–2028` id 89, `PACTO CULTURA NAVARRA` id 91, and any others surfaced by `reviews:scan-expiry`) so no live scope's only document is a dead scan.
- **No-convenio / multi-scope documents → not in the answerable set.** Scope derives via the convenio; a document with no `convenio_id` carries no scope and is neither shown in the map's answerable set nor used in answers. These include: manually-uploaded single-file summaries covering multiple convenios, partial-agreement documents whose convenio couldn't be resolved at ingest, and any `Plan de Igualdad`-style cross-scope PDF. Fix = Sprint 7b (Structured Reference Knowledge — segment into individually-scoped facts rather than forcing one `convenio_id` onto a multi-scope file). *Pre-go-live action:* audit the `under_review` docs with `convenio_id = NULL`; either assign a convenio where the doc genuinely covers one scope, or accept that they await Sprint 7b.
- **Non-salary structured Excel / docx (hours tables, SMI summaries, compiled rule compilations) → no home yet.** Not salary (the salary SQL path, ADR-0006, doesn't apply), and not single-scope prose (the vector path doesn't apply). They arrive as scans or structured files and sit `under_review` with no route to retrieval. Fix = Sprint 7b (same structured-reference segmentation mechanism). *Pre-go-live action:* identify these files in the `under_review` queue (they're typically `.xlsx` or prose `.docx` covering multiple rules across areas); accept the gap or defer to Sprint 7b.
- **Salary Excel → already handled (not a gap).** Salary tables go to the `salary_tables` SQL path (ADR-0006) via `salary:import`; they are served in chat as exact structured answers, not via embedding. Listed here for completeness so the three real gaps above are not obscured by wondering about salary.

## 5. Corpus coverage — unanswerable / expired convenios (added: Sprint 2c)

The Sprint 2c re-chunk used the **registry** as the arbiter of the active set; doing so surfaced two go-live coverage risks. Neither blocks a build; both must be **resolved or explicitly accepted** before serving employees in the affected province×sector cells.

- [ ] **Scanned, no-extractable-text convenios → currently unanswerable.** Some registry-`active` convenios are **image-only PDFs** with no extractable text, so they produce **no chunks** and the chat cannot answer from them: **`CONVENIO DEPORTE NAVARRA 2025–2028` (id 89)** and **`PACTO CULTURA NAVARRA` (id 91)** (both also `under_review`). They need **OCR** before go-live, or an **explicit scope exclusion** so the gap is acknowledged rather than silent. *(Same family as any other scan in the corpus.)*
- [ ] **Expired convenios with no loaded active successor → coverage gaps.** The registry holds these convenio **prose texts only as `historical`** (validity ended 2024/2025) with **no `active` successor loaded**, so their province×sector has **no current document** to answer from: Andalucía **COEAS** (id 29), Vizcaya **Intervención Social** (id 18 + family 13/16), Huesca **Hostelería** (id 33), Salamanca **Oficinas** (id 51), Gipuzkoa **Intervención Social** (id 45), Navarra **Hostelería** (id 75) and **Oficinas-despachos prose** (id 93, where only a salary-table PDF id 94 is active), Deporte **Estatal** (id 69), Asturias **Deportes** (id 52), Navarra **Deporte** (id 80). Two further successors exist but are stuck `under_review` (not yet trustworthy): **COEAS Estatal** (id 72), **COEAS Madrid** (id 24). Before go-live: **load/activate the current text**, **clear the `under_review` ones**, or **accept the gap** per cell. (Feeds Sprint 8 coverage-gap detection — see `roadmap.md`.)

## 6. Operational readiness (placeholder — see roadmap pre-go-live phase)

- [ ] Monitoring + alerting (service-down, escalation-rate spikes).
- [ ] Rate limits (abuse / cost protection — especially the external answer API).
- [ ] Backups + a **tested** restore.
- [ ] Containerisation / deployment pipeline.

---

## 7. Staging environment — build record (in progress)

> Full runbook to be written at the end of the staging build (session 3 of `hr-docs/sprints/staging-env/`). This section is a running record of what session 1 (infra) actually created, so resource ids/tags survive a chat compaction. See `hr-docs/sprints/staging-env/plan.md` for the design and `hr-docs/infra/` for the scripts.

### Session 1 (infra) — complete

Region `eu-west-1`, account `049681810267`. Every resource carries the `hr-staging-` name prefix and the tags `Project=hr-platform`, `Env=staging`, `ManagedBy=hr-docs/infra` (this account also hosts another project's resources — `reviewpilot*`/`scheduler*` — never touched by any script here).

| Resource | Id | Notes |
|---|---|---|
| VPC (default, reused) | `vpc-0f33f60b804ff6738` | not created — confirmed existing |
| DB subnet group | `hr-staging-db-subnet-group` | spans all 3 default-VPC AZs |
| EC2 security group | `hr-staging-ec2-sg` (`sg-0632f8c8fc693b5ce`) | 22/tcp from admin IP (resolved live by `02-security-groups.sh`, re-diffed on every run — never hardcoded), 80/443 public |
| RDS security group | `hr-staging-rds-sg` (`sg-04c582d52899dac72`) | 5432/tcp from `hr-staging-ec2-sg` only (SG-to-SG, not a CIDR) |
| RDS instance | `hr-staging-db` — endpoint `hr-staging-db.cpsukkwcomk6.eu-west-1.rds.amazonaws.com` | PostgreSQL 16, `db.t4g.medium`, 50GB gp3, single-AZ, 7-day backups, `PubliclyAccessible=false`, storage-encrypted. `CREATE EXTENSION vector` applied (v0.8.1, HNSW+ivfflat) from inside the VPC via SSH through the EC2 — RDS was never opened publicly. DB name `hr_platform`, master user `hr_staging_admin`. |
| S3 documents bucket | `hr-staging-documents-049681810267` | encrypted (SSE-S3), public access blocked, versioning off |
| S3 backups bucket | `hr-staging-backups-049681810267` | encrypted, public access blocked, **versioning ON** |
| IAM role | `hr-staging-ec2-role` | trust: `ec2.amazonaws.com`; two inline policies: `hr-staging-s3-read` (ListBucket on both buckets; GetObject/PutObject/DeleteObject on the documents bucket — hr-backend uploads + hr-ai page images both need write, found in session 2 while wiring the compose stack; GetObject/PutObject on the backups bucket for the nightly dump), `hr-staging-ssm-read` (GetParameter(s)/GetParametersByPath on `/hr-staging/*` + `kms:Decrypt` on `alias/aws/ssm`) |
| IAM instance profile | `hr-staging-ec2-profile` | contains `hr-staging-ec2-role`; attached to the EC2 at launch |
| EC2 key pair | `hr-staging-ec2-key` | **private key exists ONLY at `~/.hr-staging/hr-staging-ec2-key.pem` on the operator's machine** (chmod 400) — never committed, never printed, unrecoverable from AWS if lost (delete+recreate the key pair if it is) |
| EC2 instance | `i-057d55d8dbec6978f` | `t3.large`, Ubuntu 24.04 (AMI resolved live via the public SSM parameter `/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id` — was `ami-0526a6499f6470118` at build time, never hardcoded), 100GB gp3, `hr-staging-ec2-profile`, `hr-staging-ec2-sg`. User-data installed Docker + Compose v2 + `postgresql-client`; `/opt/hr-staging/ingest-scratch` created. |
| Elastic IP | `52.211.251.235` | associated with the instance |

**SSM parameters** (names only — values never appear in a repo/chat/log):

| Name | Set by | Value class |
|---|---|---|
| `/hr-staging/rds/master-password` | `05-ssm-params.sh` (machine-generated) | machine-only — RDS master credential |
| `/hr-staging/shared/hr-ai-db-password` | `05-ssm-params.sh` (machine-generated) | machine-only — becomes `HR_AI_DB_PASSWORD` for hr-backend's migration (creates the scoped `hr_ai` Postgres role, ADR-0007) and hr-ai's own `DATABASE_URL` |
| `/hr-staging/shared/internal-token` | `05-ssm-params.sh` (machine-generated) | machine-only — `HR_AI_INTERNAL_TOKEN` (hr-backend) / `INTERNAL_TOKEN` (hr-ai), must match on both sides |
| `/hr-staging/hr-backend/app-key` | `05-ssm-params.sh` (machine-generated) | machine-only — Laravel `APP_KEY` (`base64:...`) |
| `/hr-staging/hr-backend/postmark-api-key` | placeholder — unused this session | human-owned; `MAIL_MAILER=log` for now (§7 below); set when a domain + a new Postmark *server* on the HR domain exist |

**Deviation from the original build-authorization prompt, disclosed and accepted by Pedram:** the four machine-only credentials above were generated by `05-ssm-params.sh` (`openssl rand`) and written straight to SSM via command substitution — never displayed, never typed, never in shell history — rather than asked of Pedram interactively. This was also a practical necessity: `03-rds.sh` needs the master password to exist *before* RDS can be created, which happens earlier in the build order than the prompt's "Pedram sets SSM values" step. Pedram can rotate any of these via `aws ssm put-parameter --overwrite` at any time; nothing here is a secret a human is expected to remember.

**IAM bootstrap policy discipline (attach → build → detach), as it actually happened:** the `hr-staging-iam-bootstrap` policy (plan §2.4) was attached to `cursor-dev` twice and detached twice this session — once around `06-iam.sh` (role + instance profile creation), and a second time around `07-ec2.sh` (because `iam:PassRole` is also required when EC2 attaches an instance profile at `run-instances`, not only at role-creation time — a correction to the original plan, worth carrying into the production re-run's runbook). Both attach/detach cycles were confirmed live: `iam:CreateRole`/`iam:PassRole` denied before attach, succeeded after, denied again after detach. `cursor-dev` currently holds `PowerUserAccess` only — no standing IAM-management grant.

**Corpus source-file upload note:** neither the documents S3 bucket nor `/opt/hr-staging/ingest-scratch` has source files yet — that's session 3 (§7, `documents:ingest-folder` needs a local path, so files land in S3 first and are then synced down to the scratch folder on the resized instance).

**Not yet done (session 2+):** no application containers exist yet; `hr-staging-ec2-role`'s SSM-read policy has not been exercised by an actual container; the RDS `hr_ai` role does not exist yet (created by hr-backend's migration in session 2, `2026_06_22_100001_create_hr_ai_role_and_chunk_indexes.php`, reading `HR_AI_DB_PASSWORD`).

**Cost note:** as of this session, EC2 (`t3.large`) + RDS (`db.t4g.medium`) are both running 24/7 — matches the plan's ~$4/day estimate. `stop.sh`/`start.sh` exist in `hr-docs/infra/` (untested this session — first real use is session 3 per the build order) to stop them when idle.

### Session 2 (containers + deploy) — complete

Built on top of session 1's infra, no AWS resource re-created.

**Dockerfiles (net-new, one per app repo, ADR-0025):**

| Repo | Image shape |
|---|---|
| `hr-backend/Dockerfile` | Two-stage: `composer:2` vendor stage → `php:8.3-fpm` runtime with nginx + supervisord (both processes in one container — simplest given no Octane setup). Reused unmodified as `hr-backend-worker` (compose overrides the command to `php artisan queue:work --tries=3 --timeout=120`). |
| `hr-ai/Dockerfile` | `python:3.11-slim`, CPU-only torch wheel, `HF_HOME=/model-cache` set explicitly (closes the plan §1.2 gap). |
| `hr-frontend/Dockerfile` | `node:22-slim` build stage (`VITE_API_BASE_URL` as a build ARG, per the §1.3 build-time-vs-runtime finding) → tiny `alpine` stage that copies `dist/` into a shared volume for Caddy and exits (`restart: "no"`). |
| Caddy | Official `caddy:2-alpine`, no custom Dockerfile — `hr-docs/infra/compose/Caddyfile` (plain `:80`, `/api/*` + `/up` → hr-backend, everything else → the frontend volume with SPA fallback). |

**Compose + secrets plumbing**, all in `hr-docs/infra/` (not any one app repo — it references all four as sibling build contexts):

- `infra/compose/docker-compose.staging.yml` — 6 services (`hr-backend`, `hr-backend-worker`, `hr-ai`, `frontend-dist`, `caddy`; no `postgres`/`minio`/`mailhog` — those are RDS/S3/log-driver-mail on staging). `json-file` logging (`max-size: 20m, max-file: 5`) on every service.
- `infra/compose/entrypoint.sh` — shared by hr-backend, hr-backend-worker, hr-ai. Set as each service's Compose `entrypoint:` (not `command:`) specifically so `docker compose run --rm hr-backend php artisan migrate --force` (the deploy script's migrate step) still goes through the SSM resolution — Docker concatenates `entrypoint:` argv + the run-time command argv, so this works whether the container is started normally or via `run`/`exec` with a different command. Resolves named SSM parameters into process env via the instance profile's credential chain; nothing written to disk.
- `infra/compose/leak-scan.sh` — run by `deploy.sh` before every build, not just once (staging plan §4 step 4): scans all four repos, `docker-compose.staging.yml`, and (once present) the built image histories for AKIA-style keys, raw secret assignments, and PEM headers.
- `infra/compose/otp.sh` — login verification helper (see below).
- `infra/08-derived-secrets.sh` — added because hr-ai's `config.py` takes ONE composed DSN (`DATABASE_URL`), not decomposed `DB_HOST`/`DB_USER`/... vars like hr-backend does. Composes `postgresql://hr_ai:<password>@<rds-endpoint>:5432/hr_platform` once (RDS endpoint + the already-generated `hr-ai-db-password`, neither typed nor echoed) and stores it whole as `/hr-staging/hr-ai/database-url` (SecureString). Idempotent — same "leave a real value untouched" pattern as `05-ssm-params.sh`.
- `infra/vars.sh` — two new hardcoded constants, `RDS_ENDPOINT` and `STAGING_EIP`: `deploy.sh` runs ON the EC2 under the instance profile, which has no `ec2:Describe*`/`rds:Describe*` permission (only the narrow S3+SSM read policies) — both values are stable once created (an RDS endpoint hostname never changes across restarts; an EIP is static by definition), so they're hardcoded once rather than widening the instance profile just to re-derive values that don't change.

**hr-ai code changes this session (both additive, disclosed and authorized live — see ADR-0025):**
1. `GET /health/model` — checks the BGE-M3 safetensors snapshot exists under `$HF_HOME`; does not modify `/health` or `/health/config`. Compose's hr-ai healthcheck polls this.
2. `app/storage.py` — omits `aws_access_key_id`/`aws_secret_access_key` from `boto3.client()` when both are empty/unset, falling back to the default credential chain (the instance profile on staging). Local dev (MinIO, always set) unchanged. No IAM user, no new policy, no SSM key added for this — see the README note in `hr-ai/README.md`.

**hr-backend addition:** `php artisan staging:seed-test-users` (`app/Console/Commands/StagingSeedTestUsers.php`) — a thin, explicit wrapper around the existing `TestUserSeeder` (no logic duplicated), refusing to run if any `SEED_*_EMAIL` still resolves to blank. Seeds one admin per role (`super_admin`, `knowledge_editor`, `auditor`, `hr_agent`) + one test employee.

**`deploy.sh`** (`hr-docs/infra/deploy.sh`) — `deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>`, run from the EC2: clone/checkout the four pinned SHAs into `/opt/hr-staging/` → leak scan → `docker compose build` → `docker compose run --rm hr-backend php artisan migrate --force` (hr-backend only, ADR-0007) → `docker compose up -d` → a 30-attempt/10s health-check loop (`/up`, `/`, hr-ai `/health` + `/health/model`, worker container state `running`) → prints all four deployed SHAs → records `.last-good-shas` **only on a fully green run**.

**Rollback procedure (documented + tested):** symmetric with deploy — re-run `deploy.sh` with an OLDER set of four SHAs (`.last-good-shas` holds the set that was good *before* the run being rolled back from, so read it before a risky deploy, not after a bad one). Tested this session: deployed a deliberately broken hr-backend commit (syntax error), confirmed the health-check loop correctly failed and did **not** overwrite `.last-good-shas`, then ran `deploy.sh $(cat .last-good-shas)` and confirmed the stack returned to the last known-good state with a full green health-check pass.

**`otp.sh` login verification:** since no Postmark account exists yet, staging runs `MAIL_MAILER=log` — the OTP email (incl. the plaintext code) lands in `storage/logs/laravel.log` instead of an inbox. `otp.sh <email>` requests a code, reads it back out of that log (anchored on the mailable's subject line so it doesn't grab an unrelated 6-digit number), and verifies it — used this session to confirm a `super_admin` login end-to-end issues a Sanctum bearer token. **Correction logged (plan §1.1/§9 item 6):** `hr-backend/.env.example` and this file's §2 checkbox both said `POSTMARK_TOKEN` — the var Laravel's Postmark mailer config actually reads is `POSTMARK_API_KEY` (`config/services.php:18`). `POSTMARK_TOKEN` in a real `.env` is a silent no-op. Staging's `.env.staging.example` uses the correct name; switch `MAIL_MAILER=log` → `MAIL_MAILER=postmark` + set `POSTMARK_API_KEY` (not `POSTMARK_TOKEN`) the moment a Postmark account + sending domain exist — no other change.

**ADR written:** `hr-docs/architecture/decisions/0025-staging-deploy-topology.md`.

**Not yet done (session 3):** ingest on staging (source corpus → S3 → resize → `registry:import`/`documents:ingest-folder`/`chunks:embed`/`salary:import` → resize back), the nightly `pg_dump` backup service + a restore rehearsal against a throwaway RDS instance.

---

> **Maintenance rule:** when a sprint introduces something that needs handling at go-live (a new secret, a new external dependency, a new compliance obligation), add a checkbox here **in the same change**, the same way docs move with code elsewhere. Don't let deploy-time obligations live only in a sprint review.
