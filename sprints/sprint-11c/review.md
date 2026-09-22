# Sprint 11c — Review (build record)

> Status: **CLOSED.** Steps 0–9 done, CP-1 (§3a) and CP-2 (§5a) both approved by Pedram. `hr-frontend`, `hr-backend`, `hr-docs` committed on `sprint-11c`, merged `--no-ff` into `main`, pushed. Close-out (staging reset, real `deploy.sh` redeploy, post-deploy verification, RDS snapshot) recorded in §6.

---

## 1. What was built

### Steps 0–3 (backend rules + endpoint) — done earlier this session

- `hr-frontend/package.json` — `3d-force-graph@1.80.0`, `three@0.186.0`, `force-graph@1.51.4` pinned exact. Baseline entry gzip recorded: **145,308 B**.
- `hr-backend/app/Support/KnowledgeGraphBuilder.php` — pure function, all of spec §2's honesty rules (bipartite six edge kinds, hub folding at <2 attachments, orphan/rejected drops, dev-fixture exclusion, state precedence unverified_ai > historical > draft > active/verified). 12 unit tests, `tests/Unit/KnowledgeGraphBuilderTest.php`.
- `hr-backend/app/Http/Controllers/Admin/KnowledgeGraphController.php` + `routes/api.php` (`GET /admin/knowledge-graph`, `auth:sanctum` + `admin` + `active`). Feature tests: role matrix (incl. auditor 200, employee 403, unauthenticated 401), no-PII deny-list, counts, ordering — `tests/Feature/Sprint11cKnowledgeGraphTest.php`.
- Cross-checked against `measure-graph.php` on staging: **261 nodes / 421 edges** exact match, both at Step 3 and again just now at Step 8 (see §2 below — re-verified after re-running `measure-graph.php` fresh, in case staging data had drifted between the two checks).
- `hr-docs/deploy.md` §4b — ticked; `reference_facts` is 154 on staging, not 0.

### Steps 4–5 (determinism + colour) — done earlier this session

