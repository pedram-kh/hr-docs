# Sprint 11b — Review notes

> Status: **CP-2 passed** (Pedram, 2026-09-23). Mass extraction complete
> (`PENDING_EXTRACTION_FILES = []`), `BACKEND_MESSAGE_MAP` (22 entries) +
> Intl sites wired, English pass against the approved glossary. **AC5
> deviation accepted at close:** entry gzip delta **+14.95 KB** vs the
> revised ≤14 KB budget (~1 KB over) — the eager Spanish dictionary at full
> corpus. Lazy `en-*.js` still holds. Close-out (commit/merge/push, staging
> reset, real `deploy.sh`, served-from-images verification, snapshot) follows
> the sprint-11c §6 convention and is recorded below once it lands.

---

## OQ-2 — backend-sourced strings: documented v1 limitation

Per plan.md §A.2, translating these is out of `hr-frontend`-only scope this
sprint. Recorded here as the permanent v1-limitation record, copied verbatim
from plan.md §A.2 (not re-measured — no backend code changed since planning):

| Category | Count | v1 disposition | Why not fixed this sprint |
|---|---|---|---|
| Custom English error messages (10 controllers, 34 sites) | 22 distinct strings | **Frontend-mapped** via `BACKEND_MESSAGE_MAP` (`hr-frontend/src/i18n/backendMessageMap.ts`, 22 exact EN→ES entries), consulted only when `locale === 'es'` | Done at CP-2 (§C.9 step 9). Dynamic `Unknown lens/vocabulary '…'` templates intentionally omitted (unmappable by exact match). |
| Custom Spanish error messages (`ConvenioGroupController` + 2 others) | 20 distinct strings | **Left raw, both locales** — reads correctly in `es`, wrong in `en` | Needs a stable `code` field per response (precedent: `email_change_confirmation_required`, `publish_blocked`) to map without fragile string matching — a `hr-backend` change |
| Dynamic passthrough (`$e->getMessage()` / `$err`) | 6 sites, 3 controllers | **Left raw, permanently, both locales** | Per-request dynamic content, unmappable by construction |
| Laravel's built-in English validation copy (bare `$request->validate([...])`, no custom message) | 16 controllers | **Left raw, both locales** | No `lang/es/validation.php` exists; `APP_LOCALE=en` (`config/app.php:81`). Needs the backend to either publish Spanish validation strings or branch on `Accept-Language`. |
| Generic network/HTTP fallback (`api.ts` request/upload) | 2 template strings | **Frontend-mapped** via `networkFallbackMessage` → `es.errors.*` / inline EN templates (EN copy kept out of a static `en` import so the lazy `en-*.js` chunk is preserved) | Done at CP-2 |

**Roadmap ticket (for whoever scopes the next backend sprint):**

