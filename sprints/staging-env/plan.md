# Staging environment — plan (inspect-and-plan turn)

> Location: `hr-docs/sprints/staging-env/plan.md`
> Status: **plan for review — no AWS resource created, no application code changed this turn.**
> Reads: `hr-docs/sprints/staging-env/staging-env-spec.md`, `hr-docs/deploy.md`, ADR-0007, ADR-0009, ADR-0015, ADR-0018, ADR-0019, `architecture.md` §2–3, and the live config of all four repos (cited below with real paths/lines).

---

## 0. Prerequisite correction (superseding the kickoff prompt's prerequisite block)

The kickoff prompt's prerequisite section is **out of date** and is superseded by this correction:

- **IAM identity:** the deployer is the existing IAM user **`cursor-dev`**, not `hr-staging-deployer`. **No IAM user-creation step is needed or will be performed.**
- **Verified this turn** (read-only, no key printed):

  ```
  $ aws sts get-caller-identity
  {
      "UserId": "AIDAQXEKEINN4ZHAQHTW5",
      "Account": "049681810267",
      "Arn": "arn:aws:iam::049681810267:user/cursor-dev"
  }
  $ aws configure get region
  eu-west-1
  ```

  Account `049681810267`, region `eu-west-1` — matches the spec's locked region decision (`staging-env-spec.md:10`, "**Region:** `eu-west-1` (Ireland)").
- **Permissions:** `cursor-dev` has **`PowerUserAccess`**, confirmed sufficient for every EC2/RDS/S3/SSM action in this plan (read-only checks below all succeeded: `ec2:DescribeVpcs`, `ec2:DescribeSubnets`, `ssm:GetParameters`). `PowerUserAccess` explicitly **excludes IAM management** (it denies `iam:*` except a narrow read set) — so it **cannot** create the EC2 instance role/instance-profile the plan calls for in §2.4.
- **No `IAMFullAccess` will be requested, granted, or used at any point.** The build-time gap is closed the other way around: §2 below produces an **exact, narrow, inline IAM policy** — scoped only to `iam:*Role*`/`iam:*InstanceProfile*` actions on resources named `hr-staging-*` — that **Pedram** attaches to `cursor-dev` in the console himself, once, immediately before the one build step that needs it (creating `hr-staging-ec2-role` + `hr-staging-ec2-profile`). Every other step in every script in this plan runs under the existing `PowerUserAccess` grant alone.
- The original prerequisite's "create an IAM user, attach FullAccess policies, create an access key, run `aws configure`" steps are **all skipped** — `cursor-dev` is already configured and verified.

Read-only confirmations captured this turn (no resource created, no secret shown):

```
$ aws ec2 describe-vpcs --filters Name=is-default,Values=true --region eu-west-1
  → vpc-0f33f60b804ff6738 (172.31.0.0/16)
$ aws ec2 describe-subnets --filters Name=default-for-az,Values=true --region eu-west-1
  → subnet-0b3a475aab8624f55 (eu-west-1a, 172.31.16.0/20)
  → subnet-00fc8f66fd3d786c6 (eu-west-1b, 172.31.32.0/20)
  → subnet-067b22b6b40d634c8 (eu-west-1c, 172.31.0.0/20)
$ aws ssm get-parameters --names /aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id --region eu-west-1
  → ami-0526a6499f6470118 (Ubuntu 24.04 LTS, eu-west-1, as of this planning turn — the AMI ID drifts over time; scripts resolve it dynamically at build time via the same SSM public parameter, never hardcode it)
```

Default VPC is fine for staging per `staging-env-spec.md:22` ("VPC/subnets (default VPC is fine for staging)"). No infra created — these are `Describe*`/`Get*` reads only, all covered by `PowerUserAccess`.

---

## 1. What exists — reality check

### 1.1 hr-backend — configured today

**`.env.example`** (`/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-backend/.env.example`):

```20:26:hr-backend/.env.example
# PostgreSQL + pgvector (docker-compose, host-exposed on localhost:5432)
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=hr_platform
DB_USERNAME=hr
DB_PASSWORD=hr_secret
```

```35:36:hr-backend/.env.example
FILESYSTEM_DISK=local
QUEUE_CONNECTION=sync
```

```47:65:hr-backend/.env.example
# Local dev: MailHog SMTP (docker-compose). UI at http://localhost:8025
# Production: set MAIL_MAILER=postmark and POSTMARK_TOKEN (no other code change).
MAIL_MAILER=smtp
...
MAIL_HOST=localhost
MAIL_PORT=1025
...
POSTMARK_TOKEN=

# S3-compatible object storage — MinIO for local dev (ADR-0009)
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=hr-documents
AWS_ENDPOINT=http://localhost:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

```67:70:hr-backend/.env.example
# hr-ai document-extraction service (ADR-0010). The internal token must match
# hr-ai's INTERNAL_TOKEN.
HR_AI_URL=http://localhost:8001
HR_AI_INTERNAL_TOKEN=dev-internal-token
```

**Gotcha found (not in the kickoff prompt's list):** `FILESYSTEM_DISK=local` today, **not** `s3` — even though `AWS_*` vars are present for the S3 disk. Staging must set `FILESYSTEM_DISK=s3` explicitly (config-only, ADR-0009 unaffected) or uploads/reads will silently hit the local disk. `config/filesystems.php:16` default is also `local`.

**`config/filesystems.php`** — the S3 disk block hr-backend will use on staging (only `AWS_ENDPOINT`/`AWS_USE_PATH_STYLE_ENDPOINT` become unset on real S3):

```50:59:hr-backend/config/filesystems.php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),
    'endpoint' => env('AWS_ENDPOINT'),
    'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
],
```

**`config/queue.php:16`** — `'default' => env('QUEUE_CONNECTION', 'database')`. `.env.example` overrides to `sync` for dev (no worker needed for `sync`). **Staging must set `QUEUE_CONNECTION=database`** — `sync` runs jobs inline in the request and would make a Compose worker pointless.

**`config/services.php:17-41`** — Postmark + hr-ai + answer-model wiring:

```17:41:hr-backend/config/services.php
'postmark' => [
    'key' => env('POSTMARK_API_KEY'),
],

