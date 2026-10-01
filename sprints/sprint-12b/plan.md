# Slice 12b — Plan

> Status: **PLAN — awaiting review. No code, no commits, no staging writes.**
> Spec: `hr-docs/sprints/sprint-12b/sprint-12b-spec.md` (the kickoff calls it `spec.md`; the file on disk is `sprint-12b-spec.md`. I did not rename it.)
> Also read: Sprint 11a `plan.md`/`review.md` (§B sidebar, §C FilterToolbar, §A tokens, CP-2 sidebar revision), Sprint 12a `glossary.md` (**alcance**; **Status**/**Flags** stay English), `hr-frontend/src/lib/statusLabels.ts` + `statusLabels.test.ts`.
> Frontend baseline today: `npx vitest run` → **16 files / 154 tests green**. Repos: `hr-frontend`, `hr-backend`, `hr-docs` all on `main`.

## 0. How the staging numbers in §1 were obtained

Five **read-only** passes over SSH → `docker exec hr-staging-hr-backend-1` → `php artisan tinker`. Every pass opened a transaction, ran `SET TRANSACTION READ ONLY`, ran `SELECT`s (plus the existing `DeflectionAnalytics` / `QuestionClusteringService` read methods for §4), and rolled back. Nothing was written, no file was created on the box, and I did not touch containers. Data was read at **2026-10-01 ~20:20 UTC**. Cards are still being created by someone's eyes-on testing (latest card `2026-10-01 19:02`, `max(id)` 1824), so the counts below are a snapshot, and §1.4 freezes the set by id.

---

## 00. Four things I found that change the spec's premises — read these first

| # | Finding | Why it matters | Where it's handled |
|---|---|---|---|
| **F1** | **Closing the cards does not clear the board.** The board renders all five statuses as columns, including **Cerrada** (`EscalationController.php:123` returns `statuses: [new, assigned, in_progress, resolved, closed]`; `EscalationBoardPage.tsx:187` renders one `BoardColumn` per status) and lists the newest **500** cards of *any* status (`EscalationController.php:113`). After the op the 677 cards sit in a **Cerrada** column full of test traffic. Eyes-on #2 ("board shows only real/pilot-relevant cards") would fail. | Item 2 as specced cannot meet its own goal. | **Decision D1.** I propose a small presentation-only item **2b**: *Cerrada* column hidden by default behind a "Mostrar cerradas (N)" toggle in the board's `FilterToolbar`. No API or data change. |
| **F2** | **The "tag_events" audit does not exist for cards.** `tag_events` is the document-vocabulary provenance log. Card activity lives in **`escalation_events`**, which the migration says is "kept DISTINCT from `tag_events`" (`2026_06_24_100002_create_escalation_events_table.php:13-15`). Spec §2 item 2 and acceptance #4 say `tag_events`. | The acceptance row must read **`escalation_events` rows written**. | §1.5, and acceptance #4 below. |
| **F3** | **There is no "session label" or "gate marker" in the schema.** `chat_sessions` is `id, uuid, employee_id, started_at, last_activity_at, timestamps` (`2026_06_20_131017_create_chat_sessions_table.php:11-18`). The only marker is the **account**: `answer:gate` and `estatuto:gold-eval` refuse to persist anything unless the employee is `test-*@example.com` (`AnswerGate.php:222`, `EstatutoGoldEval.php:89`), and `answer:gate` otherwise runs inside a transaction it rolls back (`AnswerGate.php:234-238`). | The predicate must key on the card's **employee**, not the session. Sessions can't be used anyway: **41** open cards have lost their session (cleanup scripts; `chat_session_id` is `nullOnDelete`, `…131021_create_escalation_cards_table.php:14`). | §1.3. I do **not** propose adding a column. |
| **F4** | **Bulk-closing inflates Analítica's "Resueltas".** `EscalationFixAnalytics::byFix` counts `status in ('resolved','closed')` as `resolved_count` (`EscalationFixAnalytics.php:35`) and `boardThroughput` counts the same pair as `total_resolved` (`:80`). Today that is **1**; after the op it is **678**, and the "tasa de conversión a conocimiento" line in Analítica (`AnalyticsPage.tsx:99-100`) collapses toward 0 %. | A number the client will see on the Analítica page moves for the wrong reason. Backend code is out of scope (spec §3). | **Decision D2.** Recommended: accept and brief Pedram; it is all test traffic pre-pilot. |

---

## 1. Item 2 — clear the escalations board (R1)

### 1.1 What is open today (read-only)

`escalation_cards.status` is `new | assigned | in_progress | resolved | closed` (`EscalationService.php:38-44`; `closed` is a legal target from `new`/`assigned`/`in_progress`/`resolved`). "Open" below = `new | assigned | in_progress`. There are **0** `resolved` cards.

| Status | Cards |
|---|---|
| new | 672 |
| assigned | 1 |
| in_progress | 4 |
| **open total** | **677** |
| closed (already; card #288, `employee@hr-staging.internal`) | 1 |
| total in table | 678 (`2026-09-06` → `2026-10-01`) |

Open by employee account (every open card, nothing omitted):

| Account | Open | First → last card |
|---|---|---|
| test-midingest@example.com | 243 | 09-11 → 09-30 |
| test-andalucia@example.com | 233 | 09-11 → 09-30 |
| test-fullgap@example.com | 94 | 09-11 → 10-01 |
| employee@hr-staging.internal | 35 | 09-10 → 10-01 |
| test-navarra@example.com | 32 | 09-12 → 10-01 |
| test-gipuzkoa@example.com | 20 | 09-12 → 10-01 |
| test-deporte-estatal@example.com | 6 | 09-29 → 10-01 |
| test-ocio-alava@example.com | 5 | 09-29 |
| test-hosteleria-navarra@example.com | 3 | 09-09 → 09-14 |
| salary-test@hr-staging.internal | 3 | 09-06 |
| test-andalucia-nocat@example.com | 2 | 09-29 |
| test-deportivas-alava@example.com | 1 | 09-30 |

### 1.2 The sources you asked about

| Source | Open cards | Notes |
|---|---|---|
| **`@example.com` accounts** | **639** | 100 % are `test-<profile>@example.com` seeded profiles (`ChatTestUserSeeder.php:47-191`). **0** open cards on any other `example.com` address. |
| **`@hr-staging.internal` accounts** | **38** | `employee@` (35) + `salary-test@` (3) — "Test Employee" / "Test Salary Employee". `limpieza-gipuzkoa-test@` has 0. The four admin logins on this domain can't own cards. |
| **`answer:gate` sessions** | **0 by marker** | No marker exists (F3). 64 gate-synthesised `test-answer-gate-<hash>@example.com` employees exist (`ResolvesGateCases.php:120`); **none** has an open card — the default gate run rolls back. Gate output only persists with `--persist` and only for `test-*@example.com` (`AnswerGate.php:222`), so any persisted gate card is inside the 639. |
| **CP / eyes-on runs** | **38 + part of the 639** | Hand-driven logins use `employee@hr-staging.internal` (the 38 above: PIF, "Hola", "Buenos días", vacaciones…) and the seeded `test-*` profiles (the OTP-bypass accounts the eyes-on rule in `deploy.md:174` tells us to use). |
| **Anything else** | **0** | `javier@ffrw.es` is the only non-test employee and owns **0** cards. |

Shape of the 639 `example.com` cards — **informational evidence, not part of the predicate**:

| Session shape | Cards |
|---|---|
| single user turn in the session (fresh-session-per-case: gate / 13-series shape) | 343 |
| 2–8 user turns | 21 |
| ≥ 19 user turns (reused-session runs; 101- and 106-turn sessions are 207 of these) | 234 |
| session deleted (cleanup scripts) | 41 |
| bursts | 09-11/12 = 267 cards, 09-29 = 317 cards |

### 1.3 The predicate (R1)

A card is **eval traffic** iff **all** of:

```
c.status IN ('new','assigned','in_progress')          -- open only; resolved/closed untouched
AND c.id <= :frozen_max_id                            -- frozen at dry-run time (see 1.4)
AND (
     e.email ~* '^test-[a-z0-9-]+@example\.com$'                          -- Tier A
  OR (e.email ~* '@hr-staging\.internal$' AND e.full_name ILIKE 'Test %') -- Tier B
)
```

where `e` = `employees` joined on `c.employee_id`. Never joined through `chat_sessions`.

Why this is *provable*, not heuristic:

- **Tier A** is the exact gate the two persisting eval tools hard-enforce (`AnswerGate.php:222`, `EstatutoGoldEval.php:89`); the 14 seeded profiles come from `ChatTestUserSeeder.php`. `.com` addresses with a `test-` local part are not real people's mailboxes in this system.
- **Tier B** is `.internal` (not routable, so not a person) **and** the seeded name starts "Test " (`TestUserSeeder.php:56-88` seeds admins named "Test …"; the three staging employees are named "Test Employee" / "Test Salary Employee" / "Test Limpieza Gipuzkoa"). Both conditions are required, so a future real `@hr-staging.internal` account named otherwise stays open.

Run against staging with that exact predicate:

| Tier | new | assigned | in_progress | **open** | already closed |
|---|---|---|---|---|---|
| A — `test-*@example.com` | 637 | 1 | 1 | **639** | 0 |
| B — `Test *@hr-staging.internal` | 35 | 0 | 3 | **38** | 1 (#288) |
| unclassified | 0 | 0 | 0 | **0** | 0 |
| **total** | **672** | **1** | **4** | **677** | **1** |

Classification is **total today** (0 unclassified), so the "what stays open" list is currently empty.

**Human-touched open cards (6)** — all inside Tier A/B, listed separately in the dry run and needing your explicit yes (D3): #1, #2 (`salary-test`, fence tests from Sprint 7d, with `publish_blocked` events and resolution rows), #3 (resolution row, no events), #280 (`test-navarra`, assigned to admin 1), #281 (`test-hosteleria-navarra`, in_progress), #293 (`employee@`, in_progress). Closing them destroys nothing — `update()` only writes a status and an event — and every one is reversible (§1.7).

**What stays open, by rule** (always listed, with the reason, in the dry-run output):
1. any open card whose employee matches neither tier (none today — e.g. `javier@ffrw.es`, any future real address);
2. any card with `id > frozen_max_id` (arrived after the dry run, i.e. someone is still testing);
3. any card whose status changed between dry run and execute (re-checked per card);
4. `resolved` cards and the existing `closed` one (not open).

### 1.4 Dry run (read-only) — output format

A script `hr-docs/sprints/sprint-12b/scripts/eval-clear.php`, run through tinker the way the existing `/opt/hr-staging/run-*.sh` + `*.php` diagnostics are (the PID-1 env-load recipe). **Default mode is dry run; there is no flag that makes it write without the three guards in 1.5.** It is not a product path and lives in `hr-docs`, not `hr-backend`.

Output (all text, plus a manifest file):

```
== 12b eval-clear · DRY RUN · run_id=12b-20261001T2030Z
db_host=hr-staging-db.….rds.amazonaws.com  env=staging  frozen_max_id=1824
predicate_sha256=<sha of the predicate text>
-- tier × status (open cards that WOULD close)
 tier | new | assigned | in_progress | total
 A    | 637 | 1           | 1       | 639
 B    | 35  | 0           | 3       | 38
 TOTAL| 672 | 1           | 4       | 677
-- by account (would close)                       -- every account, count, first/last
-- human-touched (6): id, uuid, account, status, assigned_to, event count, resolution?
-- STAYS OPEN (n): id, uuid, account, status, reason  ← "no_tier_match" | "after_frozen_max_id"
-- already closed (1): #288
manifest: eval-clear-manifest-12b-<run_id>.json  sha256=<…>  (677 rows)
```

The manifest holds, per card: `id, uuid, employee_email, tier, prior_status, assigned_to, touched`. Dry-run output and manifest go to `hr-docs/sprints/sprint-12b/eval/` (committed), so the counts you confirm are the counts that execute.

### 1.5 Execution — through the service, never SQL

- **Service method:** `EscalationService::update($card, 'closed', false, $actor)` (`EscalationService.php:60`). `new`, `assigned` and `in_progress` all have `closed` as a legal target (`:38-44`). `false` means "assignee not provided" (`:65`), so `assigned_to` is preserved. The method writes the `status_change` row to `escalation_events` (`:95`) inside its own transaction.
- **Reason code:** `update()` hard-codes the note "status moved on the board" and has no reason parameter (`:95`, `log()` is `private` at `:508`). I will **not** change product code. Inside the *same* per-card `DB::transaction`, after `update()` returns, the script appends one more `EscalationEvent` (Eloquent, append-only model, `EscalationEvent.php:16-26`): `type = bulk_closed`, `old_value = <prior status>`, `new_value = closed`, `actor_id`, **`note = eval_traffic_pre_pilot`**, `detail = {run_id, tier, manifest_sha256, snapshot_id}`. `type` is a free string (migration comment at `…100002…php:21`), so no migration. Result: two audit rows per card, the second carrying the reason code. Option, if you'd rather have one row: add an optional `$note` argument to `update()` — a 3-line backward-compatible product change; I'd need your OK because spec §4.5 says no backend code change (D4).
- **Actor:** the service needs an `Admin`. Staging has 4 admins (`admin@`, `editor@`, `auditor@`, `agent@hr-staging.internal`). Proposed actor: **`admin@hr-staging.internal` (id 1, super_admin)** — the account that already did the earlier test-card triage (events on #1, #280, #281, #293). No new account is created (that would be a write outside the op).
- **Three guards before any write:** (1) `--manifest=<file> --expect-sha=<sha256>` must match the dry-run file Pedram confirmed; (2) `--snapshot=<id>` must name an **available** RDS snapshot created this session; (3) printed DB host must equal the staging host and `app()->environment('staging')`. Per card the script re-reads the row and **skips** it (recording why) if it is no longer open, no longer matches the predicate, or its status differs from the manifest.
- **Never deletes.** No `DELETE`, no `UPDATE` outside `update()`.
- **Report (acceptance #4):** open before/after, closed-by-tier, closed-by-prior-status, anything left open and why, `escalation_events` rows written (expect 2 × closed count), snapshot id, run_id, manifest sha.

### 1.6 When it runs

Last (see §6). Sequence: dry run → **you confirm the counts** → `aws rds create-db-snapshot --db-snapshot-identifier hr-staging-pre-12b-eval-clear` + `wait db-snapshot-available` (the `deploy.md:429` rule) → execute → verify.

### 1.7 Rollback

1. **Full recovery point:** the manual snapshot above. Restoring is a throwaway-instance restore + row copy (the `09-restore-rehearsal.sh` pattern, `deploy.md:310`), **not** an in-place restore — an in-place restore would also discard every other write since the snapshot.
2. **Surgical reverse (primary):** `eval-clear.php --reverse --manifest=…` walks the manifest and, through the same service, moves `closed → in_progress` (legal, `:43`) and then on to the manifest's prior status (`in_progress → assigned → new`, both legal), writing normal `status_change` events. `assigned_to` is never cleared by the close, so it is intact. Dry-run-able like the forward path.

### 1.8 Item 2b — proposed (needs D1)

`EscalationBoardPage.tsx`: the board columns come from `data.statuses` (`:187`). Proposed: filter `closed` out of the rendered columns unless a new "Mostrar cerradas (N)" checkbox is on, placed in the existing `FilterToolbar` children (`:161-180`, next to "Solo asignadas a mí"). `N` comes from `data.counts.closed` (already in the payload). Default off; remembered per-browser like the other 12b prefs (`hr-admin-board-show-closed`). No API change, no change to what a closed card *is*. Without 2b, item 2 should be re-scoped as "close for the record" and the demo board will still show the cards.

---

## 2. Item 8 — collapsible sidebar groups (R3)

### 2.1 Today

| Piece | Where |
|---|---|
| Five groups built as arrays of `navBtn(...)`, then wrapped by `navGroup(label, items)`; a group with zero items renders nothing | `AdminShell.tsx:198-235` (`conocimiento`/`atencion`/`analisis`/`personas`/`gobierno`; `navGroup` at `:229`) |
| `navGroup` markup: `div.shell-nav-group` > `span.shell-nav-group-label.shell-sidebar-text` + the item buttons | `AdminShell.tsx:229-235` |
| Each item is `button.btn.btn-ghost.shell-nav-item` with `aria-label="Group · Item"` and `data-tooltip` | `AdminShell.tsx:180-197` |
| Icon-only mode: persisted `hr-admin-sidebar-collapsed`; class `shell-sidebar--collapsed` only when `collapsed && !mobileNavOpen` | `AdminShell.tsx:86,148,243` |
| Icon-only CSS hides every `.shell-sidebar-text` (so the group label disappears; groups remain as stacked icons separated by a border) | `index.css:621-629`, group divider `:546-550` |
| Mobile overlay (≤ 640 px): off-canvas sidebar, `mobileNavOpen`, never uses the icon rail while open; selecting an item closes it | `AdminShell.tsx:163,185-188`; `index.css:655-` |
| Existing nav test reads `.shell-nav-group-label` text and **every `button`** inside a group | `AdminShellNav.test.tsx` `renderedGroups()` (`el.querySelectorAll('button')`) |

### 2.2 Fold mechanics

- Group header becomes a real `<button type="button" class="shell-nav-group-toggle">` containing the existing `span.shell-nav-group-label.shell-sidebar-text` and a lucide `ChevronDown` (already a dependency; `aria-hidden`). Chevron rotates −90° when folded; no transition under `prefers-reduced-motion` (the repo already has that block at `index.css:1991`).
- The group's items move into a sibling `<div role="group" aria-labelledby=<label id> id=<panel id>>`. Folded = the HTML **`hidden`** attribute on that div. Items stay in the DOM, so group→item structure is identical in every fold state (this is what keeps the role snapshots stable) and folded items drop out of the tab order and the accessibility tree.
- **Fold applies to expanded mode only (R3).** `iconOnly = collapsed && !mobileNavOpen` (the exact expression already at `:243`). When `iconOnly`: the toggle button gets `hidden` (nothing to click — the label is already invisible) and the item div is **never** `hidden`, whatever the stored fold state, so every icon stays visible with its tooltip/`aria-label`. Stored fold state is not read or written while in icon mode, so expanding the rail restores the user's folds.
- **Mobile overlay:** `mobileNavOpen` is expanded mode, so folds apply; selecting an item still closes the overlay (`:188`). A fold chosen on desktop carries to phone (same keys).

### 2.3 Persistence

One key per group, same `try/catch` posture as `SIDEBAR_COLLAPSED_KEY` (`:80-93`):

| Group | Key | Value |
|---|---|---|
| Conocimiento | `hr-admin-sidebar-group-conocimiento` | `'folded'` when folded; key **removed** when open |
| Atención | `hr-admin-sidebar-group-atencion` | " |
| Análisis | `hr-admin-sidebar-group-analisis` | " |
| Personas | `hr-admin-sidebar-group-personas` | " |
| Gobierno | `hr-admin-sidebar-group-gobierno` | " |

Absent key = open (default: everything expanded, same as today). Written on toggle only (no write-on-mount). Group ids are the stable English slugs above, not the translated labels, so changing locale doesn't lose state.

### 2.4 Active-group rule

`open(group) = !folded[group] || group contains the current view` — a **derived** value, never written to storage. Consequences: navigating by hash, by a "Corregir" link, or by `openEscalation` into a folded group opens it automatically (eyes-on 1); leaving it returns it to its stored fold. On the active group the toggle is `aria-disabled="true"` (still focusable, so a screen-reader user hears why) with an `aria-describedby` hint ("La página actual está en este grupo"), and clicks are ignored. (Alternative: write "open" to storage when auto-opened — stickier, but the stored state stops meaning "what the user chose". Raising as D5; default is derived.)

### 2.5 Keyboard / a11y

- Native `<button>`: Enter and Space toggle; `aria-expanded`, `aria-controls`; visible `:focus-visible` ring using the existing accent focus token pattern (same as `.btn`).
- Tab order: header → its items (if open) → next header. Folded items are not focusable.
- Header label keeps `uppercase` micro-label styling; the button gets full-width hit area (`min-height: 32px`), `--text-faint` label → `--text-muted` on hover/focus (label contrast on `--surface`: 3.61 light is already the 11a "labels only" posture; I'll bump the *toggle's* text to `--text-muted`, 7.29:1, since it is now interactive).
- New strings in both dictionaries (`adminShell.groupActiveHint`, plus a `toggleGroup` aria pattern "Plegar/Desplegar {grupo}"), so `noHardcodedStrings.test.ts` stays green.

### 2.6 Per-role snapshot tests (R3)

- `renderedGroups()` is the only helper that breaks: change `el.querySelectorAll('button')` to `el.querySelectorAll('.shell-nav-item')` (class already on every item button, `AdminShell.tsx:187`). `EXPECTED` for all four roles (super_admin, hr_agent, knowledge_editor, auditor) is **unchanged** — the point of using `hidden` rather than unmounting.
- With item 7 the Análisis group loses *Cobertura* only when the flag is off; tests set the flag explicitly (`vi.stubEnv`), so the four role snapshots assert the flag-on structure and a separate case asserts flag-off.
- **New tests:** default all open; fold persists to the right key and survives remount; active-group cannot fold (`aria-disabled`, items not `hidden`); hash change into a folded group opens it; icon-only + folded → items not `hidden`, toggle `hidden`, all `aria-label`s intact; mobile-open + folded respects the fold and still closes on select; for each of the four roles, fold all groups → structure equal and only the active group's items visible; an absent group (Personas for knowledge_editor/auditor) stays absent and writes no key.
- jsdom doesn't apply `index.css`, so visuals are asserted through the `hidden` attribute and classes only, as 11a did.

---

## 3. Item 4 — panel separation (R2)

### 3.1 Shared CSS today

| Rule | `index.css` | Role |
|---|---|---|
| `.panel` — `background: var(--surface); border-left; box-shadow` | `:359-363` | the surface every drawer inherits; **also used outside drawers** (`.groups-queue .panel`, `:1396`; print `:2041`) — so the new canvas must be scoped to `.detail`, not `.panel` |
| `.detail-backdrop` | `:1067-1076` | full-screen scrim, `z-index: 60` |
| `.detail` — 80vw × 85vh centred dialog, `overflow:hidden` | `:1079-1088` | the box. It is a centred modal in practice; there is **no ≤ 640 px override** today |
| `.detail-head` | `:1091,1148-1155` | sticky header on `--surface` |
| `.detail-body` — scroll area, `padding: 0 16px 32px` | `:1096-1100` | content |
| `.detail-body section` / `:first-child` — blocks are separated only by `border-top` + `margin-top` | `:1164-1173` | what turns into cards |
| `.modal-backdrop`/`.modal` — small confirm dialogs, one block, own `--surface` card | `:2443-2475` | **not changed** (single block; nothing to separate) |

### 3.2 Everything that inherits (`detail-backdrop > .detail.panel > .detail-head + .detail-body`)

| Surface | File:line | Body blocks are `<section>`? |
|---|---|---|
| Escalation card (spec: "escalation card") | `EscalationCardDrawer.tsx:132-138` | yes (10) |
| Document detail (spec) | `DocumentDetailPanel.tsx:174-188` | yes (12) |
| Reference-fact panel (spec: "fact panel") | `ReferenceFactPanel.tsx:133-153` | yes (6) |
| Employee drawer (spec) | `DirectoryPage.tsx:331-337` | yes (3) |
| Fact duplicate panel | `FactDuplicatePanel.tsx:95-101` | yes (1) |
| Quality sample drawer (Calidad) | `QualitySampleQueue.tsx:288-294` | yes (2) |
| **History conversation (spec)** | `HistoryPage.tsx:229-235` | **no** — loose `p.notice`, `dl.kv`, `EmployeeContextBlock`, `div.card-convo` |
| Reference-fact create panel | `ReferenceFactCreatePanel.tsx:107-114` | **no** — two-column grid |
| Grafo node card | `grafo/GrafoNodeCard.tsx:53-59` | **no** — a few `<p>`s |
| The loading/error stubs of the above | same files | n/a |

The five confirm dialogs (`EscalationCardDrawer.tsx:656`, `DocumentDetailPanel.tsx:813`, `ReferenceFactPanel.tsx:418`, `FactDuplicatePanel.tsx:208`, `DirectoryPage.tsx:493`) stay as they are.

### 3.3 Tokens (new, in both blocks)

```css
:root {
  --panel-canvas: #eeefed;            /* grey well behind the cards */
  --panel-card-border: #bebebd;       /* = --border-strong */
  --panel-card-radius: var(--radius-md);
  --panel-card-gap: var(--space-3);   /* vertical margin between cards */
  --panel-card-pad: var(--space-4);
}
[data-theme='dark'] {
  --panel-canvas: #0b2120;            /* Verde Profundo, darkened */
  --panel-card-border: #3a6563;
}
```

Why a new `--panel-canvas` rather than `--surface-inset`: dark `--surface-inset` is `#0e131c`, a blue-black that isn't in the brand palette and is what *inputs* use (`index.css:397-409`); re-using it would repaint panels off-brand and couple them to input styling.

