# Sprint 11b — Plan: Interface translations (ES/EN)

> Status: **PLAN GATE — no code, no commits.** Awaiting review.
> Spec: `hr-docs/sprints/sprint-11b/sprint-11b-spec.md`. Depends on 11a
> (`statusLabels.ts`, `FilterToolbar.tsx`, the welcome screen, the sidebar
> footer) and 11c (the Grafo module, the chat hamburger) — both closed and
> merged to `main`.
> Every count in §A is **measured**, not estimated: an AST-based scan
> (`hr-docs/sprints/sprint-11b/count-strings.mjs`, uses the TypeScript
> compiler API already in `hr-frontend`'s devDependencies — no new tooling to
> run it) plus a manual reconciliation pass over every file it touched, and a
> `grep`-based sweep of `hr-backend` for error/validation strings. Every
> bundle-size number in §B is a **real built Vite bundle** in a scratch
> directory (`/tmp/s11b-probe`, outside both repos, deleted after
> measuring), gzip'd with `gzip -9`, not read off a README. The raw script and
> its JSON output are left in this folder (§C, "artifacts"), same convention
> as `sprint-11c/measure-graph.php`.

---

## 0. Verdict up front, and three corrections found while measuring

**This sprint is buildable, but the spec's own AC5 (§5.5 — "locale
dictionaries add ≤15 KB gzip … no lazy loading needed, plan reports real
numbers") is very likely not achievable, on the real string count, regardless
of mechanism.** Measured: ~870 unique user-facing strings in `hr-frontend`
today; the Spanish dictionary alone gzips to **11.6 KB**; a same-size English
mirror brings the two-locale total to an estimated **~23 KB gzip** — before
counting the mechanism's own runtime code. This isn't a reason not to build;
it's a number Pedram needs before approving a budget that the corpus itself
already exceeds. Full derivation in §B.4.4.

Three corrections to the kickoff prompt / spec, found while measuring, in the
same spirit as 11a's ADR-citation correction and 11c's scale correction:

1. **"The fixed legal caveat and the Estatuto-fallback caveat" (ground
   rules) are one constant, described twice, not two.** `ChatService::
   FALLBACK_CAVEAT` (`hr-backend/app/Services/ChatService.php:86-90`) *is*
   the legal caveat (states the Estatuto is a legal minimum, a convenio may
   only improve on it) *and* is what's appended on the Estatuto fallback —
   same string, same call site (`ChatService.php:1756-1762`, the sole
   `decorate()` method). There is only one caveat constant in this codebase.
   Flagged only so the §2 guard test's fixture list (§A.3) isn't built
   expecting two.
2. **"Escalation explanation texts shown to employees" (spec §2) are the six
   `ChatService`/`SalaryAnswerService` `*_MESSAGE` constants, not
   `EscalationExplainer::registry()`.** `EscalationExplainer`'s long-form
   Spanish sentences (`asked`/`found`/`stopped_reason`/`fix_action`) render
   **only** in `EscalationCardDrawer.tsx` (`hr-frontend/src/pages/admin/`) —
   an admin-only surface. Confirmed by grep: `factsToSentences`/
   `explanation_text`/`explanation_facts` have zero references anywhere
   under `src/pages/chat/`. The employee-visible explanation texts are the
   fixed `*_MESSAGE` constants in §A.3 below.
3. **`react-i18next` alone costs more gzip than the spec's entire ≤15 KB
   budget, before a single dictionary entry is added** (measured §B.4.1) —
   this is the single fact that decides the mechanism recommendation in §B.4.

Five findings that shape the build order in §C.9:

1. **The corpus is bigger than "translate some labels."** 1,108 AST-matched
   string instances (870 unique after dedup) across 38 of 61 non-test source
   files — not a small tail-up sprint, a real extraction across nearly every
   admin page (§A.1).
2. **The codebase is already a Spanish/English mix today**, inconsistently —
   confirmed by the same grep that found the R2 cases: `KnowledgeMapPage.tsx`'s
   toolbar segments ("Territory", "Graph", "List") are English while the
   sidebar nav around them is Spanish (11c's plan already flagged this at
   `sprint-11c/plan.md:775`); `ReviewQueuePage.tsx`'s five tab labels
   ("AI tagging", "Reference facts", "Groups", …) are English; `DocumentsPage.tsx`
   list headers ("Title", "Type", "Flags") are English; `LoginPage.tsx` is
   entirely English; roughly half of `hr-backend`'s custom error messages are
   English, half Spanish, with no lang file governing either (§A.2). This
   sprint isn't just adding English — it's also fixing today's accidental
   Spanish/English mix into two deliberate, complete locales.
3. **The mechanism decision is not close.** Real measured bundle delta:
   `react-i18next`+`i18next` costs **+16.4 KB gzip**; a hand-rolled typed
   `t()` costs **+0.2 KB gzip** — for identical interpolation/pluralization
   behavior on the actual R2 cases found in this codebase (§B.4).
4. **The switcher has an exact, existing, one-line-different precedent to
   copy: `ThemeToggle`.** `hr-frontend/src/theme/ThemeToggle.tsx` is already
   a two-state footer toggle rendered in both shells' exact target locations
   (`AdminShell.tsx:272` sidebar footer, `EmployeeShell.tsx:26` header
   dropdown). A `LocaleToggle` is the same component shape, minus the
   deliberate no-persistence stance `ThemeProvider` takes (§B.7).
5. **The anti-rot guard has an exact existing precedent too, already proven
   out in 11c**: `layoutSeed.test.ts` already reads every file under one
   directory and asserts none matches a forbidden pattern
   (`/Math\.random|crypto\.getRandomValues|.../`, `sprint-11c/plan.md:643-653`).
   The R3 guard this sprint needs is the same technique with an allowlist
   instead of a total ban (§B.6) — not a new kind of test for this codebase.

---

## 1. How this was inspected