'hr_ai' => [
    'url' => env('HR_AI_URL', 'http://localhost:8001'),
    'internal_token' => env('HR_AI_INTERNAL_TOKEN', 'dev-internal-token'),
    'answer_provider' => env('HR_AI_ANSWER_PROVIDER', 'claude'),
    'answer_model' => env('HR_AI_ANSWER_MODEL', 'claude-sonnet-4-5'),
    'answer_endpoint' => env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com'),
    'router_model' => env('HR_AI_ROUTER_MODEL', 'claude-haiku-4-5'),
    'router_endpoint' => env('HR_AI_ROUTER_ENDPOINT', env('HR_AI_ANSWER_ENDPOINT', 'https://api.anthropic.com')),
],
```

**Naming mismatch found:** `.env.example:54` and its own comment say `POSTMARK_TOKEN`; `config/services.php:18` reads `env('POSTMARK_API_KEY')`. **`POSTMARK_TOKEN` in `.env` is a silent no-op today** — the real var Laravel's Postmark mailer config reads is `POSTMARK_API_KEY`. The staging `.env.staging.example` must use `POSTMARK_API_KEY`, and this should also be logged as a `deploy.md` correction (not fixed this turn — no app-code/doc change beyond the plan).

**Queue worker command** — there is **no `queue:work` anywhere in the repo**. The only queue-runner invocation is `queue:listen`, used only in local dev via Composer:

```49:hr-backend/composer.json
"php artisan queue:listen --tries=1 --timeout=0"
```

For staging the worker container must run **`php artisan queue:work --tries=3 --timeout=120`** (or similar) — `queue:listen` re-forks the framework per job (fine for hot-reload dev, wasteful/no different guarantee for a long-lived container) — `queue:work` is the correct persistent-process command and is what the compose worker service should invoke.

**Existing Docker assets:** `hr-backend/docker-compose.yml` exists but is **infra-only** (Postgres+pgvector, MinIO, MailHog) — no app containers:

```3:9:hr-backend/docker-compose.yml
# This file defines ONLY the three infra services. The app services
# (hr-backend, hr-ai, hr-frontend) run on the HOST in dev and connect over
# localhost to the host-exposed ports below. App containerization is out of
# scope for Sprint 0. All image tags are pinned (no floating `latest`).
```

No `Dockerfile` exists in hr-backend, hr-ai, or hr-frontend today — **all four app Dockerfiles are net-new this sprint** (§3).

**Local-only surfaces that must become config on staging:** `AWS_ENDPOINT=http://localhost:9000` (MinIO) → unset on real S3; `DB_HOST=localhost` → RDS endpoint; `HR_AI_URL=http://localhost:8001` → the internal Compose service name (`http://hr-ai:8001`); `MAIL_HOST=localhost` (MailHog) → Postmark; `FRONTEND_URL=http://localhost:5173` → the staging URL/EIP; `APP_URL=http://localhost:8000` → same. **No `55433` reference exists in hr-backend** (the kickoff prompt's `55433` was a guess — the real alternate port used in some dev-container comments is `55432`, see §1.2). Nothing in hr-backend hardcodes `55433`.

### 1.2 hr-ai — configured today

**`.env.example`** (`/Users/pedram/Desktop/PROJECT/JV/HR-AI/hr-ai/.env.example`, full file, 27 lines):

```1:27:hr-ai/.env.example
# --- Database (Sprint 2a) ---
# ...
# In the dev container the host Postgres is reached at host.docker.internal:55432.
DATABASE_URL=postgresql://hr_ai:hr_ai_secret@localhost:5432/hr_platform

# --- Document extraction / object storage (Sprint 1, ADR-0010) ---
AWS_ENDPOINT=http://localhost:9000
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_REGION=us-east-1
AWS_BUCKET=hr-documents
AWS_USE_PATH_STYLE=true
EXTRACT_IMAGE_DPI=150

# Shared secret guarding internal endpoints (hr-backend ↔ hr-ai).
INTERNAL_TOKEN=dev-internal-token

# --- Embeddings (Sprint 2a, ADR-0006) — BGE-M3, self-hosted in-process, 1024-dim ---
EMBED_MODEL=BGE-M3
EMBED_MODEL_HF=BAAI/bge-m3
EMBED_DIM=1024

# --- LLM (unused this sprint — 2b) ---
ANTHROPIC_API_KEY=
```

**Confirms the real alternate dev port is `55432`, not `55433`** (`.env.example:5`, and identically `app/config.py:21`) — the kickoff prompt's "the 55433 port" does not exist anywhere in either repo; treat it as a typo for `55432`, and note it only matters for the **dev container**, not staging (staging talks to the RDS endpoint on port 5432, not a forwarded host port at all).

**hr-ai's naming for the same S3 vars differs slightly from hr-backend's:** `AWS_REGION` (hr-ai) vs `AWS_DEFAULT_REGION` (hr-backend); `AWS_USE_PATH_STYLE` (hr-ai) vs `AWS_USE_PATH_STYLE_ENDPOINT` (hr-backend). Both repos need their **own** `.env.staging.example` with the same values under each repo's own var names — not a shared file.

**Where the HF cache lives — no override configured.** No `HF_HOME`, `TRANSFORMERS_CACHE`, or `SENTENCE_TRANSFORMERS_HOME` anywhere in hr-ai (`.env.example` or `app/config.py`) — confirmed by grep, zero matches. `sentence-transformers`/`huggingface_hub` therefore fall back to the library default, `~/.cache/huggingface`. On this dev machine that resolves to `/Users/pedram/.cache/huggingface/hub/models--BAAI--bge-m3`, measured at **4.3 GB** today (matches the kickoff prompt's figure) — both `.safetensors` and `pytorch_model.bin` snapshot variants are present under that one HF cache dir (the library's default `snapshots/` layout keeps both format variants it has ever downloaded; nothing in hr-ai code chooses one over the other explicitly — `SentenceTransformer(...)` picks safetensors when both are present). For the container, **`HF_HOME` must be set explicitly** (e.g. `/model-cache`) and mounted as a named volume — right now, with no env var set, the cache would land inside the container's writable layer at `/root/.cache/huggingface` (or the container user's home), which is **not persistent across a container recreate** unless a volume happens to be mounted at exactly that path. This is the single highest-risk gap for the "pre-cache + never re-download" goal and is called out explicitly in §3.2.

**Model load timing — lazy, on first use, not at startup.** `app/embeddings.py`:

```1:11:hr-ai/app/embeddings.py
"""BGE-M3 embeddings (ADR-0006), self-hosted in-process.

Multilingual (Spanish + Euskara), open-weight, 1024-dim. Loaded lazily as a
process singleton so importing this module stays cheap; the (large) model is
fetched/loaded on first use. CPU is acceptable — embedding runs on the
background admin path (ingestion), never the latency-critical employee path.
```

```19:37:hr-ai/app/embeddings.py
_model = None
_tokenizer = None
_lock = threading.Lock()


def _load():
    global _model, _tokenizer
    if _model is not None:
        return
    with _lock:
        if _model is not None:
            return
        # Imported lazily so the service (and /health, /extract) does not pay the
        # torch import cost unless embeddings are actually used.
        from sentence_transformers import SentenceTransformer

        model = SentenceTransformer(settings.embed_model_hf, device="cpu")
        _tokenizer = model.tokenizer
        _model = model
```

