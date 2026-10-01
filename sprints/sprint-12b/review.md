# Slice 12b — review (demo polish) — CLOSED 2026-10-02

**Outcome: CP-1 passed; every item is live on staging; item 2 executed against the frozen manifest; merged to `main` (`--no-ff`, WIP squashed to one commit per repo) and deployed via `deploy.sh` — see "Close-out" at the end.** `hr-backend` untouched.

| | |
|---|---|
| What it does | Hides Chunk Health and the Cobertura nav item by build flag; collapsible sidebar groups; escalation card with the conversation collapsed; grey drawer canvas with white cards; Historial filters on one line; Analítica in plain Spanish; closed board cards hidden behind a toggle; the 677 open test cards closed |
| Blast radius | Frontend presentation only, plus one audited data operation on staging (item 2). No backend code, no migration, no API change |
| Migrations | none |
| Frontend tests | 23 files / 270 tests green; `tsc -b` clean; eslint 23 problems (identical to the pre-sprint baseline) |
| Backend suite | 1564/1564, unchanged (run once as a baseline; no code touched) |
| Deployed | frontend only: rsync (excluding `.git`) → `docker compose build frontend-dist` → `up -d frontend-dist`. No snapshot needed for the deploy itself (no migration) |

## Acceptance #4 — escalations board cleared (item 2)

Executed through `EscalationService::update($card, 'closed', false, $actor)` only: no raw SQL, no deletes. Actor `admin@hr-staging.internal` (id 1). Each card also got one appended `escalation_events` row, `type=bulk_closed`, `note=eval_traffic_pre_pilot`, `detail={run_id, tier, manifest_sha256, snapshot_id}`.

