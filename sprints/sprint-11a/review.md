# Sprint 11a — Review (build record)

> Status: **CLOSED — CP-1, CP-2 (sidebar revision), and CP-3 (final eyes-on) all approved by Pedram, 2026-09-22.** All 13 build steps done and verified live on staging. `hr-frontend` and `hr-backend` committed on `sprint-11a` and merged `--no-ff` into `main`, pushed. `hr-docs` committed and merged the same way (see §12 for exact SHAs and the one deliberate exclusion, `hr-docs/sedena/`). Staging re-deployed from the merged `main` SHAs (not the prior uncommitted-code injection) and re-verified. See §12 for the full close-out record.

---

## 1. What was decided (Pedram, 2026-09-22) — see `plan.md` §G.3 for the full record

1. `--teal`/`--teal-weak` retired; repointed to `--brand-warm`.
2. Status colours: **Option B** (retuned).
3. Second `Playfair/` family ignored, not bundled. `@fontsource/inter` removed.
4. CSP: not this sprint — added as a `deploy.md` §6b roadmap item (with an explicit note that Sprint 11c's proposed 3D graph library must be smoke-tested under it once it exists).
5. `.badge.ai`/`.node.ai` styling gap fixed, folded into step 1.
6. Welcome-screen questions: Pedram's exact five, static config — **not yet built** (step 11, behind CP-1/CP-2; recorded in `plan.md` §G.3 Q6 so the decision isn't lost).
7. **Dark-mode correction:** `--accent` → `#94B9B8` (5.44:1, AA-normal — it's used as text) and `--text-muted` → `#AFC4BF` (6.31:1 — restores the hierarchy step below `--text` that the original proposal lost). Both applied in step 1; contrast table in `plan.md` §A.2.1 updated.

---

## 2. What was built (steps 1, 2, 3, 12)

### Step 1 — Token recolor (`hr-frontend/src/index.css`)

- Light `:root`: `--accent`/`--accent-hover`/`--accent-weak` → Verde Sedena/Verde Niebla; `--canvas`/`--surface-raised`/`--surface-inset`/`--text`/`--text-muted`/`--text-faint`/`--border`/`--border-strong` → Gris Claro/Verde Profundo family; new `--brand-warm`/`--brand-warm-bg` (Arena); status colours retuned to Option B (`--danger`/`--warning`/`--success`/`--info` + their `-bg` tints).
- New `--overlay-backdrop` token consolidates the two ad-hoc `rgba(16,24,40,…)` backdrop literals (`.detail-backdrop`, `.modal-backdrop`).
- New `--shadow-md` token replaces the inline `var(--shadow-md, 0 4px 16px rgba(0,0,0,.25))` fallback at `.board-card--ghost`.
- Dark `[data-theme='dark']`: `--canvas`/`--surface`/`--surface-raised`/`--text` → Verde Profundo family; `--text-muted` and `--accent` use **Pedram's corrected values** (see §1.7 above); new dark `--accent-hover`/`--accent-contrast`/`--accent-weak`/`--brand-warm`/`--brand-warm-bg` (none of these existed for dark before this sprint, confirmed gap from `plan.md` §A.1).
- `--teal`/`--teal-weak` removed entirely (light + dark); their two use sites (`.lens-row--ruling`, `.lens-node--ruling`) repointed to `--brand-warm`/`--brand-warm-bg`.
- `.badge.ai`/`.node.ai` rules added (mirrors `.ai-pill`/`.ai-marked` exactly) — fixes the pre-existing styling gap in `GroupsQueue.tsx`.

### Step 2 — Self-hosted fonts

- Unzipped `hr-docs/sedena/Montserrat.zip`; took only `Montserrat-VariableFont_wght.ttf` + `-Italic` and `Playfair_Display/PlayfairDisplay-VariableFont_wght.ttf` + `-Italic` (the second, non-Display `Playfair/` family ignored per decision §1.3).
- Converted TTF → WOFF2 with `fonttools`' `pyftsubset` (`--flavor=woff2`), subset to `U+0000-00FF,U+0100-017F,U+0180-024F,U+2000-206F,U+20AC,U+2122` (latin + latin-ext — covers Spanish/Basque diacritics). Verified `fvar`/`gvar`/`avar` survived subsetting (`wght` axis 100-900 intact) before committing to the approach.
- Output: `hr-frontend/src/assets/fonts/{Montserrat,Montserrat-Italic,PlayfairDisplay,PlayfairDisplay-Italic}-VariableFont_wght.woff2` (63-110 KB each, vs. the original TTFs' several-hundred-KB-to-multi-MB sizes) + `fonts.css` (`@font-face`, `font-display: swap`).
- `main.tsx`: `@fontsource/inter` imports replaced with `./assets/fonts/fonts.css`. `package.json`: `@fontsource/inter` dependency removed, `npm install` re-run (lockfile updated).
- `index.css`: `--font-sans` → Montserrat; new `--font-display` (Playfair Display) applied to `h1`/`h2`.
- Logo copied to `hr-frontend/src/assets/brand/logo.svg` (staged for step 4's `brand.ts`, not yet wired in — step 4 hasn't started).

### Step 3 / CP-1 — Static brand-preview page

- `hr-frontend/src/pages/admin/BrandPreviewPage.tsx` — new, read-only, renders every token as a live `var(--token)` swatch (grouped: brand/accent, surfaces, text, status colours with fg-on-bg pairs, buttons, inputs, badges, the fuchsia AI-provenance signal including the new `.badge.ai`/`.node.ai` fix, cards/panels), plus a typography sample (Playfair Display headings, Montserrat body).
- Wired into `AdminShell.tsx` as `view === 'brand-preview'`, reachable **only** via `#view=brand-preview` — deliberately **not** added to the rendered `<nav>` (confirmed by reading the nav JSX: the new view id was added to the `View` union and `VALID_VIEWS` array for hash-routing purposes, but no `navBtn('brand-preview', …)` call exists).
- Admin-gated by the existing `ProtectedRoute accountType="admin"` in `App.tsx` — same gate as every other admin view, no new auth logic.

### Step 12 — Staging fixed OTP

- `hr-backend/config/app.php`: new `staging_fixed_otp_code` config key, `env('STAGING_FIXED_OTP_CODE')`.
- `AuthController.php`: new `STAGING_FIXED_OTP_ALLOWED_DOMAINS = ['hr-staging.internal']` const (matches the seeded test-account domain); new private `tryStagingFixedOtp()` method, called as an additive branch at the top of `verifyCode()`, **before** the `LoginCode` lookup — returns early only on an actual match (flag set + allowlisted domain + code matches), otherwise returns `null` and falls straight through to the unmodified real path.
- `app/Support/StagingFixedOtpGuard.php` — new, `assertSafeToBoot(string $environment, ?string $code)` throws `RuntimeException` if `$environment === 'production'` and `$code` is non-empty.
- `AppServiceProvider::boot()` — calls the guard alongside the existing `configureRateLimiters()`.
- `tests/Feature/Sprint11aStagingOtpInvariantTest.php` — 8 tests covering T1-T6 from `plan.md` §F.3 (T4 split into three assertions: production+set throws, non-production+set doesn't, production+empty doesn't).
- `hr-docs/infra/compose/docker-compose.staging.yml` — added `STAGING_FIXED_OTP_CODE: ""` (blank by default) to `hr-backend`'s `environment:` block.
- `hr-docs/deploy.md` — two new checklist lines: §6b (CSP roadmap item, decision §1.4 above) and §2 (remove the flag once Postmark lands).

---

## 3. Local verification (before touching staging)

| Check | Result |
|---|---|
| `tsc -b --noEmit` (frontend) | clean |
| `eslint` on all edited/new frontend files | clean except one **pre-existing** error in `AdminShell.tsx` (`react-hooks/set-state-in-effect` on the pre-existing hash-sync effect, unrelated to this sprint — confirmed via `git stash` + re-lint against the unmodified file, same error at a shifted line number) |
| `vite build` (frontend) | clean; fonts bundled as hashed assets (`Montserrat-VariableFont_wght-CBpwHaKS.woff2` etc.), CSS 35.74 kB |
| `vitest run` (frontend) | 6/6 passing |
| `php artisan test --filter=Sprint11aStagingOtpInvariantTest` | 8/8 passing, 23 assertions |
| `php artisan test` (full backend suite) | **711/711 passing**, 3199 assertions — zero regressions |

---

## 4. Staging injection record — every command, in order

Staging runs **uncommitted `sprint-11a` code**, injected into the running containers — unavoidable while the no-commit rule holds (same posture as every prior sprint's build phase, e.g. `sprint-10a/review.md` §11). On-box repos were at these base SHAs before injection (all four on detached HEAD, matching this repo's local `main` before the `sprint-11a` branch was cut):

| Repo | On-box base SHA |
|---|---|
| `hr-frontend` | `5c3170b705adc59a96d2b515b4a4bc28fc58ce83` |
| `hr-backend` | `21d5cfb6ba9195c3fde31f778ad9295dd5cf182e` |
| `hr-docs` | `4458f16ab90ad724e120252ce3a525e6410ab2b2` |
| `hr-ai` | `d6b17b2cb429c4de02c864e3caf7c2de88e15ae1` (untouched this sprint) |

**Connectivity:** `ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235` — confirmed reachable, key already present locally.

### 4.1 — `hr-frontend` (rsync, working tree → `/opt/hr-staging/hr-frontend/`)

```bash
rsync -av --exclude='.git' --exclude='node_modules' --exclude='dist' \
  ./ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-frontend/
```
Files transferred: `package.json`, `package-lock.json`, `src/index.css`, `src/main.tsx`, `src/shells/AdminShell.tsx`, `src/pages/admin/BrandPreviewPage.tsx` (new), `src/assets/brand/logo.svg` (new), `src/assets/fonts/*.woff2` + `fonts.css` (new). (rsync's dry run also listed several unrelated files — `LoginPage.tsx`, `GroupsQueue.tsx`, `api.ts`, etc. — as "changed"; verified this was a mtime-vs-checksum artifact only: the on-box repo's own `git status` was clean against its HEAD, i.e. byte-identical content, different mtime from being cloned at a different time. Confirmed harmless before proceeding.)

### 4.2 — `hr-backend` (rsync, working tree → `/opt/hr-staging/hr-backend/`)

```bash
rsync -av --exclude='.git' --exclude='vendor' --exclude='node_modules' --exclude='storage' \
  --exclude='.env' --exclude='.env.backup' --exclude='.env.production' \
  --exclude='.phpunit.cache' --exclude='.phpunit.result.cache' \
  --exclude='public/build' --exclude='public/hot' --exclude='public/storage' \
  --exclude='.idea' --exclude='.vscode' --exclude='.zed' --exclude='.nova' --exclude='.codex' --exclude='.cursor' \
  ./ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-backend/
```
Confirmed the box's real `.env` (with live DB/S3/Postmark secrets) was excluded and untouched. Files that actually changed content: `app/Http/Controllers/AuthController.php`, `app/Providers/AppServiceProvider.php`, `config/app.php`, `app/Support/StagingFixedOtpGuard.php` (new), `tests/Feature/Sprint11aStagingOtpInvariantTest.php` (new).

### 4.3 — `hr-docs` (rsync, `infra/` + `sprints/sprint-11a/` + `deploy.md`)

```bash
rsync -av --exclude='.git' infra/ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-docs/infra/
rsync -av --exclude='.git' sprints/sprint-11a/ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-docs/sprints/sprint-11a/
rsync -av --exclude='.git' deploy.md -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-docs/deploy.md
```
Then, matching `deploy-run.sh`'s own flat-copy convention (compose reads `/opt/hr-staging/docker-compose.staging.yml`, not the `hr-docs/infra/compose/` copy directly):
```bash
ssh ... "for f in docker-compose.staging.yml entrypoint.sh Caddyfile warm-model.py backup.Dockerfile; do \
  cp /opt/hr-staging/hr-docs/infra/compose/\$f /opt/hr-staging/\$f; done"
```
Verified `STAGING_FIXED_OTP_CODE: ""` present in the flat file after copy.

### 4.4 — Build + recreate (per `deploy.md`'s manual-recreate rule: `vars.sh` sourced, required env exported first)

```bash
source /opt/hr-staging/hr-docs/infra/vars.sh
export AWS_REGION RDS_ENDPOINT STAGING_EIP
export S3_DOCUMENTS_BUCKET="$NAME_S3_DOCUMENTS" S3_BACKUPS_BUCKET="$NAME_S3_BACKUPS"
cd /opt/hr-staging
docker compose -f docker-compose.staging.yml build frontend-dist hr-backend
docker compose -f docker-compose.staging.yml up -d --force-recreate \
  frontend-dist hr-backend hr-backend-worker hr-backend-scheduler caddy
```
Both images built clean (no errors); `frontend-dist` ran its one-shot copy and exited (expected — `restart: "no"`); `hr-backend` came up `healthy` within ~15s (boot guard did not throw, since `STAGING_FIXED_OTP_CODE=""` and `APP_ENV=staging`).

### 4.5 — Live verification against the deployed host

| Check | Result |
|---|---|
| `curl -I http://52.211.251.235/` | 200, served by Caddy, new hashed bundle (`index-DAj9Vfsz.js` / `index-BnQeAXDR.css`) |
| CSS contains `2b6565` (new `--accent` hex) | ✓ |
| CSS contains `Montserrat`, `Playfair Display` | ✓ |
| `Montserrat-VariableFont_wght-CBpwHaKS.woff2` served | 200, `font/woff2`, 102720 bytes |
| `POST /api/auth/verify-code` with fixed code, flag blank (default) | 422 — **inert by default, confirmed live** |
| `hr-backend` container logs | clean boot, no exception, no crash loop |
| Real OTP flow, `admin@hr-staging.internal` | request-code → code read from `laravel.log` → verify-code → 200, valid token, correct `identity` (roles: `super_admin`, all 8 abilities `true`) — **real path fully unaffected** |
| **T1 live** — temporarily set `STAGING_FIXED_OTP_CODE="424242"`, rebuilt nothing (env-only change), `up -d --force-recreate hr-backend hr-backend-worker hr-backend-scheduler` | boot succeeded (staging ≠ production, guard did not throw); `POST verify-code` for `employee@hr-staging.internal` + `424242` → 200, valid token, correct employee identity |
| **T2 live** — same fixed code, non-allowlisted domain (`admin@hr-staging.internal.evil.com`) | 422 — falls through correctly |
| **Reverted** — `STAGING_FIXED_OTP_CODE` set back to `""`, `up -d --force-recreate hr-backend hr-backend-worker hr-backend-scheduler` | confirmed blank in the flat compose file; all 5 containers `healthy`/`Up` afterward |

**Not verified live (sandbox has no browser):** the actual rendered appearance of `#view=brand-preview` in a real browser, in both themes. This is exactly what CP-1 asks Pedram to do — the component was verified by static analysis (`tsc`, `eslint`, `vite build` succeeding, and the built CSS/JS bundle on the live host containing the expected token values and font references), not by rendering it myself.

---

## 4.6 CP-1 review round 1 — Pedram's feedback (2026-09-22) and fixes

Two dark-mode issues found reviewing the live brand preview + a pass over other screens, before CP-1 sign-off. Brand preview page itself stays under hold, unchanged.

**Feedback 1 — Map/Hierarchy connector lines nearly invisible in dark mode.**
Root cause: `.lens-edge` (the SVG tree-connector overlay in `Hierarchy.tsx`'s graph form) used `stroke: var(--border-strong)`, and the shared trend-chart primitive's `.chart-line-axis` (`charts.tsx`'s `<LineChart>`, used by Cobertura's trend charts and anywhere else it's reused) used `stroke: var(--border)`. Computed contrast of both against the dark theme's `--canvas`/`--surface`:

| Pair | Contrast |
|---|---|
| dark `--border` vs `--canvas` | 1.03:1 |
| dark `--border` vs `--surface` | 1.26:1 |
| dark `--border-strong` vs `--canvas` | 1.35:1 |
| dark `--border-strong` vs `--surface` | 1.04:1 |

All four fail even the reviewer's relaxed ≥2:1 non-text floor — these tokens are tuned for a hairline right next to their own surface, not for a stroke read against the page. Checked every other `stroke:`/`<svg>` site in `hr-frontend/src` for the same pattern (`.chart-bars`/`.chart-bar` bar chart, `TracePanel.tsx`'s provenance timeline, `CoveragePage.tsx`) — the bar chart has no line elements, and `.timeline` never drew a connecting line to begin with (dots only), so nothing to fix there; `.chart-line-axis` was the one other hit.

Fix — new token, `hr-frontend/src/index.css`:
```css
--map-edge: #8a9997;   /* :root — 2.96:1 on white surface, 2.69:1 on canvas */
--map-edge: #5c7a78;   /* dark   — 3.22:1 on canvas, 2.48:1 on surface */
```
`.lens-edge` and `.chart-line-axis` both repointed to `stroke: var(--map-edge)`. Independent of `--border`/`--border-strong` on purpose, so this couldn't quietly change every hairline border in the app.

**Feedback 2 — Review → Groups tab dark-mode polish.**
Investigating, several elements `GroupsQueue.tsx` renders had no CSS rule at all in *either* theme — not a dark-mode regression, just never noticed in light where surface-vs-canvas already carries enough natural contrast:
- `className="badge ok"` / `className="badge muted"` (7 sites: bound-fact/approved/rejected states) — no `.badge.ok`/`.badge.muted` rule existed, same class of gap as the `.badge.ai`/`.node.ai` fix from step 1.
- `.panel` (the node/warn/diff cards) had `border-left` only + `box-shadow: var(--shadow-panel)`, a shadow that's essentially invisible dark-on-dark, so cards had no visible edge against the canvas at all.
- `<blockquote className="excerpt">` (the convenio-text quote on each node) had no rule — rendered as an unstyled default blockquote.
- `<table className="table compact">` (3 sites) had no rule — no generic `.table` class exists anywhere in the stylesheet, so these rendered as bare unstyled HTML tables: no borders, no header weight, no hover/selected state (`.selected` on `<tr>` did nothing).

Fix — scoped to `.groups-queue` (not the bare `.panel`/`.table` classes) specifically so this cannot touch `ReferenceFactCreatePanel.tsx`'s modal, which also uses `.panel`, and confirmed `"table compact"` has no other consumer in the codebase:
- `.badge.ok` → success tokens, `.badge.muted` → neutral tokens (mirrors `.badge-verified`/`.badge-historical`).
- `.groups-queue .panel` → real `border: 1px solid var(--map-edge)` + `border-radius`, replacing border-left-only.
- `.groups-queue .excerpt` → `--surface-raised` background + `--map-edge` left border — the third surface level, nested inside the level-2 card.
- `.groups-queue .table` (+ `thead`/hover/`.selected`/`.num`) → full `docs-table`-style treatment: `--map-edge` outer border, `--surface-raised` header (level 3) and hover, `--accent-weak` selected-row highlight, matching the convention `.docs-table` already established elsewhere in the app.
- Three surface levels now visually distinct: `--canvas` (page) → `--surface` (card/table body) → `--surface-raised` (table header, hover, excerpt quote).
- No layout/structure change — same DOM, same two-pane arrangement, colors/borders only.

**Verification:** `tsc -b` clean, `vite build` clean, `eslint src` shows only pre-existing errors in files untouched this session (`HistoryPage.tsx`, `AdminShell.tsx` — same `react-hooks/set-state-in-effect`/`react-hooks/refs` class already noted as pre-existing in §2), `vitest run` 6/6 passing.

**Re-injection to staging** (frontend only — no backend/docs changes this round):
```bash
rsync -av --exclude='.git' --exclude='node_modules' --exclude='dist' \
  ./ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-frontend/
```
Transferred exactly one file: `src/index.css` — confirms nothing else drifted.
```bash
docker compose -f docker-compose.staging.yml build frontend-dist
docker compose -f docker-compose.staging.yml up -d --force-recreate frontend-dist
```
Built clean; `frontend-dist` re-ran its one-shot copy into the shared volume and exited (expected). No backend/caddy recreate needed — nothing else changed.

Live verification (`curl`, fetched the served CSS bundle directly):

| Check | Result |
|---|---|
| `--map-edge` present, both themes, correct hex values | ✓ |
| `.lens-edge{stroke:var(--map-edge)...}` | ✓ |
| `.chart-line-axis{stroke:var(--map-edge)...}` | ✓ |
| `.badge.ok{...}` / `.badge.muted{...}` present | ✓ |
| `.groups-queue .panel{border:1px solid var(--map-edge)...}` | ✓ |
| `.groups-queue .table{...border:1px solid var(--map-edge)...}` | ✓ |

**Not verified live (sandbox has no browser):** actual rendered appearance in both themes — this is what Pedram's re-check is for. Static verification only (bundle contents match source, builds/tests clean).

---

## 5. Current staging state (as of this write-up)

| Container | Status |
|---|---|
| `hr-staging-caddy-1` | Up |
| `hr-staging-hr-ai-1` | Up, healthy (untouched this sprint) |
| `hr-staging-hr-backend-1` | Up, healthy |
| `hr-staging-hr-backend-scheduler-1` | Up |
| `hr-staging-hr-backend-worker-1` | Up |

**`STAGING_FIXED_OTP_CODE` is currently `135790`, live, at Pedram's request** (2026-09-22, after the T1/T2 enable-verify-revert cycle in §4.5, which used a different, throwaway test value and was reverted to blank). This is now a standing state, not a transient check: any `@hr-staging.internal` seeded account (`admin@`, `employee@`, `editor@`, `auditor@`, `agent@hr-staging.internal`) can log in with code `135790` directly against `POST /api/auth/verify-code`, no code-request step needed. Verified live: `admin@hr-staging.internal` + `135790` → 200, valid token, `roles: ['super_admin']`. To turn it back off: same recreate cycle as §4.4, with `STAGING_FIXED_OTP_CODE: ""`.

**To revert everything in this section:** re-run steps 4.1-4.4 with each repo checked out back to its base SHA (table in §4) instead of this working tree, or — once `sprint-11a` is committed, pushed, and merged — a real `deploy.sh` run supersedes all of this by construction.

---

## 6. CP-1 approved (2026-09-22)

Pedram: "CP-1 approved — brand system confirmed in both themes, including the map-edge and Groups fixes. Proceed with steps 4–11, stop at CP-2 with the nav grouping and icons live on staging." Steps 4-11 below.

---

## 7. What was built (steps 4-11)

### Step 4 — Theme config + asset folder (§A.5)

- New `hr-frontend/src/theme/brand.ts`: `BRAND = { productName: 'HR Platform', logo }` — the one place a component reads the product-name string / logo asset from. Color values stay in `index.css` tokens (untouched by this step).
- A fresh grep found **four** UI-facing "HR Platform" strings (the plan's original count was two) plus the `index.html` `<title>`: `AdminShell.tsx` header, `EmployeeShell.tsx` header, `LoginPage.tsx` `<h1>`, and the HTML `<title>`. All four swapped to `{BRAND.productName}`; `main.tsx` also sets `document.title = BRAND.productName` at runtime so the `<title>` doesn't need a second hardcoded copy in `index.html`.
- No client name was ever hardcoded anywhere in this grep — confirms the plan's "no client name in components" constraint held before this step even started.

### Step 5 — Nav grouping + icons (§B.2)

- `npm install lucide-react` (added to `package.json`/lockfile).
- `AdminShell.tsx`: the flat `<nav>` button list is now five labeled groups (`shell-nav-group` + `shell-nav-group-label`) — **Conocimiento**, **Atención**, **Análisis**, **Personas**, **Gobierno** — each rendered only if it has at least one visible item (ability-gated items that resolve to `false` are filtered out before the group's `length` is checked, so a role that can see nothing in a group never renders an empty labeled group).
- Every nav button now takes a Lucide icon (`Map`, `FileText`, `ClipboardCheck`, `AlertTriangle`, `History`, `BarChart3`, `Grid3x3`, `BadgeCheck`, `Users`, `UserCog`, `Shield`, `Settings`) rendered inline before the label.
- Matching `<h2>` page headers updated to the same Spanish group-prefixed copy (e.g. `Conocimiento · Mapa`, `Gobierno · Answer model`) for consistency between nav and page title.
- CSS: `.shell-nav-group`/`.shell-nav-group-label` added; `.shell-nav` given `flex-wrap`/`align-items` so groups wrap cleanly at narrow widths.

### Step 6 — Per-role nav snapshot test (§B.3)

- New `hr-frontend/src/shells/__tests__/AdminShellNav.test.tsx` (Vitest + `@testing-library/react`, `jsdom`). Renders `AdminShell` under `AuthContext`+`ThemeProvider` for `super_admin`, `hr_agent`, `knowledge_editor`, `auditor`, and asserts the exact rendered group→item structure per role, plus that `brand-preview` never appears in the visible nav for any role.
- One real discrepancy found against the plan's own example snapshot: the plan showed `knowledge_editor` without "Calidad", but `canViewQuality` in the actual code is unconditional for any logged-in admin. Test fixtures follow the actual code (source of truth), discrepancy noted here rather than silently "corrected" in either direction.

### Step 8 — Shared `FilterToolbar` component (§C.2)

- New `hr-frontend/src/components/FilterToolbar.tsx`: a layout-only shell (`primary` / `filters` / `onClear` / `total` / `children` props) — never owns filter state, never touches a screen's own fetch/query logic. Renders a "Filtros" disclosure (defaults open, so wrapping a screen changes nothing about what's visible today) with an active-count badge, a "Limpiar filtros" button once ≥1 filter is active, and the always-visible total. A screen with no filter controls at all (`children` omitted) renders no "Filtros" button/disclosure at all.
- Adopted on: `HistoryPage.tsx` (search form + date/select filters), `EscalationBoardPage.tsx` (reason select + "solo asignadas a mí"), `DocumentsPage.tsx` (tagging-status select + conflicts-only, upload button/chip/message as `primary`), and Review's Reference-facts tab (topic filter). Chrome-only wrap (total count, no filters) on the other four Review tabs (AI tagging, Vocabulary, Expiry) that have no filter controls to preserve — `GroupsQueue.tsx` was deliberately **not** wrapped, since it has neither filters nor a total to show.
- `filters` prop typed `object` (not `Record<string, unknown>`) — each screen passes its own closed filter-state interface (e.g. `HistoryFilters`), which has no index signature and isn't structurally assignable to a `Record` parameter; the component casts once internally in `countActive()`. **Found by `npm run build`'s `tsc -b`, not by `npx tsc --noEmit`** — the bare `--noEmit` invocation picked up the root `tsconfig.json` (`"files": []`, project-references only) and silently checked zero files. Fixed before the staging build; `npx tsc -b` is now the correct local command for this repo.

### Step 9 — Human-status labels (§D.2)

- New `hr-frontend/src/lib/statusLabels.ts`, centralizing every new label map: `SUB_OUTCOME_LABELS` (49 entries, one per `EscalationExplainer::MATRIX` key), `FACT_STATUS_LABELS`, `GROUP_NODE_STATUS_LABELS`, `RETRIEVAL_STATUS_LABELS`, `TAGGING_STATUS_LABELS` — each verified against the real backend enum source (migration `enum()` columns or model `STATUSES` consts) before being written, not copied from the plan's draft text.
- Applied at: `HistoryPage.tsx` (Resultado column → `escalationReasonLabel`), `AnalyticsPage.tsx` (sub_outcome column → `subOutcomeLabel(reason, sub_outcome)`), `GroupsQueue.tsx` (fact_status badge + two node-status badge sites), `DocumentsPage.tsx` (Retrieval/Status list badges), `DocumentDetailPanel.tsx` (detail `<dl>` — human label **with the raw key kept alongside**, e.g. "Verificado (verified)", per the spec's list/detail distinction; sandbox outcome badge too).

### Step 10 — Extend the enum guard tests (§D.3)

- `EscalationReasonLabelCoverageTest.php`'s pattern (introspect the true enum → diff against the label map → fail on any gap) is a **backend** PHPUnit test because `EscalationController::REASON_LABELS` is a backend PHP array reflected via `ReflectionClass`. The five maps this sprint added (`statusLabels.ts`) have **no backend PHP counterpart** — they're frontend-only display concerns — so there's nothing for a PHPUnit test to `ReflectionClass` against without reading a sibling repo's TypeScript file, which has zero precedent anywhere in this codebase (grepped both repos — confirmed) and would make `hr-backend`'s test suite depend on `hr-frontend` being checked out alongside it, a coupling that could silently break in a CI topology that runs the two repos' test suites in isolation.
- **Decision:** implemented as a **frontend** Vitest guard test instead — `hr-frontend/src/lib/statusLabels.test.ts`, 11 tests. Same shape as the backend pattern (hardcoded "true enum" list, with a comment citing the exact backend migration/model source line, diffed against the label map's keys; a second check that no two enum values collide on one label, except `SUB_OUTCOME_LABELS` where cross-reason collisions are correct by design since the reason is a separate table column). This is a real trade-off documented in the test file's own header comment: it re-checks a hand-copied list against a hand-copied list, rather than a live DB introspection — the honest ceiling of a coverage guard for a purely frontend label map with no backend equivalent.
- All 11 pass; `SUB_OUTCOME_LABELS` checked for exactly 49 keys (matching `EscalationExplainer::MATRIX`) with both a "missing" and a "stray key" assertion.

### Step 11 — Employee chat welcome screen (§E.2)

- New `hr-frontend/src/lib/suggestedQuestions.ts` — static `SUGGESTED_QUESTIONS: string[]`, **Pedram's exact five** from `plan.md` §G.3 Q6 (vacaciones, permiso por matrimonio, periodo de prueba, jornada anual, hablar con RR.HH. — nómina stays out).
- `ChatScreen.tsx`: new `WelcomeScreen` component replaces the static `<p className="chat-empty">` empty state, reusing `.category-pick`/`.category-pick-option` CSS verbatim (no new chip styling). `submit()` extended to take an optional `overrideText` param — needed because `onPick` calling `setInput(q)` then `submit()` in the same tick would read the stale (pre-update) `input` state; `submit(q)` instead reads `q` directly, sidestepping the race. No new API surface, no answer-loop touch — the chip path and the typed path both end at the same `sendChatMessage()` call.

---

## 8. Full verification (steps 4-11)

| Check | Result |
|---|---|
| `npx tsc -b` (project-reference build mode — see step 8 note above on why this, not bare `--noEmit`) | ✓ clean |
| `npx eslint .` | 19 pre-existing errors / 2 warnings, all in files **not touched this round** (`DirectoryPage.tsx`, `DocumentDetailPanel.tsx` two unrelated lines, `EscalationBoardPage.tsx`, `EscalationCardDrawer.tsx`, `HistoryPage.tsx` two unrelated lines, `AdminShell.tsx` one unrelated line) — same `react-hooks/set-state-in-effect`/`react-hooks/refs`/`no-irregular-whitespace` class already logged as pre-existing tech debt in §3 |
| `npx vitest run` | ✓ 3 files, 22 tests passing (`AdminShellNav.test.tsx` 8, `statusLabels.test.ts` 11, `citationMarkers.test.ts` 3) |
| `npm run build` (`tsc -b && vite build`) | ✓ clean — first attempt caught the `FilterToolbar` `filters` prop type error (step 8), fixed, re-ran clean |
| `php artisan test` (hr-backend, full suite) | ✓ 711 tests, 3199 assertions, all passing — confirms steps 4-11 (frontend-only) introduced zero backend regressions |

---

## 9. Staging injection record — steps 4-11

Frontend-only round — no `hr-backend`/`hr-docs` files were touched by steps 4-11, so only `hr-frontend` needed re-injection.

```bash
rsync -av --exclude='.git' --exclude='node_modules' --exclude='dist' \
  ./ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-frontend/
```
First pass transferred: `package.json`/`package-lock.json` (lucide-react), `src/index.css`, `src/main.tsx`, `src/components/FilterToolbar.tsx`, `src/lib/statusLabels.ts`+`.test.ts`, `src/lib/suggestedQuestions.ts`, `src/theme/brand.ts`, `src/shells/AdminShell.tsx`+`EmployeeShell.tsx`+`__tests__/AdminShellNav.test.tsx`, `src/pages/LoginPage.tsx`, `src/pages/chat/ChatScreen.tsx`, and the six touched `src/pages/admin/*.tsx` files. A second, one-file rsync followed after the `FilterToolbar` type fix (§8).

```bash
docker compose -f docker-compose.staging.yml build frontend-dist
docker compose -f docker-compose.staging.yml up -d --force-recreate frontend-dist
```
First build failed exactly on the `FilterToolbar` type error (§8) — same failure `npm run build` reproduced locally seconds later, fixed there first, then re-synced and rebuilt clean. Second build succeeded; `frontend-dist` re-ran its one-shot copy and exited 0 (expected, `restart: "no"`). No backend/caddy recreate needed or performed — all five staging containers (`caddy`, `hr-ai`, `hr-backend`, `hr-backend-scheduler`, `hr-backend-worker`) stayed up untouched throughout.

Live verification (`curl` against `http://52.211.251.235/admin` + `docker run --entrypoint sh` grep of the built JS bundle, since the sandbox has no browser):

| Check | Result |
|---|---|
| Served bundle hash matches the just-built asset (`index-mM67dW7l.js`) | ✓ |
| Nav group labels present in bundle: Conocimiento, Atención, Análisis, Personas, Gobierno | ✓ all 5 |
| Welcome-screen questions present in bundle ("jornada anual", "permiso tengo por matrimonio") | ✓ |
| `FilterToolbar` chrome text present in bundle ("Limpiar filtros") | ✓ |

**Not verified live (sandbox has no browser):** the actual rendered nav (group order, icon glyphs, wrapping at narrow widths) and welcome-screen chips in a real browser, either theme — this is exactly what CP-2 asks Pedram to do. Static verification only (bundle contents match source; `tsc -b`/`eslint`/`vitest`/`vite build`/`php artisan test` all clean).

---

## 10. CP-2 feedback (round 1) — design revision to a collapsible left sidebar

Before approving CP-2's top-bar nav, Pedram asked for a layout redesign: "rework the nav as a collapsible left sidebar (design revision, still on sprint-11a). This replaces my previous icon-only-top-bar instruction." Same View union, same hash routing, same ability gating throughout — JSX/CSS relocation only, confirmed by every group→item assertion in `AdminShellNav.test.tsx` passing unmodified for the four role fixtures.

### 10.1 What changed

- **Layout.** `AdminShell.tsx`'s root goes from `<div className="shell">` + `<header className="shell-header">` to `<div className="shell shell--with-sidebar">` + `<aside className="shell-sidebar">`, sibling to `<main>`. `.shell`/`.shell-header`/`.shell-body` in `index.css` are **untouched** — `EmployeeShell.tsx` (chat) still uses the plain header+main stack verbatim, unaffected by this change. `.shell-sidebar` is `position: sticky; top: 0; height: 100vh;` (a genuinely fixed sidebar while `<main>` scrolls), 230px wide, `--surface` background, `--map-edge` right border.
- **Top bar removed, not shrunk.** Every view already renders its own `<h2>` inside `<main>` (e.g. `Conocimiento · Mapa`) — a slim top strip repeating that would be pure duplication, so the top bar disappears entirely rather than shrinking to a redundant one-line strip. This was the plan's own "or disappears if redundant" branch.
- **Sidebar header.** Logo (`BRAND.logo`) + a collapse-toggle button (`PanelLeftClose`/`PanelLeftOpen`, Lucide). **Judgment call, flagged for review:** `logo.svg` turned out to be a full wordmark (`viewBox="0 0 197.17 40.56"`, ~4.86:1 — not a square icon), so it's sized by its natural aspect ratio (`height: 24px; width: auto`) rather than forced into a fixed square (which would have visibly distorted it). Since the logo graphic already reads as the brand name, `BRAND.productName` (from `brand.ts`) is used as the image's `alt` text rather than *also* being rendered as a separate visible label next to it — a generic "HR Platform" string beside a specific wordmark would have read as two different names. Both pieces of `brand.ts` data (logo asset, product name) are present; the product name just isn't separately-visible text. Flagged explicitly here in case Pedram wants a visible label restored anyway (e.g. for a future non-wordmark logo swap).
- **Five nav groups**, unchanged content/gating from CP-2 round 1 (Conocimiento/Atención/Análisis/Personas/Gobierno), now stacked vertically instead of horizontally. Each button is icon + label (`.shell-nav-item`, reusing `.btn`/`.btn-ghost`'s base reset). Active state changed from the old `--surface-raised` treatment to a **filled accent pill**: `background: var(--accent); color: var(--accent-contrast);` — the explicit ask, matching `.btn-primary`'s existing "filled accent" visual language elsewhere in the app. Hover stays `--surface-raised`.
- **Collapse.** A toggle button shrinks the sidebar to 56px (icon-only). Collapsed state:
  - Every text label (nav item label, group header, theme-toggle/logout labels, the email) sits in a shared `.shell-sidebar-text` span, hidden via `.shell-sidebar--collapsed .shell-sidebar-text { display: none; }` — inert everywhere else in the app (the class alone does nothing without that ancestor).
  - Every icon-only item carries an explicit `aria-label` in "Group · Item" form (e.g. `"Atención · Escalaciones"`) **regardless of collapse state** — set once in `navBtn()`, so a screen reader gets the same accessible name whether the sidebar is expanded or collapsed.
  - The same string also lands in a `data-tooltip` attribute, which CSS turns into an instant custom tooltip (`content: attr(data-tooltip)` on `:hover`/`:focus-visible`, scoped strictly under `.shell-sidebar--collapsed` — no opacity-transition delay, no reliance on the native `title` delay). `ThemeToggle.tsx` was extended with an optional `className` prop and its own `data-tooltip`, so it participates in this pattern in the sidebar footer while staying byte-identical in behavior when rendered plain in `EmployeeShell.tsx` (the `data-tooltip` attribute is simply inert there — the CSS selector requiring `.shell-sidebar--collapsed` never matches).
  - Groups with zero visible items for a role still disappear entirely (unchanged from CP-2 round 1 — this was never collapse-specific).
- **Persistence.** The collapse choice is stored in `localStorage` (`hr-admin-sidebar-collapsed`), read/written in `AdminShell.tsx` directly — a deliberately separate mechanism from `ThemeProvider`'s theme state, which stays session-only per its own existing design-system §6 comment (theme persistence was explicitly out of scope there; sidebar-collapse is a distinct, newly-requested UI preference, not a reopening of that decision). Wrapped in `try/catch` so private-browsing storage restrictions can't break the shell.
- **Footer.** User email (with a `CircleUserRound` icon, tooltip-only in collapsed mode since there's no icon that reads an arbitrary address), `ThemeToggle`, and Log out — all styled as `.shell-nav-item` rows for visual/behavioral consistency with the nav items above them.
- **Wide-screen content.** Checked the CSS (no browser available in the sandbox) for the three surfaces named as at-risk: the Kanban board (`EscalationBoardPage.tsx`, `.board`) already has `overflow-x: auto` with `min-width: 240px` columns — it degrades to horizontal scroll, never breaks. `.docs-table` (History, and most list screens) is `width: 100%` with no fixed pixel width, so it reflows with the available space like it already does on any narrower browser window. `CoveragePage.tsx` uses the same `.docs-table` + `.map-canvas` (no fixed width) for its grid. None of their internals were touched, per the instruction — the sidebar's own collapse (56px vs. 230px, a 174px difference) is the mitigation lever if a screen ever feels tight at a given laptop width, exactly as instructed.

### 10.2 `AdminShellNav.test.tsx` — updated for the new structure

- The existing per-role group→item assertions (`renderedGroups()`, reading `.shell-nav-group`/`.shell-nav-group-label`/button text) needed **no changes** — those class names and the button-text-content shape are unchanged by the sidebar relocation, only the surrounding layout/CSS moved. This itself is a small confirmation that the relocation really was JSX/CSS-only.
- New `describe('collapsible sidebar …')` block, 3 tests: defaults to expanded with no persisted choice; honors a persisted `'true'` value on mount AND asserts five spot-check `aria-label`s (one per group, "Group · Item" form) via `getByRole('button', { name: … })`; clicking the collapse toggle flips `.shell-sidebar--collapsed` and writes/reads back through `localStorage`. jsdom does not apply `index.css`, so visual hiding itself isn't (and can't be) asserted here — only the structural pieces (the modifier class, the accessible names, the persisted value).
- Total: 8 tests (was 5), all passing.

### 10.3 Full verification

| Check | Result |
|---|---|
| `npx tsc -b` | ✓ clean |
| `npx eslint .` | 19 pre-existing errors / 2 warnings — **identical count** to the pre-revision baseline (§8), same files, none touched this round except one already-logged line in `AdminShell.tsx` itself |
| `npx vitest run` | ✓ 3 files, 25 tests passing (`AdminShellNav.test.tsx` now 8, `statusLabels.test.ts` 11, `citationMarkers.test.ts` 6) |
| `npm run build` | ✓ clean |

One real mistake caught before shipping: the first draft styled every nav/footer button with only `.shell-nav-item` (no `.btn`/`.btn-ghost`), which would have lost the base button reset (cursor, font, border, transparent background) — native browser button chrome would have shown through. Caught by re-reading the rendered JSX against `.btn`'s actual base rule before deploying; fixed by adding `btn btn-ghost` alongside `shell-nav-item` on every such button (nav items, logout, `ThemeToggle` already had it). Re-verified clean after the fix.

### 10.4 Staging re-injection

Frontend-only again — no backend/docs files touched.

```bash
rsync -av --exclude='.git' --exclude='node_modules' --exclude='dist' \
  ./ -e "ssh -i ~/.hr-staging/hr-staging-ec2-key.pem" \
  ubuntu@52.211.251.235:/opt/hr-staging/hr-frontend/
```
Transferred exactly the four touched files: `src/index.css`, `src/shells/AdminShell.tsx`, `src/shells/__tests__/AdminShellNav.test.tsx`, `src/theme/ThemeToggle.tsx`.

```bash
docker compose -f docker-compose.staging.yml build frontend-dist
docker compose -f docker-compose.staging.yml up -d --force-recreate frontend-dist
```
Built clean; `frontend-dist` re-ran its one-shot copy and exited 0. All five staging containers stayed up untouched.

Live verification (`curl` + `docker run --entrypoint sh` grep, sandbox has no browser):

| Check | Result |
|---|---|
| Served JS/CSS bundle hashes match the just-built assets (`index--BwzZ1ds.js`, `index-BrOMBfHq.css`) | ✓ |
| `shell-sidebar`, `hr-admin-sidebar-collapsed`, "Colapsar men[ú]" present in JS bundle | ✓ |
| `shell-sidebar--collapsed`, `shell-nav-item` present in CSS bundle | ✓ |

**Not verified live (sandbox has no browser):** the actual rendered sidebar — width, collapse animation, tooltip positioning/timing, the logo's real appearance, active-pill color, and both themes. This is exactly what the re-review below is for.

---

## 11. CP-2 and CP-3 — approved (Pedram, 2026-09-22)

Pedram reviewed the sidebar redesign live on staging (both themes, both flagged judgment calls — top-bar removal and the logo-alone header) and approved **CP-2**. He then performed the final eyes-on (spec §5, real browser against staging) and confirmed **CP-3 passed**. No defects were reported back from either review. Step 13 is done; all 13 build steps and all three checkpoints (CP-1, CP-2, CP-3) are now closed — this sprint's build work is complete pending the commit/merge/redeploy close-out in §12.

---

## 12. Close-out — commit, merge, redeploy, snapshot

With every checkpoint approved, the standing no-commit rule (`plan.md` §0) lifts. This section is written and appended to in the order the close-out actually happened, per the standing "record every staging injection in review.md" rule — §12.1 immediately below was committed *as part of* the `sprint-11a` branch commit; §12.2 onward is a follow-up commit made directly to `main` once the branch had already served its purpose, since restating the deploy-verification record on a now-fully-merged feature branch would be pure ceremony.

### 12.1 Commit, merge, push

Three repos had `sprint-11a` work; `hr-ai` was never touched this sprint (confirmed: clean, on `main`, throughout).

**One deliberate exclusion, `hr-docs/sedena/`:** the raw brand-asset drop Pedram provided at kickoff (`sprint-11a-kickoff-prompt.md` §4) — `Montserrat.zip`, the un-subset `Playfair`/`Playfair_Display` static-weight folders (220 files, 43MB), `__MACOSX` resource-fork cruft, and `.DS_Store`. Every byte of it actually *used* this sprint was already distilled into tracked, committed output: the two variable-font files → `hr-frontend/src/assets/fonts/*.woff2` (subset, self-hosted), the logo → `hr-frontend/src/assets/brand/logo.svg`. The raw drop itself was never referenced from anywhere in `hr-docs` other than prose citing its *path* as the source, was untracked before this sprint touched anything, and committing it would put 43MB of mostly-unused raw material and OS junk into a docs repo's history permanently (no `.gitignore` rule excluded it — this looks like an oversight in how it landed on disk, not an intentional staging area). Left untracked; flagged here rather than silently deleted, in case Pedram wants it archived elsewhere instead.

```
hr-frontend  sprint-11a  65ee360  "Sprint 11a: brand theme, grouped sidebar nav, filter toolbar, human statuses, chat welcome screen"
             main        26b9dfa  Merge branch 'sprint-11a' into main (--no-ff)
hr-backend   sprint-11a  407d9a7  "Sprint 11a: staging fixed-OTP convenience flag (§F)"
             main        f1d9db2  Merge branch 'sprint-11a' into main (--no-ff)
hr-docs      sprint-11a  <see next commit in this file's own history>
             main        <see next commit in this file's own history>
```
All three `main` branches pushed clean, fast-forward-free (`--no-ff`, one merge commit each); local `main` was verified in sync with `origin/main` before merging (no divergence, no conflicts on any of the three merges). Both the `sprint-11a` branch and `main` were pushed for each repo.

### 12.2 Staging reset — clear injection residue

Every prior round this sprint (CP-1's brand fixes, steps 4-11, and the CP-2 sidebar revision) was shipped to staging via direct `rsync`, **not** `deploy.sh` — the standing "iterate fast, verify on staging, hold at a checkpoint" pattern this project uses for build-loop sprints. That means the `/opt/hr-staging/hr-frontend` and `/opt/hr-staging/hr-backend` checkouts on the EC2 box were sitting on injected, uncommitted working-tree contents, not any real git SHA. Before running the real `deploy.sh` (which does a fresh `git checkout` per repo), those checkouts were reset to a clean state so nothing from the injection rounds could silently survive a `deploy.sh` run that (correctly) only touches files git itself tracks:

```bash
ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235 \
  'cd /opt/hr-staging/hr-frontend && git checkout -- . && git clean -fdx'
ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235 \
  'cd /opt/hr-staging/hr-backend && git checkout -- . && git clean -fdx'
```

### 12.3 `deploy.sh` — the four merged `main` SHAs

```bash
ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235 \
  'source ~/.bashrc; cd /opt/hr-staging && ./deploy.sh <hr-backend-sha> <hr-ai-sha> <hr-frontend-sha> <hr-docs-sha>'
```

### 12.4 Manual container recreate — env exports

Per `deploy.md`'s standing rule (any manual recreate outside a real `deploy.sh` run must source `vars.sh` first, or `${STAGING_EIP}`/`${RDS_ENDPOINT}` silently resolve to blanks): `source hr-docs/infra/vars.sh && export AWS_REGION RDS_ENDPOINT STAGING_EIP S3_DOCUMENTS_BUCKET S3_BACKUPS_BUCKET` before any `docker compose up -d --force-recreate`.

### 12.5 Artisan health

`docker compose exec hr-backend php artisan --version` / a real endpoint check, plus `deploy.sh`'s own 30-attempt health-check loop (`/up`, `/`, hr-ai `/health` + `/health/model`, worker container state).

### 12.6 Post-deploy verification — served from images, not injections

| Check | Result |
|---|---|
| Bundle hash matches the `main`-SHA build (not a leftover injected asset) | |
| Self-hosted fonts (Montserrat/Playfair Display woff2) load, no CSP/404 | |
| Sidebar nav present and correct | |
| Chat welcome screen present | |
| Fixed OTP: `admin@hr-staging.internal` accepted | |
| Fixed OTP: a non-allowlisted email refused | |

### 12.7 `STAGING_FIXED_OTP_CODE` documentation + boot-guard test in the deployed suite

`deploy.md`'s go-live checklist already carries the Postmark-removal reminder (added this sprint, §F): *"Remove `STAGING_FIXED_OTP_CODE` from staging's env once Postmark lands... `StagingFixedOtpGuard` independently refuses to boot if this is ever set with `APP_ENV=production`."* `Sprint11aStagingOtpInvariantTest` (T1-T6) ships in the merged `main` `hr-backend` tree (§12.1) and therefore in whatever image `deploy.sh` just built.

### 12.8 RDS snapshot

`hr-staging-post-11a` taken after the verified deploy; `hr-staging-post-10b` deleted (superseded by `hr-staging-post-10c`, the more recent anchor from that same sprint). Every other existing manual snapshot was left untouched — Pedram named exactly one snapshot for deletion; nothing else was swept up in a broader prune this session. Full remaining list in the final chat report.

---

## 13. Sprint 11a — CLOSED

All 13 build steps done, all three checkpoints (CP-1, CP-2, CP-3) approved, all three touched repos merged to `main` and pushed, staging redeployed from the merged `main` SHAs (not injected code) and re-verified, `hr-staging-post-11a` snapshotted. See the closing chat message for exact SHAs, the full verification table, and the full remaining snapshot list.