`_load()` is called from `embed_texts()` (`embeddings.py:44`) and `count_tokens()` (`embeddings.py:61`), which are reached only via `/embed` (through `app/pipeline.py:38`) and `/retrieve`/`/sandbox-retrieve` in `app/main.py`. **There is no `@app.on_event("startup")` / lifespan hook anywhere in `app/main.py`** — `FastAPI()` is instantiated with no startup handler. This confirms the kickoff prompt's framing exactly: model load is **first-call, not startup**, which is precisely why an un-warmed health check can report healthy while the model is still a 4.3 GB download away from being usable, and why `/health` must be extended (§3.2) rather than trusted as-is.

**`/embed` endpoint:**

```294:302:hr-ai/app/main.py
@app.post("/embed", dependencies=[Depends(require_internal_token)])
async def embed(req: EmbedRequest) -> JSONResponse:
    """Re-extract column-aware → de-space → article-chunk → embed (BGE-M3/1024)
    → WRITE document_chunks (ADR-0013). hr-backend passes the resolved scope;
    the denormalized scope columns are copied verbatim. Idempotent re-embed.
    """
    from .pipeline import embed_document
```

**Current health endpoints — do NOT verify model presence:**

```241:274:hr-ai/app/main.py
@app.get("/health")
async def health() -> dict[str, str]:
    """Liveness probe."""
    return {"status": "ok", "service": "hr-ai"}

@app.get("/health/db")
async def health_db() -> JSONResponse:
    """Read-only DB connectivity check (no writes, no migrations)."""
    ...

@app.get("/health/config")
async def health_config() -> dict[str, object]:
    """Echo non-secret config placeholders so the contract shape is visible."""
    return {
        "embed_model": settings.embed_model,
        "embed_dim": settings.embed_dim,
        ...
    }
```

`/health` is a static `{"status": "ok"}` — no model check at all. `/health/config` only echoes config strings (model **name**, not presence). **This plan proposes a new, additive `/health/model` endpoint** (§3.2) — no existing endpoint is modified, so this is scoped as a small, explicit, reviewable code change for the *build* turn, not invented silently.

**No Dockerfile/compose file exists in hr-ai** — confirmed, zero files.

### 1.3 hr-frontend — configured today

```1:2:hr-frontend/.env.example
# Base URL of the hr-backend API (host-exposed by docker-compose / artisan serve).
VITE_API_BASE_URL=http://localhost:8000
```

```1:2:hr-frontend/src/lib/api.ts
// Centralized API client. The backend URL comes from env (never hardcoded).
const BASE_URL = import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000';
```

Vite env vars are **build-time** (baked into the static bundle at `npm run build`), not runtime — so the frontend Dockerfile (§3) must accept `VITE_API_BASE_URL` as a **build ARG**, not a container-start env var; changing the backend URL after the image is built means rebuilding the frontend image. This matters for the deploy script (§5): the frontend image build step needs the staging URL known at build time. No Dockerfile exists in hr-frontend today.

### 1.4 The ingest command sequence — source-folder assumption

| Command | File | Signature | Source assumption |
|---|---|---|---|
| `registry:import` | `app/Console/Commands/RegistryImport.php:34` | `{path? : path to the registry .xlsx (defaults to config registry.xlsx_path)}` | `config('registry.xlsx_path')` → `env('REGISTRY_XLSX_PATH', base_path('data/01_listado_convenios.xlsx'))` (`config/registry.php:7`) — **local repo-relative path by default** |
| `documents:ingest-folder` | `app/Console/Commands/IngestFolder.php:24,30` | `{path? : corpus root (default data/all-files)}` → `base_path('data/all-files')` | **Reads a local folder on the container's filesystem**, walked with `Symfony\Finder` (`IngestFolder.php:38`), then calls `DocumentIngestor` which pushes each file to S3 (`Sprint-1` ingestor) — so on staging, source files must be **downloaded from S3 to a local scratch folder on the EC2 first**, then `documents:ingest-folder` run against that scratch folder (the command itself never reads S3 directly) |
| `salary:import` | `app/Console/Commands/SalaryImport.php:34,43-46` | `{--document= : import a single salary document by uuid}` — **no path option** | Operates on `Document` rows already in the DB with `document_type = salary_tables` and an `.xlsx` `storage_path` — i.e. it runs **after** `documents:ingest-folder` has already put the salary `.xlsx` files into S3 via the ingestor; no separate source-folder concern |
| `chunks:embed` | `app/Console/Commands/ChunksEmbed.php:26,37-42` | `{--document=} {--dry-run}` — **no path option** | Selects in-scope `Document` rows already in the DB (`document_type ∈ {convenio_text, national_law, partial_agreement, internal_hr_ruling}`, `retrieval_status ∈ {active, historical}`, `tagging_status != under_review`) and calls hr-ai `/embed` per document (`ChunksEmbed.php:65`) — reads the original bytes from S3 via `storage_path`, no local folder involved |