Rules (all scoped under `.detail`, nothing else changes):

```css
.detail { background: var(--panel-canvas); }                 /* beats .panel's --surface only here */
.detail-body { padding: var(--space-3) var(--space-4) var(--space-6);
               display: flex; flex-direction: column; gap: var(--panel-card-gap); }
.detail-body > section { background: var(--surface); border: 1px solid var(--panel-card-border);
                         border-radius: var(--panel-card-radius); padding: var(--panel-card-pad); margin: 0; }
.detail-body > section.ai-marked { border-left: 3px solid var(--provenance-ai); }   /* keep ADR-0020 marker */
```

The old separator rules (`:1164-1173`) are replaced; nested `<section>`s inside a card keep a plain top rule. **Two traps I found:** (1) `.ai-marked` is a 3 px fuchsia left border (`index.css:1341`) — the card's `border:` shorthand would erase the "unverified AI" marker, hence the explicit line above; (2) `.edit-block` and `.sandbox` sections carry their own inset background (`:2419`) — they become ordinary cards (inset tint dropped), which I believe is the intent, but flagging it.

Markup changes: **History conversation** gets its loose children wrapped in three `<section>`s (read-only notice + metadata / employee / conversation); **ReferenceFactCreatePanel** puts each grid column in a `<section>`; Grafo node card is exempt (a 4-line info popup, not in the spec's list). Everything else is CSS-only. At ≤ 640 px: `--panel-card-pad` → `--space-3`, body side padding → `--space-2`; verified on phone at CP-1.

### 3.4 Contrast rows (computed WCAG 2.1, same method as 11a §A.2)

Light: canvas `#eeefed`, card `#ffffff`, border `#bebebd`. Dark: canvas `#0b2120`, card `#173f3e`, border `#3a6563`. Text on the *canvas* matters because loose notices/paragraphs sit directly on it.

| Pair | Light | Dark | Bar |
|---|---|---|---|
| card fill vs canvas | 1.15 | 1.45 | fill step only; separation is carried by the border + gap (not text) |
| card border vs card | 1.86 | 1.77 | non-text, ≥ 1.5 (above 11a's 2:1 floor only for the map stroke; hairlines were never held to it) |
| card border vs canvas | 1.61 | 2.57 | non-text |
| `--text` on canvas / on card | 10.02 / 11.56 | 14.92 / 10.29 | AA ✓ |
| `--text-muted` on canvas / on card | 6.32 / 7.29 | 9.16 / 6.31 | AA ✓ |
| `--accent` (links) on canvas / on card | 5.78 / 6.66 | 7.90 / 5.44 | AA ✓ |
| `--danger` text on canvas / on card | 5.70 / 6.57 | 8.19 / 5.65 | AA ✓ |
| `--warning` text on canvas / on card | **4.54** / 5.24 | 9.42 / 6.49 | AA ✓ (light is the tight one) |
| `--success` text on canvas / on card | 4.58 / 5.29 | 9.52 / 6.56 | AA ✓ |
| `--info` text on canvas / on card | 4.69 / 5.41 | 7.87 / 5.43 | AA ✓ |
| `.notice` (warning on warning-bg) over canvas / over card | 4.58 / 4.58 | 8.31 / 6.13 | AA ✓ |
| `.notice--neutral` (muted on neutral-bg) over canvas / card | 6.61 / 6.61 | 7.62 / 5.67 | AA ✓ |
| `.review-task.is-conflict` (danger on danger-bg) over canvas / card | 5.58 / 5.58 | 7.70 / 5.70 | AA ✓ |
| `.notice--ai` (`--text` on 8 % fuchsia) over canvas / card | 9.35 / 10.77 | 12.81 / 8.80 | AA ✓ |
| `--text-faint` (`.timeline-meta`) on canvas / on card | 3.13 / 3.61 | 3.37 / **2.32** | **pre-existing**: captions only, not AA; unchanged on cards, slightly better on canvas. Not introduced by this item |

Tuning note for D6: `#eeefed` is the lightest canvas that keeps bare `--warning` text ≥ 4.5 (a darker `#eceeeb` drops it to 4.49). The fill step (1.15) is deliberately subtle; the border does the work. If it reads too faint on a projector, `#e9ebe8` is the next step but bare warning text on it falls to ~4.4 (loose warning text outside a `.notice` is rare — I found none in the drawers — so it is your call).

---

## 4. Item 6 — Analítica labels (R4)

### 4.1 Grep result — everything on `AnalyticsPage.tsx` (+ `charts.tsx`) that can render a raw or internal value

`BarChart` prints `d.label` verbatim and mirrors it into `title` (`charts.tsx:30`); `LineChart` isn't used on this page. Findings, in render order:

| Where | Source of the string | Today | Status |
|---|---|---|---|
| Reparto por vía — bar labels | `Object.keys(summary.path_split)` (`AnalyticsPage.tsx:46,82`) ← `floor_decision.path` (`DeflectionAnalytics.php:57-60,144`) | `salary_sql`, `prose`, `reference_fact`, … | **raw** |
| Reparto por autoridad — bar labels | `Object.keys(summary.authority_split)` (`:47,92`) ← `authorityKey()` = sorted atoms joined by `+` (`DeflectionAnalytics.php:107-116`) | `national_law+official_convenio`, `none`, … | **raw** |
| Preguntas por tema — bar labels | `Object.keys(clusters.topic_breakdown)` (`:48,161`) ← `TopicLexicon` keys (`QuestionClusteringService.php:264-284`) | `periodo_prueba`, `horas_extra`, `trabajo_distancia`, unaccented others | **raw** |
| Declinadas por día — bar labels | ISO date string `d.date` (`:87`) | `2026-10-01` | raw date, not an enum |
| Escalaciones por corrección: Motivo | `escalationReasonLabel` (`:112`) | label | OK; **falls back to the raw string** if unmapped (`escalationReasons.ts:57`) |
| …: Sub-resultado | `subOutcomeLabel` (`:116`) | label | OK; **falls back to raw** (`statusLabels.ts:17`) |
| …: Acción de corrección | server text (`fix_action`/`fix_surface` are already Spanish prose, e.g. `EscalationExplainer.php:394-428`) | prose | OK |
| Agrupación: Motivo top | `escalationReasonLabel` (`:151`) | label | OK (same fallback caveat) |
| Headings / KPI that print code identifiers | `es.ts:1234,1252-1255,1259,1264-1266,1275` | see 4.3 | **internal vocabulary in chrome** |

Live values, so this isn't a guess: all-time on staging today `path_split` = `salary_sql 121, prose 685, reference_fact 42, reference_fact_composition 48, agent_planner 13, general_knowledge 13, agent_ask_employee 7`; `authority_split` = `none, official_convenio, national_law, structured_reference, general_knowledge` and the `+` combinations of them; 13 topic keys live. The code defines more (below) — a new turn type must not render raw the day it first fires.

### 4.2 Key → proposed Spanish → English (**proposals — your wording wins**; the one you gave is marked)

**A. `path` (13 values: 11 set in code via `'path' =>`, plus the synthesised `prose` and `unknown`)**

| Key | Proposed ES | Proposed EN |
|---|---|---|
| `salary_sql` | **Preguntas sobre el sueldo** *(yours)* | Salary questions |
| `prose` | Preguntas resueltas con el texto del convenio o la ley | Answered from convenio or law text |
| `reference_fact` | Preguntas resueltas con un dato de referencia | Answered from a reference fact |
| `reference_fact_composition` | Preguntas que combinan varios datos de referencia | Answered by combining reference facts |
| `salary_prose_crosspath` | Preguntas de sueldo con apoyo del texto del convenio | Salary questions backed by convenio text |
| `general_knowledge` | Preguntas de conocimiento general | General-knowledge questions |
| `agent_planner` | Decisión del asistente (escalar o derivar) | Assistant decision (escalate or hand off) |
| `agent_ask_employee` | Preguntas aclaratorias al empleado | Clarifying questions to the employee |
| `agent_finalize` | Respuesta final del asistente | Assistant's final answer |
| `agent_figure_guard` | Cifra no respaldada (bloqueada) | Unsupported figure (blocked) |
| `agent_budget` | Límite de consultas del asistente alcanzado | Assistant tool limit reached |
| `pre_model_guard` | Bloqueada por guardarraíles | Blocked by guardrails |
| `unknown` | Sin clasificar | Unclassified |

**B. `authority` atoms** (`authority_used_key` = sorted atoms joined by `+`; `none` when empty). A single `authorityLabel()` splits on `+`, labels each atom, joins with " + " / " + ". Atoms: `national_law`, `official_convenio`, `internal_hr_ruling` (chunk enum, `…131011_create_document_chunks_table.php:33`), `structured_reference` (`ReferenceFact.php:34`), `general_knowledge` (`'authority_used' => ['general_knowledge']`), plus `none`.

| Key | Proposed ES | Proposed EN |
|---|---|---|
| `national_law` | Ley | National law |
| `official_convenio` | Convenio | Convenio |
| `internal_hr_ruling` | Criterio interno de RR. HH. | Internal HR ruling |
| `structured_reference` | Dato de referencia | Reference fact |
| `general_knowledge` | Conocimiento general | General knowledge |
| `none` | Sin fuente documental | No source document |

Example: `national_law+official_convenio` → "Ley + Convenio".

**C. `topic` keys (16 in `TopicLexicon::ANCHORS`, names in `TOPIC_NAMES`)**

| Key | Proposed ES | Proposed EN |
|---|---|---|
| `vacaciones` | Vacaciones | Annual leave |
| `jornada` | Jornada | Working hours |
| `permisos` | Permisos retribuidos | Paid leave |
| `excedencia` | Excedencias | Leave of absence |
| `periodo_prueba` | Periodo de prueba | Probationary period |
| `trabajo_distancia` | Trabajo a distancia | Remote work |
| `horas_extra` | Horas extraordinarias | Overtime |
| `preaviso` | Preaviso | Notice period |
| `lactancia` | Lactancia | Breastfeeding leave |
| `maternidad` | Maternidad y paternidad | Maternity and paternity |
| `descanso` | Descansos | Rest periods |
| `festivos` | Festivos | Public holidays |
| `movilidad` | Movilidad geográfica | Geographic mobility |
| `antiguedad` | Antigüedad | Seniority |
| `despido` | Despido | Dismissal |
| `ascensos` | Ascensos | Promotions |

EN follows the 11b glossary where it already decided a word (annual leave, paid leave, public holidays). 11b also says topic names shown as *data* stay Spanish; these are fixed lexicon keys and not content strings, so I translate them, but that is your call (D7).

**D. Dates**: `declined_by_day` x-axis → localised short date through the existing `formatDate` (`i18n/format.ts`), not `YYYY-MM-DD`.

### 4.3 Identifiers leaking through headings (`es.ts` `analyticsPage`) — proposed plain wording

| Key | Today | Proposed ES |
|---|---|---|
| `pathSplitHeading` | Reparto por vía (path_split) | Cómo se resolvieron las preguntas |
| `authoritySplitHeading` | Reparto por autoridad (authority_split) | En qué fuente se basó la respuesta |
| `kpiDeflectionRateLabel` | Tasa de resolución (deflection) | Tasa de resolución automática |
| `kpiSatisfactionSubSuffix` | (§7, opcional) | *(removed)* |
| `escalationsByFixHeading` | Escalaciones por corrección (§3) | Escalaciones por acción de corrección |
| `clusteringHeadingPrefix` | Agrupación de preguntas (§4) — ejecución | Preguntas agrupadas por similitud — ejecución |
| `clusteringNotePrefix` | Etiqueta = medoide del cluster … Umbral τ= | Cada grupo se muestra con su pregunta más representativa (no un resumen de IA). Umbral de similitud: |
| `medoidHeader` | Medoide | Pregunta representativa |
| `subOutcomeHeader` | Sub-resultado | Detalle del motivo |
| `unansweredRankingHeading` | Ranking "sin responder" (escalation_rate × volumen × personas afectadas) | Preguntas sin respuesta, por prioridad |
| page description | `stats:*`/`questions:cluster` in `<code>` (`AdminShell.tsx:341`) | keep (11b's technical-string allowlist) unless you want it gone |

These are chrome, not enum values, but they show internal names on the very page the spec targets ("No raw internal key visible anywhere in Analítica"). Included for approval; drop any row you'd rather leave.

### 4.4 Mechanism and guard

- **Dictionaries:** new top-level `analyticsLabels: { path: {…}, authority: {…}, topic: {…} }` in `es.ts` and `en.ts` (en is `Widen<typeof es>`, so a missing English key fails `tsc`).
- **Helpers in `lib/statusLabels.ts`:** `analyticsPathLabel(t, key)`, `analyticsAuthorityLabel(t, key)` (split on `+`), `analyticsTopicLabel(t, key)`. Unlike the existing helpers (which return the raw value for an unmapped key — `statusLabels.ts:17,23`), these fall back to a **humanised** string (underscores → spaces, first letter capitalised) so a new value can never show `snake_case`, and log a `console.warn` in dev. `pathData`/`authorityData`/`topicData` in `AnalyticsPage.tsx:46-50` map through them; `BarChart` is untouched.
- **Guard test, extending `statusLabels.test.ts`:** for each of `path`, `authority atoms`, `topic` — (a) every value has a label in **both** `es` and `en`; (b) no two values collide on one label (the existing `assertFullCoverage` / `assertNoLabelCollisions`); (c) no label contains `_`; (d) the humanised fallback never contains `_`.
- **Honest limit:** that file's own header concedes it checks "a copy against a copy" (`statusLabels.test.ts:11-18`) — the lists are hand-copied from cited backend lines. To cut the drift risk I'd add (e) a **scrape guard** that reads `hr-backend/app/**` for `'path' => '…'` literals and `TopicLexicon::ANCHORS` keys and diffs them against the dictionaries, written with `it.skipIf(!existsSync('../hr-backend'))` because `hr-frontend` builds in isolation in Docker (`Dockerfile`). It runs locally and at my gates; it can't run in the staging build. And (f) at CP-1 I re-run today's live-value sweep (the read-only query above) and diff the live sets against the maps.
- Out of scope here: the same raw-key shape exists in the escalation drawer's activity log (`EscalationCardDrawer.tsx:673-690` prints `e.type.replace(/_/g,' ')`), not in Analítica. A `bulk_closed` event would read "bulk closed" there. Noted, not fixed.

---

## 5. Items 1 and 7 — frontend flags

### 5.1 Where things are read today

- No feature flag exists. The only `import.meta.env` reads are `VITE_API_BASE_URL` (`lib/api.ts:8`) and `import.meta.env.DEV` (`LoginPage.tsx:80`). Vite env is baked at **build** time (`Dockerfile:3-10`), and staging passes build args through `docker-compose.staging.yml` (`frontend-dist: build.args: VITE_API_BASE_URL: /api`, lines 285-290).
- **Chunk Health block:** `ChunkHealthSection` in `DocumentDetailPanel.tsx:568-591`, rendered once at `:271`. Backend payload (`DocumentController.php:131`) unchanged.
- **Cobertura nav item:** `showCoverage && navBtn('coverage', …)` at `AdminShell.tsx:217`; `showCoverage = canViewCoverage(identity)` at `:169` (`api.ts:108-110`); the page renders at `:345` (`view === 'coverage' && showCoverage`); `'coverage'` is in `VALID_VIEWS` (`:70`). The backend also emits `#view=coverage[&convenio=N]` as "Corregir" fix-links (`AdminLinks.php:75-80`) — they must keep working.

### 5.2 Design

New `hr-frontend/src/lib/featureFlags.ts`:

```ts
const on = (raw: string | undefined, dflt: boolean) =>
  raw === undefined || raw === '' ? dflt : ['true', '1'].includes(raw.toLowerCase());
export const showChunkHealth = () => on(import.meta.env.VITE_SHOW_CHUNK_HEALTH, false); // spec: default off
export const showCoverageNav = () => on(import.meta.env.VITE_SHOW_COVERAGE, true);     // spec: on by default
```

Functions, not constants, so tests can `vi.stubEnv(...)` (`import.meta.env` is live in vitest; baked at `vite build`).

- **Item 1:** `{showChunkHealth() && <ChunkHealthSection …/>}` at `DocumentDetailPanel.tsx:271`. Component and dictionary strings stay.
- **Item 7:** only the **nav item** is gated: `showCoverage && showCoverageNav() && navBtn(…)` at `:217`. The `view === 'coverage' && showCoverage` render at `:345` is **not** touched, so `#view=coverage` (typed, bookmarked, or from a fix-link) still opens the page; the permission check still applies (ADR-0018). With the item hidden, the Análisis group simply has one fewer entry; no group goes empty for any role (Calidad is unconditional, `api.ts:121-123`).
- **Defaults:** code defaults are the spec's (chunk off, coverage on). `hr-frontend/.env.example` and local `.env` set both `=true` so dev is unchanged.
- **Demo vs normal build (staging is the only deployed environment, so "demo build" = the staging `frontend-dist` build args):** `Dockerfile` gains `ARG VITE_SHOW_CHUNK_HEALTH=false` / `ARG VITE_SHOW_COVERAGE=true` + matching `ENV`; `docker-compose.staging.yml` passes `VITE_SHOW_CHUNK_HEALTH: ${VITE_SHOW_CHUNK_HEALTH:-false}` and `VITE_SHOW_COVERAGE: ${VITE_SHOW_COVERAGE:-false}`. So staging builds as the **demo** by default; the **normal** build is the same build with `VITE_SHOW_CHUNK_HEALTH=true VITE_SHOW_COVERAGE=true` exported before `docker compose build frontend-dist && up -d frontend-dist` (the `vars.sh`-export discipline from `deploy.md:413`). Recorded in `deploy.md`. Flipping needs a frontend rebuild (build-time flags), not a backend deploy.
- **Tests:** flag matrix (unset / `false` / `true` / `1`); coverage flag off → no Cobertura button for any of the four roles *and* `#view=coverage` still renders the Cobertura heading; chunk flag on/off in a `DocumentDetailPanel` render.

---

## 6. Items 3 and 5

### 6.1 Item 3 — conversation collapse (`EscalationCardDrawer.tsx`)

Today: `<section><h4>Conversación</h4>` then the restricted notice **or** an intro `<p>` + `div.card-convo` of every bubble (`:194-216`). The employee's triggering question is **already outside this block**: the first section's `div.card-trigger` shows `card.question` at `:148-151`, and the intro text itself says "la pregunta que originó esta tarjeta se muestra arriba" (`es.ts:508-511`). So "question stays visible" holds with no duplication.

Change:

- Header row: `h4` + a text button, `aria-expanded`, `aria-controls`. Labels (new, both dictionaries): collapsed → **"Ver conversación completa"**, expanded → **"Ocultar"** (your wording), with a message count "(N mensajes)" next to the collapsed label.
- Collapsed (default): intro `<p>` and `div.card-convo` get `hidden`.
- The `conversation_restricted` branch (knowledge_editor, `:197-205`) gets **no** toggle — nothing to collapse.
- **Memory:** `localStorage` key `hr-admin-escalation-convo-open` (`'true'` when expanded; removed when collapsed), `try/catch` like the other prefs. Read once per drawer open, written on toggle. Default collapsed until you open one.
- **Interpretation question (D8):** "per-session memory" with "(localStorage)" reads to me as *one preference for the browser* (open a card, expand once, later cards open expanded). The alternative — per-card memory — means one key per card uuid (unbounded growth across ~680 cards) and a collapsed default every time you open a different card, which I think is the demo-annoying behaviour. I've planned the single-preference version.
- The reply box, resolve block and activity log are unaffected. Tests: default collapsed, question text present in both states, toggle flips `hidden` and writes the key, restricted variant has no button, saved `'true'` opens on mount.

### 6.2 Item 5 — Historial filters on one line

**Current markup** — `FilterToolbar.tsx:54-77`:

```tsx
<div className="filter-toolbar">
  <div className="docs-toolbar filter-toolbar-row">
    {primary}                                   {/* History: <form style="display:contents"> input.input + Buscar */}
    {hasFilterControls && <button …toggle…/>}   {/* "Filtros" + count badge */}
    {hasFilterControls && activeCount > 0 && onClear && <button …clear…/>}
    {total}
  </div>
  {hasFilterControls && open && <div className="docs-toolbar filter-toolbar-filters">{children}</div>}
</div>
```

History's children (`HistoryPage.tsx:121-132`): 4 `select.select` (convenio, territorio, resultado, motivo) + 2 `input.input[type=date]` (desde, hasta) — **six controls**; I read "the five filters" as four selects plus the date range as one pair (D9).

**Why it wraps** (static reading of `index.css`; no browser here): `.docs-toolbar` is `display:flex; flex-wrap:wrap` (`:720-726`); `.input` is `width:100%` (`:397-409`); only `.select` is overridden to `width:auto` under `.docs-toolbar` (`:728`). So the search input is a full-width item, and each date input also claims a full row. That, not lack of space, is why Historial reads as 4–5 lines.

**Change (CSS-led, no filter behaviour touched):**

```tsx
<div className="filter-toolbar">
  <div className="docs-toolbar filter-toolbar-row">
    {primary}
    {hasFilterControls && open && <div className="filter-toolbar-filters">{children}</div>} {/* display: contents ≥ breakpoint */}
    {toggle}{clear}{total}
  </div>
</div>
```

```css
.filter-toolbar { container-type: inline-size; }
.filter-toolbar .input[type='date'] { width: 9rem; flex: 0 0 auto; }
.filter-toolbar .select { max-width: 11rem; text-overflow: ellipsis; }
.filter-toolbar-row form .input { flex: 1 1 11rem; min-width: 9rem; width: auto; }
@container (min-width: 960px) { .filter-toolbar-filters { display: contents; } }   /* one flow line */
@container (max-width: 959px)  { .filter-toolbar-filters { display: flex; flex-wrap: wrap; gap: var(--space-3); flex-basis: 100%; } } /* today's two-row wrap */
```

A **container** query (the toolbar's own width), not a viewport media query, because the sidebar takes 230 px from the viewport (`index.css:464-480`).

**Honest arithmetic.** Inner width at a 1280 px viewport with the sidebar open = 1280 − 230 − 2×24 (`.shell-body` padding, `index.css:438`) ≈ **1002 px**. Budget: search 144 + Buscar 76 + 4 selects × 128 + 2 dates × 112 + 7 gaps × 8 ≈ **1012 px** — within a few pixels of the limit, and the "Filtros" toggle (+ "Limpiar filtros" when active) also lives in this row. With the sidebar collapsed (56 px) the row has ~1176 px and fits comfortably. So: ≥ 1280 px viewport **guarantees one line with the sidebar collapsed**, and **fits at 1280 px with it open only if** the selects truncate (ellipsis + `title`) and the toggle wraps onto its own line if needed. I can't measure without a browser, so CP-1 checks 1280 (open and collapsed), 1440 and 1024. If 1280-open doesn't fit, the fallback is dropping the "Filtros" toggle from the one-line layout on History only. Below the breakpoint it wraps control-by-control (graceful), then to the existing two-row disclosure on phone.

Behaviour untouched: `filters`, `setFilter`, `onClear`, the active-count badge, and every param name are not edited (`HistoryPage.tsx:51-57` logic and `usePaginatedQuery` are not touched). The same CSS applies to the Escalaciones board toolbar and Review's topic filter (one `select` each); `max-width` on `select` is the only visible effect there. Tests: one-line layout is CSS (not asserted in jsdom); I assert the DOM order (primary → filters → toggle → clear → total), that toggle still hides filters, and that the six controls keep their `aria-label`s.

---

## 7. Build order, tests, eyes-on, open questions, CP-1

### 7.1 Build order

Branch `sprint-12b` in `hr-frontend` and `hr-docs` (no `hr-backend` or `hr-ai` change). Each step ends with `tsc -b`, `eslint .`, `vitest run`.

| # | Step | Items |
|---|---|---|
| 1 | `featureFlags.ts`, wire both flags, `.env.example`, Dockerfile ARGs, compose args, tests | 1, 7 |
| 2 | `analyticsLabels` in both dictionaries, helpers, `AnalyticsPage` mapping, heading rewrites (as approved), guard tests incl. scrape guard | 6 |
| 3 | Sidebar group folding, CSS, new keys + strings, update `renderedGroups()`, new tests | 8 |
| 4 | Panel tokens + `.detail` rules, History/FactCreate section wrapping, both-theme check | 4 |
| 5 | Conversation collapse | 3 |
| 6 | FilterToolbar one-line layout | 5 |
| 7 | *(if D1 = yes)* "Mostrar cerradas" on the board | 2b |
| 8 | Gates: `tsc -b`, `eslint .`, `vitest run`, `vite build` (grep the bundle for `localhost:8000` and for the flag-off markers); backend suite run as an unchanged-baseline check (no backend code touched) | all |
| 9 | Inject frontend to staging per the `deploy.md` recipe (rsync with the `.git` excludes, build with flags **off**, copy `dist`) — snapshot rule doesn't apply (no migration) | 1,3-8 |
| 10 | **Item 2 last:** dry run → report to Pedram → confirm → RDS snapshot → execute → verify (§1.4-1.7) | 2 |

### 7.2 Tests (net new, beyond §2.6, §4.4, §5.2, §6)

- Frontend suite stays green: baseline 154; 12a/13-series goldens are backend fixtures and untouched.
- Backend: **no code change**; the full suite is run only to confirm it is unchanged.
- Item 2 script: a dry-run-vs-fixture test against a throwaway local DB (seeded Tier A, Tier B, one real-looking account, one post-freeze card) asserting counts, the "stays open" list, per-card skip on a changed row, the three guards refusing to run, and the reverse path restoring the original statuses. This runs locally in the backend repo's test DB harness via `php artisan tinker` — the script is not added to `hr-backend`.

### 7.3 Eyes-on list (Pedram, staging; spec §5 plus what the plan adds)

1. Sidebar: fold/unfold each group; reload keeps it; click into a folded group via a Corregir link → it opens; collapse to icon rail with a group folded → all icons visible; phone overlay.
2. Escalaciones: board has no test cards visible (needs D1); open one → Conversación collapsed, question visible, toggle works, memory on the next card, blocks on grey cards. Check the fuchsia AI marker on a fact in a card is still there.
3. Historial: filters on one line at 1280 (sidebar open **and** collapsed) and 1440; wraps cleanly at 1024 and phone; open a conversation → same card separation.
4. Analítica: every legend, heading and tooltip in Spanish prose; Calidad still its own page; the "Resueltas" figure after item 2 (D2).
5. Chunk Health and Cobertura absent in the demo build; open a card's Corregir link that points at Cobertura → still opens; flip both flags and rebuild → both back.
6. Dark mode on 2–4; phone on 2 and the sidebar.
7. Item 2: dry-run counts match §1.3 within the new cards that arrived; snapshot id; after: open = 0 (or the listed exceptions), `escalation_events` = 2 × closed.

### 7.4 Open questions / decisions

| # | Question | My recommendation |
|---|---|---|
| **D1** | Closed cards still show in a *Cerrada* column (F1). Add item 2b (hide closed by default, toggle)? | **Yes.** Without it item 2 doesn't change what the client sees. |
| **D2** | Analítica "Resueltas" jumps 1 → 678 and conversion rate → ~0 % after the op (F4). | Accept and expect it; it is all test traffic pre-pilot. Any read-side exclusion is a backend change outside this slice. |
| **D3** | Scope of the close: Tier B (`employee@hr-staging.internal`, the account your colleague and you use for eyes-on) and the 6 human-touched cards — close them too? | Yes, all of Tier A + B. They are test accounts by name and domain. The eyes-on account will create fresh cards as you test; those stay open by design (frozen id). |
| **D4** | Reason recording: script-appended second event (no product change) vs adding an optional `$note` to `EscalationService::update()`. Also confirm actor = `admin@hr-staging.internal`, and that acceptance #4 reads `escalation_events`, not `tag_events` (F2). | Script-appended event; actor id 1; fix the acceptance wording. |
| **D5** | Active-group rule: derived (re-folds after you leave) vs sticky (auto-open is saved). | Derived. |
| **D6** | Light `--panel-canvas`: `#eeefed` (safe on contrast, subtle) vs `#e9ebe8` (more visible). Dark `#0b2120` (new off-palette hex, Verde Profundo darkened) vs reusing `--canvas` `#0f2b2a` (1.30 vs 1.45 card step). | `#eeefed` / `#0b2120`. |
| **D7** | Analítica wording: edit the 4.2/4.3 tables freely. Topic names in English — translate (proposed) or keep Spanish as data? Keep the `stats:*` command names in the page description? | Translate; keep the commands. |
| **D8** | Conversation-collapse memory: one browser-wide preference (planned) vs per-card. | Browser-wide. |
| **D9** | "five filters": History has four selects + two dates (six controls). One line at exactly 1280 px *with the sidebar open* is within a few px of not fitting (§6.2). OK to drop the "Filtros" toggle on that screen if it doesn't? | Yes, if CP-1 shows it doesn't fit. |
| D10 | Is "demo build" = the staging `frontend-dist` build? (Only one deployed environment exists.) | Yes; flip procedure goes in `deploy.md`. |

### 7.5 The single CP-1

**CP-1 = everything live on staging** (items 1, 3, 4, 5, 6, 7, 8, and 2b if approved) in both themes, desktop and phone for 3, 4, 8. **Item 2 is executed last, only after Pedram confirms the dry-run counts**, then CP-1 closes on the §7.3 checklist.

Acceptance #4 report, exactly: open escalations before/after, closed count by tier and by prior status, list of anything left open and why, `escalation_events` rows written (expect 2 × closed), snapshot id, run id and manifest sha.

---

**STOP.** No code, no commits, no staging writes were made. Waiting for review, starting with D1–D4.
