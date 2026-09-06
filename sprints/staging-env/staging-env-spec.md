# Staging environment — spec (pulled forward from the pre-go-live phase)

> Location: `hr-docs/sprints/staging-env/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `architecture.md` §2 (the four services + the Laravel↔Python contract), §3 (three storage layers; the S3 adapter — ADR-0009 — so MinIO→S3 is config, not code), the hr-ai model/embedding notes (BGE-M3, ADR-0006/0010); `deploy.md` (the existing go-live notes, coverage gaps, the queue-worker + confirm-route items); ADR-0007 (hr-backend owns migrations; hr-ai never migrates); ADR-0015 (the answer-model key: encrypted at rest, per-call, never in the browser — on staging it lives in the DB as today, set once via the admin screen).
> **Why now.** Local dev has become the bottleneck and the risk: today alone — a lost Docker volume, a dependency crash, a silent 4.3 GB model download, and CPU-bound embedding at **14.5 min per document (~5–7 h for the corpus)**. Every remaining sprint (7d calibration, 7e OCR, 7f group-scope, 8, 9) needs the real vectorized corpus on stable infrastructure. Standing staging up now pulls the deploy/ops risk **forward** to where there are sprints left to absorb it, and turns the eventual production deploy into a rehearsed repeat. **From here on, every sprint ships to staging; the sprint-gate loop is unchanged — only the eyes-on moves from a laptop to staging.**

## Goal
A **repeatable, scripted** AWS staging environment in **eu-west-1** for ~20 test users: managed **RDS PostgreSQL (with pgvector)** + **S3**, one **EC2** running the four services via **Docker Compose**, the **BGE-M3 model pre-cached** in a persistent volume, **secrets in SSM Parameter Store** (never in the repo/image), a **tested backup + restore**, a documented **resize-for-ingest** procedure, and a **one-command deploy** — then **one clean ingest from the source files**, snapshotted. Everything created by CLI scripts checked into `hr-docs/infra/` (or a small `hr-infra` folder) so production is a re-run with different sizes.

## Decisions (locked)
- **Region:** `eu-west-1` (Ireland).
- **Compute:** one EC2 **`t3.large`** (2 vCPU / 8 GB — enough headroom for hr-ai to hold the ~2 GB model in RAM alongside the other services), Ubuntu 24.04, **100 GB gp3 EBS**. Daily driver for ~20 users.
- **Ingest mode:** temporarily resize to **`c7i.2xlarge`** for the ingest hour, then back. The model cache and everything else live on the EBS volume, so a resize is stop → change type → start, with **no re-download**.
- **Database: RDS PostgreSQL 16, `db.t4g.medium`, 50 GB gp3, single-AZ** (staging), `vector` extension enabled, automated backups on (7-day retention), **not publicly accessible** — reachable only from the EC2's security group.
- **Object storage: S3** — one bucket for documents/page images (via the existing ADR-0009 adapter — MinIO is retired on staging) + one prefix/bucket for DB backups. Versioning on the backup location.
- **Queue:** the existing DB-backed queue + a worker container (no SQS). The worker is a Compose service with `restart: unless-stopped` — the "is the worker running?" failure class is closed by construction.
- **Secrets:** SSM Parameter Store (SecureString) → injected into containers at start via an entrypoint/env script. **Never** in the repo, the image, a Compose file, or a chat. The Anthropic answer-model key stays where it is today (encrypted in the DB, set via the admin screen).
- **Networking:** a security group allowing SSH (your IP only) + HTTP/HTTPS to the frontend/backend; RDS reachable only from the EC2 SG. A single public entry (the frontend, with the backend proxied) — TLS via a simple reverse proxy (Caddy) with an automatic cert if you point a subdomain at it; plain HTTP on the public IP is acceptable for the first bring-up, TLS before any real test user.
- **Cost posture:** ~$4–5/day running 24/7; **stop the EC2 (and RDS) when idle** — a documented one-command stop/start. Ingest costs ~$0.50 extra per run.

## In scope
1. **Infra scripts (idempotent, checked in):** VPC/subnets (default VPC is fine for staging), security groups, the RDS instance + `vector` extension, the S3 buckets, the SSM parameters (created empty/placeholder — **values set by Pedram, never by a script that would log them**), the EC2 with an instance profile granting S3 + SSM read, an Elastic IP, and the EBS volume. Plus `stop.sh` / `start.sh` / `resize-for-ingest.sh` / `resize-back.sh`.
2. **Containerization:** Dockerfiles for hr-backend (PHP-FPM + nginx or Octane), hr-ai (Python; **BGE-M3 downloaded at image build or into a named volume on first start — verified present before the service reports healthy**), hr-frontend (built static, served by the proxy), the queue worker (same backend image, `queue:work`), Caddy. One `docker-compose.staging.yml`.
3. **Config for staging:** hr-backend/hr-ai `.env` templates (`.env.staging.example`) pointing at RDS + S3 + the internal hr-ai URL; the S3 adapter switched from MinIO to S3 by config only (ADR-0009 — **no code change**).
4. **The deploy path — one command:** `deploy.sh` = pull the pinned commits for all four repos → build images → `php artisan migrate --force` (hr-backend only; hr-ai never migrates) → restart services → health-check all four → print the versions deployed. Rollback = redeploy the previous commit set (documented).
5. **Backup + restore, tested:** nightly `pg_dump` to S3 (a cron/Compose one-shot) **and** RDS automated snapshots; **a restore is actually rehearsed once** (restore into a scratch DB, verify counts) and the procedure written down. The corpus must never be "rebuild from scratch by accident" again.
6. **One clean ingest from the source files** (uploaded to S3, run through the existing `registry:import` + `documents:ingest-folder` + `chunks:embed` on the resized instance), then **snapshot**. Report counts (documents, chunks, salary rows, reference facts).
7. **Operational visibility (minimum):** `status.sh` printing service health, queue pending/failed, chunk count, disk/RAM; container logs to CloudWatch (or at least `docker logs` with rotation). Enough to tell "running" from "stuck".
8. **Docs:** `hr-docs/deploy.md` updated into a real runbook (bring-up, deploy, stop/start, resize-for-ingest, backup/restore, secrets, the model-cache lesson, the known coverage gaps); an **ADR** for the staging/deploy topology (managed RDS+S3, single-EC2 Compose, secrets-in-SSM, model-pre-cached, scripted infra).

## Out of scope
- Production sizing/multi-AZ/load balancer/autoscaling (a later re-run of the scripts with different parameters); SQS/ECS/EKS/SageMaker (over-engineering at this scale); CI/CD pipelines (the one-command deploy is enough for now); least-privilege IAM hardening beyond a sensible first cut (pre-go-live); any application feature work.

## Acceptance criteria
1. `aws` CLI scripts create the whole environment from nothing, idempotently, in `eu-west-1`; re-running is safe.
2. All four services + worker + proxy run via Compose on the `t3.large`; `status.sh` shows all healthy; hr-ai reports the BGE-M3 model **present and loaded** without downloading at runtime.
3. RDS has `vector` enabled and is reachable **only** from the EC2; S3 holds documents via the existing adapter (no MinIO); **no secret appears in any repo, image, Compose file, or log** (grep-proven).
4. `deploy.sh` deploys a pinned commit set end-to-end (migrate + restart + health) in one command; a rollback is documented and tried once.
5. Backup runs to S3 **and** a restore was rehearsed and verified (counts match).
6. `resize-for-ingest.sh` → ingest → `resize-back.sh` works; **one clean ingest** completed from source files with counts reported; snapshot taken.
7. `stop.sh`/`start.sh` work; the running cost matches the estimate (~$4–5/day on, near-zero off).
8. The login/OTP flow works end to end on staging (Postmark configured), and an admin can log in and see the Knowledge Center with the ingested corpus.

## Eyes-on
Open the staging URL → log in via OTP → Knowledge Center shows the corpus (documents, chunks, salary rows, reference facts) → ask a prose question and a salary question in chat as a test employee → sensible answers with citations. Run `status.sh` → all green, worker idle. Run `stop.sh` → the instance stops; `start.sh` → everything comes back on its own (worker included) with no manual steps. Confirm `git grep` across the four repos finds **no** secret.

## Risks / notes
- **Secrets discipline is the thing to get right first.** The IAM user/keys are created by **Pedram, in the console**, and configured locally; SSM values are set by Pedram. Scripts reference parameter *names* only. If a key ever lands in a repo or log, rotate it immediately.
- **The model cache must be persistent and verified** — the single biggest "why is it slow / why did it hang" lesson from today. Health = model present.
- **Two small steps beat one big one:** bring the stack up first (Compose, proving the deploy path), *then* the ingest on the resized instance, *then* the backup rehearsal. Don't try to do everything in one session.
- **Postmark + a domain/TLS** are needed before real test users; plain HTTP on the EIP is fine for the first bring-up only.
- **Coverage gaps carry over:** the ingest will legitimately produce 0 chunks for the scanned docs (7e) and skip `under_review` docs (COEAS Estatal conflict) — expected, not a failure; the calibration anchors will score 2 of 5 for the documented reasons.

## Definition of done
All criteria pass; Pedram eyes-on on staging; `deploy.md` is a real runbook; the staging ADR written; the infra scripts committed; the 7d build then **deploys to staging and runs its calibration + succession eval there** (the next step). Cursor writes `hr-docs/sprints/staging-env/review.md` and **stops — no commit until reviewed.**
