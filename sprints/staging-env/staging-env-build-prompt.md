# Staging environment — Cursor build-authorization prompt

> Paste into the staging Cursor thread (the one that wrote `plan.md`). The plan is approved — the §2.4 IAM policy is verified narrow (role/instance-profile lifecycle only, `hr-staging-*` scoped, inline `PutRolePolicy` so there is no managed-policy escalation path), the secrets flow is correct, and the two config gotchas (`FILESYSTEM_DISK=local`, the `POSTMARK_TOKEN`/`POSTMARK_API_KEY` mismatch) are real. This authorizes the **build**, in **three sessions** (do not attempt all in one). Every open question is resolved below. **Two moments require Pedram in the AWS console — the prompt stops and waits at each.** Write the ADR, update `deploy.md` into a runbook, write `review.md`, and **STOP — do not commit.**

---

The staging plan (`hr-docs/sprints/staging-env/plan.md`) is approved as written. Build it in the §9 order, in three sessions. The IAM user is `cursor-dev` (PowerUserAccess). Apply the decisions below exactly.

## Shared-account discipline (this AWS account also hosts another project)

- **Every HR resource is named with the `hr-staging-` prefix** (roles, instance profile, security groups, buckets, SSM path `/hr-staging/...`, the EC2 Name tag, the RDS identifier). Never touch, list-and-modify, or reuse any resource without that prefix — the other project's resources (e.g. anything `reviewpilot*`/`scheduler*`) are out of bounds.
- **Tag every created resource** with `Project=hr-platform`, `Env=staging`, `ManagedBy=hr-docs/infra` (activate `Project` as a cost-allocation tag once, so Billing can split the two projects). The scripts apply tags at creation.
- The narrow IAM policy only ever touches `hr-staging-*` — it cannot affect the other project's roles by construction.

## Resolved open questions (apply exactly)

