# Sprint 5 — User & directory management + roles + role-scoped history

> Location: `hr-docs/sprints/sprint-05/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram
> Read first: `data-model.md` — `employees` (the scope FKs: `convenio_id`/`job_category_id`/`territory_id`/`employment_type`, `status`, `profile_last_reviewed_at`), `employee_audit_log`, `admins` + the `spatie/laravel-permission` note, `login_codes` (OTP, `account_type`), the chat tables + the **"role-scoping-ready: every row keys to `employee_id`, access is a query filter not a reshape"** note; **ADR-0003** (email OTP), **ADR-0004** (no HRIS/AD sync — directory is first-class), **ADR-0005** (Sanctum ~24h), **ADR-0007** (hr-backend owns all writes); the Sprint-3 `knowledge.edit` + Sprint-4 `escalation.work` ability/`EnsureCan` patterns; `roadmap.md` Sprint 5; the privacy pillar (§4.2) and Sprint 9 (GDPR hardening — what is *deferred* there).
> **The access-control layer.** Much is laid down (the tables exist; chat data is role-scoping-ready by design; two abilities already seeded and enforced). This sprint **surfaces and enforces** it: manage people, wire the four roles through the UI, and — the load-bearing part — **enforce who can read whose conversations**, server-side.

## Goal
Make the platform a managed system of people and permissions: a first-class **employee directory** (CRUD + CSV bootstrap, every change audited), **admin/role management** through the UI (replacing env-seeded test users), **server-enforced role-scoped conversation access**, and the **full History browser + search** — gated to the oversight roles, with **every conversation access logged**.

## In scope

### A. Employee directory (CRUD + CSV + audit)
- Manual **create / edit / search / filter** employees; **CSV bulk upload** to bootstrap ~1,500 (ADR-0004 — no HRIS/AD sync). Search/filter by name/email/convenio/territory/sector/status.
- Scope fields (`convenio_id`, `job_category_id`, `territory_id`, `employment_type`) are set via **FK pickers into existing vocabulary** (same discipline as the Sprint-3 bounded edit — no free-text vocabulary creation). `territory_id` is the employee's **own** location (may differ from an Estatal convenio — keep that distinction).
- **Every profile change writes `employee_audit_log`** (`field_changed`, `old`, `new`, `changed_by`, `changed_at`) — the dispute-defense record ("what scope did this person have when the bot answered?"). Editing **email** (= how they log in) is **flagged in the UI** before save.
- **CSV upload** validates each row (email present/unique, convenio resolvable) and reports per-row pass/fail; it does **not** silently drop bad rows. Audit entries written for created/updated rows.
- `profile_last_reviewed_at` surfaced as a **staleness signal** (and settable on review), since there's no sync to keep profiles fresh.

### B. Admin & role management (UI)
- Manage **admin accounts** (create/edit/deactivate; OTP auth, same flow — `login_codes.account_type='admin'`, no schema change) and **assign the four roles** through the UI: `super_admin`, `hr_agent`, `knowledge_editor` (docs only, **no** chat access), `auditor` (read-only). Via `spatie/laravel-permission` (don't hand-roll).
- Roles map to abilities; the two existing abilities (`knowledge.edit`, `escalation.work`) are now **assignable through the UI** rather than env-seeded. The env seeds remain as a bootstrap fallback.
- Deactivating an admin (`status='inactive'`) immediately removes access (token/ability check honors status).

### C. Role-scoped conversation access — **enforced, server-side** (the load-bearing part)
The access model (decided with Pedram), enforced in `hr-backend` on **every** conversation read — not hidden UI, real authorization:

| Role | Conversation access |
|---|---|
| **super_admin** | Full History — all employees, all conversations |
| **auditor** | Full History — all employees, **read-only** |
| **hr_agent** | **Only** conversations on the **cards they work** (the Sprint-4 card-scoped view) — resolve + reply; **no** full browser |
| **knowledge_editor** | **None** |

- A new ability **`history.view_all`** gates the full History browser, granted to `super_admin` + `auditor` only — **distinct** from `escalation.work` (which gives `hr_agent` their card-scoped access). An `hr_agent` without `history.view_all` **cannot** reach any employee's full history, only the conversations attached to cards they work.
- Enforcement is an **`EnsureCan`-style server gate** on the history endpoints (mirroring Sprint 3/4); the card-scoped path stays keyed strictly to `card.chat_session_id` (Sprint-4 guard, unchanged). The frontend hides what the server forbids, **but the server is the boundary**.

### D. The full History browser + search (gated)
- A **History** section: browse **all employees' conversations** (sessions → messages → traces/citations), search across them (the history-search UI moved from 2b), filter by employee/scope/date/reason/answered-vs-escalated. Gated by `history.view_all` (so: `super_admin` read-write-context, `auditor` read-only).
- **Every access to a conversation through History is logged** — who viewed whose conversation, when — an append-only **conversation-access log** (an additive table, e.g. `conversation_access_log`). This is the safeguard that makes broad oversight **accountable** rather than invisible (and the data Sprint 9 hardens/audits). Even a `super_admin`'s reads are logged.
- Read-only for `auditor` (no reply/resolve affordances); `super_admin` may pivot to the card/escalation actions where applicable.

## Out of scope (do NOT build)
- **GDPR hardening → Sprint 9:** encryption-at-rest for chat data, retention/erasure policy, the *audit/reporting* over the access log, AEPD/comité-de-empresa sign-off prep. Sprint 5 **builds + enforces** role-scoped access and **starts** the access log; Sprint 9 **hardens** it.
- **Guardrails config** (Sprint 6), **LLM tagging / structured knowledge** (Sprint 7), **analytics** (Sprint 8 — though the access log + directory feed it).
- **No HRIS/AD sync** (ADR-0004) — manual + CSV only. No SSO (ADR-0003 — OTP stays).
- **No answer-loop change.** Reading history is read-only over existing data.

## Acceptance criteria
1. Employee directory: create/edit/search/filter + CSV bulk upload (per-row validation report) all work; **every** profile change writes `employee_audit_log`; email-edit is flagged; `profile_last_reviewed_at` surfaced/settable.
2. Admin/role management UI: create/edit/deactivate admins, assign the four roles; the two existing abilities are UI-assignable; deactivation removes access.
3. **Role-scoped access is enforced server-side**: an `hr_agent` (without `history.view_all`) is **denied** any full-history endpoint (403) and can reach **only** card-scoped conversations; `knowledge_editor` is denied all conversation reads; `auditor`/`super_admin` reach the full browser; **verified by API tests, not just hidden UI**.
4. The History browser lists/searches all conversations for the gated roles; **every** conversation access writes the `conversation_access_log` (who/whom/when), including for `super_admin`.
5. All writes `hr-backend`; the access model is real authorization (the frontend hides, the server enforces). Additive migrations only (the access log; any directory/CSV support) — state them in the plan.
6. Nothing out-of-scope: no encryption/retention (Sprint 9), no HRIS sync, no answer-loop change.

## Eyes-on
Seed/import a few employees via CSV (incl. a deliberately bad row → see it reported, not silently dropped). Edit an employee's convenio → confirm the `employee_audit_log` entry; edit an email → see the login warning. Create an admin of each role. **Then the access matrix, live:** as `hr_agent`, confirm you can open a card's conversation but the **History section is absent/forbidden** (and the endpoint 403s if hit directly); as `auditor`, browse + search **all** history **read-only**; as `super_admin`, full access. Open an employee's history as auditor → confirm a `conversation_access_log` row was written. As `knowledge_editor`, confirm **no** conversation access anywhere.

## Risks / notes
- **This sprint has real legal weight (AEPD / comité de empresa).** Conversations contain special-category data (health, family, harassment). The defensibility rests on two things being *true in code*, not just intended: (1) broad access is **a deliberately-granted ability** (`history.view_all`), held by a designated few, **not** automatic for every agent; (2) **every access is logged**. The plan must make both server-enforced and testable.
- **Enforce server-side, never UI-only.** The classic failure is hiding a button while the endpoint stays open. Every history/conversation endpoint must check the ability/role server-side; the plan states where each gate sits.
- **CSV is the bootstrap risk** — 1,500 rows, partial failures, scope-resolution errors. It must be **transactional per row + reported**, never half-import silently. Reuse the registry-import discipline (validate, report, don't guess).
- **`hr_agent` card-scoped access is unchanged** from Sprint 4 (keyed to `card.chat_session_id`) — this sprint *adds* the gated full browser for the oversight roles; it must not loosen the agent boundary.

## Definition of done
All criteria pass; Pedram eyes-on; docs updated — `architecture.md` (the directory/CRUD/CSV, the role→ability map incl. `history.view_all`, the **enforced** role-scoped access matrix, the conversation-access-log safeguard, the UI-hides/server-enforces rule), `data-model.md` (the `conversation_access_log` table + any directory/CSV-support fields; the ability assignments), `roadmap.md` (Sprint 5 done; reaffirm what Sprint 9 hardens — encryption/retention/access-audit-reporting), an **ADR** if the access model/`history.view_all` warrants one (likely yes — it's a privacy-load-bearing decision), the relevant READMEs. Cursor writes `hr-docs/sprints/sprint-05/review.md` and **stops — no commit until I review**.
