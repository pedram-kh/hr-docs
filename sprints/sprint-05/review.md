# Sprint 5 — Review (build + acceptance proof)

**User & directory management + roles + role-scoped history — the access-control layer (pillar §4.2).**
Built per the approved plan's §6.2 order, all open questions applied as resolved. Status: **built; pending eyes-on + commit** (not committed — awaiting your review).

---

## 1. What was built

### hr-backend (enforcement first)

1. **`EmployeeAuditLog` model + `EmployeeAuditLogger` service** — the writer the schema was missing since Sprint 0. One row per changed field, written inside the **same transaction** as the employee write. `recordCreated` writes a `*`=`created` marker + a row per initial non-null field; `recordChanges` diffs a pre-edit snapshot.
2. **`EmployeeDirectoryController`** — CRUD + search/filter + `mark-reviewed`; FK validation (job category must belong to the chosen convenio); audit on every change; the convenio-scoped `GET /admin/job-categories?convenio_id=`; the **email-change 409** gate (`confirm_email_change`). Behind `directory.manage` (reads included).
3. **`EmployeeCsvImporter`** — two-phase **validate → report → apply**. Header-by-name parsing, per-row pass/fail, valid rows applied **each in its own transaction with its audit** (not whole-file-atomic). Bad rows reported, never dropped. Resolves convenio/territory/category into existing vocabulary only.
4. **`AdminController`** — CRUD + `syncRoles` (the four roles via spatie) + status enforcement: **`EnsureActiveAccount`** middleware (alias `active`) on `/me`, `/chat/*`, `/admin/*`; **token revocation** on deactivate; the **active-status refusal applied to both OTP paths** (admin *and* employee) in `AuthController`.
5. **`history.view_all` / `directory.manage` / `admin.manage`** — spatie permissions attached to roles via `RoleSeeder` **and** the idempotent data migration (lockstep). Surfaced in `IdentityPresenter.abilities`.
6. **`ConversationAccessLog` model + `HistoryController`** — list / search / per-employee / open-conversation, all behind `history.view_all`. **Every access writes `conversation_access_log`** (`conversation_view` on open, `history_search` on a search run). The **§4.4 card-payload tightening**: `EscalationController@show` now withholds the conversation messages unless the caller holds `escalation.work` OR `history.view_all` (`conversation_restricted` flag).
7. **Routes** — all wired in `routes/api.php` under the ability gates.
8. **API-matrix tests** — `tests/Feature/Sprint5AccessMatrixTest.php` (§3 below).

### Additive migrations only

- `2026_06_25_100001_create_conversation_access_log_table` — the one new table (append-only).
- `2026_06_25_100002_seed_sprint5_permissions` — idempotent data migration for the three abilities (lockstep with `RoleSeeder`).
- **No `employees`/`admins` column changes** — confirmed; the schema already had every field.

### hr-frontend (UI hides; the server enforces)

- `api.ts` — types/fns for directory, admins, history + `canViewAllHistory` / `canManageDirectory` / `canManageAdmins`; `conversation_restricted` on `EscalationDetail`.
- `DirectoryPage.tsx` + `CsvImportPanel.tsx` — list/search/filter, the FK-picker edit drawer, the email-edit warning + 409 confirm modal, the staleness badge + mark-reviewed, the audit timeline, and the two-phase CSV upload→report→apply.
- `AdminsPage.tsx` — list/create/edit, role checkboxes, deactivate/reactivate.
- `HistoryPage.tsx` — conversation list + filters + content search, the read-only conversation drawer (reuses `CitationList`/`TracePanel`).
- `AdminShell.tsx` — **Directory / History / Admins** nav, each gated on its ability.
- `EscalationCardDrawer.tsx` — shows the "no permission" notice when `conversation_restricted`.

No new visual primitives.

### Docs