**Confirmed sequence on staging** (§7): upload source files to S3 (or a scratch EBS folder, since `documents:ingest-folder` needs a local path) → `registry:import` → `documents:ingest-folder` → `chunks:embed` → `salary:import`. This matches the README-documented order (`hr-backend/README.md:169-184`) and is unchanged by staging — only the **origin** of the folder changes (synced down from S3 to a scratch dir on the resized instance, rather than a folder that's always been local).

### 1.5 ADRs confirmed

- **ADR-0007** (`hr-docs/architecture/decisions/0007-laravel-python-split.md:9`): *"...and never runs migrations."* — hr-backend owns the schema/migrations; hr-ai reads registry/scope tables, reads+writes `document_chunks` only, never migrates. Echoed in `hr-ai/app/main.py:29`-area comments. **No staging deviation.**
- **ADR-0009** (`decisions/0009-s3-object-storage.md:9-14`): *"A single storage adapter wraps the S3 client; all access goes through it... Works with AWS S3 or a self-hosted MinIO without code changes."* Confirms MinIO→S3 on staging is **config only** (`AWS_ENDPOINT` unset + `FILESYSTEM_DISK=s3`, §1.1) — no code change, matching the hard constraint.
- **ADR-0015** (`decisions/0015-external-answer-model.md:12`): *"the secret is stored encrypted in the DB and read server-side — set-and-mask, not env-rewrite."* Confirms the Anthropic key is **never** an env var / SSM parameter on staging — it stays exactly where it is today: set once via the hr-backend admin "Answer model" screen, `Crypt`-encrypted in `answer_model_settings`. Staging's SSM parameters (§4) therefore hold **no** answer-model key.
- **ADR-0018** (`decisions/0018-role-scoped-conversation-access.md`) / **ADR-0019** (`decisions/0019-raise-only-guardrail-configuration.md`): both are permanent access/guardrail models (conversation-history RBAC; raise-only admin guardrails over a hardcoded floor) with **no environment toggle** — nothing in either ADR changes on staging. Confirmed by reading both files in full; neither references environment, staging, or deployment target.
- **`architecture.md` §2** (`hr-docs/architecture/architecture.md:19-38`): the four-repo table and the 5-step Laravel↔Python contract, confirming `hr-ai` "**Never runs migrations**" (line 26) and the call sequence (frontend → hr-backend resolves scope → hr-backend calls hr-ai → hr-ai returns answer+citations+confidence+trace → hr-backend persists+decides).
- **`architecture.md` §3** (`architecture.md:42-50`): the three storage layers — Postgres (relational, owned by hr-backend), pgvector `document_chunks` (read+written by hr-ai), S3 (accessed only through the ADR-0009 adapter). Confirms RDS Postgres 16 + pgvector satisfies layers 1+2 in one instance, and S3 satisfies layer 3, with no schema/code implication from the swap.

---

## 2. Infra scripts — `hr-docs/infra/`

All scripts are **idempotent bash + `aws` CLI**, checked into `hr-docs/infra/` (chosen over a new `hr-infra` repo — the docs repo is already checked out and versioned, and infra-as-scripts is documentation-adjacent, not itself a service repo). Every script:
- Is safe to re-run: it `describe`s/`get`s first and only `create`s when the named resource is absent; re-running with no changes is a no-op that prints "already exists, skipping."
- Takes zero interactive input — all names/sizes are constants at the top of the script (or a shared `hr-docs/infra/vars.sh` sourced by all of them) so production is these same scripts with different constants.
- Never echoes a secret value (only parameter **names**, ARNs, and IDs).

Proposed file layout:

```
hr-docs/infra/
  vars.sh                    # shared constants: region, names, sizes, CIDRs
  01-network.sh              # confirm/tag default VPC + subnets (no creation needed)
  02-security-groups.sh      # hr-staging-ec2-sg, hr-staging-rds-sg
  03-rds.sh                  # RDS instance + `CREATE EXTENSION vector`
  04-s3.sh                   # documents bucket + backups bucket (versioned)
  05-ssm-params.sh           # empty/placeholder SecureString parameter *names*
  06-iam.sh                  # role + instance profile (needs Pedram's console step first — see 2.4)
  07-ec2.sh                  # EC2 instance + EIP + attach instance profile
  stop.sh
  start.sh
  resize-for-ingest.sh
  resize-back.sh
  status.sh
```

### 2.1 Network — `01-network.sh`

- **Creates nothing.** Confirms the default VPC (`vpc-0f33f60b804ff6738`, `172.31.0.0/16`, confirmed this turn) and its 3 default subnets (`eu-west-1a/b/c`) via `aws ec2 describe-vpcs`/`describe-subnets`, and picks one AZ deterministically (`eu-west-1a`, first alphabetically) for the EC2 + RDS subnet group. Idempotent by construction (read-only).
- RDS needs a **DB subnet group** spanning ≥2 AZs even for single-AZ deployment (an RDS requirement, not a staging choice) — the script creates `hr-staging-db-subnet-group` from all 3 default subnets if it doesn't already exist (`aws rds describe-db-subnet-groups` → `create-db-subnet-group` only if absent).

### 2.2 Security groups — `02-security-groups.sh`

Creates two SGs (idempotent via `describe-security-groups --filters Name=group-name,Values=...` before `create-security-group`):

- **`hr-staging-ec2-sg`**: ingress `22/tcp` from `<Pedram's current public IP>/32` (resolved at script run time via `curl -s https://checkip.amazonaws.com`, **never hardcoded** — re-running the script after an IP change updates the rule via `authorize`/`revoke` diffing against the existing rule set, not a blind re-add), `80/tcp` + `443/tcp` from `0.0.0.0/0`. Egress: all.
- **`hr-staging-rds-sg`**: ingress `5432/tcp` **only** from `hr-staging-ec2-sg` (security-group-to-security-group rule, not a CIDR) — satisfies "RDS reachable only from the EC2 SG" exactly, and is immune to the EC2's IP ever changing.

### 2.3 RDS — `03-rds.sh`

`aws rds describe-db-instances --db-instance-identifier hr-staging-db` first; if absent, `create-db-instance`:

| Setting | Value |
|---|---|
| Engine | `postgres`, version `16.x` (latest 16 minor at build time) |
| Instance class | `db.t4g.medium` |
| Storage | `50` GB `gp3` |
| Multi-AZ | `false` (single-AZ, per spec) |
| Backup retention | `7` days, `--backup-window` set to a low-traffic UTC hour |
| Public access | `--no-publicly-accessible` |
| VPC security group | `hr-staging-rds-sg` |
| DB subnet group | `hr-staging-db-subnet-group` |
| Master credentials | **username** is a plain arg (`hr_staging_admin`); **password** is generated once with `aws secretsmanager` — no: per the secrets rule, the script instead **requires** the SSM parameter `/hr-staging/rds/master-password` to already hold a value (Pedram sets it via §4 **before** running `03-rds.sh`) and passes `--master-user-password "$(aws ssm get-parameter --name /hr-staging/rds/master-password --with-decryption --query Parameter.Value --output text)"` — the value is read into a shell variable and passed directly to the `create-db-instance` call, never written to a file or echoed. |
| Extension | After `available`, the script runs `CREATE EXTENSION IF NOT EXISTS vector;` via `psql` (connecting from the machine running the script, which is Pedram's laptop or the not-yet-resized EC2 — either way over the temporarily-open path; **the script does not open RDS publicly to do this** — it either runs from inside the VPC (the EC2, once up) or the operator temporarily adds their IP to `hr-staging-rds-sg` for this one `psql` call and the script removes it again in a `trap`/finally block). |

Re-running: `describe-db-instances` finds it existing → prints status, skips `create`. Extension creation uses `CREATE EXTENSION IF NOT EXISTS` — safe to re-run.

### 2.4 IAM — `06-iam.sh` and the exact policy Pedram attaches

**This is the one step `PowerUserAccess` cannot do.** `06-iam.sh` creates:
- Role `hr-staging-ec2-role` (trust policy: `ec2.amazonaws.com`)
- Two inline role policies (`PutRolePolicy`, not `AttachRolePolicy` — see below): `hr-staging-s3-read` (scoped `s3:GetObject`/`s3:ListBucket` on the two staging buckets from §2.5 only) and `hr-staging-ssm-read` (scoped `ssm:GetParameter`/`GetParameters`/`GetParametersByPath` on `arn:aws:ssm:eu-west-1:049681810267:parameter/hr-staging/*` only, plus `kms:Decrypt` on the `alias/aws/ssm` key for `SecureString` values)
- Instance profile `hr-staging-ec2-profile`, with the role added to it

**Why inline policies (`PutRolePolicy`), not `AttachRolePolicy` of AWS-managed policies:** attaching a *managed* policy (e.g. `AmazonS3ReadOnlyAccess`) would need `iam:AttachRolePolicy`, and best practice is to pair that with an `iam:PolicyARN` condition to prevent the granted identity from attaching something broader (e.g. `AdministratorAccess`) later. Writing the exact-scope permissions as **inline** role policies instead needs no `AttachRolePolicy`/`PolicyARN`-condition machinery at all, is strictly narrower (bucket-and-parameter-path-scoped, not account-wide S3/SSM read), and is exactly what `staging-env-spec.md:26` asks for ("an instance profile granting S3 + SSM read"). So the policy below **only** contains role/instance-profile lifecycle actions — it never touches `AttachRolePolicy`/managed-policy ARNs at all, which also means there is no path by which this policy could be used to attach `AdministratorAccess` (or any other managed policy) to anything.

**Build-time procedure:**
1. Pedram opens the AWS console → IAM → Users → `cursor-dev` → Add permissions → Create inline policy → JSON tab → pastes the policy below → names it `hr-staging-iam-role-management` → Create policy.
2. Cursor runs `06-iam.sh` (creates the role, the two inline policies on that role, and the instance profile).
3. Once §2.7's EC2 is up and the instance-profile attachment is confirmed working, Pedram **removes** the inline policy from `cursor-dev` in the console (same screen, "Remove"). No standing IAM-management permission is left on the deployer user between builds; it is re-attached only if the role/profile ever need to be recreated (e.g. the production re-run).

The exact policy (account ID `049681810267`, confirmed this turn via `aws sts get-caller-identity`; region is not part of an IAM ARN):

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Sid": "HrStagingIamRoleManage",
      "Effect": "Allow",
      "Action": [
        "iam:CreateRole",
        "iam:GetRole",
        "iam:DeleteRole",
        "iam:TagRole",
        "iam:UntagRole",
        "iam:PutRolePolicy",
        "iam:GetRolePolicy",
        "iam:DeleteRolePolicy",
        "iam:ListRolePolicies",
        "iam:ListInstanceProfilesForRole"
      ],
      "Resource": "arn:aws:iam::049681810267:role/hr-staging-*"
    },
    {
      "Sid": "HrStagingIamInstanceProfileManage",
      "Effect": "Allow",
      "Action": [
        "iam:CreateInstanceProfile",
        "iam:DeleteInstanceProfile",
        "iam:GetInstanceProfile",
        "iam:AddRoleToInstanceProfile",
        "iam:RemoveRoleFromInstanceProfile",
        "iam:TagInstanceProfile",
        "iam:UntagInstanceProfile"
      ],
      "Resource": "arn:aws:iam::049681810267:instance-profile/hr-staging-*"
    },
    {
      "Sid": "HrStagingPassRoleToEc2Only",
      "Effect": "Allow",
      "Action": "iam:PassRole",
      "Resource": "arn:aws:iam::049681810267:role/hr-staging-*",
      "Condition": {
        "StringEquals": {
          "iam:PassedToService": "ec2.amazonaws.com"
        }
      }
    }
  ]
}
```

What this policy **does not** grant, deliberately: no `iam:AttachRolePolicy`/`iam:DetachRolePolicy` (nothing can attach an AWS-managed policy — including `AdministratorAccess` — to anything, closing the classic escalation path); no `iam:CreateUser`/`iam:CreateAccessKey`/`iam:*Group*`/`iam:*Policy*` (customer-managed policy objects) at all; no unscoped `Resource: "*"`; no action on any role/instance-profile **not** named `hr-staging-*`; `iam:PassRole` is further restricted by the `iam:PassedToService` condition so `hr-staging-ec2-role` can only ever be handed to `ec2.amazonaws.com`, never assumed generally. This is materially narrower than `IAMFullAccess` and narrower than attaching any AWS-managed IAM policy at all.

### 2.5 S3 — `04-s3.sh`

Two buckets (`aws s3api head-bucket` → create only if 404):
- `hr-staging-documents-<account-id-suffix>` (or a fixed name if globally available) — versioning **off** (documents are replaced via re-ingest, not versioned per spec); server-side encryption (SSE-S3) enabled; public access block enabled.
- `hr-staging-backups-<account-id-suffix>` — **versioning ON** (`put-bucket-versioning --versioning-configuration Status=Enabled`, per spec's "backups with versioning"); same encryption + public-access-block.

Bucket names include a short account-derived suffix so re-running in a fresh account (the production re-run) doesn't collide with an already-taken global S3 name.

### 2.6 SSM parameters — `05-ssm-params.sh`

Creates **empty placeholder** `SecureString` parameters (names only — the script never accepts or writes a value argument; it creates each with a literal placeholder string like `"SET-ME-VIA-CONSOLE"` and Pedram overwrites the value via `aws ssm put-parameter --overwrite` or the console himself):

```
/hr-staging/rds/master-password
/hr-staging/hr-backend/app-key
/hr-staging/hr-backend/db-password        # the app's own DB user, distinct from the RDS master
/hr-staging/hr-backend/internal-token     # must match hr-ai's copy below
/hr-staging/hr-backend/postmark-api-key
/hr-staging/hr-ai/db-password             # the scoped hr_ai role's password (ADR-0007)
/hr-staging/hr-ai/internal-token          # same value as hr-backend's copy
```

Re-running: `get-parameter` first; only `put-parameter` (create) when absent, **never** overwrites an existing value (so re-running never clobbers a value Pedram has already set).

### 2.7 EC2 — `07-ec2.sh`

`describe-instances --filters Name=tag:Name,Values=hr-staging-app` first. If absent: `run-instances` with `t3.large`, the Ubuntu 24.04 AMI resolved live via the SSM public parameter (`/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id` — confirmed this turn, resolves to `ami-0526a6499f6470118` today, but the script re-resolves it every run rather than hardcoding), `100` GB `gp3` root volume, `hr-staging-ec2-sg`, `hr-staging-ec2-profile` (from §2.4), in the chosen default subnet. Allocates + associates an Elastic IP (`allocate-address` + `associate-address`, `describe-addresses --filters Name=tag:Name,Values=hr-staging-eip` idempotency-checked first).

### 2.8 `stop.sh` / `start.sh` / `resize-for-ingest.sh` / `resize-back.sh` / `status.sh`

| Script | Does | Idempotency |
|---|---|---|
| `stop.sh` | `aws ec2 stop-instances` (EC2) + `aws rds stop-db-instance` (RDS) | Checks current state first; no-ops if already stopped |
| `start.sh` | `aws ec2 start-instances` + `aws rds start-db-instance`; waits for both `running`/`available`, then SSHes in only far enough to confirm `docker compose ps` shows all services `healthy` (Compose's own `restart: unless-stopped` — §3 — brings the stack back with no manual step) | No-ops if already running |
| `resize-for-ingest.sh` | `stop-instances` → `modify-instance-attribute --instance-type c7i.2xlarge` → `start-instances`. The EBS volume (and everything on it — Docker images, the model-cache volume, Postgres data is on RDS not this volume) is untouched by an instance-type change, so **no re-download** happens. | Checks current instance type first; no-ops (prints "already c7i.2xlarge") if already resized |
| `resize-back.sh` | Same stop → `modify-instance-attribute --instance-type t3.large` → start | Same idempotency check |
| `status.sh` | SSH in (or SSM Session Manager, if the instance profile is later widened to include it — out of scope this cut) and run: `docker compose -f docker-compose.staging.yml ps`, `docker compose exec hr-backend php artisan queue:monitor` (pending/failed counts), `curl -s localhost:8001/health/model` (chunk/model status), `psql ... -c "select count(*) from document_chunks"`, `df -h` / `free -m`. Prints a one-screen summary. Read-only — always safe to re-run. |

---

## 3. Containerization

### 3.1 Dockerfiles

- **`hr-backend/Dockerfile`** (new): multi-stage — `composer install --no-dev` stage, then PHP-FPM 8.3 + nginx (simplest given no existing Octane setup in `composer.json:10-16`) runtime stage. Same image is reused, unmodified, as the **worker** service in Compose — only the container `command` differs (`php-fpm` for the web service vs `php artisan queue:work --tries=3 --timeout=120` for the worker, per the real command gap found in §1.1). Worker service gets `restart: unless-stopped`.
- **`hr-ai/Dockerfile`** (new): Python 3.11-slim base, `pip install` (torch CPU wheel + sentence-transformers + fastapi/uvicorn per existing `requirements`), sets `ENV HF_HOME=/model-cache` explicitly (closing the §1.2 gap — with no override today the cache would land in the container's ephemeral layer). **Decision needed at build time (flagged in §9 open questions): bake the 4.3 GB model into the image at build vs. download once into the named `/model-cache` volume on first start.** This plan recommends the **named-volume** approach (smaller image, one-time 4.3 GB pull on first `docker compose up`, never again as long as the volume persists) because it matches the EBS-volume-survives-a-resize property already relied on for `resize-for-ingest.sh`, but documents the bake-in alternative for the build turn to choose (§9).
- **`hr-frontend/Dockerfile`** (new): multi-stage — `npm ci && npm run build` with `VITE_API_BASE_URL` passed as a build `ARG` (per the §1.3 finding that Vite env vars are build-time, not runtime), then a tiny static-file stage (nginx or Caddy serving the `dist/` output) — or, more simply, since Caddy is already in the stack as the reverse proxy, the frontend build output is copied into a volume Caddy serves directly, avoiding a second web server. This plan proposes the latter (one fewer moving part).
- **Caddy**: official `caddy:2-alpine` image, no custom Dockerfile needed — just a `Caddyfile` (plain `:80` reverse-proxying `/api/*` → hr-backend, everything else → the static frontend volume, for first bring-up; a domain-based `Caddyfile` with automatic HTTPS is the documented upgrade once a subdomain exists, per §9).

### 3.2 hr-ai health check — the model-presence gate (small, explicit code addition)

Per the kickoff prompt's own instruction ("hr-ai's health can verify 'model present'"), and because §1.2 confirmed neither `/health` nor `/health/config` checks this today, this plan specifies (for the *build* turn to implement, not this turn) one **additive** endpoint:

```
GET /health/model
  → checks: does a BAAI--bge-m3 snapshot dir exist under $HF_HOME with the expected
    safetensors file present and non-zero size?
  → {"status": "ok", "model_present": true}   (200)
  → {"status": "not_ready", "model_present": false}   (503)
```

This does **not** modify `/health` (used as the container-liveness probe — must stay cheap and instant) or `/health/config` (unchanged, still non-secret config echo). The Compose healthcheck for the `hr-ai` service polls `/health/model` (not `/health`) so Compose/`docker compose ps` correctly shows hr-ai `unhealthy`/`starting` for the entire first-run download window rather than falsely `healthy`.

### 3.3 `docker-compose.staging.yml` — services

`hr-backend` (php-fpm+nginx or Octane) · `hr-backend-worker` (same image, `queue:work`, `restart: unless-stopped`) · `hr-ai` (uvicorn, `/model-cache` named volume, healthcheck → `/health/model`) · `caddy` (serves the built frontend + reverse-proxies `/api`) · **no** `postgres`/`minio`/`mailhog` services — those are RDS/S3/Postmark on staging, matching `deploy.md §2` exactly ("S3-compatible storage pointed at the production bucket (not local MinIO)").

### 3.4 Log rotation

Each service sets Compose's built-in `logging.driver: json-file` with `max-size: "20m", max-file: "5"` — bounds `docker logs` growth without needing CloudWatch agent installation this cut (CloudWatch is the documented upgrade in §8, not required for the initial bring-up).

---

## 4. Secrets flow

1. **`05-ssm-params.sh`** (§2.6) creates the parameter **names** as `SecureString` placeholders. Pedram sets real values via console or `aws ssm put-parameter --overwrite` (never via a script argument that would appear in shell history verbatim — the plan recommends Pedram pipe the value from a password manager: `aws ssm put-parameter --name ... --type SecureString --value "$(pass show hr-staging/x)" --overwrite`, or type it interactively at a `read -s` prompt if a small wrapper script is preferred at build time).
2. **Container start entrypoint** (`hr-backend`, `hr-ai`, and the worker all share one small `entrypoint.sh`): calls `aws ssm get-parameters-by-path --path /hr-staging/hr-backend --with-decryption` (or the hr-ai-specific path) using the **instance profile's** credentials (no key material anywhere in the container), writes the resolved values to process environment (`export VAR=value`), then `exec`s the real command (`php-fpm`, `queue:work`, or `uvicorn`). Nothing is written to a file inside the image or a bind-mounted `.env` — env vars are process-memory only, gone when the container stops.
3. **`.env.staging.example`** — two files (`hr-backend/.env.staging.example`, `hr-ai/.env.staging.example`), committed, containing every var name with an obvious placeholder (`AWS_BUCKET=hr-staging-documents-XXXX`, `POSTMARK_API_KEY=__SET_VIA_SSM__`) — a template for a human to read, never consumed directly by anything (the real values come from SSM at container start, per step 2).
4. **Scan step** proving no secret ever lands in a repo/image/Compose file:
   ```bash
   git -C hr-backend grep -InE '(AKIA|aws_secret_access_key\s*=\s*[^$]|POSTMARK_API_KEY\s*=\s*[A-Za-z0-9]|-----BEGIN)' -- . ':(exclude)vendor' || echo "clean"
   git -C hr-ai grep -InE '...' -- . ':(exclude).venv' || echo "clean"
   docker history --no-trunc hr-backend:staging | grep -iE 'AKIA|secret' && echo "LEAK" || echo "clean"
   grep -InE 'AKIA|secret_access_key\s*=' docker-compose.staging.yml || echo "clean"
   ```
   Run once before the deploy step and recorded in the build log (acceptance criterion 3 in the spec).
5. **The Anthropic answer-model key is explicitly excluded from SSM** (ADR-0015, confirmed §1.5) — it is set once via the hr-backend admin "Answer model" screen after the stack is up, exactly as it works today; no staging-specific handling needed for it.

---

## 5. The deploy path

`deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>`:

1. For each of the four repos: `git fetch && git checkout <pinned-sha>` in a deploy workspace on the EC2 (or build locally and `docker push` to a private registry — this plan proposes building **on the instance** for the first cut, since ECR setup is additional scope not in `staging-env-spec.md`'s in-scope list; noted as a §9 open question if build time on `t3.large` proves too slow).
2. `docker compose -f docker-compose.staging.yml build`.
3. `docker compose run --rm hr-backend php artisan migrate --force` (hr-backend only, per ADR-0007 — hr-ai has no migrate step, nothing to run).
4. `docker compose up -d` (restarts anything whose image changed; Compose leaves unchanged services running).
5. Health-check loop: poll `hr-backend /up` (Laravel's default health route) or a dedicated `/api/health`, `hr-ai /health` **and** `/health/model`, the frontend's `/` via Caddy, and confirm the worker container is `Up` (not `Restarting`) via `docker compose ps hr-backend-worker`.
6. Print the deployed commit SHA of each of the four repos (`git -C <repo> rev-parse HEAD` inside each build context, echoed at the end).
7. **Rollback**: re-run `deploy.sh` with the previous four SHAs — the script is symmetric, a "rollback" is just a deploy to an older pin. Documented as: `deploy.sh $(cat .last-good-shas)` where `deploy.sh` writes `.last-good-shas` on every **successful** health-check pass (so the last known-good set is always one file away).

**Two-step bring-up** (explicit, per the hard constraint):
- **(a)** First `deploy.sh` run against an **empty** RDS database — proves steps 1–6 work end to end with zero data risk.
- **(b)** Only after (a) is verified: `resize-for-ingest.sh` → the §7 ingest sequence → `resize-back.sh`.
- **(c)** Only after (b): the backup rehearsal (§6), run once, deliberately, against real ingested data.

---

## 6. Backup + restore

- **Nightly `pg_dump`**: a Compose one-shot service (`docker compose run --rm db-backup`) invoked by a **host cron** entry (`crontab -e` on the EC2, e.g. `0 3 * * * cd /opt/hr-staging && docker compose run --rm db-backup`) — the container runs `pg_dump $DATABASE_URL | gzip > /tmp/backup.sql.gz && aws s3 cp /tmp/backup.sql.gz s3://hr-staging-backups-XXXX/pg/$(date +%F).sql.gz` using the instance profile's S3-write permission (§2.4's inline policy needs a small addition — `s3:PutObject` on the backups bucket, alongside the read scope already there; noted so §2.4's policy text is updated at build time to include write on the backups bucket specifically, not broadened generally).
- **RDS automated snapshots**: already covered by `--backup-retention-period 7` in §2.3 — no separate script needed, AWS manages the daily snapshot inside the maintenance window.
- **Restore rehearsal procedure** (written now, executed once during the build, per the hard constraint):
  1. `aws rds create-db-instance --db-instance-identifier hr-staging-restore-test --db-snapshot-identifier <latest-automated-snapshot>` (restore-from-snapshot, a **new**, throwaway instance — never restore over the live one).
  2. Wait for `available`; connect; run count queries: `select count(*) from documents; select count(*) from document_chunks; select count(*) from salary_table_rows; select count(*) from reference_facts;`.
  3. Compare each count against the live instance's same queries, captured immediately before the rehearsal.
  4. Counts must match exactly (no ingest activity should be running concurrently — the rehearsal is scheduled for a moment with no active writes).
  5. `aws rds delete-db-instance --db-instance-identifier hr-staging-restore-test --skip-final-snapshot` — tear down the scratch instance immediately after verification (it costs real money while it exists).
  6. Record the executed rehearsal (timestamp, counts, pass/fail) in `deploy.md`'s runbook section at build time.

---

## 7. The ingest on staging

1. `resize-for-ingest.sh` → `c7i.2xlarge`.
2. Sync source files to the EC2's scratch folder: `aws s3 sync s3://hr-staging-documents-XXXX/source-corpus/ /opt/hr-staging/ingest-scratch/` (source files are themselves uploaded to that S3 prefix by Pedram ahead of time — out of this plan's scripted scope, a manual one-time `aws s3 cp --recursive` from wherever the corpus lives today).
3. `docker compose exec hr-backend php artisan registry:import` (reads `/opt/.../01_listado_convenios.xlsx` — the container needs the scratch folder bind-mounted, or the file copied in via `docker compose cp`).
4. `docker compose exec hr-backend php artisan documents:ingest-folder /scratch-path` — same bind-mount.
5. `docker compose exec hr-backend php artisan chunks:embed` (no `--dry-run` — every in-scope document; this is the CPU-heavy step the `c7i.2xlarge` resize exists for).
6. `docker compose exec hr-backend php artisan salary:import`.
7. Report counts: documents ingested (by `tagging_status`), chunks written (`chunks:embed`'s own summary line, `ChunksEmbed.php:88`: *"Embed complete: {total} chunks written across {count} documents ({failed} failed)"*), salary tables/rows (`SalaryImport.php:126`: *"Salary import complete: {tables} tables, {rows} rows, {categories} new job categories"*), reference facts (a `select count(*) from reference_facts` — no command prints this directly).
8. **Expected legitimate zeros** (not failures — per `deploy.md §4a` and the spec's own risk note): scanned PDFs with no extractable text produce **0 chunks** (documents `id 89`, `id 91`, and any others `reviews:scan-expiry` surfaces — fixed only by 7e OCR); `under_review` documents are excluded from `chunks:embed`'s selection by construction (`ChunksEmbed.php:41`: `->where('tagging_status', '!=', 'under_review')`) — **COEAS Estatal (`id 72`) stays `under_review` and is correctly skipped**; the calibration anchors are expected to score **2 of 5** for the documented reasons in `deploy.md §5`.
9. `aws rds create-db-snapshot --db-instance-identifier hr-staging-db --db-snapshot-identifier hr-staging-post-ingest-<date>`.
10. `resize-back.sh` → `t3.large`.

---

## 8. Cost + ops

| Component | Size | ~$/day (eu-west-1, on-demand, 24/7) |
|---|---|---|
| EC2 `t3.large` | 2 vCPU/8GB | ~$2.19 (`$0.0912`/hr — verified live via `aws pricing get-products`, Session 3; this plan's original `$1.95` figure was a slightly stale estimate) |
| EBS 100GB gp3 | | ~$0.29 (`$0.088`/GB-month) |
| RDS `db.t4g.medium` | single-AZ | ~$1.66 (`$0.069`/hr — verified) |
| RDS storage 50GB gp3 | | ~$0.21 (`$0.127`/GB-month) |
| Elastic IP | | ~$0.12 (`$0.005`/hr — **correction, found at build time**: AWS made *all* public IPv4 addresses billable Feb 1 2024, attached or idle, same rate either way. This plan's original "$0.00, free while associated" line reflected the pre-2024 policy and was wrong the whole time; it is *not* a staging-specific mistake, just an out-of-date assumption baked into the initial plan) |
| RDS manual snapshot (`hr-staging-post-ingest-20260906`, 50GB) | | $0.00 today — within the free backup-storage allocation (equal to provisioned DB storage, i.e. 50GB, while the DB instance exists); would become `$0.095`/GB-month if the source instance is ever deleted |
| S3 (documents+backups, low volume) | | ~$0.05–0.15 |
| **Total, running** | | **~$4.5–4.7/day** — still inside the spec's `$4-5/day` range, revised up from this plan's original `$4.0-4.2` after the EC2-rate and EIP corrections above |
| **Total, stopped** (`stop.sh`) | EC2+RDS compute stopped; EBS/RDS storage + the now-always-billed EIP still billed | ~$0.62–0.72/day (was `$0.45`; +$0.12 EIP correction + more precise storage rates) |
| Ingest hour, resized | `c7i.2xlarge` (8 vCPU/16GB) for ~1-2h | ~$0.35-0.70 extra that day — matches the spec's "~$0.50 extra per run" |

Verified live at build time via `aws pricing get-products` (eu-west-1, captured 2026-09-06) rather than re-estimated from memory. Cost Explorer's own tag-filtered billing (`Env=staging`) was checked too but returned no data yet — cost-allocation tags take up to 24h to activate after first use, and these resources are hours old at time of writing; re-check Cost Explorer after a day or two of real usage to confirm against actual billed amounts rather than list pricing.

**Stop-when-idle habit**: `stop.sh` documented as the end-of-day default; `start.sh` as the beginning-of-day default. `status.sh` (§2.8) is the single command that answers "is anything running that I forgot to stop" and "is the corpus/worker healthy."

**Log rotation**: Compose `json-file` driver bounds (§3.4) for the initial cut; CloudWatch Logs agent is the documented **upgrade**, not required for acceptance criteria (the spec's own wording is "CloudWatch **or** `docker logs`" — `staging-env-spec.md §7` / kickoff prompt item 7 — `docker logs` with rotation satisfies the "or").

---

## 9. Migrations & build order, ADR, assumptions & open questions

### Build order

`infra (§2) → containers (§3) → secrets (§4) → deploy, empty DB (§5a) → ingest, resized (§7) → resize-back → backup rehearsal (§6) → docs (deploy.md runbook + this sprint's ADR)`.

### ADR to write at build time

`hr-docs/architecture/decisions/0025-staging-deploy-topology.md` (0024 is taken by 7d on the `sprint-7d` branch, so this is 0025, the next free number after that) — decision: managed RDS+S3, single-EC2 Docker Compose (not ECS/EKS/SQS — explicitly out of scope per the spec), secrets in SSM read at container-start via instance-profile credentials (not baked into images), BGE-M3 pre-cached in a named volume with an additive `/health/model` gate, worker as the same backend image with `restart: unless-stopped`, IAM least-privilege via a Pedram-attached inline policy scoped to `hr-staging-*` role/instance-profile names rather than a broad IAM grant on the deploy user.

### Assumptions & open questions (for Pedram to resolve before/during the build turn)

1. **Domain/TLS**: no subdomain has been confirmed pointed at the eventual EIP. Plan assumes **plain HTTP on the EIP for first bring-up** (per spec) and defers Caddy's automatic-HTTPS `Caddyfile` variant until a domain exists — open question: what domain, and is DNS already delegated somewhere Pedram controls?
2. **Postmark availability**: `.env.example` shows `POSTMARK_TOKEN` (a var Laravel doesn't actually read — see the §1.1 mismatch finding) — open question: does a working Postmark account + sending domain already exist for staging, or does this need setting up as part of the build turn (it's referenced in `deploy.md §1` as a go-live blocker, and OTP login won't work end-to-end without it, which is acceptance criterion 8).
3. **Bake-model-into-image vs. named-volume**: this plan recommends the named-volume approach (§3.1) for a smaller image and reuse of the same "the EBS volume survives everything" property already relied on for resizes — but bake-in is more deterministic (no first-start download window at all, simpler `/health/model` semantics since the model is guaranteed present from the first container start). Open question for the build turn: confirm the volume approach, or switch to bake-in if the first-start download window (4.3 GB over the instance's network path) proves too slow/fragile in practice.
4. **IAM least-privilege cut**: this plan's §2.4 policy is deliberately narrow (role/instance-profile lifecycle only, scoped to `hr-staging-*`, no `AttachRolePolicy`). Open question: is the manual "Pedram attaches, build runs, Pedram detaches" console step (rather than a standing narrow grant) an acceptable process for the production re-run too, or should the production cut instead provision a **separate, dedicated** deploy IAM user with this policy attached permanently (still not `cursor-dev`, still not `IAMFullAccess`) — a call for the pre-go-live IAM hardening pass already flagged as out of scope in `staging-env-spec.md` ("Out of scope: ... least-privilege IAM hardening beyond a sensible first cut (pre-go-live)").
5. **Build location**: §5 assumes images are built on the EC2 itself (no registry). Open question: acceptable for a `t3.large`/`c7i.2xlarge`, or should a container registry (ECR) be added — this would be new infra scope beyond the spec's explicit list and should be called out to Pedram before adding it.
6. **`POSTMARK_TOKEN`/`POSTMARK_API_KEY` mismatch** (§1.1): this plan assumes the staging `.env.staging.example` uses the **correct** var name `POSTMARK_API_KEY` (matching `config/services.php:18`) and that the `hr-backend/.env.example` + `deploy.md`'s references to `POSTMARK_TOKEN` are themselves latent bugs to flag in `deploy.md` at build time (a doc fix, not an app-code change, so within the "change no application code" constraint for *this* turn — deferred to the build turn regardless).
7. **`REGISTRY_XLSX_PATH` / ingest scratch folder location**: this plan assumes a fixed scratch path (e.g. `/opt/hr-staging/ingest-scratch`) bind-mounted into the `hr-backend` container for the two path-taking commands (§7 steps 3-4). Open question: confirm this path with Pedram at build time, or make it a `vars.sh` constant now.

---

## Summary — what this turn produced

- This plan file only. **No AWS resource was created.** The only AWS calls made were read-only (`sts:GetCallerIdentity`, `ec2:DescribeVpcs`, `ec2:DescribeSubnets`, `ssm:GetParameters` against a public AMI-alias parameter) — all covered by the existing `cursor-dev` `PowerUserAccess` grant, none requiring or touching IAM.
- **No application code was changed.** The one code addition this plan calls for (`GET /health/model` in hr-ai) is explicitly scoped as build-turn work, not done now.
- The corrected prerequisite (§0) replaces the kickoff prompt's IAM-user-creation instructions; the exact inline IAM policy (§2.4) is ready for Pedram to paste into the console **at build time**, immediately before `06-iam.sh` runs, and to remove immediately after.

**Ready for review. Stopping here per the plan-gate instruction.**