- **Frontend string count (§A.1):** AST-based, via `hr-docs/sprints/sprint-11b/count-strings.mjs`,
  which `require`s the `typescript` package already vendored into
  `hr-frontend/node_modules` (no install needed to reproduce — run it from
  inside `hr-frontend` so Node resolves the package: `node
  ../hr-docs/sprints/sprint-11b/count-strings.mjs src`). It parses every
  non-test `.ts`/`.tsx` file under `hr-frontend/src` into a real AST and
  counts four buckets (method documented in the script's own header comment
  and repeated in §A.1). Cross-checked by a manual pass: every file with a
  non-zero count was opened and read (not sampled) as part of writing this
  plan — the false positives the raw AST pass produced (icon glyphs,
  `method: 'POST'` fetch options, `'--accent'` CSS-token-name values used as
  *values* in `graphColors.ts`) were found this way and are excluded from the
  final number, not silently left in. The script and its JSON output are
  left in this folder.
- **Backend string inventory (§A.2, §A.3):** direct `grep` sweeps of
  `hr-backend/app` for every `response()->json(['message' => …])`,
  `ValidationException::withMessages`, and `public const *_MESSAGE`/`*_CAVEAT`
  site, each one opened and read (not just grep-matched) to get exact line
  ranges and to classify language.
- **Bundle sizes (§B.4):** real installs (`npm install react-i18next
  i18next` at their latest resolved versions, `i18next@26.4.2` /
  `react-i18next@17.0.15`) and real `vite build` runs (`vite@8.3.0`,
  `react@19.3.0` — close to, not identical to, `hr-frontend`'s pinned
  `vite@^8.0.12`/`react@^19.2.6`; the delta this measures is dominated by the
  library code, not by the React/Vite point version) in a scratch directory
  outside both repos, deleted after measuring. Three entry points (baseline
  React-only, `react-i18next`, hand-rolled) built separately; sizes are the
  actual emitted `dist/assets/*.js`, `gzip -9`'d, same method as
  `sprint-11c/plan.md` §B.1.
- **Every existing component this plan proposes extending** (`ThemeToggle`,
  `ThemeProvider`, `AdminShell`'s sidebar footer, `EmployeeShell`'s hamburger
  dropdown, `FilterToolbar`, `statusLabels.ts`, the Grafo module, `layoutSeed.test.ts`)
  was read in full, not summarized from the 11a/11c plans — citations below
  are against the code on the working tree, 2026-09-22.
- **No live staging check this pass.** Everything this plan needed is a
  static code fact (string literals, bundle sizes, file structure) with no
  live-only component, unlike 11a's CSP-header check or 11c's DB-row counts.
  If Pedram wants a staging walkthrough of *today's* mixed-language UI before
  approving the "both complete locales" target, that's cheap and folds into
  CP-1.

---

## A. The strings, measured

### A.1 Frontend user-facing string count — method, numbers, R2 cases

**Method.** AST-based (not grep + manual pass — the codebase is React/JSX,
and a plain-text grep can't distinguish `className="active"` from `>Activo<`
reliably at this scale). Four buckets, each scoped by AST node *shape*, not
by text pattern, so `className`/`href`/`key`/technical-enum-value strings are
excluded **by construction**, not by a denylist of words:

| Bucket | What it counts | Excluded by construction |
|---|---|---|
| `jsxText` | Rendered text between JSX tags. Sentences split by an inline `{expr}` (e.g. `<p>Hola {name}, bienvenido</p>`) are joined into **one** count per element, not one per AST text-node sibling — the naive per-node count over-counted split sentences by ~150 before this fix. | `className`, all other attribute names |
| `jsxAttr` | String literals on exactly four attributes: `aria-label`, `alt`, `title`, `placeholder`. | Every other JSX attribute (`href`, `src`, `key`, `type`, `role`, `data-*`, …) |
| `objectLiteral` | String literals that are the *value* of an object-literal property whose *key* isn't a closed technical-key list (`id`/`type`/`className`/`method`/`headers`/…) — this is how label maps (`STATE_BADGE`, `SUB_OUTCOME_LABELS`, `TYPE_LABEL`) surface. A value matching a CSS custom-property name (`--accent`) is excluded unconditionally regardless of key (found in `graphColors.ts`, §0 corrections). | Object *keys*; enum-like bare single-word values with no accent and no space (`'active'`, `'draft'`) *unless* an accented character proves it's prose even under a technical-looking key (the `SUB_OUTCOME_LABELS` case, keyed by `reason.sub_outcome` enum strings) |
| `templateOrCall` | String/template literals nested anywhere inside a call to `setError`/`setErr`/`new Error(...)` — the `err instanceof ApiError ? err.message : 'fallback text'` pattern, walked past the `ConditionalExpression` to find the literal. | `String(e)` fallbacks (no literal — see §A.2) |

**Known limitations, stated plainly:** array-literal string elements are not
scanned (by design — the one real case, `SUGGESTED_QUESTIONS: string[]`, is
§2-protected content, not chrome, so *not* scanning it is correct; a sweep
found no other frontend string array that renders as chrome, but this is a
gap in the tool, not a proof of zero). A manual spot-check of the 81
shortest matches (≤5 chars) found ~6 residual false positives out of 787
`jsxText` matches (a stray CSS-fragment split, an empty `«»`, a lone `…`) —
call it **~99% precision** on this heuristic, not 100%.

**Grand total: 1,108 AST-matched string instances (870 unique after
dedup)** across 38 of 61 non-test `.ts`/`.tsx` files.