> **Title:** Add stable error codes + Spanish validation copy for i18n
> **Why:** Sprint 11b (frontend i18n) found 20 Spanish-only custom error
> messages and all Laravel built-in validation copy render in English
> regardless of the UI's selected locale, because responses carry no
> `code` field to map by (only 2 of ~40+ error sites do:
> `email_change_confirmation_required`, `publish_blocked`) and no
> `lang/es/validation.php` exists.
> **Scope:**
> 1. Add a `code` field to every custom `response()->json(['message' => …])`
>    and `ValidationException::withMessages([...])` site (~54 sites across
>    11 controllers) so the frontend can map deterministically instead of
>    exact-string matching on English/Spanish text.
> 2. Publish `lang/es/validation.php` (Laravel's built-in rule messages)
>    and either set `APP_LOCALE` per-request from `Accept-Language`, or
>    have the frontend pass the locale and branch server-side.
> **Out of scope:** no `hr-frontend` changes needed once codes exist — the
> `BACKEND_MESSAGE_MAP` mechanism built in Sprint 11b already maps by
> string as an interim measure and can be swapped to map by code later.
> **Reference:** `hr-docs/sprints/sprint-11b/plan.md` §A.2.

---

## OQ-3 — lazy-load vs. bundle-both: measured outcome

**Chosen path: lazy-loading the non-default locale**, per the accepted
resolution. Verified against a real production build
(`npx vite build` in `hr-frontend`), gzip sizes as reported by Vite (not a
scratch probe this time — the real app, real Vite config, real code split):

| Build | Entry chunk (`index-*.js`) gzip | Delta vs. no-i18n baseline |
|---|---|---|
| Baseline (`git stash` — no Sprint 11b changes) | 149.60 KB | — |
| With Sprint 11b's CP-1 slice | 152.02 KB | **+2.42 KB gzip** |

`en.ts` is confirmed a separate, lazily-loaded chunk — `dist/assets/en-*.js`,
**5.29 KB raw / 2.36 KB gzip** — absent from the initial page load, fetched
once on first switch to English via `LocaleProvider`'s `import('./en')`
(11c's dynamic-import pattern), then cached in component state for the rest
of the session (no re-fetch on subsequent toggles).

**Result at CP-1:** entry delta +2.42 KB gzip, well under the revised AC5
budget of ≤14 KB — CP-1 slice only.

### OQ-3 update — CP-2 full-corpus re-measure

Same build command (`VITE_API_BASE_URL=/api npx vite build`), after mass
extraction + `BACKEND_MESSAGE_MAP` + Intl wiring:

| Build | Entry chunk (`index-*.js`) gzip | Delta vs. no-i18n baseline (149.60 KB) | `en-*.js` lazy chunk |
|---|---|---|---|
| CP-1 slice | 152.02 KB | **+2.42 KB** | 5.29 KB raw / 2.36 KB gzip |
| **CP-2 full corpus** | **164.55 KB** (`index-B_k-CXbP.js`, 590.49 KB raw) | **+14.95 KB gzip** | **53.88 KB raw / 18.99 KB gzip** (`en-BjUcAEI1.js`) |

**Honest AC5 comparison:** entry delta **+14.95 KB gzip** is **~1 KB over**
the revised ≤14 KB budget. **Accepted at close** (Pedram, 2026-09-23): the
overage is the eager Spanish dictionary at full corpus, not a lazy-split
failure. Lazy `en` still works (`index.html` does not reference `en-*.js`;
chunk is fetched on first switch). A mid-build regression was caught and
fixed: a static `import { en } from './en'` inside `backendMessageMap.ts`
collapsed the lazy split (Vite warning `INEFFECTIVE_DYNAMIC_IMPORT`); EN
network-fallback templates are now inlined in that module so `en.ts` stays
a separate chunk.

No friction observed switching to the lazy approach (the loading-state
flicker is handled by `LocaleProvider`'s `loadingLocale` flag, consumed by
`LocaleToggle` to disable itself mid-fetch) — the bundle-both fallback
described in the acceptance message was not needed.

---

## Staging injection record — CP-1, frontend-only round

Same posture and technique as `sprint-11c/review.md` §2/§4b (staging runs
**uncommitted `sprint-11b` code**, injected directly, not through
`deploy.sh` — that requires a pushed SHA, and the no-commit rule holds
until Pedram's review). **Frontend-only round, no backend files touched.**

**Connectivity:** `ssh -i ~/.hr-staging/hr-staging-ec2-key.pem
ubuntu@52.211.251.235` — reachable. `docker volume ls` confirmed the target
volume name: `hr-staging_frontend-dist`.

**1. Local build**, explicit `VITE_API_BASE_URL=/api` from the start (per
11c's CP-1 lesson — omitting it bakes `http://localhost:8000` into the
bundle instead):

```bash
cd hr-frontend
VITE_API_BASE_URL=/api npx vite build
```

Output: `index-eWIp0Ztf.js` (533.11 KB / 152.02 KB gzip), `en-C4lSwPq7.js`
(5.29 KB / 2.36 KB gzip, confirmed a separate chunk), `index-Bu8EjnkV.css`,
`Grafo2D-Da47W3fk.js`, `GrafoView-BbZUnQmj.js`, `nodeSize-BMyhgxU7.js`
(the last three unchanged 11c chunks, untouched this sprint).
`grep -r "localhost:8000" dist/` → no matches, confirming the env var took
effect (`api.ts:2`'s fallback was never triggered).

**2. Tar, `scp`, unpack into the named volume via a throwaway `alpine`
container** — the `hr-frontend` compose service is itself a one-shot build
container (`restart: "no"`), so writing the shared volume directly is the
equivalent action without a Docker image rebuild:

```bash
cd hr-frontend/dist && tar -czf /tmp/s11b-dist.tar.gz .
scp -i ~/.hr-staging/hr-staging-ec2-key.pem /tmp/s11b-dist.tar.gz \
  ubuntu@52.211.251.235:/tmp/s11b-dist.tar.gz
ssh -i ~/.hr-staging/hr-staging-ec2-key.pem ubuntu@52.211.251.235 '
  mkdir -p /tmp/s11b-dist-out && cd /tmp/s11b-dist-out && tar -xzf /tmp/s11b-dist.tar.gz &&
  docker run --rm -v /tmp/s11b-dist-out:/in -v hr-staging_frontend-dist:/out \
    alpine sh -c "rm -rf /out/* && cp -a /in/. /out/"
'
```

Unpacked clean; the volume-listing sanity check afterward showed
`en-C4lSwPq7.js` (5298 B), `index-eWIp0Ztf.js` (533119 B), and
`index-Bu8EjnkV.css` (42522 B) present in the volume, matching the local
build byte-for-byte.

**3. Verification against the live host (not localhost):**

| Check | Result |
|---|---|
| `curl http://52.211.251.235/` → `index.html` references | `index-eWIp0Ztf.js` + `index-Bu8EjnkV.css` — matches the local build exactly, and **critically, no `en-*.js` reference anywhere in the HTML** — confirms `en.ts` is genuinely lazy, not eagerly preloaded under a different mechanism |
| SHA-256 of served `index-eWIp0Ztf.js`, `en-C4lSwPq7.js`, `index-Bu8EjnkV.css` vs. local `dist/` copies | **Byte-identical**, all three files — `d36e5135…`, `3f135584…`, `3b01d6a0…` respectively, matched on both ends |
| `curl -I` on `en-C4lSwPq7.js` and `index-eWIp0Ztf.js` | Both `200`, `Content-Type: text/javascript; charset=utf-8` — confirms these are real served files, not Caddy's SPA `try_files` fallback (which would 200 with `text/html` for any path) |
| Full stack health, before and after | `hr-staging-caddy-1`, `hr-staging-hr-ai-1` (healthy), `hr-staging-hr-backend-1` (healthy), `hr-staging-hr-backend-worker-1`, `hr-staging-hr-backend-scheduler-1` all stayed `Up` throughout — no restart, no backend touch, consistent with a frontend-only round |

Temp files (`/tmp/s11b-dist.tar.gz`, `/tmp/s11b-dist-out`) cleaned up on
both the staging host and locally after verification.

**Live at:** `http://52.211.251.235/` — log in as an admin, the locale
toggle is in the sidebar footer (collapsed or expanded) and, for the
employee side, the chat header.

---

---

## Translation glossary — FINAL, approved (reference for all remaining translation)

> **Approved with two changes from the original proposal**, both applied
> below: **`vacaciones` → "annual leave"** (EU HR English, not "vacation" —
> Pedram's correction) and **`vigencia` → "validity" as the default noun**
> (per-site phrasing only where "validity" truly doesn't fit — narrower
> than originally proposed). The "validity" default has a direct in-code
> precedent found while updating this table: `DocumentDetailPanel.tsx:395`,
> `ReferenceFactPanel.tsx:247`, `DocumentsPage.tsx:207`, and
> `KnowledgeMapPage.tsx:14` all already render a `Facet`/column labeled
> exactly **"Validity"**, in English, unconditionally (a pre-i18n
> inconsistency this sprint inherits and now standardizes on rather than
> replaces).

Each term below was checked against real usage (`grep` across `hr-frontend`,
`hr-backend`, and the `hr-docs` data-pass artifacts where relevant), not
assumed. Your prior on `convenio` and on topic-name-as-data is adopted as
stated.

| Term | Decision | Reasoning |
|---|---|---|
| **convenio** | Keep **"convenio"** | Your prior, adopted: a specific Spanish legal instrument under the Estatuto de los Trabajadores; "collective agreement" obscures what it actually is. Already the live convention in the CP-1 slice: `colConvenio: 'Convenio'`, `filterConvenioAriaLabel: 'Filter by convenio'` (`en.ts:109,121`). |
| **dato (de referencia)** | Translate → **"reference fact"** (badge/label), **"fact"** (shorter contexts) | Not a new coinage — the codebase already names this concept in English internally: the component is `ReferenceFactPanel.tsx`, and one existing `title` attribute already reads "Structured reference fact" verbatim (`Hierarchy.tsx:99`). Also consolidates with **"hecho"** (used interchangeably with "dato" in the current Spanish UI, e.g. `FactDuplicatePanel.tsx`'s "Sustituye a una versión anterior... hecho anterior") — both map to the single English word "fact," which is correct, not a loss of nuance. |
| **escalación/escalado** | Translate → **"escalation"** / **"escalated"** | Standard HR/support terminology, no ambiguity. Already decided and shipped in the CP-1 slice: `t.adminShell.nav.escalaciones` → `en.ts:29` `'Escalations'`. |
| **vigencia** | Translate → **"validity"** as the **default noun** (e.g. `GroupsQueue.tsx:519`'s column header → "Validity", matching the exact existing precedent). **Per-site phrasing only where "validity" truly doesn't fit** — e.g. `FactDuplicatePanel.tsx:77`'s "la vigencia del hecho anterior se ha cerrado" reads better as "the previous fact's validity period has closed" than a bare noun-swap; still built from "validity," not a different word. | **Approved with a change**: "validity" is the default now, not context-dependent phrasing per site. Precedent: `DocumentDetailPanel.tsx:395`, `ReferenceFactPanel.tsx:247`, `DocumentsPage.tsx:207`, `KnowledgeMapPage.tsx:14` already all say exactly "Validity" in English today. |
| **ámbito** | Translate → **"scope"** | Already the codebase's own internal English word for this exact concept — `graphColors.ts:17`'s `NodeState = 'scope' \| 'active' \| 'draft' \| 'historical' \| 'verified' \| 'unverified_ai'`, used for the territory/sector/topic hub role. Extending existing naming, not inventing new. |
| **grupo profesional** | Translate → **"job group"** — *(flagged, not yet a real site)* | Measured: the exact phrase "grupo profesional" has **zero occurrences** anywhere in `hr-frontend`. The two real fields this likely refers to are already decided: `categoría profesional` → "Job category" (`jobCategoryLabel`, `en.ts:144`) and `grupo del convenio` → "Convenio group" (`convenioGroupLabel`, `en.ts:147`). Proposing "job group" only for if a literal site turns up during full extraction — not assuming it exists. |
| **categoría** | Translate → **"category"** / **"job category"** | Already decided and shipped: `colCategory: 'Category'` (`en.ts:123`), `jobCategoryLabel: 'Job category'` (`en.ts:144`). |
| **periodo de prueba** | Keep Spanish — **it's currently data, not chrome** | Measured: its only occurrence in this codebase's context is as a Grafo **topic-vocabulary name** (a hub label pulled from convenio content, `sprint-11c/review.md`'s starburst discussion) — same category as any other topic name, which your own prior already settles ("topic names shown as data stay Spanish everywhere"). If it ever appears as descriptive chrome prose (not seen in the current corpus), the standard English is "probationary period" — flagging the hypothetical, not deciding it now. |
| **jornada** | **Polysemous — two senses, already split in practice.** (1) "tipo de jornada" (full/part-time) → already decided **"Employment type"** (`employmentTypeLabel`, `en.ts:154`). (2) Standalone "jornada" meaning working hours/schedule (e.g. `ChatScreen.tsx:238`'s welcome text "jornada, vacaciones, permisos, festivos") → propose **"schedule"** | Sense (1) is settled and shipped. Sense (2) is prose-only so far, not yet extracted (`ChatScreen.tsx` is on the `PENDING_EXTRACTION_FILES` list) — proposing "schedule" for that sense specifically, distinct from "Employment type." |
| **permisos retribuidos** | Translate → **"paid leave"** | Standard, unambiguous English HR term, one-to-one correspondence. Currently prose-only (`ChatScreen.tsx:238`'s welcome text), not yet a topic-vocabulary name anywhere measured. |
| **vacaciones** | Translate → **"annual leave"** | **Approved with a change**: EU HR English convention (Pedram's correction), not "vacation" (which the original proposal picked to match `format.ts`'s `en-US` `Intl` locale tag — overridden here; the `Intl` locale tag itself is untouched, this only affects the dictionary word). |
| **festivos** | Translate → **"public holidays"** | Standard, unambiguous English HR term. |
| **verificado/sin verificar** | Translate → **"verified"** / **"unverified"** | Direct, unambiguous calque. Matches the existing English CSS class-naming convention already in the codebase (`badge-verified`, `BrandPreviewPage.tsx:180`) — the code already treats this as an English concept, chrome just hadn't caught up. |
| **cobertura** | Translate → **"coverage"** | Already decided and shipped: `t.adminShell.nav.cobertura` → `en.ts:32` `'Coverage'`. |
| **guardia/expectativa** | Keep Spanish — **it's data, never chrome** | Measured across `hr-frontend` and `hr-backend`: **zero occurrences** as UI chrome anywhere. Its only real occurrences are inside `group_label` and fact `value` content pulled verbatim from convenio source text during the Sprint 10c data pass (e.g. `"Personal en régimen de guardia o expectativa"`, `hr-docs/sprints/sprint-10c/triage/facts-export.json:867`). Same category as a topic name or any other backend-sourced content string — this sprint's i18n mechanism never touches it regardless of which way this glossary entry goes. |

**One structural note, not a term:** "dato" and "hecho" both collapsing to
the single English word "fact" (above) is the only place two distinct
Spanish source words become one English word. Flagging it explicitly in
case you'd rather keep a visible distinction (e.g. "fact" vs. "record") —
proposing the collapse because the current Spanish UI already uses them
interchangeably for the same underlying concept (a structured reference
fact), so no information is lost.

---

## What's not verified (outside this agent's reach)

- **Real deploy via `deploy.sh`.** This round is a direct file injection
  (identical posture to every prior sprint's pre-review checkpoints), not a
  committed-SHA deploy — expected until Pedram approves and this merges.
- **Manual switcher click in a real browser / full §6 eyes-on checklist.**
  Structurally verified (`LocaleToggle` in both shells, persistence via
  `LocaleProvider.test.tsx`, served bundle byte-verified live), but this
  agent has no browser — **Pedram walks spec §6 on staging for CP-2 sign-off**.

## CP-1 slice — what's built

- **Mechanism**: `hr-frontend/src/i18n/{context,LocaleProvider,LocaleToggle,format,es,en}.ts(x)` —
  hand-rolled typed `t()` (direct `Dict`-typed object access, no string
  keys), `Widen<typeof es>` for type-safe-but-loose English mirroring,
  `Intl.PluralRules`/`DateTimeFormat`/`NumberFormat` helpers.
- **Guards**: `noHardcodedStrings.test.ts` (R3 — AST-based, same scan logic
  as `count-strings.mjs`, with an auditable allowlist for `BrandPreviewPage.tsx`
  (OQ-1) and the 5 real CLI/permission-name literals in `AdminShell.tsx`,
  plus a `PENDING_EXTRACTION_FILES` list for the ~33 files not yet done);
  `protectedStrings.test.ts` (§2 — asserts none of the 8 fixture-listed
  backend constants' exact values appear in either dictionary file).
- **Extracted**: `AdminShell.tsx` (nav groups/labels, collapse/expand,
  logout, all view headings/descriptions) and `DirectoryPage.tsx` (full
  page + `EmployeeDrawer` sub-component, 53 matches per plan.md §A.1) — both
  locales, wired through `useT()`/`useLocale()`.
- **Switcher**: `LocaleToggle` in `AdminShell.tsx`'s sidebar footer and
  `EmployeeShell.tsx`'s header actions, `localStorage` key `hr-locale`,
  default resolution `localStorage → 'es'`.
- **Tests added**: `LocaleProvider.test.tsx`, `format.test.ts`, plus the two
  guard test files above — 94 total tests pass, 0 new lint errors, `tsc -b`
  clean.

---

## Staging injection record — CP-2, frontend-only round

Same technique as CP-1 (uncommitted `sprint-11b` code injected into
`hr-staging_frontend-dist`; no `deploy.sh`, no backend touch).

**1. Local build:** `VITE_API_BASE_URL=/api npx vite build`

Output: `index-B_k-CXbP.js` (590.49 KB / **164.55 KB gzip**),
`en-BjUcAEI1.js` (53.88 KB / **18.99 KB gzip**, separate lazy chunk),
`index-Bu8EjnkV.css` (unchanged hash from CP-1).
`grep -r "localhost:8000" dist/` → no matches.
`index.html` references only `index-B_k-CXbP.js` + CSS — **no `en-*.js`
preload**.

**2. Inject** (tar → scp → alpine copy into named volume) — volume listing
confirmed `en-BjUcAEI1.js` (53886 B) + `index-B_k-CXbP.js` (590490 B).

**3. Live verification:**

| Check | Result |
|---|---|
| `curl http://52.211.251.235/` → asset refs | `index-B_k-CXbP.js` + `index-Bu8EjnkV.css` only — lazy `en` confirmed |
| SHA-256 served vs local | **Byte-identical** — index `f404b3e8…`, en `6628f73c…`, css `3b01d6a0…` |
| `curl -I` on both JS chunks | Both `200`, `Content-Type: text/javascript; charset=utf-8` |
| Stack health | All five staging containers stayed `Up` / healthy — no restart |

**Live at:** `http://52.211.251.235/` — locale toggle in admin sidebar footer
and employee chat header; switch ES↔EN across both shells / both themes for
§6 eyes-on.

---

## CP-2 — mass extraction complete

### Suite
| Check | Result |
|---|---|
| `npx tsc -b` | clean |
| `npx vitest run` | **104/104** passed (12 files) |
| `PENDING_EXTRACTION_FILES` | **`[]`** — anti-rot guard asserts zero un-allowlisted bare strings |
| `npx eslint .` | pre-existing errors only (e.g. `HistoryPage` ref-during-render from Jun 2025, `AdminShell` hash→setState) — **0 new** from this sprint |
| `vite build` | clean; lazy `en` chunk restored after fixing static `en` import in `backendMessageMap.ts` |

### Landed since CP-1 (§C.9 steps 6–13)
- Full admin + employee + Grafo extraction into `es.ts` / `en.ts` (glossary:
  `vacaciones`→"annual leave", `vigencia`→"validity")
- `BACKEND_MESSAGE_MAP` — 22 exact EN→ES backend messages; `api.ts` wired
- `formatDate` / `formatPercent` / `Intl.NumberFormat` at remaining §B.8 sites
  (Analytics, History, Guardrails, DocumentDetailPanel, ReviewQueue)
- English pass — no `vacaciones`/`vigencia` left in `en.ts`; `annual leave` /
  `validity` used per glossary
- Bundle re-measure recorded above (entry **+14.95 KB gzip** vs baseline —
  ~1 KB over AC5 ≤14 KB; documented honestly)

### Spec §6 checklist for Pedram (eyes-on)
Walk on staging in both languages, both shells, both themes — switcher,
sidebar + major admin pages, employee chat welcome/input, Grafo toggles,
dates/% following locale.