ADR-0018 (the access model), `architecture.md` (§9 directory/status, §11 the access matrix + log + tightening), `data-model.md` (`conversation_access_log`, the now-written `employee_audit_log`, the ability map, the email-change confirm), `roadmap.md` (Sprint 5 done; Sprint 9 reaffirmed), and both READMEs.

---

## 2. The hard constraints, honoured

- **Server-side enforcement, never UI-only** — every history/conversation/directory/admin endpoint checks its ability on the server; proven by tests that hit endpoints **directly** (§3).
- **The `hr_agent` card-scoped boundary (Sprint 4) is unchanged** — still keyed to `card.chat_session_id`; only the `knowledge_editor` read is tightened (proven both directions).
- **Additive migrations only**; roles via spatie (no hand-rolled tables); FK pickers into existing vocabulary only.
- **Every directory change → `employee_audit_log`** (one row per field, same transaction; CSV rows too).
- **Every History access → `conversation_access_log`** — including super_admin.
- No HRIS/AD sync, no SSO, no answer-loop change, no GDPR encryption/retention (Sprint 9) — but the access log + status enforcement start here.

---

## 3. Acceptance proof — the API matrix (server is the boundary)

Run against a dedicated Postgres test DB (`hr_platform_test`; the schema is Postgres-specific — pgvector/enums), configured in `phpunit.xml`.

```
php artisan test --filter=Sprint5AccessMatrixTest --testdox

Sprint5Access Matrix (Tests\Feature\Sprint5AccessMatrix)
 ✔ Hr agent is denied every history endpoint 403
 ✔ Knowledge editor is denied all conversation reads including card payload
 ✔ Hr agent card scoped conversation still works unchanged
 ✔ Auditor reaches history read only and access is logged
 ✔ Super admin full access and even own read is logged
 ✔ Search logs a history search event
 ✔ Directory reads gated to directory manage
 ✔ Admin management is super admin only
 ✔ Deactivated admin is refused
 ✔ Inactive employee cannot chat
 ✔ Editing convenio writes employee audit log
 ✔ Email change requires confirm 409
 ✔ Csv import reports bad row and applies valid rows
 ✔ Role assignment grants history view all

OK (14 tests, 48 assertions)
```

Mapped to the non-negotiable acceptance criteria:

| Required proof | Test | Result |
|---|---|---|
| hr_agent → 403 on every `history.view_all` endpoint | `hr_agent_is_denied_every_history_endpoint_403` | ✅ 403 on list/search/conversation/employee |
| hr_agent → 200 only on its own card's conversation | `hr_agent_card_scoped_conversation_still_works_unchanged` | ✅ 200, `conversation_restricted=false` |
| knowledge_editor → 403 on all conversation reads (incl. card payload) | `knowledge_editor_is_denied_all_conversation_reads_including_card_payload` | ✅ history 403; card meta 200 but `conversation:[]`, `conversation_restricted=true` |
| auditor + super_admin → 200 on history, `conversation_access_log` row written | `auditor_reaches_history_read_only_and_access_is_logged`, `super_admin_full_access_and_even_own_read_is_logged` | ✅ 200 + a `conversation_view` row (incl. super_admin's own read) |
| search writes one `history_search` row | `search_logs_a_history_search_event` | ✅ |
| deactivated admin → 401/403 (login refused + active gate + token revoked) | `deactivated_admin_is_refused` | ✅ access refused after deactivate+revoke |
| deactivated employee can't chat | `inactive_employee_cannot_chat` | ✅ 403 on `/chat/session` |
| every directory change → `employee_audit_log` (per field, same txn) | `editing_convenio_writes_employee_audit_log`, `email_change_requires_confirm_409` | ✅ per-field rows; email 409 then audited on confirm |
| CSV bad row reported, not dropped; valid rows applied + audited | `csv_import_reports_bad_row_and_applies_valid_rows` | ✅ |
| role assignment grants `history.view_all` (survives role edits) | `role_assignment_grants_history_view_all` | ✅ knowledge_editor 403 → promoted to auditor → 200 |

> Full suite: `php artisan test` → **16 passed** (the 14 above + 2 framework example tests).

> Test-harness note: the test client reuses one app instance across sub-requests, so the auth guard caches the first resolved user and the spatie registrar caches the first permission load — a harness artifact, not a production bug (production is a fresh app + guard per request). The matrix resets both (`forgetGuards()` + `forgetCachedPermissions()`) before each sub-request to mirror the real per-request condition; a single fresh request resolves the correct user + `can()` for every role.

---

## 4. CSV report sample

Input (`employees.csv`):

```
email,full_name,convenio_numero,employment_type
new1@example.com,New One,01TEST0001,full_time
,Missing Email,01TEST0001,full_time
new3@example.com,New Three,DOES-NOT-EXIST,full_time
```

`POST /admin/employees/import/validate` (dry run — writes nothing):

```json
{
  "ok": true,
  "summary": { "total": 3, "valid": 1, "invalid": 2, "created": 0, "updated": 0 },
  "rows": [
    { "row_number": 2, "email": "new1@example.com", "full_name": "New One",       "action": "create", "status": "pass", "errors": [] },
    { "row_number": 3, "email": "",                  "full_name": "Missing Email", "action": "skip",   "status": "fail", "errors": ["Falta el correo."] },
    { "row_number": 4, "email": "new3@example.com",  "full_name": "New Three",     "action": "skip",   "status": "fail", "errors": ["Convenio no encontrado: \"DOES-NOT-EXIST\"."] }
  ]
}
```

`POST /admin/employees/import` (apply) — imports only the valid row and audits it: `summary.created = 1`; `new1@example.com` exists with a `*`=`created` audit row; `new3@example.com` is **not** created. The bad rows are **reported**, never silently dropped.

---

## 5. Eyes-on checklist (Pedram runs live)

Migrations + role seed have already been applied to the dev DB (`hr_platform`); the four roles' abilities are granted. Suggested run:

- [ ] **CSV** — import a few employees including a deliberately bad row → it is reported as `fail`, valid rows import + audit.
- [ ] **Directory edit** — change an employee's convenio → confirm the `employee_audit_log` entry in the drawer timeline; edit an email → see the warning **and** the 409 confirm modal.
- [ ] **Admins** — create an admin of each role (super_admin / hr_agent / knowledge_editor / auditor).
- [ ] **Matrix, live** —
  - as **hr_agent**: open a card's conversation (works); the History nav is absent; hitting `GET /admin/history/conversations` directly → **403**.
  - as **knowledge_editor**: no conversation access anywhere, incl. the now-tightened card detail.
  - as **auditor**: browse + search all history read-only → confirm a `conversation_access_log` row was written.
  - as **super_admin**: full access → confirm **your own** read wrote an access-log row.
- [ ] **Deactivation** — deactivate an admin → access refused immediately. Deactivate an employee → they can't chat.

How to peek at the access log live:

```sql
SELECT a.full_name AS viewer, e.full_name AS subject, l.access_type, l.context, l.created_at
FROM conversation_access_log l
JOIN admins a ON a.id = l.admin_id
LEFT JOIN employees e ON e.id = l.employee_id
ORDER BY l.id DESC LIMIT 20;
```

---

## 6. Notes / decisions worth flagging

- **Frontend lint** — the new pages follow the established house style (`onCloseRef.current = onClose` during render; `useEffect(load, [deps])`), which the existing `EscalationBoardPage`/`EscalationCardDrawer` also use; the React-19 lint rules flag those patterns repo-wide. `tsc --noEmit` is clean. Not changed to avoid diverging from the codebase's conventions — flag if you'd prefer the stricter pattern adopted everywhere.
- **Staleness threshold** — the directory "Sin revisar" badge uses ~6 months (183 days) client-side; trivially adjustable.
- **History date filter** — `last_activity_at` primary, `started_at` available (Q10); no denormalization, query-filterable at 1,500 employees. Optional indexes deferred until profiling shows need.
- **Nothing committed** — awaiting your review.
