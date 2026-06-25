# Sprint 5 — Plan (user & directory management + roles + role-scoped history)

> Location: `hr-docs/sprints/sprint-05/plan.md`
> Status: **plan for review — no directory/role/history code written this turn.**
> Read with: `sprint-05-spec.md`, `data-model.md` (§7 People/auth/roles, §8 Chat), ADR-0003/0004/0005/0007, `roadmap.md` (Sprint 5 + Sprint 9), `architecture.md` §8.1–8.2 (card-scoped view) + §11 (Privacy).
> This is the **access-control layer** (pillar §4.2). The load-bearing deliverable is **server-enforced role-scoped conversation access** + the **conversation access log** — both must be *true in code* and *testable*, not just intended.

---

## 1. What already exists (reality check)

I inspected the real schema (`hr-backend/database/migrations/*`), models, controllers, middleware, seeders, the OTP path, and the frontend shell. Summary: **the tables and the role substrate exist; almost nothing of this sprint's surface does.** It is mostly *new build on a ready foundation*, with two important *wiring gaps* (audit-log is never written; admin `status` is never enforced).

### 1.1 Tables — present, shapes confirmed

**`employees`** (`...131014_create_employees_table.php`, `province_id` later renamed → `territory_id`) — complete for CRUD. No CSV-support column is missing:

```11:26:hr-backend/database/migrations/2026_06_20_131014_create_employees_table.php
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('email')->unique();
            $table->string('full_name');
            $table->string('employee_external_id')->nullable();
            $table->foreignId('convenio_id')->constrained('convenios');
            $table->foreignId('job_category_id')->nullable()->constrained('convenio_job_categories')->nullOnDelete();
            $table->foreignId('province_id')->constrained('provinces');
            $table->string('work_location')->nullable(); // free text (data-model §12.4)
            $table->enum('employment_type', ['full_time', 'part_time']);
            $table->date('start_date')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('profile_last_reviewed_at')->nullable();
            $table->timestamps();
        });
```

- `convenio_id` and `territory_id` are **NOT NULL** (every employee must be scoped); `job_category_id` is nullable. `Employee` model has the matching `$fillable` + `profile_last_reviewed_at` datetime cast + auto-uuid + `convenio()/jobCategory()/territory()` relations.

**`employee_audit_log`** (`...131015`) — schema-only. `employee_id`, `field_changed`, `old_value`, `new_value`, `changed_by → admins`, `changed_at`. **There is no `EmployeeAuditLog` model and nothing writes this table** (grep across `app/` finds only the migration + docs). The "dispute-defense record" is **not yet real** — building the writer is core to this sprint.

**`admins`** (`...131006`) — `id, uuid, email (unique), full_name, status enum(active|inactive)`. `Admin` model uses `HasRoles` + `Authorizable` + `HasApiTokens`, `guard_name = 'web'`. **No admin CRUD endpoints exist.**