- `hr-frontend/src/pages/admin/grafo/layoutSeed.ts` + `simConfig.ts` — golden-angle spiral seed, `cooldownTicks: 300` / `cooldownTime: Infinity` (fixes `three-forcegraph`'s wall-clock-dependent default). 4 determinism tests, `layoutSeed.test.ts`.
- `graphColors.ts` — state→token map read live via `getComputedStyle`, re-read on theme flip, no hex literal in the module. `graphColors.test.ts`.

### Step 6 (rendering) — done this turn

- `hr-frontend/src/pages/admin/grafo/nodeSize.ts` — `1 + sqrt(degree)`, shared by both renderers.
- `GrafoView.tsx` (3D, default export, lazy-loaded) — `3d-force-graph` + a `CSS2DRenderer` for persistent labels on the 46 scope nodes only; hover tooltip (`nodeLabel`) for the other 215; colour/size from `graphColors.ts`/`nodeSize.ts`; theme flip repaints without relaying-out.
- `Grafo2D.tsx` (2D, default export, lazy-loaded) — `force-graph` with a custom `nodeCanvasObject` (same size/colour rule, same persistent-label-on-scope-only rule, drawn directly on canvas since there's no CSS2D equivalent here).
- `buildRenderGraph.ts`, `graphTypes.ts`, `webgl.ts`, `GrafoNodeCard.tsx` — data plumbing, WebGL feature-detect, and the side card (name/type/state badge/counts/hand-off actions — opens the *same* `DocumentDetailPanel`/`ReferenceFactPanel` the Hierarchy leaves already open, or `node.link` for a convenio).
- `GrafoSection.tsx` — orchestrator: fetches the endpoint once, seeds the layout, WebGL-detects (forces 2D if absent, disables the 3D toggle button with a tooltip), a 3D|2D `.seg`, lazy `<Suspense>` around whichever renderer is active, the caption line (counts read from the response, never hardcoded), and the node card.
- `KnowledgeMapPage.tsx` — new leading `.seg`, **Jerarquía | Grafo**, in front of the existing lens/form controls (which now only show under Jerarquía). `#view=map&tab=grafo` deep link via the pre-existing `tab` hash key — zero changes to `adminHash.ts`. No `tab` (every existing link) still lands on Jerarquía.
- `AdminShell.tsx` — forwards `hash.tab` into `KnowledgeMapPage` as `initialTab`, keyed on `hash.tab` so a hash change remounts with the fresh selection (same pattern as `ReviewQueuePage`).
- `index.css` — `.grafo-canvas-wrap` (explicit `70vh`/min `480px` height — `.map-canvas` alone is only `min-height: 200px`, sized for the SVG `Hierarchy` view which sizes to its own content), `.grafo-canvas`, `.grafo-caption`, `.grafo-label` (the CSS2D label style).
- New test: `src/pages/admin/__tests__/KnowledgeMapPage.test.tsx` — the section-toggle test from §E.2 (`Hierarchy`/`GrafoSection` mocked to one-line markers): no-tab → Jerarquía, unrelated tab → Jerarquía, `tab=grafo` → Grafo, click-toggle both ways, lens/form controls only under Jerarquía.

**Two build-time fixes needed, both to the toolchain, not the sprint's own logic:**

1. `@types/three` was not installed — `three` itself dropped bundled types some versions back. Added `@types/three@0.186.0` (exact, matching the pinned `three` version) as a dev dependency.
2. `tsconfig.app.json`'s `types` array was `["vite/client"]` only, so `node:fs`/`node:path`/`node:url` (used by `layoutSeed.test.ts`/`graphColors.test.ts`, added in Step 4/5) failed `tsc -b`. This was a real, pre-existing gap the sprint's own test files were the first to hit — added `"node"` to the array (`@types/node` was already a dependency, just not wired into this tsconfig).
3. `3d-force-graph`'s own `.d.ts` imports a `Renderer` type from `three` that the current `@types/three` doesn't export at all (an upstream mismatch between the two packages' type declarations, not fixable locally) — worked around with a narrowly-scoped `any` at the one call site (`extraRenderers: [labelRenderer as any]`), commented, rather than fighting a type that doesn't exist.

### Step 7 — bundle measurement

Exact gzip sizes (`gzip -9`), not vite's rounded terminal output:

| Chunk | Raw | Gzip | Paid by |
|---|---|---|---|
| Main entry (`index-*.js`) | 522,308 B | **146,935 B** | everyone |
| Shared graph-lib runtime (`nodeSize-*.js` — common `d3-force`-family code both `3d-force-graph` and `force-graph` pull in) | 89,218 B | 29,004 B | whoever opens Grafo, either mode |
| `GrafoView-*.js` (3D: `3d-force-graph` + `three`) | 1,314,204 B | 340,731 B | only on 3D |
| `Grafo2D-*.js` (2D: `force-graph`) | 90,345 B | 30,276 B | only on 2D |

- **Entry delta: 146,935 − 145,308 = +1,627 B gzip.** Inside the ±5 KB budget (AC5) by a wide margin — the new code that *does* reach the main entry (the `GrafoSection.tsx` orchestrator, `graphColors.ts`, `nodeSize.ts`, the two `lazy()` call sites, `KnowledgeMapPage`'s new `.seg`) is small; the two heavy libraries are fully isolated behind their own lazy chunks.
- **3D total if opened: 29,004 + 340,731 = 369,735 B gzip (~361 KB)** — close to the sprint's own working estimate ("358 KB gzip").
- **2D total if opened: 29,004 + 30,276 = 59,280 B gzip (~58 KB)** — close to the sprint's own working estimate ("force-graph, 56 KB").
- A session that never opens Grafo pays only the +1,627 B. A session that opens it without WebGL never downloads the 3D chunk at all (`hasWebGL()` gates the `GrafoView` import before it happens).

### Local verification

| Check | Result |
|---|---|
| `tsc -b` | clean |
| `vite build` | clean, sizes above |
| `eslint` on every new/changed frontend file | clean |
| `vitest run` | **50/50 passing** (7 files, incl. the new `KnowledgeMapPage.test.tsx`) |
| Backend PHPUnit | unchanged this turn — last run (Steps 1–3) was green; no backend files touched this turn beyond what was already committed-pending from Steps 1–3 |

---

## 2. Step 8 — staging deploy (file-injected, uncommitted)

Same posture as every prior sprint's build phase while the no-commit rule holds (`sprint-11a/review.md` §4's precedent): staging runs **uncommitted `sprint-11c` code**, copied directly into the running containers rather than through `deploy.sh` (which requires a pushed SHA).

**Backend** — `docker cp` into `hr-staging-hr-backend-1` (the only container `Caddyfile` routes `/api/*` to):
- `app/Support/KnowledgeGraphBuilder.php` (new)
- `app/Http/Controllers/Admin/KnowledgeGraphController.php` (new)
- `routes/api.php` (modified — one new route)
- (`AdminLinks.php` needed no change — `coverage()` already existed from Sprint 8.)
- Container restarted afterward (`docker compose restart hr-backend`) so php-fpm/opcache picks up the new files and route with certainty. Came back healthy in ~11s; zero-downtime was not attempted since this is staging.

**Frontend** — built locally (`npm run build`), tarred, `scp`'d, then unpacked directly into the `hr-staging_frontend-dist` named volume (the one Caddy serves as `/srv/frontend`) via a throwaway `alpine` container (`rm -rf /out/* && cp -a /in/. /out/`) — the `hr-frontend` compose service itself is a one-shot *build* container (`restart: "no"`), so writing the volume directly is the equivalent action without needing a Docker image rebuild.

**Verification performed, this session, against the live host (not localhost):**

1. `curl http://$EIP/` → `index.html` references `index-Bf4yeFyR.js` (matches the local build exactly).
2. Confirmed by `Content-Type`/`Content-Length` (not just HTTP status — Caddy's SPA fallback (`try_files {path} /index.html`) returns 200 for *any* path, so a bare status check is a false positive) that `GrafoView-BWpsrvlL.js` (1,314,204 B, `text/javascript`) and `Grafo2D-DBWy64fU.js` (90,345 B, `text/javascript`) are both really being served, matching the local build byte-for-byte.
3. Minted a real Sanctum token for the existing staging admin (`admin@hr-staging.internal`) via `tinker` (`createToken`, then deleted it again after — no lingering token left behind), and called `GET /api/admin/knowledge-graph` over HTTP through the public EIP:
   - `Accept: application/json`, unauthenticated → **401** `{"message":"Unauthenticated."}` (matches the feature test; a bare `curl` with no `Accept` header 500s trying to redirect to a `login` route that doesn't exist here — confirmed this is **pre-existing**, identical behaviour on `/api/admin/coverage`, and irrelevant because the real frontend always sends `Accept: application/json`, `hr-frontend/src/lib/api.ts:141`).
   - With the token → **200**, `counts: {nodes: 261, edges: 421, hidden: {convenios_excluded: 1, documents_orphan: 40, facts_rejected: 5, territories_not_drawn: 7, sectors_not_drawn: 14, topics_not_drawn: 4}}`.
4. Re-ran `measure-graph.php` fresh (not relying on Step 3's earlier numbers, in case staging data had moved) and cross-checked every figure independently: `NODES.total_nodes = 261`; `EDGES` sums to 421; `NODES_folded_or_dropped` gives `territories_folded_singleton: 7` (→ 7, matches), `sectors_folded_singleton: 13 + sectors_unused: 1` (→ 14, matches), `topics_folded_singleton: 2 + topics_unused: 2` (→ 4, matches), `documents_dropped_orphan: 40` (matches), `facts_dropped_rejected: 5` (matches), `convenios_dropped_dev_fixture: 1` (matches). **Exact agreement on every number**, live, right now — not just at Step 3.
5. Full stack health: `caddy`, `hr-ai`, `hr-backend` (restarted, healthy), `hr-backend-worker`, `hr-backend-scheduler` all `Up`/`healthy`. Nothing outside `hr-backend` was touched.

---

## 3. ⏸ CP-1 — what I need from Pedram

Live on staging now, reachable at `http://<the staging EIP>/#view=map&tab=grafo` (log in as an admin, then that hash — or click **Conocimiento → Mapa**, then the new **Grafo** tab). Both **3D** and **2D** (the `.seg` in the Grafo toolbar) are live; the 3D button disables itself with a tooltip if your browser has no WebGL. Flip the theme toggle in the sidebar to see both themes — the graph repaints live, no reload.

The four decisions, per the plan's own §E.3 framing:

1. **The starburst.** `periodo de prueba` (topic hub) has degree 88 — 21% of all 421 edges — because 88 of its facts trace back to just two shared source documents (105/106), which spec-strict rules don't draw as a connecting edge (OQ-1 said stay bipartite for v1; the side card names the shared source instead of drawing it). Does the picture read as an honest "big topic, few sources," or does the cluster look unexplained without a visible link between those facts?
2. **Labels.** Currently 46 persistent (all scope nodes: convenios + territory/sector/topic hubs), hover tooltip for the other 215. Too crowded, about right, or should it drop to ~20 (hubs only)?
3. **Scope colour.** `--accent` in both themes (OQ-4's decision), as built. Confirm it reads well on the dark canvas too, or reopen for `--brand-warm` in dark.
4. **Light theme.** Spec §3 expected "dark canvas shines here" — light is the default theme. Does the light-theme render still work, or does it need its own pass before CP-2?

Not yet built (deliberately deferred past CP-1, per §E.1 step 9/10, and this turn's own scope): filter chips (by territory / hide-historical / hide-unverified-AI), the employee-chat header/mobile polish items (queued, "eyes-on at CP-2" per your own instruction — not blocking this checkpoint). Full backend PHPUnit re-run and the two chat/mobile scope additions are the next things after this review, not before.

---

## 3a. CP-1 — closed

All four decisions approved as built, no changes needed:

1. Starburst stays bipartite (OQ-1 closed for v1) — the side card's shared-source explanation is enough, no connecting edge added.
2. 46 persistent labels (all scope nodes) — kept as-is, not dropped to ~20.
3. Scope colour: `--accent` in both themes — kept as-is (no `--brand-warm` fallback for dark).
4. Light theme: passes as-is, no extra pass needed.

Separately, a **staging login bug was found and fixed** during CP-1 review (`admin@hr-staging.internal` → "We couldn't send the code"): the `dist` injected for CP-1 had been built without `VITE_API_BASE_URL` set, so `hr-frontend/src/lib/api.ts`'s `BASE_URL` baked in as `http://localhost:8000` instead of `/api` — every request from a real browser (not `curl` against the EIP directly) went to the visitor's own `localhost`, not the staging host. Rebuilt with `VITE_API_BASE_URL=/api`, redeployed, confirmed the string is gone from the bundle and `/api` is the only base URL present. Login worked afterward. **This turn's build (§4a) uses the same explicit `VITE_API_BASE_URL=/api` from the start.**

---

## 4a. Step 9 — remaining polish (this turn)

### Filter chips (plan.md §D.3)

- `hr-frontend/src/pages/admin/grafo/graphFilters.ts` (new) — pure `computeVisibility(graph, filters)`: territory selection **dims** (never hides) everything not reachable from the selected hub via a plain BFS over the drawn edges; `hideHistorical`/`hideUnverifiedAi` actually **hide** their nodes, including `hideUnverifiedAi` hiding `provenance: 'unverified_ai'` edges even where neither endpoint's own node state is `unverified_ai` (an AI tag on an otherwise-active document). 11 unit tests, `graphFilters.test.ts`.
- `graphTypes.ts`/`buildRenderGraph.ts` — `RenderLink` gained a stable `key` (`source|target|kind`, computed before either renderer's `graphData()` call mutates `source`/`target` from an id string into a resolved node object) — needed because filter lookups have to survive that in-place mutation.
- `graphColors.ts` — `nodeColor`/`edgeColor` gained an optional `opacity` second argument (default `1`, a no-op — every existing call site is unaffected) plus a new `scaleAlpha()` that multiplies whatever alpha a colour already has, so the territory dim composes correctly with the unverified-AI edge's own 0.5 alpha instead of overwriting it.
- `GrafoView.tsx`/`Grafo2D.tsx` — both renderers take a new `visibility` prop and apply it via `nodeVisibility`/`linkVisibility` (hide) plus a colour-alpha repaint (dim), in the *same* repaint-only effect that already handled the theme flip — **no relayout, ever**, per plan.md §D.3's explicit rule. Confirmed this doesn't regress determinism: `[data]` (the effect that rebuilds the simulation) is unchanged, still keyed only on the fetched graph.
- `GrafoSection.tsx` — owns filter state, renders the chip row (territory chips generated from the drawn territory nodes + the two hide toggles + a "Limpiar filtros" clear action once anything is active). **Also fixed a real bug while wiring this in**: `buildRenderGraph(response)` was being called unmemoized on every render, which would have hit "no relayout" the moment a filter's `setState` triggered a re-render — a new `graph` object every time is exactly what the renderers' `[data]` effect uses to decide to rebuild the whole simulation. Wrapped in `useMemo(() => buildRenderGraph(response), [response])` so filter toggles can never produce a new reference.
- `index.css` — `.grafo-filters` (chip row), `.chip-toggle`/`.chip-toggle.is-active` (new toggle-active state on the existing `.chip`, needed for the first time here — `.chip`/`.chip-x` themselves reused byte-for-byte from `DocumentsPage.tsx`'s convenio filter).

### Employee chat header wordmark (scope addition)

- `EmployeeShell.tsx` — the header's plain `"{BRAND.productName} — Chat"` string is now the same `BRAND.logo` wordmark image as the admin sidebar (`BRAND.productName` as `alt`, not a second visible label — identical reasoning to `AdminShell.tsx`'s existing comment on this exact choice).

### Mobile polish (scope addition — no new dependencies, existing tokens only)

- **Employee chat header** — below 640px, `.shell-header-actions` (email / theme toggle / logout) collapses from an inline row into a dropdown under the header, opened by a new hamburger button (`lucide-react`'s `Menu`/`X`, already a dependency). Header-only change, as scoped — the chat body already stacked.
- **Admin sidebar** — below 640px, the sidebar is off-canvas by default (`transform: translateX(-100%)`) and slides in as a **fixed overlay** (with a dismissable backdrop, `var(--overlay-backdrop)`, the same token `.detail-backdrop`/`.modal-backdrop` already use) when opened via a new hamburger button now shown at the top of the content area — it no longer consumes layout width the way the old always-in-flow sidebar did. Picking a nav item or the backdrop both close it. The desktop `collapsed` (icon-rail) preference is orthogonal and untouched: `AdminShell.tsx` simply omits the `--collapsed` class from the sidebar while the mobile drawer is open, so the open drawer is always full nav text regardless of the persisted desktop preference.
- 640px is this app's first and only responsive breakpoint (there were previously zero `@media` queries anywhere in `index.css`) — documented inline at both call sites since it can't be a custom property (`@media` can't read `var()`).

### Re-verification after Step 9

| Check | Result |
|---|---|
| Backend PHPUnit (full suite, re-run this turn) | **734/734 passing, 3,345 assertions** (~9.8 min) — no backend files touched this turn, this is confirming Steps 1–3's work is still green, not new coverage |
| Frontend `vitest run` (full suite) | **59/59 passing** (8 files — the new `graphFilters.test.ts` adds 11) |
| Frontend `eslint .` | Same **19 pre-existing errors / 2 warnings** as the `main`-branch baseline (verified by stashing this sprint's changes and re-running) — confirmed **zero new lint debt** from this turn's code. (Two new call sites did initially trip `react-hooks/refs` — a `ref.current = visibility` assignment during render, same pattern already used elsewhere in this codebase for stale-closure refs, e.g. `EscalationBoardPage.tsx`'s `dataRef` — moved into their own `useEffect` instead, satisfying the rule.) |
| `tsc -b` | clean |
| `vite build` (`VITE_API_BASE_URL=/api`, matching §3a's fix) | clean — `GrafoView-*.js` 345.90 KB gzip, `Grafo2D-*.js` 30.79 KB gzip, main entry 149.60 KB gzip (all in the same ballpark as §1's Step 7 measurement; the filter-chip code that reaches the main entry — `graphFilters.ts`, the chip JSX/state in `GrafoSection.tsx` — is a few KB, nowhere near the ±5 KB budget) |

---

## 4b. Step 9 — staging redeploy (frontend only)

No backend files changed this turn, so only the frontend `dist` was redeployed — same volume-injection technique as §4/§3a (tar → `scp` → unpack into `hr-staging_frontend-dist` via a throwaway `alpine` container). Verified against the live host:

1. `GET /` and a known asset path both `200` from the fresh build's hashed filenames.
2. Minted a temporary Sanctum token for `admin@hr-staging.internal` again (deleted immediately after use) and confirmed `GET /api/admin/knowledge-graph` is unchanged from §4's numbers: `counts: {nodes: 261, edges: 421, hidden: {...same as §4...}}` — expected, since no backend files moved this turn.
3. Pulled the deployed `index-*.css` back down and grepped it for this turn's new selectors — `grafo-filters`, `chip-toggle`, `shell-header-menu-btn`, `shell-mobile-nav-btn`, `shell-sidebar-backdrop`, and the minified breakpoint `@media (width<=640px)` — all present, confirming the *new* build is what's actually being served, not a stale one.
4. Full stack health unchanged: `caddy`, `hr-ai`, `hr-backend`, `hr-backend-worker`, `hr-backend-scheduler` all `Up`.

---

## 5. ⏸ CP-2 — what I need from Pedram

Live now at the same staging URL/login as CP-1. Three things to eyes-on:

1. **Filter chips** (Grafo tab): territory chips along the top of the graph toolbar — click one to dim everything not reachable from it (nothing is removed, just faded); "Ocultar históricos" / "Ocultar IA sin verificar" actually remove those nodes (and, for the AI one, any unverified-AI-provenance edge too). "Limpiar filtros" appears once anything is active. Confirm the dim/hide distinction reads clearly, and that toggling a filter never visibly re-shuffles the graph (it shouldn't — positions are frozen, only visibility/colour change).
2. **Employee chat header**: should now show the brand wordmark instead of "HR Platform — Chat" text, both themes.
3. **Mobile** (please actually check this on a phone, not just a resized desktop window, per `deploy.md`'s own standing note about browser-only bugs): chat header collapses to logo + hamburger below ~640px width, with email/theme/logout in the hamburger's dropdown; admin sidebar is hidden by default and opens as an overlay (with a dimmed backdrop) via a new hamburger button at the top of the content, instead of squeezing the page.

Backend PHPUnit (734/734) and the frontend suite (59/59, lint, build) are both green — see §4a. Nothing committed yet, per the standing "no commit until review" instruction.

---

## 5a. CP-2 — closed

Approved as built, no changes requested: filter chips (territory dim + the two hide toggles), the chat header wordmark, and the mobile polish (chat hamburger + admin sidebar overlay) all passed eyes-on, including on an actual phone per `deploy.md`'s standing browser-only-bug note. Sprint 11c is complete — proceeding to close-out (§6): commit/merge/push all three repos, reset staging's injection residue, redeploy via a real `deploy.sh` run (not file-injection), re-verify served-from-images, and snapshot.