**Per-area breakdown** (per the kickoff's explicit list):

| Area | Files with ≥1 match | Total instances |
|---|---|---|
| Admin pages (`pages/admin/*.tsx`, excl. Grafo) | 25 of 27 | **920** |
| 11a's shared modules (`statusLabels.ts` 60, `AdminShell.tsx` 34, `escalationReasons.ts` 11, `FilterToolbar.tsx` 2) | 4 | **107** |
| Employee shell (`pages/chat/*`, `EmployeeShell.tsx`) | 4 of 4 | **37** |
| 11c's Grafo module (`pages/admin/grafo/*`) | 2 of 12 | **32** |
| Everything else (`LoginPage.tsx`, `theme/brand.ts`, `ProtectedRoute.tsx`) | 3 | **12** |
| **Total** | **38 / 61** | **1,108** |

Notes on this split:
- **11a's welcome screen contributes 0 chrome strings of its own** beyond
  what's already inside `ChatScreen.tsx`'s 14 (the `WelcomeScreen` wrapper
  paragraph and the "Preguntas frecuentes" `aria-label`) — `SUGGESTED_QUESTIONS`
  itself is §2-protected content (spec §2, "stays Spanish-only in v1"), not
  chrome, correctly excluded (§A.3).
- **The Grafo module's 32 is a floor, not the true Grafo-attributable
  count**: `KnowledgeMapPage.tsx`'s 14 matches (counted under "admin pages"
  above) include the shared `Jerarquía | Grafo` section toggle and the `3D |
  2D` mode toggle that only exists because Grafo exists — a few of those 14
  are really Grafo's, just physically inside the page that hosts both
  sections. `GrafoView.tsx`/`Grafo2D.tsx` (the two canvas renderers) score
  **zero** — confirmed correct by reading both files: every visible string
  in the Grafo section (mode buttons, filter chips, the caption, the node
  card) lives in `GrafoSection.tsx`/`GrafoNodeCard.tsx`, not the renderers.
- **`BrandPreviewPage.tsx` (79 matches) is 11a's internal CP-1 preview
  tool**, reachable only via `#view=brand-preview`, never in the rendered
  nav (`AdminShell.tsx`'s `VALID_VIEWS`, confirmed no `navBtn('brand-preview', …)`
  call exists). Every token/color-name label on it ("Accent (Verde Sedena)",
  "Accent hover") is real UI text an admin could see, so it's counted, but
  it's the single best candidate to **explicitly deprioritize** if the
  extraction needs to be sequenced by user-facing impact — flagged as **OQ-1**.

**R2 cases found (interpolation / concatenation / naive plurals) —** every
one of these needs more than a flat `t('key')` call:

| File:line | Pattern | Kind |
|---|---|---|
| `DocumentsPage.tsx:111` | `` `Ingesting ${files.length} file(s)…` `` | naive plural ("file(s)"), English |
| `DocumentsPage.tsx:118` | `` `Ingested ${a}, skipped ${b}, failed ${c}.` `` | triple interpolation, English |
| `HistoryPage.tsx:267` | `` `${years} año(s) (desde ${date})` `` | naive plural ("año(s)"), Spanish |
| `ThemeToggle.tsx:14` | `` `Switch to ${next} theme` `` | interpolation, English (the exact component the locale switcher copies — §B.7) |
| `ChatScreen.tsx:104` | `` `Basado en: ${sourceLabels.join(', ')}.` `` | chrome prefix + §2-adjacent server data (source labels), not itself protected but wraps something that is |
| `ChatScreen.tsx:220` | `` ` (grupo ${category.group_code})` `` | chrome suffix + data |
| `ChatScreen.tsx:342` | `` `Mi categoría: ${category.name}` `` | chrome prefix + data, echoed as a user-turn bubble |
| `ReviewQueuePage.tsx:365` | `` ` · from "${source_document.title}"` `` | chrome ("from", English) + data |
| `ReviewQueuePage.tsx:546` | `` `Linked successor${retire ? ' and retired the old document.' : ' (old document kept active).'}` `` | ternary concatenation, English |
| `AnalyticsPage.tsx:70` | `` `${up}👍 · ${down}👎 (§7, opcional)` `` | chrome + data + a literal "§7" reference |
| `DirectoryPage.tsx:449` | `` `Revisado por última vez el ${date.slice(0,10)}.` `` | chrome + a **raw-sliced date, not `Intl`-formatted** — also an §B.8 item |
| `EscalationCardDrawer.tsx:138` | `` aria-label={`Escalación · ${card.reason_label}`} `` | chrome + already-Spanish server label |
| `GrafoNodeCard.tsx:64` | `` Object.entries(counts).map(([k,v]) => `${k}: ${v}`).join(' · ') `` | dynamic key/value pairs — the *keys* here are already-English object property names (`documents`, `facts`) rendered raw; needs its own small key→label map, not a `t()` call per key |
| `GrafoNodeCard.tsx:70-78` | `` `Territorio: ${folded.territory} (sin hub propio — muy pocos convenios)` `` | chrome + data, two variants (territory/sector) |

### A.2 Backend-sourced strings reaching the UI as chrome

Three distinct categories, all confirmed real by reading (not just
grep-matching) every site:

**1. Custom inline error messages** — `response()->json(['message' => …], $code)`
across 10 controllers, 34 sites, plus `ValidationException::withMessages([...])`
across 1 controller (`ConvenioGroupController`), 15 sites. Read every one;
language is genuinely inconsistent, no `lang/`/`resources/lang` directory
exists anywhere in `hr-backend` to govern either:

| Language | Count | Example | Files |
|---|---|---|---|
| English | 22 | `'Invalid or expired code.'` (`AuthController.php:106,176`), `'This expiry task is already resolved.'` (`ReviewQueueController.php:106,174`) | `AuthController`, `ChatController`, `VocabularyController`, `ReviewQueueController`, `DocumentController`, `HierarchyController`, `QualitySampleController` (partial), `ReferenceFactController` (1 of 4) |
| Spanish | 20 | `'Este hecho no tiene un duplicado marcado.'` (`ReferenceFactController.php:318,358`), 15 `ConvenioGroupController` `withMessages` entries | `ConvenioGroupController`, `EmployeeDirectoryController`, `ReferenceFactController` (3 of 4) |
| Dynamic passthrough (`$e->getMessage()` / `$err`) — language depends on what threw it, not fixed at the call site | 6 | `VocabularyProposalController.php:120,144,159`, `QualitySampleController.php:172`, `ReferenceFactController.php:143,188` | 3 controllers |