1. **Domain/TLS — none for now.** Plain HTTP on the Elastic IP for staging. Keep the Caddy automatic-HTTPS `Caddyfile` variant ready but unused; document the one-step switch for when a domain exists. TLS is a prerequisite before any *real* test user, recorded in `deploy.md`.
2. **Postmark — not available (the existing Postmark account belongs to the other project's domain).** Staging runs **`MAIL_MAILER=log`**: the OTP flow works unchanged (generated, hashed, rate-limited, single-use) and the code is written to the log instead of sent. Add a helper **`otp.sh <email>`** (in `hr-docs/infra/`) that prints the latest OTP code for that email from the backend log. When a domain + a new Postmark *server* (inside the existing account, on the HR domain) exist, the switch is one SSM value + `MAIL_MAILER=postmark` — document it. **Fix the var name:** staging uses **`POSTMARK_API_KEY`** (what `config/services.php:18` reads); log the `POSTMARK_TOKEN` mismatch as a correction in `deploy.md` and note it as a latent local bug to verify (OTP via Postmark may never have worked locally).
3. **Model: named volume + first-start fetch + the `/health/model` gate** (the additive hr-ai health endpoint that fails until BGE-M3 is present). Smaller image; the model survives resizes on the EBS volume. If the first-start fetch proves slow/fragile on the instance network, switch to bake-in and say so.
4. **IAM process: attach → build → detach, for staging.** A dedicated permanent deploy user with the narrow policy is the **production** re-run's job (pre-go-live IAM hardening) — record it, don't build it.
5. **Build on the EC2, no ECR.** One box, no registry. Note ECR as a later option if deploy time becomes a problem.
6. **`FILESYSTEM_DISK=s3`** on staging (config only, ADR-0009); the S3 disk config keys as the plan lists.
7. **Ingest scratch path is a `vars.sh` constant now:** `/opt/hr-staging/ingest-scratch`, bind-mounted into the backend container.

## Seeded test users (so everything is testable without email)

Add an idempotent seeder/command (`staging:seed-test-users`, staging-only, refuses to run if `APP_ENV=production`) that creates:
- The **four admin roles**, one account each: `super_admin`, `hr_agent`, `knowledge_editor`, `auditor` (emails like `admin+super@hr-staging.local`).
- **Test employees covering the scopes the remaining sprints need**, each with a clear name: Navarra Hostelería (no category — the 7f case), Álava Ocio Educativo Grupo 1, Estatal COEAS, Navarra Intervención Social, Vizcaya Intervención Social (an expired/gap scope), and at least one employee in a convenio **with** salary tables (so salary questions answer). Bind scope via existing vocabulary only (ADR-0011).
- Print the list (email + scope) at the end and record it in `deploy.md`. Login = `otp.sh <email>` → type the code.

## Session 1 — infra + the two Pedram moments

1. `01-network.sh` → `02-security-groups.sh` (SSH from Pedram's current IP only — read it, don't hardcode; 80 public; RDS from the EC2 SG only) → `03-rds.sh` (PG16, `db.t4g.medium`, 50 GB gp3, single-AZ, backups 7d, **not public**, then `CREATE EXTENSION vector`) → `04-s3.sh` (documents bucket; backups bucket with versioning; block public access on both) → `05-ssm-params.sh` (**names only**, SecureString placeholders under `/hr-staging/hr-backend/*` and `/hr-staging/hr-ai/*`).
2. **⏸ PEDRAM MOMENT 1 — attach the IAM policy.** Stop and print the exact §2.4 JSON plus these steps: *AWS console → IAM → Users → `cursor-dev` → Add permissions → Create inline policy → JSON tab → paste → Next → name `hr-staging-iam-bootstrap` → Create policy.* Wait for "done".
3. `06-iam.sh` → creates `hr-staging-ec2-role` (trust `ec2.amazonaws.com`) with the two **inline** policies (S3 read on the two buckets only; SSM read on `/hr-staging/*` + `kms:Decrypt` on `alias/aws/ssm`) and `hr-staging-ec2-profile`. Verify with `GetRole`/`GetInstanceProfile`.
4. **⏸ PEDRAM MOMENT 2 — detach the policy.** Stop and print: *IAM → `cursor-dev` → Permissions → select `hr-staging-iam-bootstrap` → Remove.* Wait for "done". (Every later step runs on PowerUserAccess alone.)
5. `07-ec2.sh` → `t3.large`, Ubuntu 24.04 (the AMI you resolved), 100 GB gp3, the instance profile, the SG, an Elastic IP, Docker + Compose installed via user-data, the named volumes created. `status.sh` shows the instance up and SSH works.
6. **⏸ PEDRAM sets SSM values** (DB password, app key, internal token, `POSTMARK_API_KEY` can stay a placeholder while `MAIL_MAILER=log`): print the exact `aws ssm put-parameter --overwrite` commands using a `read -s` prompt or a password-manager pipe — **never a literal value in the command line**. Wait for "done".
   End Session 1: infra exists, tagged, prefixed; no app deployed yet. Record the resource ids in `deploy.md`.

## Session 2 — containers + deploy path + seed + login

7. Dockerfiles (hr-backend with the worker as the same image running `queue:work` under `restart: unless-stopped`; hr-ai with the named model volume + `/health/model`; hr-frontend static build; Caddy on :80), `docker-compose.staging.yml`, the shared `entrypoint.sh` (SSM → process env via instance-profile creds, `exec` the real command; nothing written to disk), `.env.staging.example` for backend + ai (correct var names), log rotation.
8. **The leak scan** (the §4 commands: `git grep` in each repo, `docker history` on each image, grep on the Compose file) — run and record "clean" **before** the first deploy.
9. `deploy.sh` → pull pinned commits (main = 7c in all four repos) → build on the box → `php artisan migrate --force` (hr-backend only; hr-ai never migrates) → up → health-check all four + worker + `/health/model` → print deployed versions. Write the rollback procedure and **try it once** (redeploy the previous commit set).
10. `staging:seed-test-users` → the admins + test employees. Log in via `otp.sh` as `super_admin` → the empty Knowledge Center loads. **Criterion 8 in its staging form:** OTP login works end-to-end via the log mailer.
    End Session 2: the stack runs on staging with an empty corpus, deploy + rollback proven, login works.

## Session 3 — ingest + backup rehearsal + docs

11. Upload the source files to the documents bucket (or the scratch path) → `resize-for-ingest.sh` (→ `c7i.2xlarge`) → `registry:import` → `documents:ingest-folder` → `salary:import` → `chunks:embed` → report counts (documents, chunks, salary rows, reference facts) and the **expected legitimate zeros** (scans → 7e; `under_review` COEAS Estatal skipped by design; the 2-of-5 calibration anchors) → `resize-back.sh`.
12. **Backup + restore rehearsal:** the nightly `pg_dump` → S3 one-shot (scheduled), plus **one manual dump now**; then **restore it into a scratch RDS database** and verify counts match. Write the procedure. Confirm RDS automated snapshots are on.
13. `stop.sh` / `start.sh` — run both; confirm everything (worker, hr-ai with the model, Caddy) comes back with no manual step. Confirm the ~$4–5/day running cost against the created sizes.
14. Docs: `deploy.md` → a real runbook (bring-up, deploy, rollback, stop/start, resize-for-ingest, backup/restore, secrets, the model-cache lesson, `otp.sh` + the seeded users, the Postmark/domain switch, the `POSTMARK_TOKEN` correction, the known coverage gaps, the resource ids + tags). **ADR-0024 is taken by 7d (on the `sprint-7d` branch) — number this one `0025-staging-deploy-topology.md`** (managed RDS+S3, single-EC2 Compose, secrets-in-SSM via instance profile, model-pre-cached + health-gated, worker auto-restart, scripted idempotent infra, narrow attach/detach IAM, the shared-account prefix+tag discipline). `roadmap.md` (the staging sprint recorded as done; "every sprint ships to staging" noted). Write `hr-docs/sprints/staging-env/review.md`.

## Hard constraints (carry)
- **Secrets never in a repo/image/Compose file/chat/log** — leak scan recorded clean; SSM values set by Pedram only, never as command-line literals.
- **Prefix + tags on everything; never touch the other project's resources.**
- **hr-backend migrates; hr-ai never migrates** (ADR-0007). **S3 by config only** (ADR-0009). **No feature work** — the one code addition is the additive `/health/model` endpoint and the staging-only seeder/`otp.sh`, nothing in the answer loop.
- **Three sessions; stop at each Pedram moment; don't push past a failed step.**
- The 7d build stays on the `sprint-7d` branch, untouched; staging deploys **main (7c)** first.

## Eyes-on (Pedram, on staging)
Open `http://<EIP>` → `otp.sh admin+super@hr-staging.local` → log in → Knowledge Center shows the ingested corpus → as a test employee (e.g. the Álava Ocio Grupo 1 one), ask a prose question and a salary question → cited answers → `status.sh` all green, worker idle → `stop.sh` then `start.sh` → everything returns on its own → confirm the leak scan was clean → in AWS Billing, filter by `Project=hr-platform` and see only HR resources.

Then **STOP — do not commit until I review.** After review: commit + **push**, then the next step is deploying the `sprint-7d` branch to staging for its calibration.
