# Staging Environment Build — Review

> Built across three chat sessions per `plan.md`'s build order: infra (session 1) →
> containers + deploy (session 2) → ingest + backup/restore + ops verification + cost
> (session 3). Everything below is **live and running** in AWS account `049681810267`,
> region `eu-west-1`, at time of writing. `deploy.md` §7 is the durable operational
> record (resource ids, SSM parameter names, cost); this file is the sprint-review
> narrative — what was built, what deviated from the plan and why, what's committed
> where, and what's still open.

---

## 1. What was built

**Infra (`hr-docs/infra/`, session 1):** VPC (default, reused) + a dedicated DB subnet
group; two security groups (EC2: 22/tcp from the admin IP + 80/443 public; RDS: 5432/tcp
from the EC2 SG only, never a CIDR); RDS PostgreSQL 16 + pgvector (`db.t4g.medium`,
50GB gp3, single-AZ, not publicly accessible, storage-encrypted, 7-day backups); two S3
buckets (documents, backups — encrypted, public access blocked); an IAM role +
instance profile with two narrow inline policies (S3 read/write on the two buckets,
SSM read on `/hr-staging/*`); an EC2 `t3.large` (Ubuntu 24.04, 100GB gp3, the instance
profile attached) + a static Elastic IP. Every resource carries the `hr-staging-`
name prefix and the `Project=hr-platform` / `Env=staging` / `ManagedBy=hr-docs/infra`
tags — this AWS account also hosts an unrelated project's resources
(`reviewpilot*`/`scheduler*`), never touched by any script here.

**Containers + deploy (session 2):** a Dockerfile per app repo (`hr-backend`:
`php:8.4-fpm` + nginx + supervisord; `hr-ai`: `python:3.11-slim`; `hr-frontend`:
`node:22-slim` build → static volume for Caddy) plus the official `caddy:2-alpine`
image, wired together by `hr-docs/infra/compose/docker-compose.staging.yml`. Secrets
never touch a repo, image, or Compose file: a shared `entrypoint.sh` resolves named
SSM `SecureString` parameters into process env at container start, using the EC2
instance profile's credential chain (not a static key). `deploy.sh` — clone/checkout
four pinned commit SHAs → leak-scan → `docker compose build` → `migrate --force`
(hr-backend only, ADR-0007) → `up -d` → a health-check loop (including hr-ai's
model-presence gate, `/health/model`) → records `.last-good-shas` only on a fully
green run. Rollback is the same script with an older SHA set — tested this session
with a deliberately broken commit.

**Ingest + ops + cost (session 3):** one clean ingest from the source corpus through
S3 (registry import → chunk embed → salary import), verified counts, a resize-for-ingest
/ resize-back cycle, a nightly backup service + a rehearsed restore, `stop.sh`/`start.sh`
verification, and a corrected, pricing-API-verified cost figure. Detailed below.

---

## 2. Deviations from the plan, disclosed and resolved live

**IAM process had two attach/detach cycles, not one.** The plan expected a single
"Pedram attaches `hr-staging-iam-bootstrap` → `06-iam.sh` runs → Pedram detaches"
cycle. In practice `iam:PassRole` is *also* checked when EC2 attaches an instance
profile at `run-instances` time, not only at role-creation time — so `07-ec2.sh`
needed the policy re-attached, and it was detached a second time only after that.
Corrected in `deploy.md` for the production re-run.

**Secrets were machine-generated, not typed by Pedram.** The plan's original build
order had "Pedram sets SSM values" as a session-1 step. In practice `03-rds.sh` needs
the RDS master password to exist *before* RDS can be created — earlier in the build
order than that step — so `05-ssm-params.sh` generates all four machine-only
credentials (`openssl rand`) and writes them straight to SSM via command substitution,
never displayed, never typed, never in shell history. Disclosed and accepted live.
Pedram can rotate any of them with `aws ssm put-parameter --overwrite` at any time.