**`login_codes`** (`...131016`) — `account_type enum(employee|admin)`, `email` indexed, `code_hash`, `expires_at`, `consumed_at`, `attempts`. Admin OTP is **already covered** by `account_type` (ADR-0003 resolved-decisions #3) — **no schema change for admin login.**

**Chat tables** (`chat_sessions`, `chat_messages`, `message_traces`, `message_citations`, `escalation_cards`) — every row keys back to `employee_id` via `chat_sessions` (data-model §8 "role-scoping-ready: access is a query filter, not a reshape"). `chat_messages.role` includes `hr_agent` + nullable `author_admin_id` (Sprint 4). Confirmed: **history access is a query filter over existing tables — no reshape needed.**

### 1.2 spatie/laravel-permission — wired, with real rows

- `...130910_create_permission_tables.php` is the standard spatie migration (real `roles`, `permissions`, and pivots).
- `RoleSeeder` creates **4 real roles** (`super_admin`, `hr_agent`, `knowledge_editor`, `auditor`, guard `web`) and **2 real permissions**, granted as rows:
  - `knowledge.edit` → `super_admin`, `knowledge_editor`
  - `escalation.work` → `super_admin`, `hr_agent`

```32:43:hr-backend/database/seeders/RoleSeeder.php
        $roles['super_admin']->givePermissionTo($knowledgeEdit);
        $roles['knowledge_editor']->givePermissionTo($knowledgeEdit);
        ...
        $escalationWork = Permission::findOrCreate('escalation.work', 'web');
        $roles['super_admin']->givePermissionTo($escalationWork);
        $roles['hr_agent']->givePermissionTo($escalationWork);
```

- **How roles attach to admins today: only via env-seeded fixtures.** `TestUserSeeder` reads `SEED_ADMIN_EMAIL`/`SEED_EDITOR_EMAIL`/`SEED_AUDITOR_EMAIL`/`SEED_HR_AGENT_EMAIL` and calls `$admin->assignRole(...)`. **There is no UI and no API to create an admin or assign a role.** That is the wiring gap this sprint closes (env seeds become the *fallback*, not the only path).
- **Enforcement pattern (`EnsureCan`, aliased `ability`)** — gate a route on a spatie permission via `$user->can($ability)`; reads stay open, writes are gated:

```68:73:hr-backend/routes/api.php
    Route::middleware('ability:knowledge.edit')->group(function () {
        Route::patch('/documents/{uuid}/facets/{facet}', [DocumentController::class, 'reassignFacet']);
        ...
    });
```

  `escalation.work` gates the board writes the same way (`api.php` L91–95). `EnsureCan` returns a JSON **403** when the ability is absent. This is the exact pattern Sprint 5 reuses for `history.view_all`.
- **Abilities surfaced to the SPA**: `IdentityPresenter` returns `roles` + `abilities: { 'knowledge.edit', 'escalation.work' }`; the frontend gates affordances via `canEditKnowledge`/`canWorkEscalations` (`hr-frontend/src/lib/api.ts`). Sprint 5 extends both.

### 1.3 OTP admin-login path

`AuthController` is shared for employee + admin: `resolveAccount()` tries `Employee` then `Admin` by email; `verifyCode()` issues a Sanctum token via `createToken('otp-login')`; `EnsureAdmin` restricts `/admin/*` to `Admin` instances. Token TTL ~24h via Sanctum (ADR-0005). **Gap (load-bearing for this sprint): `status` is never checked** — neither `AuthController` (request/verify/findAccount) nor `EnsureAdmin` filters `status='inactive'`. So today **deactivating an admin does nothing** (they can still request a code, verify, and use existing tokens). Acceptance criterion B requires "deactivation removes access" → this sprint must add a status gate + token revocation (see §3).

### 1.4 Chat read endpoints — exact shape

- **`GET /chat/session`** (`ChatController@session`) — **employee-only, self-scoped**: resolves the caller's own most-recent session (`ChatSession::where('employee_id', $account->id)`), never a caller-supplied id; rejects admins. Returns `{ session_uuid, messages }` via `ConversationPresenter::present($session, AUDIENCE_EMPLOYEE)`.
- **`GET /admin/escalations/{uuid}`** (`EscalationController@show`) — the **Sprint-4 card-scoped view**. The conversation is keyed strictly to `card.chat_session_id`:

```102:104:hr-backend/app/Http/Controllers/Admin/EscalationController.php
        $conversation = $card->session !== null
            ? $this->presenter->present($card->session, ConversationPresenter::AUDIENCE_ADMIN)
            : [];
```

  Reads are **open to any admin** (no ability gate on `index`/`show`); writes are `escalation.work`-gated. **No caller-supplied employee/session param** — the keying *is* the access guard (architecture §8.1).
- **`ConversationPresenter`** serializes a session into the live-chat shape with two audiences (`employee` hides admin PII as "Recursos Humanos"; `admin` shows the authoring admin). **Reusable as-is by the History browser.**
- **There is NO "list an employee's sessions" endpoint, no cross-employee query, and no history search** (history-search was moved out of 2b to here per `roadmap.md` Sprint 2b-2). The entire full-History surface is **new**.

### 1.5 Reality-check verdict (new vs. wiring)

| Area | State | This sprint |
|---|---|---|
| `employees` table | exists, complete | **new** CRUD/search/CSV on top; **no column migration** |
| `employee_audit_log` table | exists, **never written** | **new** model + writer on every change |
| `admins` table | exists | **new** admin CRUD + role-assign UI/API |
| spatie roles + 2 permissions | **real rows, seeded** | **wire** to UI; **add** `history.view_all`; env → fallback |
| OTP admin login | works | **add** `status` enforcement + token revocation |
| card-scoped conversation read | exists (Sprint 4) | **unchanged keying**; tighten payload gate (see §4.4) |
| full-history browser / search / access log | **none** | **new** — the load-bearing build |

---

## 2. Employee directory (CRUD + search/filter + CSV + audit)

All endpoints under the existing `['auth:sanctum','admin']` `/admin` group, mirroring `DocumentController`. Writes gated by a new **`directory.manage`** ability (see §3 / open question Q1).

### 2.1 Endpoints (`EmployeeDirectoryController`)

| Method + path | Purpose |
|---|---|
| `GET /admin/employees` | list + search/filter, paginated (reuse the `Paginated<T>` shape). Filters: `q` (name/email ILIKE), `convenio_id`, `territory_id`, `sector_id` (via `whereHas('convenio', sector)`), `status`. Eager-load `convenio:id,numero,name`, `territory:id,code,name`, `jobCategory:id,name`. |
| `POST /admin/employees` | create one employee; FK-validated; writes a `created` audit baseline. |
| `GET /admin/employees/{uuid}` | detail + the `employee_audit_log` timeline (reuse the `.timeline` component). |
| `PATCH /admin/employees/{uuid}` | edit; field-level diff → one `employee_audit_log` row per changed field. Email change flagged (§2.5). |
| `POST /admin/employees/{uuid}/mark-reviewed` | set `profile_last_reviewed_at = now()` (audited as a `profile_last_reviewed_at` field event). |
| `POST /admin/employees/import/validate` | CSV **dry-run** → per-row pass/fail report, writes nothing. |
| `POST /admin/employees/import` | CSV **apply** → imports valid rows, returns the same report (no silent drop). |

Scope FK options come from the **existing** `GET /admin/vocabulary/{type}` (`territories`, `sectors`, `convenios` — `convenios` already carries `territory`/`sector`). **One new vocabulary type needed:** job categories **scoped to a convenio** — add `GET /admin/vocabulary/job-categories?convenio_id=` (or a dedicated route) returning that convenio's `convenio_job_categories`. `employment_type` is a fixed enum (`full_time|part_time`) — a static select, not vocabulary.

### 2.2 The audit writer (the dispute-defense record)

- New **`EmployeeAuditLog`** model (`employee_audit_log`, `$timestamps` on; `changed_at` set explicitly).
- New **`EmployeeAuditLogger`** service: given `(Employee $before-attributes, array $newValues, Admin $actor)`, diff the **tracked fields** (`email`, `full_name`, `employee_external_id`, `convenio_id`, `job_category_id`, `territory_id`, `work_location`, `employment_type`, `start_date`, `status`, `profile_last_reviewed_at`) and write **one row per changed field** with `old_value`/`new_value` stringified, `changed_by = actor->id`, `changed_at = now()`. FK ids are stored as their raw id string (the dispute record answers "what scope did this person have when the bot answered?"); the detail view resolves ids → labels for display.
- **Every** create/edit/CSV-applied-row/mark-reviewed runs through this writer, inside the same `DB::transaction` as the employee write (the row and its audit never diverge). On **create**, write a baseline `created` event (e.g. `field_changed = '*'`, `new_value = 'created'`) plus the initial scope fields — so a created employee has a provenance origin.

### 2.3 CSV bulk-upload design (reuse the registry-import discipline)

Reuse the **validate → report → don't guess** posture of `RegistryImport` (header parsed by name not letter; ambiguous values flagged, never silently defaulted; a run summary). Two-phase, multipart upload (`POST .../validate` then `.../import`), so the admin sees the report **before** committing:

- **Columns** (header-name mapped): `email` (required), `full_name` (required), `convenio_numero` (required, resolved against `convenios.numero`), `territory_code` (resolved against `territories.code`; defaults to the convenio's territory if blank — but **employee territory may differ from an Estatal convenio**, so an explicit value wins and is kept distinct), `job_category` (optional, resolved **within the convenio** by normalized name), `employment_type` (`full_time|part_time`), `work_location` (free text), `employee_external_id`, `start_date`.
- **Per-row validation** (no silent drops): email present + RFC-valid + (for create) unique / (for update-by-email) resolvable; `convenio_numero` resolvable; `territory_code` resolvable; `job_category` resolvable **within that convenio**; `employment_type` in the enum. Each failing check yields a **human-readable reason** on that row.
- **Report shape**: `{ summary: { total, valid, invalid, created, updated }, rows: [{ row_number, email, action: 'create'|'update'|'skip', status: 'pass'|'fail', errors: [..] }] }`. A bad row is **reported as `fail`, never dropped silently** (the eyes-on: a deliberately bad row appears in the report).
- **Transactionality** (recommended; see Q4): the **validate** phase produces the full report and writes nothing. The **apply** phase imports the **valid** rows, each row wrapped in its own DB transaction together with its `employee_audit_log` write(s), and returns the report with `created`/`updated` filled. Failures are reported, not applied. (Strict whole-file-atomic abort is the alternative — flagged as an open question.)
- **Audit on import**: every created/updated row writes `employee_audit_log` via the same `EmployeeAuditLogger`, `changed_by = the importing admin`.

### 2.4 FK-picker scope editing (existing vocabulary only)

The edit drawer uses **dropdowns into existing vocabulary** (territories, convenios, convenio-scoped job categories), exactly like the Sprint-3 bounded edit — **no free-text vocabulary creation** (vocabulary growth stays in `registry:import`, ADR-0011). `employment_type` is a fixed enum select. `work_location` stays free text (data-model §12.4). Picking a convenio reloads the job-category options for that convenio.

### 2.5 Email-edit warning + staleness

- **Email edit** changes how the person logs in (ADR-0003). The UI shows a confirmation warning before save ("Changing the email changes how this person logs in…"). Server-side: the email change is always written to `employee_audit_log`; optionally mirror the Sprint-3/4 confirm pattern (a `confirm_email_change` flag → 409 if absent) so the change can't happen by accident. (Recommended: lightweight — UI confirm + mandatory audit; the 409 confirm is optional, flagged Q6-adjacent.)
- **`profile_last_reviewed_at`** surfaced as a **staleness badge** (e.g. "Reviewed 8 months ago" / "Never reviewed" using the design-system `.badge` neutral/warning variants), and **settable** via the `mark-reviewed` action. Recommendation: editing a profile does **not** auto-bump it — "reviewed" is an explicit human attestation distinct from "edited" (flagged Q9).

---

## 3. Admin & role management (UI)

Privileged surface — gated by a new **`admin.manage`** ability (super_admin only; see Q2). Under `/admin`.

### 3.1 Endpoints (`AdminController`)

| Method + path | Purpose |
|---|---|
| `GET /admin/admins` | list admins with `roles` + `status`. |
| `POST /admin/admins` | create (uuid auto); optional initial roles. |
| `PATCH /admin/admins/{uuid}` | edit `full_name`, `status`. |
| `PUT /admin/admins/{uuid}/roles` | **set/sync** roles via spatie `syncRoles([...])` (subset of the four). |

- **Assigning the four roles through the UI** is the core: `super_admin`, `hr_agent`, `knowledge_editor`, `auditor`. Because `knowledge.edit`/`escalation.work`/`history.view_all` are **permissions attached to roles** (not per-admin grants), assigning a role *is* assigning its abilities — the two existing abilities become **UI-assignable** for free, with the env seeds (`TestUserSeeder`) kept as the **bootstrap fallback** (unchanged, still re-runnable).
- **Deactivation removes access (the gap from §1.3):**
  1. `EnsureAdmin` (or a small `EnsureActiveAccount` middleware on the auth group) rejects a request whose `user()->status !== 'active'` with 403.
  2. `AuthController` refuses to issue a code/token to an inactive account (`findAccount`/`resolveAccount` filter `status='active'`).
  3. On `PATCH status=inactive`, **revoke the admin's tokens** (`$admin->tokens()->delete()`) so an existing ~24h session dies immediately. (Same posture should apply to an inactive **employee** at chat login — flagged Q8, likely in-scope since the directory now sets employee status.)

---

## 4. Role-scoped conversation access — server enforcement (load-bearing)

**The server is the boundary; the UI only hides.** Every full-history/conversation endpoint checks the ability on the server (403 otherwise), verified by API tests — not by hidden buttons.

### 4.1 The new ability: `history.view_all`

- Add a **spatie permission `history.view_all`** (guard `web`) in `RoleSeeder`, granted to **`super_admin` + `auditor` only** — **distinct from `escalation.work`** (which gives `hr_agent` their card-scoped access). An `hr_agent` therefore **cannot** reach any full-history endpoint (they lack `history.view_all`); they keep **only** the conversations on cards they work.
- It is a **permission attached to roles**, not a role-name check (recommended, Q3) — consistent with the existing `EnsureCan` pattern (`$user->can('history.view_all')`), so it composes with role edits and shows up in `IdentityPresenter.abilities`.

### 4.2 The access matrix (enforced)

| Role | Full History browser/search | Card-scoped conversation | Mechanism |
|---|---|---|---|
| **super_admin** | ✅ all (read + may pivot to card actions) | ✅ | `history.view_all` + `escalation.work` |
| **auditor** | ✅ all, **read-only** | ✅ (browse only) | `history.view_all`; **no** `escalation.work` → no reply/resolve |
| **hr_agent** | ❌ 403 | ✅ **only** their cards | `escalation.work`; **no** `history.view_all` |
| **knowledge_editor** | ❌ 403 | ❌ none | neither ability |

"auditor read-only" needs **no separate gate**: the History endpoints are all reads; any action (reply/resolve/convert) routes through the **existing** `escalation.work`-gated escalation endpoints, which auditor already lacks. So `history.view_all` gates *seeing*, `escalation.work` gates *acting* — clean separation, no new write gate in History.

### 4.3 The gate placement (every endpoint)

All new History endpoints sit in a `Route::middleware('ability:history.view_all')` group (the `EnsureCan` alias, unchanged). Concretely:

```
Route::middleware(['auth:sanctum','admin'])->prefix('admin')->group(function () {
    Route::middleware('ability:history.view_all')->group(function () {
        Route::get('/history/conversations', [HistoryController::class, 'index']);
        Route::get('/history/conversations/{sessionUuid}', [HistoryController::class, 'show']);
        Route::get('/history/employees/{employeeUuid}', [HistoryController::class, 'employee']);
        Route::get('/history/search', [HistoryController::class, 'search']);
    });
});
```

### 4.4 The card-scoped path stays keyed to `card.chat_session_id` (unchanged) — with one tightening

- The Sprint-4 `EscalationController@show` conversation keying is **not loosened**: still `card.session`, still no caller-supplied employee/session param. `hr_agent` access is **untouched**.
- **One refinement (recommended, Q5):** today the card-detail **read** is open to *any* admin, so a `knowledge_editor` could currently see a card's conversation — which contradicts the matrix ("knowledge_editor: none") and architecture §11 ("knowledge_editor — no chat access"). Gate the **conversation payload** of card detail behind *"can see conversations" = `escalation.work` OR `history.view_all`* (super_admin/hr_agent/auditor see it; knowledge_editor does not). This **tightens** knowledge_editor without loosening hr_agent. The card meta/board listing can stay broadly readable; only the conversation messages are gated. (If we'd rather not touch Sprint-4 reads at all, the alternative is to accept knowledge_editor can browse card conversations — but that breaks the stated matrix. Flagged for the reviewer.)

---

## 5. The History browser + search + the access log

### 5.1 Endpoints (`HistoryController`, all `history.view_all`-gated)

- **`GET /admin/history/conversations`** — list/search **all** sessions across employees, paginated. Filters: `employee_uuid`, `convenio_id`, `territory_id`, date range (`from`/`to` over `started_at`/`last_activity_at`), `reason` (sessions with an `escalation_cards.reason`), `outcome` = answered vs escalated (derived: a session is "escalated" if it has any `escalation_cards` row; "answered" otherwise). Each row: employee (name/uuid/convenio), session uuid, started/last-activity, message count, escalated flag + reason. This is a **query over existing tables** (no reshape).
- **`GET /admin/history/employees/{employeeUuid}`** — that employee's sessions (the "list an employee's sessions" endpoint that doesn't exist today).
- **`GET /admin/history/conversations/{sessionUuid}`** — the full conversation via `ConversationPresenter::present($session, AUDIENCE_ADMIN)` (reuses citations + trace). **Writes a `conversation_access_log` row** (see §5.2).
- **`GET /admin/history/search`** — full-text-ish search across `chat_messages.content` (ILIKE / `to_tsvector` later), scoped by the same filters; returns matching sessions + a snippet. (The history-search UI moved from 2b lands here.)

### 5.2 The conversation access log (the accountability safeguard)

Append-only; **every** access to a conversation through History writes a row — **including `super_admin`'s reads**. This is the data that makes broad oversight defensible to AEPD / comité de empresa (and the data Sprint 9 hardens/audits).

**`conversation_access_log`** (new additive table):

| column | type | notes |
|---|---|---|
| id | bigint PK | |
| admin_id | bigint FK → admins | **who viewed** (the actor; logged even for super_admin) |
| employee_id | bigint FK → employees | **whose conversation** |
| chat_session_id | bigint FK → chat_sessions NULL | the session viewed (null for a search-listing event) |
| access_type | varchar | `conversation_view` \| `history_search` (extensible) |
| context | varchar NULL | route/surface marker, e.g. `history` |
| created_at | timestamp | append-only — **no `updated_at`** (mirrors `escalation_events`/`tag_events`) |

- **Written on**: opening a conversation via `GET /admin/history/conversations/{sessionUuid}` and via `GET /admin/history/employees/{employeeUuid}` (one `conversation_view` per opened session). Recommendation: a content **search** writes a lighter `history_search` event (the query was run), and opening a result writes the `conversation_view` — so the "who saw whose conversation" record is precise (Q7).
- **Not** retro-applied to the Sprint-4 card-scoped path (that path already audits to `escalation_events`, and is the agent's working surface, not broad oversight). If the reviewer wants card-scoped conversation reads logged here too, it's a one-line addition — flagged.
- New **`ConversationAccessLog`** model; the write is a single `create()` inside the controller action (cheap, append-only).

### 5.3 Frontend — the History section

- **AdminShell**: add a **History** nav item, rendered **only when** `identity.abilities['history.view_all']` is true (UI hides; server still enforces). Add a **Directory** and **Admins** nav gated on `directory.manage` / `admin.manage`.
- **HistoryPage**: a conversation list with the filter bar (employee/convenio/territory/date/reason/answered-vs-escalated) + a search box; opening a conversation reuses the existing `ConversationMessage` components (`TracePanel`, `CitationList` from the chat screen). **Read-only** for auditor (no reply/resolve affordances — those need `escalation.work`); **super_admin** may pivot to the escalation card where the session has one.
- Reuse the design system (`.docs-table`, `.badge`, `.panel`, `.timeline`, `.field`, `.btn-*`) — no new visual primitives.

---

## 6. Migrations & build order

### 6.1 Additive migrations / seeds (the complete list)

1. **`create_conversation_access_log_table`** — the new append-only table (§5.2). **The only genuinely new table.**
2. **`seed_history_view_all_permission`** (data migration) — idempotent `Permission::findOrCreate('history.view_all','web')` + grant to `super_admin` + `auditor`, so existing/prod DBs pick it up via `migrate` (not only a fresh reseed). `RoleSeeder` is updated in lockstep so fresh installs get it too. *(Rationale: spatie tables already exist; the permission is data, not schema — a tiny data migration is the additive, prod-safe way to introduce it.)*
3. **No `employees`/`admins` column migration is required** — the directory and CSV need no new columns (confirmed §1.1). The only *possible* additive index is supporting indexes for history filtering (`chat_sessions.employee_id` is already FK-indexed; add btree on `chat_messages.content` search or date columns **only if** profiling shows need — additive, optional).

> Hard-constraint check: **additive only**, no destructive changes; `hr-backend` owns all writes + schema; roles via spatie (no hand-rolled tables); no encryption/retention columns (Sprint 9).

### 6.2 Build order

**hr-backend (writes + enforcement first):**
1. `EmployeeAuditLog` model + `EmployeeAuditLogger` service (the writer the schema has been missing).
2. `EmployeeDirectoryController` — CRUD + search/filter + `mark-reviewed`; FK validation; audit on every change. Add `vocabulary/job-categories?convenio_id=`.
3. CSV `import/validate` + `import` (two-phase, per-row report, transactional, audited).
4. `AdminController` — admin CRUD + `syncRoles`; **status enforcement** (login refusal + `EnsureActiveAccount` + token revocation on deactivate).
5. `history.view_all` permission (migration + `RoleSeeder`); extend `IdentityPresenter.abilities` (`history.view_all`, `directory.manage`, `admin.manage`).
6. `conversation_access_log` migration + model.
7. `HistoryController` — list/search/employee/conversation endpoints, all `ability:history.view_all`-gated; access-log write on conversation view; the card-detail payload tightening (§4.4).
8. New abilities + routes (`directory.manage`, `admin.manage`, `history.view_all`) registered; routes added to `api.php`.
9. **API tests for the matrix** (the acceptance proof): hr_agent → 403 on history endpoints + 200 only on their card; knowledge_editor → 403 on all conversation reads; auditor/super_admin → 200 on history + a `conversation_access_log` row written; deactivated admin → 403/401.

**hr-frontend (UI hides, after the server enforces):**
10. `api.ts` — new types/functions (employees, admins, history, CSV) + `canViewAllHistory`/`canManageDirectory`/`canManageAdmins` helpers.
11. `DirectoryPage` (list/search/filter, create/edit drawer with FK pickers, email-edit warning, staleness badge + mark-reviewed, audit timeline) + `CsvImportPanel` (upload → dry-run report → apply).
12. `AdminsPage` (list, create/edit, role checkboxes, deactivate).
13. `HistoryPage` (conversation list + filters + search, read-only auditor, super_admin pivot).
14. `AdminShell` nav gated on the new abilities.

### 6.3 ADR — recommended: **yes**

Add **ADR-0018 — Role-scoped conversation access (`history.view_all`) + conversation access logging.** It is **privacy-load-bearing** (AEPD / comité de empresa): it records the decision that (1) broad conversation access is a **deliberately-granted ability held by a designated few**, not automatic for every agent, and (2) **every access is logged, including super_admin's**, with the server as the boundary. This is exactly the kind of decision the ADR series exists to capture (it sits beside ADR-0003/0004 on the identity/privacy axis), and Sprint 9 will reference it when hardening.

---

## 7. Assumptions & open questions

1. **Who manages the directory?** The spec names no ability for directory CRUD. *Recommendation:* a new `directory.manage` permission → **`super_admin`** (and optionally **`hr_agent`** for day-to-day "employee transferred province" corrections, which ADR-0004 cites as the day-to-day path). Reads of PII-bearing directory data gated to the same. **Confirm the grantee set.**
2. **Who manages admins/roles?** *Recommendation:* `admin.manage` → **`super_admin` only** (creating admins and granting `history.view_all` is the most privileged action in the system). **Confirm.**
3. **`history.view_all` as a spatie permission vs. a role check?** *Recommendation:* **spatie permission attached to roles** (composes with the existing `EnsureCan`/`can()` pattern, surfaces in `abilities`, survives role edits). Confirming this is the intended model.
4. **CSV transactional shape.** *Recommendation:* two-phase **validate → report → apply**; apply imports **valid** rows (each in its own transaction with its audit write) and reports failures — **no silent drop**. Alternative: **strict whole-file atomic** (any invalid row aborts the entire import). **Which failure posture for 1,500-row bootstraps?** (Default chosen: import-valid + report.)
5. **Card-scoped conversation read for `knowledge_editor`.** The matrix says knowledge_editor = none, but the Sprint-4 card-detail read is currently open to any admin. *Recommendation:* gate the **conversation payload** of card detail behind `escalation.work OR history.view_all` (tightens knowledge_editor, **does not loosen hr_agent**). **Confirm we may tighten the Sprint-4 read this much.**
6. **Email-edit confirmation depth.** UI warning + mandatory audit is required. *Open:* do we also want a server `confirm_email_change` 409 gate (Sprint-3/4 style) to prevent accidental change, or is the UI warning sufficient?
7. **Access-log granularity.** Log one row per **opened conversation** (`conversation_view`); recommendation also logs a lighter `history_search` event. *Confirm* whether search-listing alone (snippets shown without opening) should count as "access" needing a per-employee row.
8. **Inactive-employee login.** The directory now sets employee `status`; today employee OTP login also ignores `status`. *Recommendation:* apply the same active-status refusal to employees (an inactive employee can't chat). **In scope, or defer to Sprint 9?**
9. **Does editing a profile bump `profile_last_reviewed_at`?** *Recommendation:* **no** — "reviewed" is an explicit attestation via `mark-reviewed`, distinct from "edited", so the staleness signal stays meaningful. **Confirm.**
10. **History date semantics & volume.** Filtering "by date" — over `started_at` or `last_activity_at`? *Assumption:* `last_activity_at` (most recent activity), with `started_at` available. With ~1,500 employees this stays comfortably query-filterable; no denormalization needed (data-model §8 note holds).

---

**This plan is ready for review.** No directory/role/history code, schema, or other files were written this turn — only `hr-docs/sprints/sprint-05/plan.md`. Awaiting review before any build.