**2. Laravel's own built-in validation copy** — 16 controllers call bare
`$request->validate([...])` with no custom message override
(`AuthController.php:43,81`, `AdminController.php:42,68,92`, and 12 more).
On a failed rule (`required`, `email`, `max`, …), Laravel's
`ValidationException` response is `{"message": "<first error>", "errors":
{...}}`, where `<first error>` is the framework's **built-in English**
validation-language string (`config('app.locale')` = `env('APP_LOCALE',
'en')`, confirmed `config/app.php:81` — no `lang/es/validation.php` exists to
override it). `hr-frontend`'s `ApiError` only ever reads `data.message`
(`api.ts:156`), so any bare-validated field failure surfaces English text to
an admin regardless of anything this sprint does to the frontend.

**3. Generic network/HTTP fallback** — `api.ts:156`, `` `Request failed
(${res.status})` `` when the backend returns no JSON body at all (English);
`api.ts:1377,1678`, `` `Upload failed (${res.status})` ``.

**Proposed v1 disposition, per the spec's own instruction ("translate
frontend-side where mapped, leave raw where not — listed, not hidden")**:

| Category | v1 disposition |
|---|---|
| The 22 English custom messages | **Frontend-mapped.** A `BACKEND_MESSAGE_MAP: Record<string, string>` keyed by the exact English source string (case-sensitive, exact match — fragile by construction, documented as such) → the ES translation, consulted only when `locale === 'es'`. When the locale is `'en'`, the raw string already *is* the English original, so no map lookup is needed in that direction. |
| The 20 Spanish custom messages | **Left raw in both locales — listed as a gap, not hidden.** These already read correctly when `locale === 'es'`; when `locale === 'en'`, they render in Spanish. Fixing this properly means the backend adding a stable `code` field to each response (the two existing precedents, `body?.code === 'email_change_confirmation_required'` and `'publish_blocked'`, prove the pattern already exists for a couple of 409s) so the frontend can map by code, not by fragile exact-string English/Spanish matching. That's a `hr-backend` change and explicitly **out of this sprint's `hr-frontend`-only scope** — recorded as **OQ-2** for whoever scopes that follow-up. |
| Dynamic passthrough (6 sites) | **Left raw, permanently, both locales.** Content is per-request dynamic (an exception message), unmappable by construction. |
| Laravel's built-in English validation copy (16 controllers) | **Left raw, both locales, this sprint.** The correct fix (publish `lang/es/validation.php`, set `APP_LOCALE=es` or branch on the request's `Accept-Language`) is a `hr-backend` change, same reasoning as above — **OQ-2**. |
| Generic network fallback (`api.ts`) | **Frontend-mapped** — these three strings live in `hr-frontend` already, trivial to route through `t()` like any other frontend chrome string. |

### A.3 §2 protected strings — exact locations (the guard test's fixture list)

All in `hr-backend`, all `public const` (immutable, grep-friendly), all
already documented in-source as deliberately-fixed, deterministic strings —
confirmed **none** currently pass through any i18n-adjacent code path (they
are appended to `$employeeAnswer` post-synthesis, `ChatService.php:1756-1762`,
never touched by anything `hr-frontend` renders as chrome):