**No Postmark account exists yet.** `MAIL_MAILER=log` for now — the OTP email lands
in `hr-backend`'s log instead of an inbox. `otp.sh <email>` reads it back out
(anchored on the mailable's subject line) to verify login end-to-end without a real
mail provider. Switching to Postmark later is a one-line env change
(`MAIL_MAILER=postmark` + `POSTMARK_API_KEY` — **not** `POSTMARK_TOKEN`, a latent
naming bug in `hr-backend/.env.example` found and flagged in `deploy.md`, itself
unrelated to staging).

**Test-account emails are placeholders, not real addresses.** Since `MAIL_MAILER=log`
means nothing is actually emailed, `SEED_*_EMAIL` uses obviously-fake addresses under
`hr-staging.internal` (e.g. `admin@hr-staging.internal`) rather than a real inbox
Pedram controls — purely to prove the deploy works end-to-end, not real accounts
anyone logs into. Explicitly authorized live.

**hr-ai's S3 access needed two additive app-code fixes, not a static-key IAM user.**
Covered in full in §3 — flagging here because it was the single largest deviation
from "config only, no app-code changes" and went through two rounds (the initial
`storage.py` fix wasn't sufficient on its own; a second, `config.py` default-value
bug was found live during actual ingest).

---

## 3. App-code changes — went straight to `main`, each authorized live

Five small, additive app-code changes were needed to get staging working. **All five
went directly to each app repo's `main` branch during the build**, not merged via PR —
each was proposed, explicitly authorized in chat *before* being made, and disclosed
immediately after. This section exists so they get a proper read at sprint review,
the same scrutiny a PR would get, even though the review is happening after the fact
rather than before merge.

### hr-ai — S3 instance-profile credential fallback (two commits)

**`e7c6baa` — `app/storage.py`** (part of a larger staging-Dockerfile commit):

```17:34:/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-ai/app/storage.py
    if _client is None:
        # Explicit static credentials for local dev (MinIO, always set). When
        # unset (staging — no static key is issued, by design: a long-lived
        # key is exactly what an EC2 instance profile exists to avoid), omit
        # them so boto3 falls back to its default credential chain, which
        # resolves the EC2 instance profile via IMDS automatically (ADR-0009 —
        # config only, no adapter redesign). endpoint_url/region are unchanged
        # either way: staging sets AWS_ENDPOINT to the real regional S3
        # endpoint via config, same as it always pointed at MinIO in dev.
        client_kwargs = {
            "endpoint_url": settings.aws_endpoint,
            "region_name": settings.aws_region,
            "config": Config(s3={"addressing_style": "path" if settings.aws_use_path_style else "auto"}),
        }
        if settings.aws_access_key_id and settings.aws_secret_access_key:
            client_kwargs["aws_access_key_id"] = settings.aws_access_key_id
            client_kwargs["aws_secret_access_key"] = settings.aws_secret_access_key
        _client = boto3.client("s3", **client_kwargs)
    return _client
```

Before: `boto3.client()` always received explicit `aws_access_key_id`/`aws_secret_access_key`
kwargs, even when both were empty strings — which boto3 treats as "use these (empty)
credentials," not "fall back to the credential chain." After: those two kwargs are
only passed when both are non-empty; when empty (staging), they're omitted entirely
and boto3's default credential chain resolves the EC2 instance profile via IMDS. Local
dev (MinIO, `.env` always sets both) is byte-for-byte unchanged. No IAM user, no new
policy, no SSM key — the instance profile's existing S3 grant from `06-iam.sh` covers it.

**`2a1483d` — `app/config.py`** (found live, later in session 3, during the actual
ingest — this fix was needed *in addition to* `e7c6baa`, not instead of it):

```python
# before
aws_access_key_id: str = "minioadmin"
aws_secret_access_key: str = "minioadmin"

# after
aws_access_key_id: str = ""
aws_secret_access_key: str = ""
```

`e7c6baa`'s `storage.py` check (`if settings.aws_access_key_id and settings.aws_secret_access_key:`)
is correct, but it was checking against `config.py`'s Pydantic **defaults**, which were
still `"minioadmin"` — two non-empty (but bogus) strings. Local dev never noticed
because its `.env` always sets both explicitly; staging deliberately leaves them
unset, so `pydantic-settings` fell back to the class defaults, the check saw two
non-empty strings, and passed `"minioadmin"`/`"minioadmin"` to `boto3.client()` as if
they were real credentials — every S3 call failed with `InvalidAccessKeyId` (not a
permissions error; AWS was correctly rejecting a key that isn't real). Changing the
defaults to `""` closes the gap: `""` is falsy, so the fallback now actually engages.
**Confirmed working** — the PDF ingest (session 3, §4 below) ran end-to-end through
S3 via the instance profile, no static key anywhere.

### hr-backend — `staging:seed-test-users` (two commits, one new command)

**`0feb879`** (bundled with a staging Dockerfile fix) created
`app/Console/Commands/StagingSeedTestUsers.php` — a thin, explicit wrapper around the
existing `TestUserSeeder` (no seeding logic duplicated). Its refusal guard:

```php
foreach (['SEED_ADMIN_EMAIL', 'SEED_EMPLOYEE_EMAIL', 'SEED_EDITOR_EMAIL', 'SEED_AUDITOR_EMAIL', 'SEED_HR_AGENT_EMAIL'] as $var) {
    if (blank(env($var))) {
        $this->error("{$var} is not set — refusing to seed with the example.com defaults on staging. Set it in the environment first.");
        return self::FAILURE;
    }
}
```

`TestUserSeeder::run()` itself defaults every `SEED_*_EMAIL` to an `@example.com`
address if the env var is absent (a dev convenience, `database/seeders/TestUserSeeder.php`).
This command's whole purpose is closing that door on staging: it refuses to run at
all unless every one of the five vars is explicitly set, so staging can never
silently end up with `admin@example.com` in the database. This is the guard flagged
for review — it's a fail-closed check with no bypass, and it's the only thing standing
between "staging has real placeholder identities" and "staging silently seeds
`@example.com`."

**`450bddb`** — found live: `TestUserSeeder` depends on territories + roles already
existing (per `DatabaseSeeder.php`'s own ordering), but `migrate --force` alone seeds
none of them, so the very first run failed with "No query results for model
[App\Models\Territory]." Fixed by running `TerritorySeeder`, `DocumentTypeSeeder`,
`TopicSeeder`, `RoleSeeder` before `TestUserSeeder` — deliberately *not* the full
`DatabaseSeeder` (its last step, `ChatTestUserSeeder`, depends on the registry
import, which hadn't run yet at that point in the build order).

Two more hr-backend commits this session were pure infra (Dockerfile: `gd` extension
for `phpoffice/phpspreadsheet`, then a base-image bump to `php:8.4-fpm` for
`composer.lock`'s `>=8.4.1` requirement) — no application logic changed, not detailed
here.

### hr-ai — bound-check typed salary columns before write (one commit, follow-up fix)

**`593f86e`** — `app/salary.py`. Found live, in the follow-up salary-diagnosis request
(§7 below): two source spreadsheets each have a column genuinely header-labeled
`€/hora` whose value is actually an annual figure, not hourly (`13448.6184` == exactly
12× the adjacent year's monthly figure — a data-entry error in the source file, not a
column-mapping bug; the header match to `_HOURLY` was correct). Writing it verbatim
overflowed hr-backend's `hourly_rate` `decimal(8,4)` column and crashed the entire
`salary:import` run, not just that one document.

```python
_FIELD_BOUNDS = {
    "gross_annual": 99_999_999.99,
    "base_salary_monthly": 99_999_999.99,
    "extra_pay": 99_999_999.99,
    "hourly_rate": 9_999.9999,
    "night_plus": 99_999_999.99,
}

def _bounded(field, value, name_for_warning=job_category_name):
    if value is None or abs(value) < _FIELD_BOUNDS[field]:
        return value
    warnings.append(f"sheet '{name}': {field}={value!r} out of range for "
                     f"'{name_for_warning}' ... — kept in raw_values only, not written as {field}")
    return None
```

Each typed money field is bound-checked against its actual DB column precision before
being returned. Out-of-range values are dropped (`null`, never force-fit, never
"corrected" by guessing) but stay in `raw_values` verbatim like every other column,
with a warning surfaced in `salary:import`'s CLI output — visible, not silently lost,
not silently crashing. Verified against a synthetic reproduction of the exact failing
row before deploying to staging. Full diagnosis and result in §7.

### hr-frontend — dead prop removal (one commit)

**`305c77b`**: removed an unused `onChanged` prop from `AiSuggestionsSection`
(`DocumentDetailPanel.tsx`). Pre-existing bug, unrelated to staging — `AiSuggestionsSection`
is read-only display (AI-suggested facets, no button/action), so it never had anything
to notify the parent about; the prop was dead from the component's introduction.
Confirmed via a full read of the component body before removing. It surfaced only
because it blocked the whole staging Docker image (`tsc -b`'s `noUnusedParameters`
turns it into a hard build error) — reproduced independently outside Docker first,
to rule out a Docker-specific difference before touching app code.

---

## 4. Ingest, backup/restore, ops verification (session 3 detail)

**Ingest:** source corpus → `aws s3 sync` to the documents bucket → synced down to
`/opt/hr-staging/ingest-scratch` on the resized (`c7i.2xlarge`) EC2 → `registry:import`
→ `documents:ingest-folder` (hr-ai `/extract`, over S3, via the instance profile) →
`chunks:embed` → `salary:import` → `resize-back.sh` to `t3.large`. Verified counts:

| Table | Count |
|---|---|
| `documents` | 98 |
| `document_chunks` | 3364 |
| `document_pages` | 2876 |
| `employees` | 1 |
| `admins` | 4 |
| `salary_tables` / `salary_table_rows` | 10 / 109 — see §7 below (started at 0/0, pending convenio assignment, then fixed same session) |

A manual snapshot, `hr-staging-post-ingest-20260906`, was taken immediately after; a
second, `hr-staging-post-salary-fix-20260906-220241`, after the salary fix (§7).

**Backup + restore rehearsal:** a new `db-backup` one-shot Compose service
(`pg_dump | gzip` → `aws s3 cp` to the backups bucket, via the instance profile's
existing S3-write grant) plus a cron installer (`0 3 * * *`, idempotent — verified by
running it twice). **Found live:** the shared `entrypoint.sh` needs `aws-cli` on
`$PATH` *before* it runs (it resolves `PGPASSWORD` from SSM before exec-ing the
container's command) — a first draft installing `aws-cli` inside the service's
`command:` ran too late. Fixed with a small custom image (`backup.Dockerfile`,
`postgres:16-alpine` + `aws-cli` baked in at build time). Manual test run: 19.1 MiB
compressed dump, uploaded cleanly. A new `09-restore-rehearsal.sh` restores a snapshot
into a throwaway instance (same SG/subnet-group, never public, never touches the live
DB — `restore-db-instance-from-db-snapshot` always creates a new identifier), runs the
same count query, and tears the throwaway instance down automatically. **Rehearsed
against `hr-staging-post-ingest-20260906`: every count matched exactly.** ~6.5 minutes
wall time; confirmed no lingering cost afterward. A 30-day S3 lifecycle rule now caps
the backups bucket's `pg/*` growth (nightly dumps had no cap otherwise).

**Stop/start:** `stop.sh`/`start.sh` confirmed correct for both EC2 and RDS — start
re-associates the Elastic IP (EIPs disassociate when the backing instance stops) and
confirms the Compose stack auto-restarts via its own `restart: unless-stopped`
policies, no manual `docker compose up` needed.

---

## 5. Cost — confirmed, and corrected against live pricing

The plan's original ~$4/day estimate was directionally right but had two stale
inputs, found and corrected this session by querying the AWS Pricing API directly
(`aws pricing get-products`, eu-west-1, captured 2026-09-06) rather than re-estimating
from memory:

- **EC2 `t3.large`'s actual on-demand rate is `$0.0912`/hr**, slightly higher than the
  plan's original figure.
- **Elastic IPs have been billed `$0.005`/hr whether attached or idle since AWS's
  February 2024 pricing change.** The plan's "free while associated" line reflected
  the pre-2024 policy and was wrong from the start — not a staging-specific mistake,
  just a stale assumption baked into the initial plan.

**Running: ~$4.5–4.7/day** (still inside the spec's $4-5/day band). **Stopped
(`stop.sh`): ~$0.62–0.72/day** (storage + the now-always-billed EIP). Full breakdown
in `plan.md` §8. Cost Explorer's own tag-filtered billing (`Env=staging`) returned no
usable data yet — cost-allocation tags take up to 24h to activate and these resources
are hours old — so list pricing stood in for actual incurred cost this session;
worth re-checking Cost Explorer after a few days of real usage against the estimate
above.

---

## 6. What's committed, where

| Repo | New commits this build | Status |
|---|---|---|
| `hr-docs` | infra scripts, compose files, Caddyfile, `deploy.sh`/`deploy-run.sh`, `09-restore-rehearsal.sh`, `setup-backup-cron.sh`, ADR-0025, `deploy.md` §7, `plan.md` §8 corrections, this file | pushed to `main` |
| `hr-backend` | `450bddb`, `d81eaff`, `0feb879`, `99f4712` (§3, §2) | pushed to `main` |
| `hr-ai` | `2a1483d`, `e7c6baa`, `593f86e` (§3, §7) | pushed to `main` |
| `hr-frontend` | `305c77b`, `e0fa02e` (§3) | pushed to `main` |

Nothing is left uncommitted or pending review in any of the four repos as of this
salary follow-up (end of session 3 plus the same-day salary fix).

---

## 7. Follow-up: the salary path, diagnosed and fixed (same session)

`salary:import` wrote 0 rows at first. Diagnosis: of the 26 ingested `salary_tables`-typed
documents, only 11 are `.xlsx` (the only format the command reads — ADR-0014's
xlsx-first policy; the other 15 are PDF salary grids, a separate, already-documented
coverage gap, not this bug) — and **all 11 had `convenio_id IS NULL`**, because none of
their filenames carry the 14-digit `numero` code the deterministic `FilenameParser`
keys on. This is exactly the expected "lands `under_review`, needs an admin convenio
assignment" behavior ADR-0014 describes (its "catch 4") — not an ingest failure.

**Fixed via the existing admin path, never by hand-inserting rows:**

1. Matched each of the 11 filenames against the 26-row convenio registry by
   territory+sector keyword. **5 matched a single convenio unambiguously** (e.g.
   "Tablas Intervencion Social Navarra.xlsx" → convenio 18, the only convenio with
   that sector+territory pair). The other **6 are genuine coverage gaps, left
   unresolved on purpose**: one has no identifying text ("Tabla 2026.xlsx"), three
   name a convenio (COEAS Estatal, COEAS Andalucía, Deporte Estatal) that simply
   doesn't exist in this registry, one names only "COEAS" with no territory
   (assigning it to the registry's one COEAS convenio, Navarra, without a territory
   match would itself be the kind of guess ADR-0014 forbids), one has no matching
   convenio by any field ("Tablas acuerdo parcial_Alhambra.xlsx"). All 6 stay
   `under_review`, listed every run by `salary:import`'s own pending-report.
2. Assigned the 5 confident matches via `PATCH /admin/documents/{uuid}/facets/convenio`
   (`confirm_scope_change: true`) as the seeded `super_admin`, then
   `POST /admin/documents/{uuid}/confirm` (Sprint-3 tag verify) on each.
3. Re-running `salary:import` surfaced a **real bug**, not a data gap: two of the five
   documents each have a column genuinely header-labeled `€/hora` whose value is
   actually an annual figure (`13448.6184` == exactly 12× the adjacent year's monthly
   figure — a data-entry error in the *source* spreadsheet, not a column-mapping
   bug; the header match itself was correct). Writing it verbatim overflowed
   `hourly_rate`'s `decimal(8,4)` column and crashed the **entire** command, not
   just that document — `salary:import`'s per-document `try`/`catch` only wraps the
   hr-ai extraction HTTP call, not a DB-constraint violation inside the write
   transaction, so one bad spreadsheet value took down every document queued after
   it.

### hr-ai app-code fix (`593f86e`, additive, disclosed here)

`app/salary.py`: each typed money field (`gross_annual`, `base_salary_monthly`,
`extra_pay`, `hourly_rate`, `night_plus`) is now bound-checked against its actual DB
column precision (mirrored as `_FIELD_BOUNDS`, matching `salary_table_rows`'s
migration) before being returned. An out-of-range value is **dropped** — set to
`null`, never force-fit into a column that structurally can't hold it, and never
"corrected" by guessing a plausible replacement — but it stays in `raw_values`
verbatim, exactly like every other column, and a warning is appended so it surfaces
in `salary:import`'s CLI output (ADR-0014's "coverage gaps visible, never silent"
discipline, applied to a value-level gap instead of a document-level one). Verified
locally against a synthetic reproduction of the exact failing row before deploying;
redeployed to staging (`deploy.sh` with the new hr-ai SHA), then both previously-
failing documents imported cleanly.

### Result

**10 `salary_tables`, 109 `salary_table_rows`, 58 `convenio_job_categories`**, across
5 convenios: 6 (Deporte Cantabria), 10 (Agencias de Viajes), 18 (Acción e Intervención
Social, Navarra), 19 (COEAS Navarra), 22 (Limpieza de Edificios y Locales, Navarra).
6 documents remain `under_review`, correctly, as genuine coverage gaps (above).

### Proved end-to-end

Created a new test employee, `salary-test@hr-staging.internal`, via
`POST /admin/employees` (the employee-directory admin API — not a DB insert), scoped
to convenio 18 / job category "GRUPO 1" (a clean row: `hourly_rate = 18.4916` for both
2025 and 2026, no data-quality warnings). Logged in via `otp.sh`, then
`POST /chat/message` with `¿Cuánto gano?`:

```json
{
  "answer": "Para la categoría GRUPO 1, según la tabla salarial de 2026 de tu convenio: precio/hora de 18,4916 €/hora. ...",
  "citations": [{"chunk_id": null, "is_salary_table": true, "document_id": 1, "authority_level": "official_convenio"}],
  "trace": {
    "floor_decision": {"path": "salary_sql", "outcome": "answer", "note": "exact figure from salary_tables (year 2026)"}
  }
}
```

`floor_decision.path = "salary_sql"`, a `chunk_id: null` / `is_salary_table: true`
citation, and the exact figure from `salary_table_rows` — the salary path working
end-to-end on staging, not just importable.

A fresh RDS snapshot, `hr-staging-post-salary-fix-20260906-220241`, was taken
immediately after (available, confirmed).

---

## 9. Open items / carried forward

- **Postmark**: no account exists yet (§2). `MAIL_MAILER=log` stands in; switching is
  a one-line env change once a domain + sending account exist.
- **Domain/TLS**: plain HTTP on the EIP, as planned. No Caddy automatic-HTTPS variant
  written yet — deferred until a domain is confirmed.
- **IAM process for the production re-run**: the attach → build → detach discipline
  (§2) worked but is manual per cut. Whether to keep it manual for production or
  provision a standing, dedicated deploy IAM user is flagged in `plan.md` §9 as an
  open question for Pedram, not decided here.
- **Cost re-check**: confirm the pricing-API-derived figures (§5) against actual
  Cost Explorer billing once tag data is available (a few days out).
- **Build-on-EC2 vs. a registry (ECR)**: staging built on the instance directly, as
  planned, since build time on `t3.large` proved acceptable. Still flagged in `plan.md`
  §9 as an open question for a production re-run at larger scale.
