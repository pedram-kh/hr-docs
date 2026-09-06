# Staging environment — Cursor kickoff prompt (plan-gate)

> **Prerequisite — Pedram does this first, in the AWS console, NOT Cursor:** create an IAM user `hr-staging-deployer` (never use the root account), attach `AmazonEC2FullAccess`, `AmazonRDSFullAccess`, `AmazonS3FullAccess`, `AmazonSSMFullAccess` (+ `IAMFullAccess` temporarily, only for creating the EC2 instance profile — remove after), create an access key, and run `aws configure` locally with region `eu-west-1`. **Never paste the key into a chat, a repo, or a Compose file.** Confirm with `aws sts get-caller-identity`. Then paste this prompt into a **fresh** Cursor thread.

---

You are in the `hr-platform` workspace. Sprints 0–7c are committed; the 7d build is uncommitted across all four repos (leave it exactly as is — it will deploy to staging after this). **This is the staging-environment mini-sprint**, pulled forward from the pre-go-live phase. Read `hr-docs/sprints/staging-env/spec.md` and `hr-docs/deploy.md`.

The goal: a **repeatable, scripted AWS staging environment in `eu-west-1`** — RDS PostgreSQL 16 + pgvector, S3, one EC2 `t3.large` running the four services + queue worker + Caddy via Docker Compose, BGE-M3 pre-cached in a persistent volume, secrets in SSM Parameter Store, a tested backup/restore, resize-for-ingest, stop/start, one-command deploy — then one clean ingest from source files. **Create no AWS resource this turn — plan first.**

Before anything, read in full and cite real lines:
- How each service is **configured today**: hr-backend `.env`/`config/*` (DB, queue driver, filesystem/S3 adapter per ADR-0009, mail/Postmark, the app key), hr-ai `.env`/settings (DB URL, the internal token, the embedding model name + **where the HF cache lives** and **when the model is loaded** — startup vs first call), hr-frontend build config (API base URL), the queue worker command, any existing Dockerfiles/compose files.
- `chunks:embed` and `/embed` — confirm the model load path and cache dir so the image/volume design pre-caches BGE-M3 (**4.3 GB, both safetensors + pytorch snapshots present today**) and hr-ai's health can verify "model present".
- `deploy.md` (known coverage gaps, the queue-worker + confirm-route items), `registry:import`, `documents:ingest-folder`, `salary:import`, `chunks:embed` — the ingest sequence and where source files are read from (local folder today → S3 on staging).
- ADR-0007 (only hr-backend migrates), ADR-0009 (storage adapter), ADR-0015 (answer-model key in DB), ADR-0018/0019 (nothing about access/guardrails changes on staging).
- Confirm `aws sts get-caller-identity` works and shows the deployer user in `eu-west-1`. **Do not print any key.**

Your task this turn: **inspect and plan — create nothing in AWS, change no application code.**

Produce `hr-docs/sprints/staging-env/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The real env/config surface of each service, the model cache location + load timing, the queue driver, the storage adapter's S3 config keys, the ingest command sequence and its source-folder assumption. Anything that is currently local-only (MinIO endpoints, localhost URLs, the 55433 port) that must become config.
2. **The infra scripts** (idempotent bash + `aws` CLI, in `hr-docs/infra/` or `hr-infra/`): VPC/default-VPC choice, security groups (SSH from Pedram's IP only; 80/443 public; RDS from the EC2 SG only), RDS (16, `db.t4g.medium`, 50 GB gp3, single-AZ, backups 7d, not public, `CREATE EXTENSION vector`), S3 buckets (documents; backups with versioning), SSM parameters (**names only — values set by Pedram**), the EC2 (`t3.large`, Ubuntu 24.04, 100 GB gp3, instance profile with S3 + SSM read, Elastic IP), `stop.sh`/`start.sh`/`resize-for-ingest.sh` (→ `c7i.2xlarge`)/`resize-back.sh`, `status.sh`. State exactly what each script creates and how re-running is safe.
3. **Containerization.** Dockerfiles for hr-backend (+ the worker as the same image running `queue:work`, `restart: unless-stopped`), hr-ai (BGE-M3 **baked at build or pulled into a named volume on first start, and a health check that fails until the model is present**), hr-frontend (static build), Caddy (TLS if a subdomain exists; plain HTTP on the EIP for first bring-up). `docker-compose.staging.yml`. Log rotation.
4. **Secrets flow.** SSM SecureString → an entrypoint that exports env vars at container start; `.env.staging.example` templates with placeholders; a `git grep`/scan step proving no secret is in any repo, image layer, or Compose file. The Anthropic key stays in the DB via the admin screen (ADR-0015).
5. **The deploy path.** `deploy.sh`: pull pinned commits for all four repos → build → `migrate --force` (hr-backend only) → restart → health-check all four + worker → print versions. Rollback procedure. **Two-step bring-up:** (a) stack up with the empty DB, proving the deploy path; (b) the ingest on the resized instance; (c) the backup rehearsal.
6. **Backup + restore.** Nightly `pg_dump` → S3 (versioned) as a Compose one-shot/cron + RDS automated snapshots; **the restore rehearsal** (into a scratch DB, count verification) written as a procedure and actually run once during the build.
7. **The ingest on staging.** Source files → S3 → the same command sequence; the resize before, snapshot + resize-back after; expected counts and the **expected legitimate zeros** (scans → 7e; `under_review` COEAS Estatal skipped by design; the 2-of-5 calibration anchors).
8. **Cost + ops.** The ~$4–5/day figure confirmed against the chosen sizes; the stop-when-idle habit; CloudWatch or `docker logs` rotation; what `status.sh` shows.
9. **Migrations & build order** (infra → containers → secrets → deploy → ingest → backup rehearsal → docs), the **ADR** for the staging/deploy topology, and **assumptions & open questions** — esp. domain/TLS/Postmark availability, whether to bake the model into the image (bigger image, deterministic) vs a volume (smaller image, first-start fetch), and the IAM least-privilege cut.

Hard constraints:
- **Create no AWS resource and change no application code this turn.** Plan only.
- **Secrets never in a repo/image/Compose file/chat/log.** Scripts reference SSM parameter names; Pedram sets values.
- **hr-backend migrates; hr-ai never migrates** (ADR-0007). **S3 via the existing adapter — config only, no code** (ADR-0009). **No feature work.**
- **Scripted + idempotent** so production is a re-run with different sizes. **Model pre-cached + health-verified.** **Worker auto-restarts.** **Backup + restore actually rehearsed.**
- Two-step bring-up; don't attempt everything in one session.

Do not create or modify any file other than `hr-docs/sprints/staging-env/plan.md` this turn. After writing it, stop and say it is ready for review.
