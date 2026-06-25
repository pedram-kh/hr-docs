# Sprint 5 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread (the `hr-platform/` workspace). Inspect the substrate, plan, and **stop** — no directory/role/history code until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–4 are built and committed (answer engine, Knowledge Center, escalation board + flywheel). **This is Sprint 5 — user & directory management + roles + role-scoped history** — the access-control layer (pillar §4.2).

Before anything, read in full:
- `hr-docs/architecture/data-model.md` — `employees` (scope FKs, `status`, `profile_last_reviewed_at`), `employee_audit_log`, `admins` + the `spatie/laravel-permission` note, `login_codes` (`account_type`), the chat tables + the **"role-scoping-ready: every row keys to `employee_id`; access is a query filter, not a reshape"** note
- **ADR-0003** (email OTP, no SSO), **ADR-0004** (no HRIS/AD sync — directory first-class), **ADR-0005** (Sanctum ~24h), **ADR-0007** (hr-backend owns all writes)
- the **Sprint-3 `knowledge.edit`** and **Sprint-4 `escalation.work`** abilities + the `EnsureCan` middleware pattern + the env-seeding (`SEED_*_EMAIL`) — this sprint makes roles **UI-assignable** and adds a new gated ability
- the **Sprint-4 card-scoped conversation view** (keyed to `card.chat_session_id`) — it must stay unchanged; this sprint *adds* the gated full browser, it must not loosen the agent boundary
- `roadmap.md` Sprint 5 + Sprint 9 (to honour what's deferred to hardening), the privacy pillar (§4.2)
- `hr-docs/sprints/sprint-05/spec.md`
- the existing `hr-frontend` admin shell (Knowledge Map, the escalation board) + the design system

Your task this turn: **inspect the real substrate and plan — write no directory/role/history code.**

Produce `hr-docs/sprints/sprint-05/plan.md`, then **STOP and wait for review.** Cover:

1. **What already exists (reality check).** Confirm against the real schema/code: the `employees`/`admins`/`employee_audit_log`/`login_codes` tables and what's already populated; how `spatie/laravel-permission` is currently wired (which roles/abilities exist as real rows vs. only conceptual — are `knowledge.edit`/`escalation.work` seeded as permissions, and how are roles attached to admins today?); the OTP admin-login path; whether anything writes `employee_audit_log` yet; and the exact shape of the chat read endpoints (the Sprint-4 card-scoped one, and whether any "list an employee's sessions" endpoint exists). Show real columns/code. This determines how much is new vs. wiring.
2. **Employee directory.** The CRUD + search/filter endpoints + UI; the **CSV bulk-upload** design (per-row validation, transactional, a pass/fail report — reuse the registry-import discipline, no silent drops); the **FK-picker** scope editing (existing vocabulary only, no creation); the **`employee_audit_log` write on every change** (the dispute-defense record); the email-edit warning; `profile_last_reviewed_at` surfacing/setting.
3. **Admin & role management.** The admin CRUD + the UI to assign the four roles (`super_admin`/`hr_agent`/`knowledge_editor`/`auditor`); making `knowledge.edit`/`escalation.work` **UI-assignable** (keep env seeds as fallback); deactivation removing access.
4. **Role-scoped access — server enforcement (the load-bearing part).** Define the new **`history.view_all`** ability (granted `super_admin` + `auditor`), **distinct** from `escalation.work`. Specify the **server-side gate** on every full-history/conversation endpoint (`EnsureCan('history.view_all')`), the access matrix (super_admin: all; auditor: all read-only; hr_agent: card-scoped only; knowledge_editor: none), and how the card-scoped path stays keyed to `card.chat_session_id` (unchanged). State explicitly: **the server is the boundary; the UI only hides.**
5. **The History browser + search + the access log.** The list/search-all-conversations endpoints (filter by employee/scope/date/reason/answered-vs-escalated; the history-search moved from 2b); the read-only auditor variant; and the **additive `conversation_access_log`** (who viewed whose conversation, when) written on **every** access incl. `super_admin`. State the table shape.
6. **Migrations & build order.** Across `hr-backend` (directory CRUD/CSV, admin/role mgmt, the `history.view_all` permission, the history endpoints + enforcement, the access-log) and `hr-frontend` (directory UI, admin/role UI, the gated History browser + search). List every **additive migration** (the access log; any directory/CSV-support column; the new permission seed). Flag whether the access model warrants a new **ADR** (likely yes — privacy-load-bearing).
7. **Assumptions & open questions** — esp. anything the real spatie wiring / chat-endpoint shape leaves ambiguous, the CSV failure-handling shape, and whether `history.view_all` should be a spatie *permission* attached to roles (recommended) vs a role check.

Hard constraints:
- **Server-side enforcement, never UI-only.** Every history/conversation endpoint checks the ability/role on the server; hiding a button is not access control. The **`hr_agent` card-scoped boundary (Sprint 4) must not loosen** — this sprint only *adds* the gated full browser for `super_admin`/`auditor`.
- **`hr-backend` owns all writes + schema; additive migrations only.** Roles via `spatie/laravel-permission` (don't hand-roll). **Every** profile change → `employee_audit_log`; **every** History access → `conversation_access_log` (the accountability safeguard — this is what makes broad oversight defensible to AEPD / comité de empresa).
- **No HRIS/AD sync** (ADR-0004), **no SSO** (ADR-0003 — OTP stays), **no answer-loop change** (history is read-only), **no GDPR encryption/retention** (that's Sprint 9 — but the access log starts here).
- Reuse the **design system** + the existing admin shell + the `EnsureCan` pattern; FK pickers into existing vocabulary only (no vocabulary creation — that's `registry:import`, ADR-0011).

Do not create or modify any file other than `hr-docs/sprints/sprint-05/plan.md` this turn. After writing it, stop and say it is ready for review.