| Constant | File:line | Shown when |
|---|---|---|
| `FALLBACK_CAVEAT` (the "legal caveat" / "Estatuto-fallback caveat" — one constant, §0 correction 1) | `ChatService.php:86-90` | Appended to any answer built on the Estatuto fallback (`decorate()`, `:1756-1762`) |
| `ESCALATION_MESSAGE` | `ChatService.php:46-47` | *(Historical/internal — superseded by `EMPLOYEE_ESCALATION_MESSAGE` per the in-source comment at `:49-60`; still a `public const`, still worth fixture-listing in case any code path still reads it)* |
| `EMPLOYEE_ESCALATION_MESSAGE` | `ChatService.php:62-63` | The ONE message shown on every escalation, regardless of reason (`persistTurn()` overrides all others with this, per the in-source comment) |
| `AGGREGATION_MESSAGE` | `ChatService.php:93-97` | A vague "total días libres" aggregation |
| `CROSSPATH_MESSAGE` | `ChatService.php:100-103` | A salary+prose compound question |
| `COMPOSITION_CONFLICT_MESSAGE` | `ChatService.php:111-114` | A fact-vs-convenio same-point conflict |
| `STATUTORY_SALARY_MESSAGE` | `ChatService.php:123-126` | A statutory (SMI) salary figure asked as if it were a convenio table cell |
| `SalaryAnswerService::COVERAGE_GAP_MESSAGE` | `SalaryAnswerService.php:54-56` | No salary row for the convenio/year/category |
| `SUGGESTED_QUESTIONS` (frontend, but content — spec §2's explicit welcome-questions carve-out) | `hr-frontend/src/lib/suggestedQuestions.ts:9-15` | The chat empty-state welcome chips — stay Spanish-only regardless of UI locale (spec §2) |

**Related but explicitly out of scope, not fixture-listed:** the
admin-configurable off-domain refusal copy (`ChatService.php:219,320`,
"admin-configurable … Sprint 6" per the in-source comment) is DB-stored
admin-authored content, not a code constant — already outside both "chrome"
and "code" by construction, nothing for a guard test to pin.

**The guard test itself (§C.9, test inventory):** assert that grepping the
full text of `ChatService.php` and `SalaryAnswerService.php` for any of
these eight constants' *values* finds them **only** inside their own
`public const` declaration — i.e., nothing in `hr-frontend`'s new dictionary
files contains any of these eight strings verbatim. This is a stronger,
more mechanical version of "assert no i18n wrapping" — it catches a
copy-paste into a dictionary just as surely as a `t()`-wrapping attempt.

---

## B. The mechanism

### B.4 `react-i18next` vs a hand-rolled typed `t()` — measured

#### B.4.1 Bundle cost — real builds, real gzip

Three Vite entries, same React/Vite major versions as `hr-frontend`
(`react@19.3.0` vs pinned `^19.2.6`, `vite@8.3.0` vs pinned `^8.0.12` — close
enough that the *delta* below, which is what matters, isn't materially
affected by the point-version gap):

| Entry | Raw JS | Gzip | Delta over baseline |
|---|---|---|---|
| Baseline (React + ReactDOM + one `useState` button, no i18n) | 221,305 B | 68,813 B | — |
| `react-i18next@17.0.15` + `i18next@26.4.2` — real `init()`, 2 locale resources, one interpolated + one pluralized key, one `useTranslation()` call | 272,411 B | 85,654 B | **+16,841 B (+16.4 KB)** |
| Hand-rolled `t()` — typed dictionary object, one `useLocale` hook, `Intl.PluralRules` for pluralization (a real platform API, not a naive `count===1` check — fair fight against i18next's own plural engine) | 221,721 B | 69,023 B | **+210 B (+0.2 KB)** |

**`react-i18next`'s own runtime, before a single dictionary string is added,
already costs more than the spec's entire ≤15 KB budget for two full
locales.** The hand-rolled candidate's library-shaped overhead is close to
the noise floor of the measurement itself.

#### B.4.2 Type safety

- **Hand-rolled: missing key is a compile error, for free, with zero setup.**
  A dictionary typed as `Record<DictKey, string>` (or, tighter, a literal
  object with `as const` and `satisfies`) makes `t('typo')` a TypeScript
  error at the call site — this is just how TypeScript objects already
  work, no library-specific configuration.
- **`react-i18next`: the same guarantee requires opt-in setup** — declaring
  a `CustomTypeOptions` module augmentation (an `i18next.d.ts` with
  `interface CustomTypeOptions { resources: typeof esResources }`) that this
  codebase would have to write and maintain itself. Without it, `t('typo')`
  compiles cleanly and fails silently at runtime (falls back to the raw
  key). It's achievable, but it's a second thing to set up and keep in sync
  with the dictionary — the hand-rolled route gets the same property from
  the type system it's already using everywhere else in this codebase.

#### B.4.3 Interpolation / plural support for the real R2 cases (§A.1)

The R2 list has exactly **two** genuine plural cases (`file(s)`, `año(s)`)
and a handful of prefix/suffix interpolations (`Switch to {locale}`,
`Basado en: {labels}`, `{n} elemento(s)`). Both libraries handle this; the
question is fit-for-purpose, not capability:

- **`i18next`'s pluralization** is a full CLDR plural-category engine
  (`_one`/`_two`/`_few`/`_many`/`_other` keys) — built for languages with
  complex plural rules (Arabic, Polish). Spanish and English both have the
  simplest CLDR plural shape (`one`/`other`, singular vs. everything else) —
  the CLDR engine's extra categories are dead weight for this corpus.
- **The hand-rolled candidate's plural helper is `Intl.PluralRules`** — a
  browser built-in, zero bytes shipped, and it returns the *same*
  `one`/`other` categories Spanish/English actually need. Interpolation is
  plain template-literal string substitution — the exact idiom every R2 case
  in §A.1 already uses today (`` `${a}, ${b}` ``-style), so extraction
  doesn't even change the *shape* of these call sites, just wraps the static
  parts in `t()`.

#### B.4.4 Dictionary size — the number that actually threatens AC5

Deduplicated across the 870 unique strings found in §A.1 (many repeat —
"Verificado"/"Aprobado"/"Rechazado" appear in a dozen different label maps
and collapse to one dictionary key each):

| | Value |
|---|---|
| Unique strings | 870 |
| Raw characters (Spanish values only) | 26,160 |
| Gzip, Spanish values alone, as one blob | **11,611 B (11.6 KB)** |
| Estimated two-locale total (ES + a same-shape EN mirror) | **~23.2 KB gzip** |

This is a **floor**, not a ceiling — it counts only the string *values*,
joined by newlines; it does not count the object *keys* every real
dictionary entry needs (`admin.review.tabs.taggingLabel: '...'`), which add
real bytes even though they compress well (short, repetitive identifiers).
**The two-locale dictionary content alone is already ~1.5x the spec's ≤15 KB
budget, before any mechanism's runtime code is added on top.** This is
**OQ-3**: either AC5's number needs revising with Pedram's sign-off, or the
extraction pass needs to actively hunt for consolidation (shared strings
across pages already collapse to one key by construction — the number above
already reflects that; further reduction would mean cutting real copy, not
a technical trick).

#### B.4.5 Recommendation: **hand-rolled typed `t()`.** Reasons, weighed:

1. **Bundle: decisive.** +0.2 KB vs +16.4 KB, and the 16.4 KB is spent before
   the (already-over-budget, §B.4.4) dictionary itself is added. At two
   locales with no RTL and no lazy-loading — precisely the ceiling the spec
   itself draws — `react-i18next`'s value-add (CLDR pluralization, RTL
   support, lazy namespace loading, a plugin ecosystem) is capability this
   sprint has no use for, purchased at a real, measured cost.
2. **Type safety: free vs. requires new setup to match.**
3. **R2 fit: exact match at zero marginal cost** (`Intl.PluralRules`,
   already-idiomatic template literals).
4. **Consistent with this codebase's own standing preference**: prefer a
   small owned module over a dependency when the dependency's differentiated
   capability isn't needed at the actual scale — the same reasoning 11c used
   the *other* direction (recommending `3d-force-graph`/`three.js` over
   hand-rolling because a 3D force-directed layout engine genuinely is a
   large surface to own). Translating ~900 short strings between two
   Latin-alphabet languages with matching plural shape is categorically
   smaller than the thing 11c correctly chose *not* to hand-roll.

### B.5 Dictionary layout

**One file pair, `es.ts` (source of truth) and `en.ts` (human-reviewed
mirror)**, per the spec's own explicit ask ("a single `en.ts` a human can
read top to bottom") — not per-page or per-shell files, which would scatter
~900 keys across 30+ files and defeat exactly the top-to-bottom review the
spec asks for. Internally namespaced by area (nested objects, not a flat
900-key list), mirroring the per-area breakdown in §A.1 so the file's own
structure documents where each string is used:

```ts
// hr-frontend/src/i18n/es.ts — SOURCE OF TRUTH
export const es = {
  common: { save: 'Guardar', cancel: 'Cancelar', clearFilters: 'Limpiar filtros', /* … */ },
  admin: {
    sidebar: { /* nav group/item labels — AdminShell.tsx */ },
    documents: { /* DocumentsPage.tsx, DocumentDetailPanel.tsx */ },
    review: { /* ReviewQueuePage.tsx's 5 tabs */ },
    escalations: { /* EscalationBoardPage.tsx, EscalationCardDrawer.tsx */ },
    groups: { /* GroupsQueue.tsx */ },
    directory: { /* DirectoryPage.tsx */ },
    history: { /* HistoryPage.tsx */ },
    analytics: { /* AnalyticsPage.tsx */ },
    coverage: { /* CoveragePage.tsx */ },
    quality: { /* QualitySampleQueue.tsx */ },
    guardrails: { /* GuardrailsPage.tsx */ },
    answerModel: { /* AnswerModelPage.tsx */ },
    grafo: { /* GrafoSection.tsx, GrafoNodeCard.tsx */ },
  },
  chat: { /* ChatScreen.tsx, TracePanel.tsx, CitationList.tsx */ },
  login: { /* LoginPage.tsx */ },
  errors: { /* the §A.2 BACKEND_MESSAGE_MAP-adjacent frontend fallbacks */ },
} as const;
```

```ts
// hr-frontend/src/i18n/en.ts
import type { es } from './es';
export const en: typeof es = { /* same shape, English values — a missing/extra key is a TS error via `typeof es` */ };
```

`en.ts`'s `: typeof es` annotation is the whole type-safety mechanism from
§B.4.2 — no library, no build step, no codegen. A human reviewing "English"
opens exactly one file and reads it top to bottom, exactly as the spec asks.

### B.6 The anti-rot guard (R3)

**Recommendation: a Vitest guard test, not a custom ESLint rule** — this
codebase's own dominant enforcement idiom is a source-reading test
(`EscalationReasonLabelCoverageTest`, `EscalationExplainerGuardTest`,
`statusLabels.test.ts`), and `sprint-11c`'s `layoutSeed.test.ts` already
proved the *exact* technique this guard needs: read every file's real
source text and assert it contains no forbidden pattern
(`sprint-11c/plan.md:643-653`, `/Math\.random|crypto\.getRandomValues|.../`).
An ESLint rule is a legitimate alternative (`eslint.config.js` is a flat
config; a local rule is addable inline), but it's a second enforcement
mechanism this codebase doesn't otherwise use, for no capability gain over
the pattern it already trusts.

**Proposed test**, `hr-frontend/src/i18n/__tests__/noHardcodedStrings.test.ts`:
reuse `count-strings.mjs`'s exact AST walk (promoted from a one-off
measurement script into permanent test code, same graduation
`layoutSeed.ts`'s golden-angle math went through) as a **regression** check,
not an extraction tool — after the extraction area lands, every real
`jsxText`/`jsxAttr`/`objectLiteral` match it finds should be inside a
`t(...)` call (i.e., the match is a `CallExpression` to `t`, not a bare
string literal). Any bare literal it finds after that point is either a new
violation or belongs on the allowlist.

**Allowlist mechanism**: an explicit, named, exported array —
`ALLOWED_HARDCODED_STRINGS: Array<{ file: string; text: string; reason: string }>`
in the test file itself — not a magic `// i18n-allow` comment. This matches
the codebase's existing preference for auditable named lists over inline
suppression (the same shape as `SUB_OUTCOME_LABELS`, `ESCALATION_REASON_LABELS`):
every exception is visible in one place, in a diff, with a stated reason,
rather than scattered as comments a reviewer has to go find. Expected
initial contents: the §A.3 protected strings (already excluded by never
appearing in `hr-frontend` at all, so likely an empty entry for them, kept as
documentation), a handful of `GrafoNodeCard.tsx`'s dynamic key-fragments
(§A.1's `${k}: ${v}` case) until that gets its own key→label map, and
nothing else expected — a non-trivial allowlist growing over time is itself
the signal that the guard is doing its job.

### B.7 Locale switcher

**Exact placement — copy `ThemeToggle`'s existing shape, don't invent a new
one.** `ThemeToggle` (`hr-frontend/src/theme/ThemeToggle.tsx`) already
renders in both exact target locations the spec names:

| Surface | Citation | What's there today |
|---|---|---|
| Admin sidebar footer | `AdminShell.tsx:267-283` (`.shell-sidebar-footer`), `ThemeToggle` at `:272` | User email row, `ThemeToggle`, "Log out" — all styled `.shell-nav-item` |
| Chat hamburger dropdown (11c's mobile header) | `EmployeeShell.tsx:22-30` (`.shell-header-actions`, shown via `menuOpen`, `EmployeeShell.tsx:18,24`) | Email, `ThemeToggle`, "Log out" |

**Proposal:** a new `LocaleToggle` component, structurally identical to
`ThemeToggle` (same `btn btn-ghost` + `aria-label` + `data-tooltip` shape,
same `className` passthrough prop for the sidebar's `.shell-nav-item`
styling), rendered directly beside `ThemeToggle` in both files — one new
line each, `<LocaleToggle className="shell-nav-item" />` in `AdminShell.tsx`
next to `:272`, `<LocaleToggle />` in `EmployeeShell.tsx` next to `:26`. Not
a dropdown (2 locales, no RTL — a binary toggle is the correct control, same
reasoning `ThemeToggle` already applies to light/dark).

**State/persistence — new `LocaleProvider`, modeled on `ThemeProvider` but
*with* persistence** (deliberately diverging from `ThemeProvider`'s
no-persistence stance, which is a documented, specific decision for theme
— `ThemeProvider.tsx:5-7`, "Theme is held in memory only… Persistence is
deferred to the app's own settings later — no localStorage/sessionStorage
here" —
not a general rule against persisting UI preference; the sidebar-collapse
state already persists via `localStorage` for exactly this reason,
`AdminShell.tsx:84-92`, so there's already a second precedent for "some UI
preferences persist, theme specifically doesn't yet"):

```ts
// hr-frontend/src/i18n/LocaleProvider.tsx
const LOCALE_KEY = 'hr-locale'; // one global key — a user's language choice
                                  // follows them between /admin and /app, unlike
                                  // sidebar-collapse which is admin-shell-only layout state
function initialLocale(): Locale {
  try {
    const stored = window.localStorage.getItem(LOCALE_KEY);
    return stored === 'en' ? 'en' : 'es'; // default resolution order: localStorage → 'es'
  } catch {
    return 'es'; // private-browsing storage restrictions never break the shell — same posture as AdminShell's collapse-state try/catch
  }
}
```

Mounted once in `main.tsx`, alongside (outside or inside — order doesn't
matter, they don't interact) `ThemeProvider`, so `LoginPage` (which sits
outside both shells) also gets a locale — necessary, since `LoginPage.tsx`
is entirely English today (§A.1) and needs extracting too.

### B.8 `Intl` date/number formatting — render sites and target formatter

| Site | Today | Target |
|---|---|---|
| `DirectoryPage.tsx:139,449` | Raw ISO slice, `r.profile_last_reviewed_at?.slice(0, 10)` | `Intl.DateTimeFormat(locale, { dateStyle: 'medium' })` |
| `DirectoryPage.tsx:474` | `a.changed_at?.slice(0, 19).replace('T', ' ')` | Same `Intl.DateTimeFormat`, with `timeStyle` added |
| `AnalyticsPage.tsx:45` | `.slice(0, 10)` on a date | Same |
| `HistoryPage.tsx:267` | `` `${years} año(s) (desde ${date})` `` — naive plural AND raw date | `Intl.PluralRules` (§B.4.3) + `Intl.DateTimeFormat` |
| `GuardrailsPage.tsx:380` | `new Date(h.created_at).toLocaleString()` — **already uses a locale-aware API**, but with no explicit `locale` argument, so it silently follows the browser's own locale, not this app's chosen one | `new Date(...).toLocaleString(locale)` (or the shared `Intl.DateTimeFormat` helper) — one-line fix, the only site already halfway there |
| `DocumentDetailPanel.tsx:577` | `health.token_total.toLocaleString()` — same gap as above (no explicit locale) | `Intl.NumberFormat(locale).format(...)` |
| Every `` `${Math.round(x * 100)}%` `` site (7 occurrences: `ReviewQueuePage.tsx:188,281,359`, `AnalyticsPage.tsx:58,69,89,139`) | Manual `Math.round` + string concat | `Intl.NumberFormat(locale, { style: 'percent' })` — cosmetic in `es`/`en` (both use `%` the same way) but centralizes the rounding rule once instead of 7 times |

**Proposal:** one small module, `hr-frontend/src/i18n/format.ts`, exporting
`formatDate(iso, opts?)` and `formatPercent(n)` that read the active locale
from the same context `LocaleToggle` reads, so every site above becomes a
one-line swap with no per-site locale plumbing. This is a genuinely small
piece of work — 8 real call sites, not a systemic rewrite — since almost
everything else in this codebase renders numbers/dates as opaque server
strings already (fact `validity_start`/`validity_end`, most timestamps) with
no client-side formatting to touch at all.

---

## C. Plan output

### C.9 Ordered build steps

Mechanism + guard first (nothing to extract into until both exist), then one
full area end-to-end for CP-1 (so the *pattern* — not just the plumbing — is
approved before mass extraction), then area by area, English last (per the
kickoff's own ordering — Spanish is the source of truth, so English is a
translation pass over an already-structurally-correct dictionary, not a
parallel unknown):

| # | Step | Depends on | Done when |
|---|---|---|---|
| 1 | `i18n/es.ts` skeleton (empty namespaces per §B.5's areas) + `i18n/en.ts` (`: typeof es`) + the hand-rolled `t()`/`useT()` hook + `LocaleProvider`/`useLocale` (§B.7) | none | `npx tsc -b` clean on the new empty-but-typed files |
| 2 | `LocaleToggle` component + wire into `AdminShell.tsx` sidebar footer and `EmployeeShell.tsx` hamburger dropdown (§B.7) | step 1 | Toggle renders, flips `LocaleProvider`'s state, persists across a reload (manual check — no browser in this sandbox, so this is verified structurally + asked of Pedram at CP-1) |
| 3 | The anti-rot guard test (§B.6), run against **today's un-extracted code** first — it should report ~1,108 violations, proving the tool sees the real corpus before anything is fixed | step 1 (needs `t` to exist, so it can distinguish wrapped from bare) | Test runs, count is in the same order of magnitude as §A.1's measured total (sanity-checks the tool against itself) |
| 4 | **First full-area extraction: the admin sidebar (`AdminShell.tsx`, 34 matches) + one full page.** Recommend `DirectoryPage.tsx` or `GuardrailsPage.tsx` as the one page — mid-sized (53/53 matches, §A.1), has its own error-message fallbacks (an A.2 case) AND a raw-date site (a B.8 case, `DirectoryPage.tsx:449`), so it exercises every mechanism this sprint builds, not just the easy one (plain labels) | steps 1-3 | The guard test's violation count for these two files drops to 0 (or to the allowlist); `tsc -b`/`vitest run` clean |
| 5 | **⏸ CP-1** — deploy to staging, both languages, switcher working, sidebar + the one page | step 4 | Pedram approves the *pattern* before mass extraction — the explicit gate the kickoff prompt names |
| 6 | Extract remaining admin pages, area by area, ordered by §A.1's per-file count (largest first, so the biggest risk items get the most review time while the pattern is freshest): `DocumentDetailPanel.tsx`(98) → `ReviewQueuePage.tsx`(77) → `EscalationCardDrawer.tsx`(68) → `ReferenceFactPanel.tsx`(58) → `QualitySampleQueue.tsx`(54) → `GroupsQueue.tsx`(51) → `HistoryPage.tsx`(44) → the rest | CP-1 | Guard test green for each file as it lands |
| 7 | Extract the employee shell (`ChatScreen.tsx`, `TracePanel.tsx`, `CitationList.tsx`, `EmployeeShell.tsx` — 37 total) + `LoginPage.tsx`(10) | step 6 (can run in parallel with it — different files) | Guard test green |
| 8 | Extract the Grafo module (`GrafoSection.tsx`, `GrafoNodeCard.tsx` — 32) + the shared `Jerarquía\|Grafo`/`3D\|2D` toggle strings inside `KnowledgeMapPage.tsx` | step 6 | Guard test green |
| 9 | Frontend-side backend-message mapping (§A.2): `BACKEND_MESSAGE_MAP` for the 22 English custom messages + the network-fallback strings in `api.ts` | independent of 6-8 | The 20 Spanish/6-dynamic/16-Laravel-default gaps are documented in `review.md`, not silently left undocumented (§A.2's "listed, not hidden") |
| 10 | `Intl` formatting module (§B.8) + its 8 call sites | independent | Dates/numbers follow the active locale; `GuardrailsPage.tsx:380`/`DocumentDetailPanel.tsx:577`'s pre-existing "no explicit locale" gap fixed as a byproduct |
| 11 | **English pass.** Fill in every `en.ts` value against the now-complete `es.ts` — a human reads `en.ts` top to bottom (spec's own acceptance bar), not a per-file review | steps 6-8 fully landed | Every key has a non-empty English value; `tsc -b` (via `en.ts`'s `: typeof es`) proves no key is missing or stray |
| 12 | The §2 guard test (§A.3) — assert none of the 8 protected constants' text appears in `es.ts`/`en.ts` | any time after step 1 (cheap, low-risk, can land early) | Test passes (trivially, today — it's a regression guard for later) |
| 13 | Real bundle-size check against the built app (not the scratch probe) — confirm the actual `es.ts`+`en.ts` delta against `hr-frontend`'s real baseline entry size, write the real number into `review.md` regardless of whether it clears AC5's 15 KB (§B.4.4's open question) | step 11 | Number recorded, compared honestly against AC5 |
| 14 | **⏸ CP-2** — final eyes-on (spec §6), both languages, both shells, both themes | steps 1-13 | Pedram walks the spec's own §6 checklist on staging |

### Test inventory

- **The §2 guard test** (§A.3) — text-containment assertion, `hr-backend`'s
  eight protected constants never appear verbatim in either dictionary file.
- **The anti-rot guard** (§B.6) — the promoted `count-strings.mjs` AST walk,
  asserting zero un-allowlisted bare literals once extraction is done.
- **`en.ts` completeness** — trivially enforced by its own `: typeof es`
  annotation (a missing key is a `tsc -b` failure, not a separate test).
- **`LocaleProvider`** — a small Vitest test mirroring `AdminShellNav.test.tsx`'s
  `localStorage` pattern: defaults to `'es'` with nothing stored, honors a
  persisted `'en'`, `try/catch`-safe when storage throws.
- **`format.ts`** — one `Intl.DateTimeFormat`/`Intl.PluralRules` call per
  locale, asserting the exact expected string for a fixed date/count fixture
  in both `es`/`en` (this is where a wrong `Intl` option, e.g. `dateStyle`
  choice, would actually be caught).
- **Unchanged, re-run green:** the full frontend suite (`tsc -b`, `eslint .`,
  `vitest run`, `vite build`), the full backend suite (`php artisan test`,
  since this sprint is `hr-frontend`-only and should touch zero backend
  files unless Pedram authorizes the §A.2 `OQ-2` backend follow-up
  separately).
- **Eyes-on** (spec §6), both languages, both shells, both themes, per the
  standing project rule that a presentation-layer sprint is not meaningfully
  verified by any automated test (`deploy.md` §6a's `crypto.randomUUID()`
  story, cited in every prior sprint's plan for the same reason).

### Open questions

1. **OQ-1 — `BrandPreviewPage.tsx`'s 79 matches**: extract in the normal
   sequence (it's real UI text an admin can see), or deprioritize/skip it
   since it's an internal CP-1 tool with no production nav entry? Either
   answer is low-risk; flagged only so it's a decision, not an oversight.
2. **OQ-2 — the backend-sourced gaps in §A.2** (20 Spanish custom messages
   with no English mirror, 6 dynamic-passthrough messages, 16 controllers'
   worth of Laravel's built-in English validation copy): confirmed out of
   this sprint's `hr-frontend`-only scope, but real and user-visible. Worth
   its own future `hr-backend` sprint (stable `code` fields on every error
   response + a published `lang/es/validation.php`), or accepted as a
   standing, documented v1 limitation indefinitely?
3. **OQ-3 — AC5's ≤15 KB gzip budget vs. the measured ~23 KB two-locale
   dictionary floor** (§B.4.4): revise the acceptance criterion's number
   now, with real data in hand, or hold the extraction to it and accept
   that some English copy will need to be more terse than its Spanish
   source specifically to make the byte budget (an odd reason to shorten a
   translation, and one that should be a deliberate choice, not a surprise
   at CP-2)?

### ⏸ Checkpoints

| ⏸ | When | What Pedram does | Why it needs a human |
|---|---|---|---|
| **CP-1** | After step 5 (§C.9) | Review the mechanism + the first full area (admin sidebar + one full page) on staging, in both languages, switcher working | The kickoff's own explicit gate — approve the *pattern* (dictionary shape, switcher UX, guard test behavior) before ~900 strings get moved through it |
| **CP-2** | After step 14 | Final eyes-on, spec §6's full checklist, both languages, both shells, both themes | Standing project rule — no automated test meaningfully verifies a presentation-layer sprint; this is the same posture 11a's CP-3 and 11c's CP-2 already took |

---

**STOP — plan gate.** No code written, nothing committed. Awaiting review of
this plan (and OQ-1 through OQ-3 above) before build starts.