| | |
|---|---|
| Open before → after | **677 → 0** |
| Closed | **677** |
| By tier | A (`test-*@example.com`) **639**, B (`Test *@hr-staging.internal`) **38** |
| By prior status | new **672**, assigned **1**, in_progress **4** |
| Human-touched cards closed (D3) | 6: #1, #2, #3, #280, #281, #293 (`assigned_to` preserved on #280/#281/#293, checked after) |
| Left open | **none** (no unclassified account, no card after `frozen_max_id`, no changed row; 0 skipped, 0 errors) |
| `escalation_events` written | **1,354 = 2 × 677** (677 `status_change` → closed + 677 `bulk_closed`), counted from the table, not from a counter |
| Final table state | 678 cards, all `closed` (677 + #288, already closed); 0 deleted |
| Snapshot | `hr-staging-pre-12b-close`, `available`, created 2026-10-01 21:34 UTC (before the dry run, at Pedram's instruction) |
| Run id | `12b-20261001T2140Z` (`frozen_max_id` 1824) |
| Manifest sha256 | `fb4f12dcf2da52e6f67b3fda2470dda8f8624df47d96bbaf13e30ed0ca616b7d` (677 rows; the file confirmed in the dry run is the file executed; guard 1 compared the sha) |
| Predicate sha256 | `c0f1d5dd524efd28de1f0c48a273d9ddecb042486576eaf6c7f4e9cfe808b5bd` |

Independent read-only check after the run (a second script, not the one that wrote): `bulk_closed` rows for the run 677; 677 distinct cards; all with note `eval_traffic_pre_pilot` and actor 1; every row carries the snapshot id and the manifest sha; open = 0.

Evidence in `eval/`: the dry-run output, the manifest, `MANIFEST.sha256`, the execute output and the per-card result JSON. Scripts in `scripts/` (`eval-clear.php`, `run-eval-clear.sh`, and the local fixture test, 55 assertions: tier classification incl. case-insensitive, stays-open reasons, the three guards refusing, a changed-since-freeze card skipped, a post-freeze card untouched, idempotent re-run, and `--reverse` restoring prior statuses).

**Rollback, if ever wanted:** `EC_MODE=reverse` with the same manifest + sha walks closed → in_progress → (assigned → new) through the service, only for cards closed by this run. It was exercised in the fixture test, **not** on staging. The snapshot is the full recovery point (restore to a throwaway instance and copy rows; never in place).

## Decisions as built

| | |
|---|---|
| D1 | Item 2b built: the Cerrada column renders only with "Mostrar cerradas (N)" on (default off, remembered per browser, not counted in the Filtros badge, not reset by Limpiar) |
| D2 | See the note below |
| D3 / D4 | Tier A + B including the 6 human-touched cards; reason in an appended `bulk_closed` event; acceptance #4 reads `escalation_events` (the plan's `tag_events` was a spec error, F2) |
| D5–D8 | Derived active-group rule; `#eeefed` / `#0b2120`; topic/path/authority labels translated for display only (data and `stats:*` names unchanged); browser-wide remembered prefs |
| D9 | Taken, and not by dropping the toggle outright: see "Historial toolbar" |
| D10 | Both flags are build args; the flip procedure is in `deploy.md` (Session 13) and was **run on staging both ways** at CP-1 (flags on → Cobertura and Chunk Health back; flags off → demo restored) |

## D2 — Analítica "Resueltas" jumps because of item 2 (all pre-pilot test traffic)

Closing the 677 cards makes Analítica's *Resueltas* figure go from about 1 to **678** and collapses the board conversion rate (live now: «678 resueltas en el periodo · tasa de conversión a conocimiento 0 %»). Every one of those is pre-pilot test traffic closed by this operation, not work HR did. A read-side exclusion (hide `bulk_closed` / `eval_traffic_pre_pilot` cards from the throughput figures) is **a ticket, not this slice**.

**Demo script note:** there is no demo-script file in `hr-docs` to edit, so the note lives here. When showing Analítica, say up front that *Resueltas = 678* is the pre-pilot test clean-up, and do not read the conversion rate as a product metric until the pilot has its own traffic.

## CP-1 — what I ran and what I saw (live staging, real Chrome, both themes, desktop and phone)

Logged in as the seeded admin test account (the documented staging fixed code; one login-code request was also sent to that mailbox). Screenshots of every state are in the session's `/tmp/pw/cp1/` (not committed).

| # | Check | Result |
|---|---|---|
| 1 | Sidebar | 5 group toggles. Fold → persisted across reload. Hash navigation into a folded group (what a Corregir link does) re-opens it. Icon rail with two groups folded: 14 of 14 items visible. Phone overlay: 5 groups, works |
| 2 | Escalado | Columns Nuevas / Asignadas / En curso / Resueltas, **0 cards, no Cerrada column**. Toggle shows «Mostrar cerradas (678)» and 500 cards (see the cap below). Card drawer opens with Conversación **collapsed**, toggle works, the setting is remembered on the next card. Drawer is a grey canvas with white bordered cards; no horizontal overflow on phone. Both themes, no page errors |
| 3 | Historial | **One line at 1280 (sidebar open and collapsed), 1440, and 1920** (row height 41 px). Wraps cleanly to two rows at 1024; stacks on phone. Opening a conversation shows three separated cards |
| 4 | Analítica | No snake_case anywhere in the page text; legends/headings in Spanish prose; Calidad is its own page; Resueltas = 678 (D2) |
| 5 | Flags | Demo build: no Cobertura in the sidebar, no Chunk Health in the document drawer. `#view=coverage` still opens the page («Análisis · Cobertura»). Flags on + rebuild → both back; flags off + rebuild → demo again (same bundle hash as the first deploy) |
| 6 | Dark mode / phone | Dark checked on board, card drawer, Historial drawer, Analítica; phone checked on board, card drawer, sidebar overlay and Historial |
| 7 | Item 2 | Dry-run counts matched plan §1.3 exactly (677 = 639 + 38; the six human-touched cards were the six the plan named); open = 0; events = 2 × 677 |

## Things you should know (deviations, limits, follow-ups)

1. **Historial toolbar.** In real Chrome the natural control widths (176 px selects, 144 px dates) need about 1,390 px, so the row wrapped to two lines even at 1440. Built an opt-in compact mode used by Historial only (`FilterToolbar inlineWhenWide`): selects and dates 7.5 rem, 14 px text, 8 px gaps; once the toolbar is ≥ 975 px the **"Filtros" toggle is hidden** and the filters are always shown; below that the toggle and the two-row layout behave exactly as before. Fits the 1,002 px of content at 1280 with the sidebar open, and the 987 px left if a classic scrollbar takes 15 px. Costs: selects show truncated labels («Todos los c…»), and with a filter active the «Limpiar filtros» button wraps to a second line at 1280. Pedram's D9 allowed dropping the toggle; hiding it only when it has nothing to hide seemed less surprising than removing it.
2. **Toolbar DOM order** differs from the plan's stated order: primary → **toggle → clear → filters** → total (plan: primary → filters → toggle → clear → total). Chosen so keyboard and reading order follow the visual order once the filters flow inline. Unit-tested.
3. **The board's 500-card cap still counts closed cards.** The API returns the newest 500 cards of any status and has no exclusion filter, so closed test cards still occupy the cap. With "Mostrar cerradas" on, the toggle says 678 but 500 cards render. Fine today (nothing else is open); once the pilot produces cards the closed ones will crowd the window. Ticket: a server-side status exclusion (or default `status != closed`).
4. **Contrast row.** `.notice--ai` over the new `#eeefed` canvas measures **9.46:1**; the plan said 9.35. All other rows match plan §3.4; the CSS contract/contrast guard (`panelSeparation.test.ts`) covers both themes.
5. **Edit-block inset tint dropped** in the document drawer (the edit block is now a white card; the old inset tint would have doubled up). The AI markers are unchanged: `.ai-marked` keeps its 3 px fuchsia left border, `.ai-suggestions` keeps its tint, `.notice--ai` is untouched.
6. **The activity log shows the appended event as "bulk closed"** (the type string, humanised). Not fixed: no product change this slice. The reason code is in the note.
7. **Phone: Historial and Documents tables overflow the page width** (the `docs-table` has no scroll wrapper; ~790 px on a 390 px screen with real data). Pre-existing: those rules and markup are untouched by this sprint. Ticket.
8. **Staging box vs repo compose.** The two build-arg lines were added to the box's flat `docker-compose.staging.yml` by a minimal in-place edit (12a's lesson). The repo copy already had them from the sprint's first docs commit; the box copy was then brought to match exactly, including the two comment lines. The **only** remaining difference is the box-only `STAGING_FIXED_OTP_CODE: "135790"`. Backup of the previous box file: `docker-compose.staging.yml.pre-12b`.

## Close-out

**Squash and merge.** Each repo's slice work is one commit on `sprint-12b`, merged to `main` with `--no-ff`; `main` and `sprint-12b` pushed. `hr-backend` and `hr-ai` are unchanged (nothing to merge; the one-off item-2 script lives under `sprints/sprint-12b/scripts/` with its outputs in `eval/`, never in the product tree). `hr-frontend/count-strings*` stayed untracked and out of the commit (an earlier WIP commit had swept them in; removed before the squash, the shipped tree otherwise identical to what CP-1 ran).

| Repo | Branch commit | `main` merge commit (deployed) | Previous `main` (rollback target) |
|---|---|---|---|
| hr-backend | — (no change) | `54fea620f09e156ce1d2e476289380e943256db6` | same |
| hr-ai | — (no change) | `09503bc86602509bbb0b72f94a585c0443ca98b5` | same |
| hr-frontend | `eb1b8ab637601d9b686b1516998787f99e4792a7` | `513733c9ac35a697a34cfacd5464a93489cc2bf4` | `e3e94f603b1842b9efdaf8a44e7dc3093ca985f8` |
| hr-docs | `6cf8b1e55392366beaa29706098060999d3f9af0` | `fa0bda8de3303cec44f751076b8f7b5bb0c46819` (deployed; this close-out is a later docs-only commit) | `ac7dcd67ad49d7cf04cf3250131afc512c4636f0` (box was on `a0079b7d477ca7e3ca819e5890a9f811626fbf4a`) |

Rollback of the build: `deploy.sh 54fea620f09e156ce1d2e476289380e943256db6 09503bc86602509bbb0b72f94a585c0443ca98b5 e3e94f603b1842b9efdaf8a44e7dc3093ca985f8 a0079b7d477ca7e3ca819e5890a9f811626fbf4a` (no migration belongs to the slice). Rollback of the data operation: `eval-clear.php` with `EC_MODE=reverse` and the frozen manifest (fixture-tested, not run on staging), or the snapshot `hr-staging-pre-12b-close` restored to a throwaway instance (never in place).

**Deploy.** On-box `hr-frontend` checkout reset (`git reset --hard`, `git clean -fd` after a dry run that listed only the sprint's rsync-injected files and the untracked `count-strings*`; the other three checkouts were already clean) → `deploy.sh` with the four SHAs above (leak scan clean; "Nothing to migrate"; all health checks green on attempt 2; `.last-good-shas` updated) → the flat compose was overwritten from the repo copy (**diff against it: empty**, so the box now had `STAGING_FIXED_OTP_CODE: ""`) → fixed OTP `135790` re-applied (the only diff) → `hr-backend`, worker and scheduler recreated with the `vars.sh` exports → health. No pre-deploy snapshot (no migration); the post-close snapshot below covers the state after.

**Health.** Laravel 13.16.1, environment staging, debug off, maintenance off, `APP_URL`/`DB_HOST` populated, `STAGING_FIXED_OTP_CODE=135790`, no pending migrations, `/up` 200 on the box, `/api/up` and `/` 200 on the public host, hr-ai healthy; worker and scheduler running. Verified with a real artisan command, not `/up` alone.

**Demo flags (final state).** The repo compose defaults `VITE_SHOW_CHUNK_HEALTH` and `VITE_SHOW_COVERAGE` to `false`, so `deploy.sh` builds the demo with no override; the served bundle is `assets/index-D7eoXvSs.js`, the same hash as the CP-1 build. Flip procedure: `deploy.md` Session 13.

**Verification of the deployed build** (screenshots of the live site, real Chrome, admin test account): sidebar groups fold (two groups folded at once, `aria-expanded` false/true as expected; Cobertura absent); board with columns Nuevas / Asignadas / En curso / Resueltas and **no Cerrada column**, toggle «Mostrar cerradas (678)» unchecked; Analítica in Spanish prose (checked above in CP-1, re-viewed after deploy); **Cobertura hidden from the sidebar and still reachable by `#view=coverage`** («Análisis · Cobertura»).

**What the board shows now.** Two open cards, both from `Test Navarra (Limpieza)` asking «¿Qué es el quiet quitting?» (reasons «Revisión pedida por el empleado» and «Información general bloqueada»): someone tested after the close. They are after the frozen manifest, so they stay open by design; nothing to do unless they should be cleared too (a new manifest and a new confirm).

**Snapshots** (`hr-staging-db`, manual): `post-ingest-20260906`, `post-11b`, `post-12a`, `post-13`, `post-13b`, `post-13d`, `post-13c`, `post-13e`, **`pre-12b-close`** (2026-10-01 21:34 UTC, **kept until the demo is done** — it is the rollback for the bulk close; delete it afterwards), **`post-12b`** (2026-10-01 22:29 UTC, after the deploy and verification).

**Left on the box:** `/opt/hr-staging/docker-compose.staging.yml.pre-12b` (the pre-sprint flat compose; harmless, can be deleted).
