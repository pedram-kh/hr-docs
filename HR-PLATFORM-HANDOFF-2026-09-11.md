# HR Platform — Handoff for a new thread (state as of 2026-09-11, after Sprint 8)

> **Purpose.** Paste this at the top of a new thread. It is everything the previous thread knew: what the project is, how it's built, how we work, every decision that binds, the current state on `main`/staging, and what's next. The role of the assistant in the new thread is the same as before: **architect / PM / reviewer**. Pedram runs Cursor and does eyes-on in a real browser on staging.
> The `hr-docs` repo is the source of truth for *detail* (architecture.md, data-model.md, deploy.md, roadmap.md, the ADRs, every sprint's spec/plan/review). This document is the *map* — read hr-docs for the territory. Repos: `github.com/pedram-kh/{hr-backend,hr-ai,hr-frontend,hr-docs}` (keep them **private**; the deploy path uses per-repo read-only deploy keys over SSH since Sprint 7g Item 0, so private is safe).

---

## 1. What the project is

A conversational HR platform for a Spanish client (codename in some fixtures: "Sedena" — never use the codename in code; the code prefix is `hr-`) with ~1,500 employees. It answers HR/labour-law questions **scoped to the asker**: the correct answer varies by **territory (province), sector, convenio colectivo (collective agreement), job category/group, and the date**. A confidently wrong answer carries legal weight, so the whole system is built around **getting scope right before generating anything**, making every answer traceable, and **escalating to a human rather than guessing**.

Four repos, one workspace (`hr-platform/`):
- **`hr-backend`** — Laravel 13 / PHP 8.4. System of record. Owns **all migrations and all writes**, auth (email OTP), directory, roles, knowledge management, escalation board, audit/provenance, chat history, the answer-or-escalate decision, scope resolution (deterministic). Calls hr-ai.
- **`hr-ai`** — Python / FastAPI. Embeddings (BGE-M3, 1024-d, pgvector), retrieval, router, synthesis, grounding, tagging/segmentation/OCR/explanation endpoints. **Reads-and-returns; writes only `document_chunks` + S3 sidecars; never migrates** (ADR-0007).
- **`hr-frontend`** — React 19 / Vite / TypeScript. Employee chat + admin console. **Vanilla-CSS token design system (ADR-0012/0013) — NOT Tailwind**, light-default + dark.
- **`hr-docs`** — markdown: architecture, data model, deploy runbook, roadmap, ADRs 0001–0030, every sprint folder (`sprints/sprint-NN/{spec,plan,build-prompt,review}.md`, eval harnesses/fixtures), `corpus-coverage.md` (now a generated export), `infra/` (all AWS scripts).

## 2. Architecture — the parts that bind

**Storage (ADR-0006):** Postgres (system of record) + pgvector in the same DB (`document_chunks`) + S3 via an adapter (ADR-0009; MinIO locally, real S3 on staging). **Structured data is queried, never embedded**: `salary_tables`/`salary_table_rows` (salary), `reference_facts` (structured reference knowledge). Prose is chunked (article-boundary, ADR-0017) and embedded.

**Four knowledge types:** vectorized prose (convenios, Estatuto), salary (SQL from xlsx), HR rulings (Sprint 4, published escalation resolutions, `internal_hr_ruling`), structured reference facts (7b: AI-segmented, human-verified, scoped facts like periodo de prueba per group). All four are answerable (7c).

**Faceted model (ADR-0001):** documents carry facets (territory, sector, convenio, validity, type, topic); the Knowledge Center renders **lenses** (by territory/sector/validity/topic/coverage) over them via one reusable hierarchy component (graph + list, leaf-opens-card). Scope derives from the convenio (territory/sector are never set independently). Vocabulary is closed and grows only by deliberate human action (ADR-0011): AI **proposes**, human **approves**.

**The answer loop (frozen since 2b; changed only additively by 7c):**
1. Deterministic **scope resolve** (hr-backend) from the employee profile + date.
2. **Guardrail baseline** (deterministic, before any hr-ai call): sensitive topics / legal-medical / other-employee data → escalate. Admin can only *tighten* (Sprint 6, ADR-0019, `stricter_of`, raise-only, 422 not clamp).
3. **Routing:** deterministic **salary pre-classifier** → `SalaryAnswerService` (exact SQL cell, cites source `chunk_id=null`, skips `/ground`); deterministic **reference-fact pre-check** (7c: topic lexicon match + a **verified** in-scope in-validity fact exists) → `ReferenceFactAnswerService` (quotes the fact verbatim, `structured_reference` authority, skips `/ground`); else LLM router (`/route`, Haiku) → prose | off_domain. Fail-safe = prose.
4. **Prose path:** `/retrieve` (scope-prefiltered, forced exact scan for full recall; recall-hardened union with sub-queries + a national-law pass; widened-pool **precedence re-rank** — convenio chunks displace same-topic Estatuto chunks) → `/synthesise` (abstains rather than fabricates; every substantive claim cites `[Fuente N]`; **authority precedence**: the convenio governs, the Estatuto is baseline only where the convenio is silent; **never blend, escalate on conflict**; rule 7 forbids deriving figures) → **answer-or-escalate** (hr-backend): Check A (retrieval floor) ∧ Check B (citations present and in-set) ∧ figure-guard ∧ `/ground` (per-claim entailment, substantive vs provenance, table-aware). Check C (model confidence) is a tiebreaker only.
5. **7c composition:** when a verified fact *and* governing convenio prose both apply, the fact is passed to `/synthesise` as an extra typed source (`source_type=reference_fact`, `chunk_id=null`, ranked below `official_convenio`), the composed answer **must** `/ground`, and a same-point figure conflict escalates *before* synthesis.
6. **Persist** message + citations + a full **trace** (`router_decision`, `floor_decision.{path,outcome,escalation_reason,authority_used,grounding}`); escalations create a card.

**Authority order:** `internal_hr_ruling` = `official_convenio` (0) > `structured_reference` (1) > `national_law` (2). A reference fact's column can *physically* hold only `structured_reference` (ADR-0021). Nothing the system generates can outrank the law.

**Escalation flywheel (Sprint 4):** board (New→Assigned→In Progress→Resolved), two-way chat (`hr_agent` turns, shown as "Recursos Humanos"), save-as-knowledge → published ruling → re-ingested via the normal PDF path. **Publish fence:** structural (scope+topic, fail-closed, Sprint 5 Correction-01) **OR** semantic (7d: `/compare-scope`, block ≥0.78 / acknowledge band ≥0.66, thresholds calibrated on real+synthetic anchors, `min`-not-`max` if ever exposed) — the fence only ever gets stricter.

**Escalation explanations (7g, ADR-0029):** every card stores deterministic `explanation_facts` (39-entry matrix keyed on reason→sub_outcome→fix, guard-tested so no reason can lack one) + an AI-written HR paragraph via a dedicated `/explain` (Haiku) with a no-new-claims guard (falls back to deterministic sentences) + a **structured fix link** (never AI). **Employees see one fixed neutral message, never the reason.**

**Access (Sprint 5, ADR-0018):** roles `super_admin`, `hr_agent`, `knowledge_editor`, `auditor`; abilities `directory.manage`, `admin.manage`, `history.view_all`, `escalation.work`, `knowledge.edit`, `guardrails.manage`, `vocabulary.approve`, `analytics.view` (Sprint 8). **The server is the boundary** (`EnsureCan`, API-matrix-tested); every conversation view is logged to `conversation_access_log`. Email OTP (Postmark in prod; `MAIL_MAILER=log` + `infra/compose/otp.sh <email>` on staging).

**AI is never trusted with the decision (ADR-0015/0016):** the model proposes, classifies, synthesises, explains; deterministic hr-backend code decides scope, answer-vs-escalate, routing precedence, and what is answerable. Everything AI-produced is **inert until a human verifies** (ADR-0020 spine): tags (7a), facts (7b), OCR text (7e), group trees (7f). Fuchsia `--provenance-ai` marks unverified AI content only.

## 3. Sprint history (all merged on `main`, deployed to staging)

| Sprint | What | Key ADR |
|---|---|---|
| 0–1 | Scaffold, schema, OTP; ingestion backbone (registry import, PDF `/extract`, deterministic filename parser, conflict detection, provenance) | 0001–0011 |
| 2a/2b-1/2b-2/2c | Chunking + embeddings + salary extraction; the answer loop; router + salary-in-chat + `/ground` + recall hardening; article-boundary re-chunk (Estatuto held out) | 0014–0017 |
| 3 | Knowledge Center (lenses, cards, bounded edit + 409 scope gate, sandbox) | 0012/0013 |
| 4 | Escalation board + two-way chat + save-as-knowledge + publish fence | — |
| 5 (+C-01) | Directory/CSV/audit, roles, role-scoped history, access log; fence fail-open fixed | 0018 |
| 6 | Guardrails config (additive, raise-only) | 0019 |
| 7a | LLM tagging tier (inert until verified), vocabulary proposals, expiry queue, lineage | 0020 |
| 7b-1 | `reference_facts` substrate (salary-patterned), docx/xlsx reader, manual create/verify, badged leaf | 0021 |
| 7b-2 | AI segmentation agent; **the eval was the deliverable**: 0 mis-scoped on real fixtures, restraint rules; `group_label` in the logical key | 0022 |
| 7c | Routed reference-fact answer (P1) + fact·prose composition (P2); golden-trace regression proves prose/salary byte-for-byte unchanged | 0023 |
| 7d | Semantic publish fence (additive) + fact version resolution (supersede closes validity, never deletes) + succession proposal (overlap ∧ later-validity, no LLM); thresholds calibrated on staging | 0024 |
| Staging | AWS eu-west-1: RDS PG16+pgvector, S3, one EC2 t3.large + Docker Compose (Caddy, backend, worker, scheduler, hr-ai w/ model volume), SSM secrets via instance profile, backup/restore rehearsed, deploy.sh over deploy keys | 0025 |
| 7e | OCR fallback: **Claude vision `claude-opus-5`** chosen by measured eval (column integrity/table structure/header survival; eu WER 0.7%); queued per-page jobs; S3 sidecar into the chunker (Option B); `under_review` until verified; 106 pages backfilled; admin pagination + `#doc=` deep links | 0026 |
| Correction-salary-01 | **Removed `gross_annual/14`** — 21/32 stored monthlies were wrong (up to +36%); never derive figures; monthlies labelled by source header; importer fails loudly on 0 rows; salary-PDF→xlsx conversion path | 0027 |
| 7f | Structured group scope: `convenio_groups` depth-2 tree, fact→scope join table, AI-proposed/human-approved trees (31/31, 0 under-splits), exact node matcher replaces the digit regex; live proof G2›resto 60/45/30 · G1 90/75/60 · parent escalates; manual-bind lane with recorded refusal | 0028 |
| 7g | Escalation explanations; Review-tab pagination + fact ids/excerpts; checksum-dedupe scope gate; binding-aware duplicate detection; otp.sh fix; deploy keys + private repos | 0029 |
| 8 | Analytics (deflection/paths/authority, escalations-by-fix, question clusters τ=0.80 with medoid labels), **Cobertura** (live coverage map = `CorpusCoverageService`, screen == export, headcount-ranked, every gap leaf resolves to a doc or fix link), **Calidad** (monthly stratified sample, reviewer recorded, wrong → task), thumbs feedback, scheduler; found the pre-existing `crypto.randomUUID()` chat bug (HTTP ≠ secure context) | 0030 |

## 4. How we work — the sprint-gate loop (non-negotiable)

1. **Spec** (assistant writes `spec.md` + a **plan-gate kickoff prompt**): goal, in/out of scope, acceptance criteria, eyes-on, risks. Deliverables are `.md` files with exact hr-docs paths + paste-blocks for Cursor.
2. **Plan** (Cursor, fresh thread): inspects the **real code/DB**, cites real lines, writes `plan.md`, **STOPs**. Assistant reviews **against actual files/data, never the summary**; resolves the plan's open questions explicitly.
3. **Build-authorization** (assistant): every decision stated; invariants to test; checkpoints (⏸) where Pedram must act; docs at close; "STOP — no commit/merge until reviewed."
4. **Build** (Cursor, on a `sprint-N` branch): writes `review.md`, **STOPs**. Deploy-driven work (infra) may commit as it goes with each step authorized; feature sprints wait.
5. **Review** (assistant, against real files) → **eyes-on** (Pedram, in a **real browser on staging** — chat included, every sprint) → **merge** (`--no-ff`, push, `deploy.sh` main, snapshot). The merge is the "done" moment.
6. Corrections are their own small prompts (`Correction-NN`), same gate.

**Conventions:** ADRs numbered sequentially (next: **0031**); snapshots `hr-staging-<event>`; test suites named `SprintNInvariantTest`; the golden-trace regression (`Sprint7cAdditivityRegressionTest`) must stay green through every change; `corpus-coverage.md` is regenerated by `corpus:coverage`, never hand-edited.

**Pedram:** communicates briefly in English, non-technical on ops (delegates the terminal to Cursor), wants **plain-language explanations** and **honest assessments over reassurance**, asks "explain X in simple terms" often. Give him step-by-step click lists for eyes-on. He makes product/scope calls; when he says "your call," decide and record the reasoning.

## 5. Standing lessons (each learned the hard way)

- **Review against reality, never the summary.** Every sprint's review caught something real only visible in the actual files/DB.
- **Safety by construction + a test that hits the boundary directly** (enum can't hold a higher authority; digit regex deleted; fence = old OR new).
- **For AI cognition, the eval is the deliverable** — gold set on real hard fixtures, measure, iterate the prompt, re-measure. Ask "wrong-but-flagged (catchable) vs wrong-but-confident (rubber-stampable)."
- **Never derive a figure the source doesn't state.** No correct constant exists (this corpus divides by 12/14/15/16).
- **Fail closed; err low on authority; escalate rather than guess; nothing auto-resolves/retires/publishes.**
- **Eyes-on in a real browser on the deployed host, every sprint.** Route/API tests structurally cannot catch: a missing nav key in the identity payload, a click with no handler, a browser API gated on HTTPS, a column showing the wrong field. Sprint 8's eyes-on found seven such bugs plus one six sprints old.
- **Reachability is a failure class tests don't see:** a write with no door (7f binding only at approval), a refusal made permanent (fact 35), a scheduler that didn't exist.
- **Measure thresholds, don't assume** (7d, 7e, 8's τ) — but "measure later once there's data" is an honest statement when there's nothing to measure yet.
- **Ops traps:** max_tokens truncation of large JSON outputs (stream + salvage); hr-ai stale after code change (404 on new endpoint = restart); the BGE-M3 model is 4.3 GB (pre-cache in a persistent volume); the queue worker must actually be running; `.gitignore_global` `_*` hid `__init__.py` from git for months; anonymous-HTTPS was load-bearing for deploy until deploy keys; Eloquent `toArray()` lets a relation clobber a same-named FK.
- **Split heavy sprints** (2b, 7, 7b, 7c had phases); one risk at a time; foundation first with a real deferral fallback.

## 6. Current state (2026-09-11)

**Staging:** `http://52.211.251.235` (plain HTTP — TLS is a go-live blocker). Accounts: `admin@hr-staging.internal` (super_admin), `agent@hr-staging.internal` (hr_agent), test employees (e.g. `test-navarra@example.com`), OTP via `otp.sh`. AWS eu-west-1, IAM user `cursor-dev` (PowerUserAccess; narrow inline IAM policy attached/detached only when needed). Cost ≈ $4.5/day on, `stop.sh`/`start.sh`, `resize-for-ingest.sh` for heavy embedding. Latest snapshot: `hr-staging-post-8`.

**Corpus on staging:** 106 documents, ~3,600 chunks, 14 salary tables / 179 rows / 94 categories (now with labelled monthlies), 88 reference facts (6 verified incl. the Navarra Hostelería trio + Álava/Andalucía COEAS G1; 82 `needs_review`), 5 approved group nodes (Hostelería Navarra) + 5 convenios with pending trees, 9 OCR'd documents (2 bound: doc 50→convenio 20, doc 85→convenio 9), 26 registry convenios, **9 full-gap convenios**, 14 employees (test).

**First real numbers (Analítica):** deflection 58% (11/19), top cluster "¿Cuánto dura el periodo de prueba…?" at 60% escalation (`reference_fact_coverage_gap` → assign employee groups), Estatuto not re-chunked (no evidence).

## 7. Open items / carried follow-ups (all recorded in deploy.md / roadmap.md)

- **TLS + a real domain** (go-live blocker; also unblocks Postmark and HTTPS-only browser APIs).
- **Go-live data pass** (HR, via the review queues — now ranked by Analítica): assign employee groups (directory picker / CSV `group` column `Grupo 2 > resto áreas`); verify the 82 facts; bind facts 41/79; approve the 5 pending group trees; confirm scope on `UNDER_REVIEW_SCOPE` docs (COEAS Estatal, Madrid, Huesca); **topic-tag the active convenios** (no doc has a topic tag → the structural fence blocks every ruling publish, the flywheel is dormant); source missing base texts (convenio 9's IV convenio; Cultura Navarra needs a real BOE code in the registry); re-supply salary xlsx where only PDFs exist.
- Widen the 7d fence anchors as the tagged corpus grows; revisit τ=0.80 with real traffic.
- Scrub before production: `DEV-FIXTURE-0001`, staging test traffic, the client codename in doc 14's title.
- 7e follow-ups: partial-scan selector (per-page), `SegmentReferenceSource` reads validity at job time (capture at dispatch).
- Salary-import follow-up: mis-filed category rows in convenios 15/18; "current year" logic shared with `SalaryAnswerService`.
- Two old escalation cards keep pre-7g engineer-vocabulary facts (immutable by design; backfill if wanted).
- Dedicated permanent deploy IAM user for production; consider a **separate AWS account** for the client's production.

## 8. What's next (recommended order)

1. **Sprint 9 — Privacy/GDPR hardening** (can gate deployment): encryption at rest for chat data, retention limits + erasure (incl. over `conversation_access_log`), the audit/reporting layer over the access log (who-viewed-whom), DPA/data-flow story for AEPD, **works-council (comité de empresa) sign-off prep**. Builds on ADR-0018. **Open the requirements conversation with the client first** — Sprint 9 should start from their actual requirements.
2. **Go-live data pass** (parallel; HR time, not engineering).
3. **Pre-go-live ops** (now small): domain + TLS + Postmark; production infra = re-run `hr-docs/infra/` with production sizes (multi-AZ RDS, bigger EC2 or ALB), a dedicated deploy user, monitoring/alerting, rate limits, a tested restore, one clean production ingest, scrub test data.
4. **Production** — a rehearsed repeat of the staging deploy.

## 9. How to start the new thread

Paste this file, then say which item from §8 you want. The assistant should: read the relevant hr-docs files (roadmap entry, the ADRs it depends on, deploy.md sections) before scoping; write spec + kickoff; and hold the loop in §4. For Sprint 9 specifically, read `data-model.md` (`conversation_access_log`, `employee_audit_log`, `chat_messages`, `message_traces`), ADR-0018, deploy.md's AEPD/DPA notes, and ask Pedram what the client's compliance contacts have said.
