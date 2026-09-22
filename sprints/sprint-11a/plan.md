# Sprint 11a — Plan (plan gate)

> Status: **CLOSED.** All 13 build steps done; CP-1, CP-2 (sidebar revision), and CP-3 (final eyes-on) all approved by Pedram, 2026-09-22. `hr-frontend`, `hr-backend`, and `hr-docs` committed on `sprint-11a` and merged `--no-ff` into `main`, pushed; staging redeployed from the merged `main` SHAs via `deploy.sh` and re-verified. See `hr-docs/sprints/sprint-11a/review.md` §11-13 for the full approval and close-out record.
> Spec: `hr-docs/sprints/sprint-11a/sprint-11a-spec.md`. Depends on ADR-0012 (token design system — see §0 correction on the "ADR-0012/0013" citation), ADR-0018 (server is the boundary), ADR-0020 (fuchsia = unverified AI only), Sprint 10a Correction-02 (`hr-docs/sprints/sprint-10a/correction-02.md` — shared reason labels + enum guard test).
> Everything below was checked against the **real code on the working tree** (`hr-backend`, `hr-frontend`, `hr-docs`, all four repos) and the **live deployed staging host** (`http://52.211.251.235/`, reachability + response headers curled directly, 2026-09-22). Every file reference is `path:line` against the working tree. No SSH/DB session against staging was needed this pass — see §1 for why, and CP-0 below if the reviewer wants one anyway before build starts.

---

## 0. Verdict up front, and one correction to the spec's own citation

**This sprint is buildable largely as specced**, and it sits on an unusually clean foundation: `hr-frontend` already has real token discipline (ADR-0012 — zero hardcoded colors in any `.tsx`/`.ts` file, confirmed by exhaustive grep, §A.3), `--provenance-ai` is already tightly scoped to seven CSS classes and ~15 admin-only render sites with zero employee-chat bleed (§A.6), and Sprint 10a Correction-02 already built the exact pattern (shared label map + DB-introspecting guard test) that §D's human-status work needs to extend, not invent.

**Correction to the spec's own dependency line:** the spec (and this kickoff prompt) cite "ADR-0012/0013 (token design system)". `hr-docs/architecture/decisions/0013-chunking-and-bilingual-extraction.md:1` is about hr-ai's PDF chunking/bilingual extraction — it has nothing to do with tokens or CSS. The token system is **ADR-0012 alone** (`hr-docs/architecture/decisions/0012-vanilla-css-design-tokens.md`), expanded in `hr-docs/design-system.md`. I've treated ADR-0012 + `design-system.md` as the single source of truth throughout and flagged this so the citation doesn't propagate into `review.md` uncorrected.

Five findings that change how I'd sequence and scope the build, stated up front:

1. **The token remap is a value swap, not an architecture change.** ~30 existing tokens already cover every role the brand needs (accent, accent-hover, accent-weak, accent-contrast, five semantic-state pairs, six surface/text tokens, both light and dark blocks). Section A proposes new hex values for the existing names plus exactly **two new tokens** (`--brand-warm`/`--brand-warm-bg` for Arena, which has no existing role). No new CSS architecture, no new build tooling.
2. **There is no CSP anywhere today** — not in the Caddyfile, not in nginx, not in the frontend build (grepped the whole tree; confirmed live against the deployed host — no `Content-Security-Policy` header on the `curl -I` response, §A.4). The spec's "confirm self-hosted fonts load under it unchanged" premise is moot: there is nothing to break. This is good news (self-hosted fonts need no CSP exception, ever) but it does mean the "closed CSP" the spec describes doesn't exist to be preserved — it would have to be *created*, which I've scoped as optional, separately checkpointed work (§A.4.3), not a dependency of the font work.
3. **Nav grouping is markup-only, but icons are a new dependency.** `AdminShell.tsx` has no separate Sidebar component — the nav is 12 inline buttons in one `<nav>` (§B.1). Grouping them is a JSX change with zero risk to the `view`/hash-routing state machine. But `lucide-react` (or any icon library) is **not currently installed** (confirmed in `package.json`) — this is the one new frontend dependency the whole sprint needs.
4. **The filter toolbar work is real but bounded, and "Review, all tabs" is mostly empty of filters today.** Of Review's five tabs, only Reference-facts has a filter control at all; AI tagging, Groups, Vocabulary, and Expiry use fixed query params with no user-facing filter. The shared component (§C) must not invent filters that don't exist today — it wraps what's there.
5. **The human-status grep (§D) found real, additional raw-render sites beyond the sprint-10a-Correction-02 list** — most notably `HistoryPage.tsx:140`, exactly as the spec suspected, plus `AnalyticsPage.tsx:101` (`sub_outcome`), `GroupsQueue.tsx` (fact/node status, four sites), and `DocumentsPage.tsx:203-204` (list-view `retrieval_status`/`tagging_status`, unlabeled). Table in §D.2.

---

## 1. How this was inspected

- **Code:** working tree at `/Users/pedram/Desktop/PROJECT/JV/HR-AI`, all four repos, read directly and via targeted `rg`/grep passes (hardcoded-color sweep, `--provenance-ai` sweep, raw-enum-render sweep).
- **Live staging host:** `curl -I http://52.211.251.235/` and `curl http://52.211.251.235/` (2026-09-22) — confirmed the host is up, serving the real built SPA shell (`/assets/index-D_EvXkLq.js`, `/assets/index-uHarElqn.css`, no font `<link>` tags — matches the bundled-font code path exactly), plain HTTP (no TLS, as `deploy.md` §6b already tracks as a separate go-live blocker, unrelated to this sprint), and **no `Content-Security-Policy` header on the live response** — matching the Caddyfile in the repo exactly (`hr-docs/infra/compose/Caddyfile` has no `header` directive at all). `curl -I http://52.211.251.235/up` confirms hr-backend is reachable through Caddy (`Server: nginx`, `Via: 1.1 Caddy`, `X-Powered-By: PHP/8.4.25`).
- **Why no DB/SSH session this pass:** every fact this plan needed that's *only* observable live (the CSP header, whether the deployed bundle matches the repo) was checked directly over HTTP above. The remaining facts — token values, nav source, OTP code path, label maps — are static code facts with no live-only component, unlike Sprint 10a's corpus-shape questions. If the reviewer wants a full staging walkthrough (e.g., to log in as each role and eyeball the *current* nav/badges before approving the *proposed* ones) before build starts, that's cheap and I'd fold it into **CP-0** below.

---

## A. Brand theme (spec §2.1)

### A.1 The token system today

Canonical file: `hr-frontend/src/index.css` (the **only** CSS file in the frontend — confirmed, no CSS Modules, no per-component stylesheets). Doc: `hr-docs/design-system.md`. Decision: ADR-0012 (not 0013 — see §0).

**Light — `:root` (`index.css:8-87`):**

| Token | Line | Value | Role |
|---|---|---|---|
| `--accent` | 10 | `#2563eb` | primary actions, active state, links |
| `--accent-hover` | 11 | `#1d4ed8` | hover for primary |
| `--accent-weak` | 12 | `#eff4ff` | tinted backgrounds (selected row, info) |
| `--accent-contrast` | 13 | `#ffffff` | text on accent |
| `--teal` / `--teal-weak` | 14-15 | `#0d9488` / `#f0fdfa` | secondary accent (used sparingly — checked usage below) |
| `--danger` / `--danger-bg` | 18-19 | `#b42318` / `#fef3f2` | conflict badge, destructive |
| `--warning` / `--warning-bg` | 20-21 | `#b54708` / `#fffaeb` | under-review / empty-text |
| `--success` / `--success-bg` | 22-23 | `#067647` / `#ecfdf3` | verified, healthy |
| `--info` / `--info-bg` | 24-25 | `#175cd3` / `#eff8ff` | national scope marker |
| `--neutral` / `--neutral-bg` | 26-27 | `#475467` / `#f2f4f7` | historical, inactive |
| `--provenance-ai` | 36 | `#e879f9` | **fuchsia — unverified AI only (ADR-0020). Untouched, §A.6.** |
| `--canvas` | 39 | `#f8fafc` | page background |
| `--surface` | 40 | `#ffffff` | cards, panels, table |
| `--surface-raised` | 41 | `#f2f4f7` | secondary buttons, hover rows |
| `--surface-inset` | 42 | `#f8fafc` | inputs, source-text wells |
| `--text` | 43 | `#101828` | primary text |
| `--text-muted` | 44 | `#475467` | secondary / labels |
| `--text-faint` | 45 | `#98a2b3` | captions, placeholders |
| `--border` / `--border-strong` | 46-47 | `#e4e7ec` / `#d0d5dd` | hairline / inputs |
| `--space-1..8` | 50-56 | 4px base scale | unchanged, not a color |
| `--radius-sm/md/lg/pill` | 59-62 | 6/8/12/9999px | unchanged, not a color |
| `--shadow-sm` / `--shadow-panel` | 65-66 | rgba blacks | unchanged, not a color |
| `--text-xs..xl` + line-heights | 69-78 | size scale | unchanged, not a color |
| `--font-sans` | 80-81 | `'Inter', -apple-system, …` | §A.4 |

**Dark — `[data-theme='dark']` (`index.css:90-126`):** same token names, shifted values (`--canvas:#0b1220`, `--surface:#161b26`, `--surface-raised:#222936`, `--surface-inset:#0e131c`, `--text:#f4f6fa`, `--text-muted:#9aa4b2`, `--text-faint:#667085`, `--border:#222936`, `--border-strong:#333c4a`, brighter-foreground/low-alpha-tint pairs for `--danger/-warning/-success/-info/-neutral`, `--provenance-ai:#f0abfc`). Dark block does **not** override `--accent`/`--accent-hover`/`--accent-contrast` today — a gap the brand remap should close (§A.2 dark accent).

`--teal`/`--teal-weak` (`:14-15`, `:104-105`) exist but are **barely used** — worth confirming at build time whether to retire them into the new `--brand-warm` role or keep both; noted as an open question (§G.3 Q1).

### A.2 Proposed mapping — five brand colours onto the tokens, plus status colours and dark variants

All numbers below are **computed WCAG 2.1 relative-luminance contrast ratios**, not eyeballed (script run against exact hex values; see the ratios inline). AA-normal = 4.5:1, AA-large = 3:1.

**Brand swatches** (spec §2.1): Gris Claro `#F3F4F2`, Verde Profundo `#173F3E`, Verde Sedena `#2B6565`, Arena `#D9B56D`, Verde Niebla `#DCE9E6`.

| Token | Today (light) | Proposed (light) | Source colour | Rationale |
|---|---|---|---|---|
| `--accent` | `#2563eb` | `#2B6565` | Verde Sedena | primary actions/CTA/active-nav/icons, exactly the spec's role for it. Passes AA as text too (6.66:1 on white) — versatile for links/icons, not just fills. |
| `--accent-hover` | `#1d4ed8` | `#224f4f` | Verde Sedena, darkened 22% | white-on-hover stays 9.13:1 |
| `--accent-weak` | `#eff4ff` | `#DCE9E6` | **Verde Niebla** | "soft states, active filters, supporting backgrounds" is *exactly* `--accent-weak`'s existing role (selected row, info tint) — no new token needed, direct swap |
| `--accent-contrast` | `#ffffff` | `#ffffff` (unchanged) | — | white on `#2B6565` = 6.66:1, still AA |
| `--canvas` | `#f8fafc` | `#F3F4F2` | Gris Claro | page background exactly as spec'd |
| `--surface` | `#ffffff` | `#ffffff` (unchanged) | — | cards/panels stay pure white so they visibly "lift" off the Gris Claro canvas |
| `--surface-raised` | `#f2f4f7` | `#E4E5E3` | Gris Claro, darkened 6% | secondary buttons / hover rows, same role, recolored to the new neutral family |
| `--surface-inset` | `#f8fafc` | `#EEEFED` | Gris Claro, darkened 2% | inputs / source-wells |
| `--text` | `#101828` | `#173F3E` | Verde Profundo | "structure … high-contrast text" — literally the spec's own words for this token's role. 11.56:1 on white, 10.48:1 on Gris Claro |
| `--text-muted` | `#475467` | `#425A65` | Verde Profundo blended 55% toward neutral slate | 7.29:1 on white — keeps the brand tint in secondary text without it reading as a second heading color |
| `--text-faint` | `#98a2b3` | `#788996` | Verde Profundo blended 75% toward neutral | 3.61:1 on white (AA-large). **This is already an improvement over today's production value** (`#98a2b3` on white = 2.58:1, fails even AA-large — see §A.2.1) |
| `--border` / `--border-strong` | `#e4e7ec` / `#d0d5dd` | `#D6D7D5` / `#BEBEBD` | Gris Claro, darkened 12%/22% | hairlines/inputs — not text, no AA requirement, just recolored to the new neutral family so borders don't look like a leftover blue-grey system next to the new palette |
| **`--brand-warm`** *(new token)* | — | `#D9B56D` | Arena | icon/decoration accent, secondary-data emphasis, signage. **Never used as foreground text on a light surface — fails AA at 1.95:1 on white, 1.77:1 on Gris Claro** (§A.2.1). Paired only with a dark foreground on top of it, never as the foreground itself. |
| **`--brand-warm-bg`** *(new token)* | — | `#F9F4E9` | Arena, 90%-lightened | soft tint for a "highlight" badge/chip background, following the existing fg/-bg pair pattern. `--brand-warm` itself is still not used as text even on this tint (1.78:1) — `--text`/`--accent` are the foregrounds that go on top of `--brand-warm-bg`. |

**Status colours** (error/warning/success/info — spec: "do not exist in the palette; design them to harmonise, AA in both themes"). Two options, presented with real numbers so this is Pedram's call, not mine (§G.3 Q2):

| Option | danger | warning | success | info | Why |
|---|---|---|---|---|---|
| **A — keep today's hexes unchanged** | `#b42318` (6.57:1) | `#b54708` (5.43:1) | `#067647` (5.69:1) | `#175cd3` (5.99:1) | Zero regression risk, already AA, already distinguishable from the *old* blue accent. **Open question:** the *new* accent (`#2B6565`, teal-green) sits on the color wheel between `--info` (blue) and `--success` (green) — worth an eyes-on check that "primary/active" (teal) isn't mistaken for "success" (green) or "info" (blue) in dense tables. |
| **B — retune toward the palette's warmth** | `#b42318` (unchanged, 6.57:1) | `#946200` (5.24:1) | `#2f7a3d` (5.29:1) | `#2a6ea6` (5.41:1) | Slightly less saturated / warmer, reads more "in-family" with an earthy green-and-tan palette instead of a generic SaaS red/amber/green/blue set. Every value still clears AA on white. `success` is deliberately kept a more yellow-green (`#2f7a3d`) than the teal accent (`#2B6565`) specifically to preserve hue separation between "primary/active" and "verified" — the exact risk Option A flags. |

My recommendation is **B**, specifically because it's the one that actively answers the hue-collision risk instead of just inheriting it, but the numbers for both are on the table.

**-bg tints** (badge foreground must meet AA on its own tint, design-system §6) — computed for Option B, same construction (90%-lightened toward white) as the existing danger/warning/success/info-bg pairs:

| Token | Value | fg-on-bg ratio |
|---|---|---|
| `--warning-bg` | `#F4EFE6` | 4.58:1 (was 5.43:1 fg-on-white — margin is tighter but still AA) |
| `--success-bg` | `#EAF2EC` | 4.64:1 |
| `--info-bg` | `#EAF0F6` | 4.72:1 |
| `--danger-bg` | `#F8E9E8` (unchanged fg) | 5.58:1 |

**Dark theme — derived variants**, using Verde Profundo itself as the dark "surface" (it's already a near-black-green, so the dark theme becomes genuinely brand-colored rather than a generic slate):

| Token | Proposed (dark) | Rationale / ratio |
|---|---|---|
| `--canvas` | `#0F2B2A` (Verde Profundo, darkened further) | page background |
| `--surface` | `#173F3E` (Verde Profundo, as-is) | cards/panels — text on it: 10.29:1 |
| `--surface-raised` | `#335655` (Verde Profundo, lightened 12%) | hover rows |
| `--text` | `#EDF3F1` | 10.29:1 on `--surface`, 13.36:1 on `--canvas` |
| `--text-muted` | **`#AFC4BF`** — corrected by Pedram | 6.31:1 on `--surface`, 8.20:1 on `--canvas`. The original proposal (`#E7F0ED`) was almost the same as `--text`, which lost the hierarchy between primary and secondary text — Pedram's value keeps a clearly visible step down while staying comfortably AA-normal. |
| `--accent` | **`#94B9B8`** — corrected by Pedram | 5.44:1 on `--surface`, 7.07:1 on `--canvas` — **AA-normal**, not just AA-large. This token is used as text (links, active nav), so it must clear the normal threshold, not just the UI-component allowance the original `#759B9B` (3.81:1) leaned on. `--accent-contrast` stays `#06201F` (now 8.01:1 on `--accent`, up from 5.61:1). `--accent-hover` (derived, not independently specified): `#A4C4C3`, lightened further for a "brighten on hover" dark-UI convention — 6.20:1 on `--surface`. `--accent-weak` (derived): retinted from the old blue `rgba(37,99,235,…)` to `rgba(43,101,101,0.22)` to match the new hue — background-only, no AA requirement. |
| `--brand-warm` (dark) | `#DEBE7F` (Arena, lightened 12%) | icon/bg-only, same restriction as light — 6.48:1 on `--surface`, still never used as text-on-light-tint. `--brand-warm-bg` (derived): `rgba(217,181,109,0.16)`, following the existing dark low-alpha-tint pattern — background-only. |
| `--provenance-ai` (dark) | `#f0abfc` **unchanged** | still 6.57:1 on the new `--surface` — no change needed, confirms §A.6 |

### A.2.1 Contrast table (WCAG AA) — every text/background pair, light and dark

| Pair | Theme | Ratio | Verdict | Note |
|---|---|---|---|---|
| `--text` on `--canvas` | light | 10.48:1 | AA ✓ | Verde Profundo on Gris Claro |
| `--text` on `--surface` | light | 11.56:1 | AA ✓ | Verde Profundo on white |
| `--text` on `--accent-weak` | light | 9.27:1 | AA ✓ | Verde Profundo on Verde Niebla — safe for text inside a selected/filtered row |
| `--text-muted` on `--surface` | light | 7.29:1 | AA ✓ | |
| `--text-muted` on `--canvas` | light | 6.60:1 | AA ✓ | |
| `--text-faint` on `--surface` | light | 3.61:1 | AA-large only | captions/placeholders only, never body text — **same posture as today**, and strictly better than today's actual `2.58:1` (fails even AA-large) |
| `--accent-contrast` (`#fff`) on `--accent` | light | 6.66:1 | AA ✓ | button text |
| `--accent` on `--surface` (as text/icon) | light | 6.66:1 | AA ✓ | links, active nav icon+label |
| `--accent-contrast` on `--accent-hover` | light | 9.13:1 | AA ✓ | |
| Arena (`--brand-warm`) on `--surface` | light | **1.95:1** | **FAIL** | **must never be used as text on a light surface** — icon/fill/bg only |
| Arena on `--canvas` | light | **1.77:1** | **FAIL** | same restriction on Gris Claro |
| Arena on its own `--brand-warm-bg` | light | **1.78:1** | **FAIL** | even Arena-on-its-own-tint fails — the badge foreground must be `--text` or `--accent`, never `--brand-warm` itself |
| Verde Niebla (`--accent-weak`) on `--surface` (as text) | light | **1.25:1** | **FAIL** | **must never be used as text** — background/tint only, exactly as spec's caution implies |
| `--accent` on `--accent-weak` | light | 5.34:1 | AA ✓ | e.g. an active-filter chip's icon/label sitting on its own tint |
| `--danger`/`--warning`(B)/`--success`(B)/`--info`(B) on `--surface` | light | 6.57 / 5.24 / 5.29 / 5.41 | AA ✓ (all) | Option B values; Option A values also all ≥5.4:1 |
| each status fg on its own `-bg` tint | light | 5.58 / 4.58 / 4.64 / 4.72 | AA ✓ (all, tightest margin on warning) | |
| `--provenance-ai` on `--surface` | light | 2.46:1 | **below AA — unchanged from today, by design** | never used as solid body text; always a dot/border/tinted-badge foreground on its own small glyph, confirmed by the render-site audit in §A.6 — zero regression, zero change proposed |
| `--text` on `--canvas` | dark | 13.36:1 | AA ✓ | |
| `--text` on `--surface` | dark | 10.29:1 | AA ✓ | |
| `--accent` on `--surface` | dark | **5.44:1** (corrected) | **AA ✓ (normal)** | Pedram's corrected `#94B9B8`, up from the original proposal's `#759B9B` (3.81:1, AA-large only) — this token is used as text, so AA-normal was the actual bar, not the UI-component allowance. 7.07:1 on `--canvas`. Solid-fill button text (`--accent-contrast` `#06201F` on `--accent`) is 8.01:1. |
| `--text-muted` on `--surface` | dark | **6.31:1** (corrected) | AA ✓ | Pedram's corrected `#AFC4BF`, replacing the original `#E7F0ED` (9.95:1 but visually indistinguishable from `--text`'s 10.29:1 — technically AA but a hierarchy failure) |
| Arena (dark) on `--surface` | dark | 6.48:1 | AA ✓ (icon/bg use only — restriction is about *light-surface text*, not about the color itself; still never used as body text in either theme) | |
| `--provenance-ai` (dark) on `--surface` | dark | 6.57:1 | AA ✓ | dark mode actually improves this pair over light mode |

**Where Arena and Verde Niebla may / may not be used, stated plainly (spec's explicit ask):**
- **Arena (`--brand-warm`)** — MAY: icon fills, chart/data accents, decorative signage, a badge's tinted **background** (`--brand-warm-bg`) with `--text` or `--accent` as the foreground on top. MAY NOT: body text, link text, button label text, any foreground role on any surface, in either theme.
- **Verde Niebla (`--accent-weak`)** — MAY: tinted backgrounds only (selected table row, active-filter chip background, info tint) — its existing, unchanged role. MAY NOT: text of any kind, in either theme (1.25:1 confirmed).

### A.3 Hardcoded colours — exhaustive grep result

**Zero color literals in any `.tsx`/`.ts` file under `hr-frontend/src`.** Confirmed by grepping for hex (`#[0-9a-fA-F]{3,8}`), `rgb(`/`rgba(`, and named colors (`white|black|red|blue|gray|grey|green|yellow|orange|purple|pink|transparent`) as color-value usage across every component. The only `#` characters in `.tsx` files are inside comments (e.g. `ReviewQueuePage.tsx:85,100`).

**`index.css` non-token literals** (everything outside the `:root`/`[data-theme='dark']` blocks):

| File:line | Literal | Context | Action |
|---|---|---|---|
| `index.css:726` | `rgba(16, 24, 40, 0.55)` | `.detail-backdrop` background | move to a new `--overlay-backdrop` token (light) |
| `index.css:1779` | `rgba(16, 24, 40, 0.45)` | `.modal-backdrop` background | same token, reused |
| `index.css:1952` | `rgba(0,0,0,.25)` | `.board-card--ghost` box-shadow fallback | move into `--shadow-*` scale or accept as a shadow-only literal (shadows are already not tokenized per-value elsewhere either — low priority) |
| `index.css:1421-1422,1433` | `#ffffff`/`#000000`/`#000000` | `@media print` overrides | **intentional** — print needs literal black-on-white regardless of theme; leave as-is (this is the existing, correct pattern, not a violation) |

That's the **entire** hardcoded-color surface in the whole frontend. Two backdrop `rgba`s are the only real cleanup item; everything else is either already tokenized or deliberately not (print).

### A.4 Fonts

**Today:** Inter, self-hosted via `@fontsource/inter` (`hr-frontend/package.json:16`, `"^5.2.8"`), imported at `hr-frontend/src/main.tsx:5-8` (400/500/600/700 weights). Zero `<link>` tags in `hr-frontend/index.html`, zero `@font-face` anywhere, zero references to `fonts.googleapis.com` or any external CDN — confirmed by repo-wide grep and by the live host's served HTML (`curl` on 2026-09-22 shows `<script src="/assets/index-*.js">` + `<link rel="stylesheet" href="/assets/index-*.css">` only — no font `<link>`).

**Brand assets present** (`hr-docs/sedena/`, `__MACOSX` correctly ignored):
- `LOGO-SEDENA-01.svg` (4.2 KB)
- `sedena_cromatic_range.png` (palette reference)
- `Montserrat.zip` → unzipped contains: `Montserrat-{Thin,ExtraLight,Light,Regular,Medium,SemiBold,Bold,ExtraBold,Black}.ttf` + matching `*Italic.ttf` for each (18 static weights) **plus** `Montserrat-VariableFont_wght.ttf` and `Montserrat-Italic-VariableFont_wght.ttf` (2 variable fonts covering the full weight axis)
- `Playfair_Display/` → `PlayfairDisplay-VariableFont_wght.ttf` + `PlayfairDisplay-Italic-VariableFont_wght.ttf` (2 variable fonts) + `static/` (14 discrete-weight files, present but redundant given the variable fonts)
- `Playfair/` → a **second, non-Display** Playfair family (`Playfair-VariableFont_opsz,wdth,wght.ttf` + italic + `static/`, 86 files) — the spec names "Playfair Display" specifically; this sibling family looks like it came bundled in the same download and is **not** what's asked for. Flagged as an open question (§G.3 Q3) rather than silently including or excluding it.

**Self-hosting proposal:**
- **Use the variable fonts only**, not the 18+14 static-weight files. One `Montserrat-VariableFont_wght.ttf` (+ its italic) covers every weight from Thin to Black in a single `font-weight: 1 1000` range; same for `PlayfairDisplay-VariableFont_wght.ttf`. This is the modern equivalent of what `@fontsource/inter` already does (Inter is also shipped as discrete static weight files per import, but only 4 are imported today — the variable-font route is strictly lighter for a two-family, wide-weight-range brand font than importing 8+ static Montserrat weights).
- **Weights actually needed**, cross-checked against `design-system.md §3`'s existing type scale (`--text-xs` through `--text-xl`, weights 400-700 today) plus headings: Montserrat 400 (body), 500 (table/UI medium), 600 (labels/badges — today's `--text-xs` role), 700 (page headings, today's `--text-xl` role) — i.e. the **same four weights Inter ships today**, just resolved out of the variable font's axis via `font-variation-settings` or plain `font-weight` (browsers interpolate a variable font at any requested weight automatically — no need to statically instance it into four files). Playfair Display: 600 and 700 only (display/heading use per spec §2.1 "headings / display"), also resolved from its one variable file.
- **Format:** `.ttf` → convert to **`.woff2`** at build time (or once, checked into the repo like the Inter package ships pre-converted) — `.ttf` is ~3x larger over the wire and every target browser (staging is accessed by admins/HR on modern browsers) supports woff2. This is the one real build step this item needs; it's a one-time conversion (`fonttools varLib.instancer` or a plain `woff2_compress`), not a pipeline.
- **Subsetting:** the corpus and UI copy are Spanish + Euskara-adjacent (convenio names have accented Basque/Spanish characters — ñ, á, é, í, ó, ú, ü, ç). Subset to `latin` + `latin-ext` (covers all of the above) rather than the font's full Unicode range — matches `@fontsource/inter`'s own default subsetting behavior, so this is consistent with the existing pattern, not a new one.
- **Loading mechanism:** mirror `main.tsx:5-8` exactly — add `Montserrat-VariableFont_wght.woff2`/`-Italic` and `PlayfairDisplay-VariableFont_wght.woff2`/`-Italic` as local files under `hr-frontend/src/assets/fonts/` (or a small local package under `hr-frontend/src/fonts/`, since `@fontsource` doesn't ship Sedena's private brand fonts), with a small local `fonts.css` declaring `@font-face` for each (`font-display: swap`, `src: local(...) + url(...) format('woff2-variations')`), imported once from `main.tsx` next to the existing Inter imports. Then `--font-sans: 'Montserrat', ...` and a new `--font-display: 'Playfair Display', ...` token, applied via the existing `--font-sans` `:root`/`body` rules (`index.css:80-86,134`) plus new heading-selector rules for `--font-display` (`h1`/`h2`/page-title classes).

**CSP:** confirmed above (§A.4 intro, §0.2) — there is no CSP anywhere in the stack today, live-verified. Self-hosted fonts (same-origin, served from `/assets/` like the JS/CSS bundle already is) need **no CSP exception under any policy**, closed or otherwise — they're same-origin by construction, identical to how the JS/CSS bundle itself loads. **If the reviewer wants an actual CSP added this sprint** (as a hardening item, not a font-loading requirement), I'd scope it separately with its own checkpoint (§A.4.3) since it's genuinely new production behavior with its own risk (a misconfigured CSP can break the app in ways a missing one cannot) — not bundled into the font work, and not required by anything the spec's font requirement actually needs.

#### A.4.3 (optional, separately checkpointed) Adding a baseline CSP

Not required by the font work. If Pedram wants it done this sprint anyway (since the plan is here and the host is idle right now), the shape would be: `header { Content-Security-Policy "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'" }` added to the `handle` block of `hr-docs/infra/compose/Caddyfile:32-36`, tested against a real page load (network tab, zero blocked requests) before promoting. I'd want this behind its own checkpoint distinct from CP-1/2/3 if authorized, precisely because "add security header to production" is a different risk class than "restyle buttons." Left as an open question (§G.3 Q4), not in the default build steps.

### A.5 Theme config + asset folder; no client name in components

**Proposal:** `hr-frontend/src/theme/brand.ts` — a single exported `BRAND` object: `{ productName: 'HR Platform', logo: <import of the SVG>, colors: {...} }` (the color values live in `index.css` as tokens; this object exists for the **non-color** brand facts a component might need at runtime: product name string, logo asset). Asset folder: `hr-frontend/src/assets/brand/` holding `logo.svg` (copied from `hr-docs/sedena/LOGO-SEDENA-01.svg` at build time, never referenced from `hr-docs/` directly) and the font files from §A.4.

**No client name in components — confirmed already true, and shown by construction:** grepped `hr-frontend/src` for the client codename and found zero hits (consistent with the standing project rule — "the codename never appears in code," `deploy.md:55`). The current product name string is the generic `"HR Platform"`, hardcoded in exactly two places today: `hr-frontend/index.html:7` (`<title>`) and `AdminShell.tsx:122` (`<strong>HR Platform — Admin</strong>`). Both become `{BRAND.productName}` reads from the new `brand.ts` — the **only** two call sites that need to change for this rule, and neither currently names the client, so this is a refactor for future-proofing (a name change becomes a one-line edit to `brand.ts`), not a fix to an existing violation.

### A.6 `--provenance-ai` — confirmed untouched, every render site

**Token, unchanged:** `index.css:29-36` (light, `#e879f9`, fuchsia-400) and `index.css:118-119` (dark, `#f0abfc`, fuchsia-300). Confirmed in §A.2's dark-theme table that the new dark `--surface` doesn't change its AA standing (6.57:1, actually improves).

**Seven CSS classes reference it** (`index.css`): `.timeline-dot.src-ai_agent` (`:965-967`), `.ai-pill`/`.ai-pill::before` (`:974-994`), `.ai-marked` (`:997-999`), `.ai-facet` (`:1002-1008`), `.notice--ai` (`:2152-2155`), `.ai-suggestions` (`:2157-2162`), `.ai-unresolved` (`:2164-2167`, spacing only, no color).

**Every render site** (admin-only, confirmed zero employee-chat usage):

| File:line | UI element |
|---|---|
| `ReviewQueuePage.tsx:173,189` | reference-facts queue row + "AI" badge |
| `ReviewQueuePage.tsx:268,276` | AI tagging queue row + badge |
| `ReviewQueuePage.tsx:345,347` | vocabulary proposal card + badge |
| `ReviewQueuePage.tsx:470-486` | expiry succession AI-proposal notice |
| `ReviewQueuePage.tsx:581` | expiry task card, AI-proposed |
| `DocumentDetailPanel.tsx:235-236` | unverified-AI tagging banner |
| `DocumentDetailPanel.tsx:331` | provenance timeline dot (`ai_agent` source) |
| `DocumentDetailPanel.tsx:409-416,431` | AI-suggested facets section + unresolved list |
| `ReferenceFactPanel.tsx:133,142,160-169,223,312` | AI-proposal panel border/badge/notice/excerpt/timeline |
| `ProposeVocabularyForm.tsx:95` | inline propose-vocabulary form |
| `FactDuplicatePanel.tsx:243` | AI-sourced compare column |
| `EscalationCardDrawer.tsx:272-273` | "Resumen IA" banner on AI-drafted HR reply |
| `TracePanel.tsx:44,84,106,116,126,153,164` | pipeline steps marked AI-routed (admin trace view only — `EscalationCardDrawer`, `HistoryPage`, `QualitySampleQueue`) |

**Gap found (pre-existing, not introduced by this sprint, not proposed to be fixed here — flagged only):** `GroupsQueue.tsx:110,212,357,364,556` applies `className="badge ai"` / `"panel node … ai"`, but no `.badge.ai`/`.node.ai` CSS rule exists — these render with **no** fuchsia styling today, a latent inconsistency. Fixing it is a one-line CSS addition (`.badge.ai { color: var(--provenance-ai); border-color: var(--provenance-ai); }` alongside the existing `.ai-pill` rule) with zero risk, and I'd fold it into the token-recolor pass (step 1, §G.1) since it's touching the exact same CSS region — but it's the reviewer's call whether a pre-existing gap is in scope (§G.3 Q5).

**Employee chat (`ChatScreen.tsx`) uses no provenance-AI class anywhere** — confirmed, so the welcome-screen work in §E cannot accidentally introduce fuchsia into the surface 1,500 employees see.

---

## B. Navigation (spec §2.2)

### B.1 Current nav — source, gating, identity keys

No separate Sidebar/NavItem component exists. The nav is inline in `hr-frontend/src/shells/AdminShell.tsx`:
- `View` union (12 members): `:19-31`
- Ability booleans: `:101-106`
- Rendered nav: `:123-136`
- ADR-0018 posture documented inline: `:52-59` ("nav only HIDES … server enforces … regardless")

| # | Label | View id | Nav-gated on | Helper | Backend key (`IdentityPresenter.php:62-81`) |
|---|---|---|---|---|---|
| 1 | Map | `map` | — (always shown) | — | — |
| 2 | Documents | `documents` | — | — | — |
| 3 | Review | `review` | — | — | — |
| 4 | Escalations | `escalations` | — | — | — |
| 5 | Analítica | `analytics` | `analytics.view` | `canViewAnalytics` (`api.ts:92-94`) | `:80` |
| 6 | Cobertura | `coverage` | `analytics.view` OR `knowledge.edit` | `canViewCoverage` (`api.ts:102-104`) | `:80` / `:63` |
| 7 | Calidad | `quality` | any logged-in admin | `canViewQuality` (`api.ts:121-123`) | — |
| 8 | Directory | `directory` | `directory.manage` | `canManageDirectory` (`api.ts:58-60`) | `:66` |
| 9 | History | `history` | `history.view_all` | `canViewAllHistory` (`api.ts:54-56`) | `:65` |
| 10 | Admins | `admins` | `admin.manage` | `canManageAdmins` (`api.ts:62-64`) | `:67` |
| 11 | Guardrails | `guardrails` | — (nav unconditional; writes gated `guardrails.manage`) | `canManageGuardrails` (page-level, `api.ts:72-74`) | `:68` |
| 12 | Settings | `settings` | — | — | — |

**Identity payload:** `Identity.abilities?: Record<string, boolean>` (`api.ts:26-37`), fetched via `GET /me` (`MeController.php:19-21`) or returned inline from `POST /auth/verify-code` (`AuthController.php:116`), both calling `IdentityPresenter::present()` (`IdentityPresenter.php:17-84`). The eight keys in `abilities` (`:62-81`) are the complete set of Spatie permissions (`RoleSeeder.php`, cross-checked against migrations `2026_06_25_100002`, `2026_06_25_110004`, `2026_06_26_100002`, `2026_09_10_100006`): `knowledge.edit`, `escalation.work`, `history.view_all`, `directory.manage`, `admin.manage`, `guardrails.manage`, `vocabulary.approve`, `analytics.view`.

**The Sprint 8 nav-key bug, for context (already fixed, cited so the grouping work doesn't reintroduce its shape):** `analytics.view` was added to Spatie/`RoleSeeder` before it was added to `IdentityPresenter.php`'s `abilities` array — comment at `:72-79` documents it, and `Sprint8AnalyticsAccessTest.php:164-175` now guards the identity payload specifically. **No drift found today** between the 8 frontend ability-helper keys (`api.ts:40-123`) and the 8 backend-emitted keys — confirmed 1:1. The grouping work in §B.2 touches zero ability logic, so this class of bug is structurally not at risk from this sprint — but the per-role snapshot test (§B.3) is exactly the guard that would catch it if it ever recurred.

**A useful, unplanned finding:** the page `<h2>` headers already carry an informal grouping that closely prefigures the spec's proposal — `"Knowledge · Documents"` (`:151`), `"Knowledge · Review"` (`:158`), `"Personas · Directorio"` (`:198`), `"Personas · Histórico de conversaciones"` (`:205`), `"Administración · Administradores y roles"` (`:212`), `"Seguridad · Guardarraíles"` (`:219`). The spec's grouping mostly matches (Conocimiento≈Knowledge), but re-homes **History** from "Personas" into "Atención" (with Escalations) and **Admins** from "Administración" into "Personas" — i.e. this is a genuine re-categorization, not just applying an existing grouping to the nav bar. I'd update the matching `<h2>` copy to match the new groups in the same pass, so the nav and the page headers don't say two different things (copy-only change, no risk).

### B.2 Proposed grouped structure

Keep `.shell-nav` a single horizontal bar (no new drawer/dropdown component — lowest-risk, most additive change) but wrap items in labeled sub-groups with a thin `--border` divider and a `--text-xs`/`--text-faint` uppercase micro-label per group (new `.shell-nav-group` CSS, ~10 lines). The `view`/`navBtn`/hash-routing logic (`AdminShell.tsx:90-117`) is **completely unchanged** — only the JSX between `:123` and `:136` changes, from a flat list to five `<div className="shell-nav-group">` wrappers.

| Group | Items | Icon (lucide-react) | One-line reason |
|---|---|---|---|
| **Conocimiento** | Mapa, Documentos, Revisión | `Map`, `FileText`, `ClipboardCheck` | spatial/graph view; literal document icon; a checklist reads as "verify/review" without borrowing the AI-badge's visual language |
| **Atención** | Escalaciones, Historial | `AlertTriangle`, `History` | universal "needs attention" metaphor; a clock-with-arrow is the standard "past record" icon, distinct from Documentos' flat page icon |
| **Análisis** | Analítica, Cobertura, Calidad | `BarChart3`, `Grid3x3`, `BadgeCheck` | bar-chart for trend analytics; a grid literally matches Cobertura's convenio-×-dimension table shape; a checked badge reads as "sampled and verified," distinct from Revisión's clipboard |
| **Personas** | Directorio, Administradores | `Users`, `UserCog` | plain people icon for the employee directory; a person-with-gear reads as "manage accounts/roles" without reusing Guardrails' shield |
| **Gobierno** | Guardrails, Ajustes | `Shield`, `Settings` | protection/safety-boundary metaphor, reserved for Guardrails alone (not reused on Administradores, so the two don't visually collide); the literal gear for generic settings |

`lucide-react` is not installed (`package.json` confirmed) — this is the one new dependency; it's a well-known, tree-shakeable icon set already familiar to the design vocabulary this project draws from (ADR-0012 cites a Tailwind-era reference kit for *vocabulary*, not tooling — lucide is framework-agnostic SVG, no Tailwind dependency implied).

### B.3 Per-role snapshot test

Propose `hr-frontend/src/shells/__tests__/AdminShellNav.test.tsx` (Vitest + React Testing Library — `vitest` already runs in CI per Correction-02's "6/6" mention). Render `AdminShell` under a mocked `useAuth()` returning a fixed `Identity` per role (`super_admin`: all 8 abilities true; `hr_agent`: `escalation.work`+`directory.manage`+`analytics.view`; `auditor`: `history.view_all`+`analytics.view`; `knowledge_editor`: `knowledge.edit` only — matching `RoleSeeder.php`'s actual grants), and assert the exact visible **group→item** structure by role, e.g.:

```
super_admin:      Conocimiento[Mapa,Documentos,Revisión] Atención[Escalaciones,Historial] Análisis[Analítica,Cobertura,Calidad] Personas[Directorio,Administradores] Gobierno[Guardrails,Ajustes]
knowledge_editor:  Conocimiento[Mapa,Documentos,Revisión] Atención[Escalaciones]                                                Análisis[Cobertura]                                                Gobierno[Guardrails,Ajustes]
```

This is the direct frontend-side complement to `Sprint8AnalyticsAccessTest.php` (backend payload) and would have caught the Sprint 8 bug from the *rendering* side, not just the payload side, if it had existed then.

---

## C. Filter toolbar (spec §2.3)

### C.1 Today — every control, every param, per screen

**No shared component exists.** `docs-toolbar` (CSS class, `index.css:407-421`) is reused loosely by History/Documents/Review·Reference-facts; Escalations uses a separate `map-toolbar` (`index.css:1458-1464`). Each screen owns its own JSX and state — confirmed no shared React component anywhere.

| Screen | Control | Type | State var | API param | Citation |
|---|---|---|---|---|---|
| **History** (`HistoryPage.tsx`) | full-text search | text | `query` | `q` (`api.ts:1427-1428`) | `:81-97` |
| | Convenio | select | `filters.convenio_id` | `convenio_id` | `:99-118` |
| | Territorio | select | `filters.territory_id` | `territory_id` | ″ |
| | Resultado | select | `filters.outcome` | `outcome` (`answered`\|`escalated`) | `:108-110` |
| | Motivo de escalación | select (from `ESCALATION_REASON_FILTERS`) | `filters.reason` | `reason` | ″ |
| | Desde / Hasta | date × 2 | `filters.from` / `.to` | `from` / `to` | ″ |
| | *(no control)* | — | `employee_uuid` in the type (`api.ts:1405`) but unused in UI | — | — |
| **Escalations** (`EscalationBoardPage.tsx`) | Motivo | select (`REASON_FILTERS` = `ESCALATION_REASON_FILTERS`) | `reason` | `reason` (`api.ts:914-920`) | `:155-180` |
| | Solo asignadas a mí | checkbox | `mineOnly` | `assigned_to=identity.id` | ″ |
| **Review · Reference-facts** (`ReviewQueuePage.tsx`) | Topic | select | `topicFilter` | `topic_id` | `:150-156` |
| **Review · AI tagging / Groups / Vocabulary / Expiry** | *(none)* | — | fixed params only (`tagging_status=under_review`, `status=proposed`, etc.) | — | `:242-419` |
| **Documents** (`DocumentsPage.tsx`) | Tagging status | select | `taggingStatus` | `tagging_status` | `:64,130-139` |
| | Conflicts only | checkbox | `conflictsOnly` | `conflicts_only=1` | `:65,140-147` |
| | Convenio (deep-link chip, × to clear) | chip+button | `convenioId` | `convenio_id` | `:66,148-162` |

**No screen has an active-filter-count badge today.** Partial clear exists only on Documents (convenio chip ×) and History (search-mode "Ver listado" exits search, doesn't clear list filters).

### C.2 Proposed shared component

`hr-frontend/src/components/FilterToolbar.tsx` — a **layout shell, not a state manager**. It owns: the primary search slot (optional — `EscalationBoardPage`/Documents don't have one, History does), a "Filtros" disclosure button that shows/hides a `children` region, an active-filter count badge (computed from a `filters: Record<string, unknown>` prop the toolbar only *reads*, never writes), and a "Limpiar filtros" button wired to an `onClear` callback the page supplies. **Every actual `<select>`/`<input>`/checkbox stays exactly where it is today**, as children — this preserves every param name and every piece of wiring logic verbatim; the shared component only supplies the outer chrome. A tab with zero filters (AI tagging, Groups, Vocabulary, Expiry) renders the toolbar with no "Filtros" button at all (count is always 0, nothing to disclose) rather than a forced empty shell — satisfying "apply to all tabs" as "consistent chrome everywhere," not "invent filters where none exist."

This is additive and low-risk specifically *because* it doesn't touch any `useEffect`/query-building logic in any page — those loops (`HistoryPage.tsx:58`, `EscalationBoardPage.tsx:98`, `DocumentsPage.tsx:76`, `ReviewQueuePage.tsx`'s per-tab effects) are untouched; only the JSX wrapping their existing controls changes.

---

## D. Human statuses (spec §2.4)

### D.1 What's already centralized (Sprint 10a Correction-02 — don't re-invent)

- Frontend: `hr-frontend/src/lib/escalationReasons.ts` — `ESCALATION_REASON_LABELS`/`ESCALATION_REASON_FILTERS`/`escalationReasonLabel()`, imported by `EscalationBoardPage.tsx`, `HistoryPage.tsx` (filter dropdown only, not the RESULTADO column — see §D.2), `AnalyticsPage.tsx:100` (row label, fixed by Correction-02).
- Backend: `EscalationController::REASON_LABELS` (`EscalationController.php:29-66`, 13 entries incl. 3 legacy/defensive keys not in the live CHECK constraint).
- Guard test: `EscalationReasonLabelCoverageTest.php:65-114` — introspects the live Postgres CHECK constraint on `escalation_cards.reason` (not a hand-copied list) and asserts (a) every live value has an explicit label, (b) no two live values share a label.
- Companion: `EscalationExplainerGuardTest.php:19-61` — walks `EscalationExplainer::MATRIX` (49 `reason.sub_outcome` keys, `EscalationExplainer.php:35-104` — counted directly, not taken from any doc's stale figure) and asserts every key has a registry (long-form Spanish) entry.

### D.2 Grep result — raw render sites, beyond the known list

| Where it renders | Current text | Proposed human label | Technical key stays as secondary text? |
|---|---|---|---|
| `HistoryPage.tsx:140` — RESULTADO column | literal `r.escalation_reason` (e.g. `estatuto_fallback_gap`) | `escalationReasonLabel(r.escalation_reason)` — the exact fix the spec named, and the shared helper already exists | Yes — same string available in the row's own detail if a drawer is added later; not currently shown at all, so no regression either way |
| `AnalyticsPage.tsx:101` — "por corrección" table, sub-outcome column | literal `row.sub_outcome` (e.g. `subarea_not_recorded`) | new small map or a shared `subOutcomeLabel()` helper mirroring `escalationReasonLabel()`, sourced from `EscalationExplainer::MATRIX`'s own registry text (short label, not the full sentence) | N/A — this is already the "detail" surface for engineers/analysts, but a human label reads faster in a dense table |
| `GroupsQueue.tsx:213,556` — fact-status badge | literal `f.fact_status` (`verified`/`needs_review`) | `{fact_status === 'verified' ? 'Verificado' : 'Por revisar'}` | Not needed — only two values, self-evident once labeled |
| `GroupsQueue.tsx:365` — node status badge | literal `node.status` (`needs_review`/`approved`/other) | small local label map (3 values) | Not needed |
| `GroupsQueue.tsx:397` — child-node badge | literal `c.status` | same map | Not needed |
| `GroupsQueue.tsx:220,573` — "reason" muted text | literal `f.reason` | **Not a violation** — inspected the binding-planner response shape; this field already carries human Spanish prose (a sentence, not an enum key), just muted-styled. Confirmed no change needed. | — |
| `DocumentsPage.tsx:203-204` — list table | literal `r.retrieval_status` / `r.tagging_status` | `.badge` per value (reuse the existing `badge-verified`/`badge-review`/`badge-historical` classes + a 3-value label map — same shape `DocumentDetailPanel.tsx:591` already uses for `retrieval_status` in a different list) | Yes if desired — but this **is** the list, so the label is primary here, not secondary |
| `DocumentDetailPanel.tsx:257,260` — detail `<dl>` | literal `doc.retrieval_status` / `doc.tagging_status` | **borderline** — this is a genuine detail view (spec's carve-out), but currently has *no* label at all, raw only. Recommend adding the label with the raw key kept alongside (e.g. `Verificado (verified)`), satisfying both the spec's list/detail distinction and giving engineers the key they need | Yes, explicitly, per spec's own rule |
| `DocumentDetailPanel.tsx:896` — admin-only "Sandbox" test-pipeline badge | literal `outcome` (`answer`/other) | low priority — this is an internal debugging tool (`section.sandbox`, "read-only · persists nothing"), arguably fine as-is, but a one-line label (`answer`→"Respondida", else "Escalada") costs nothing | Not needed — it's the primary content of a 2-value debug badge |
| `TracePanel.tsx` (all fields: `pg.reason_code`, `sal.outcome`, `rf.outcome`, etc.) | raw technical strings throughout | **No change** — this is exactly the spec's "detail views (card drawer, trace) as secondary text for engineers" carve-out. Every value here is already narrated in Spanish prose around it (`"convenio nunca cargado · se responde con el Estatuto"` etc.) with the raw key only as a `·`-separated suffix. Confirmed already correct. | Already secondary, by design |
| `factsToSentences()` in `EscalationCardDrawer.tsx:339-341` (`asked`/`found`/`stopped_reason`/`fix_action`) | already human Spanish prose from `EscalationExplainer::registry()` | **Not a violation** — confirmed these are the long-form registry strings, not raw enum keys | — |

**Already correctly labeled, confirmed by this grep (no action needed):** `QualitySampleQueue.tsx:22-34` (`VERDICT_LABELS`, `FAILURE_KIND_LABELS`, both complete 3/5-value maps), `Hierarchy.tsx` gap badges (`GAP_META` in `hr-frontend/src/pages/admin/gapMeta.ts`, referenced `Hierarchy.tsx:9,110,303` — every `GapKind` already has a label+hint+CSS class), `GuardrailsPage.tsx:18-24` (`REASON_LABELS`, 5 entries, falls back to raw only for genuinely-unexpected values).

### D.3 Extending the guard test

`EscalationReasonLabelCoverageTest.php`'s pattern generalizes directly: (a) introspect the live enum (DB CHECK constraint, or — where there's no DB enum, like `fact_status`/`node.status` which are plain PHP-validated strings — a `Rule::in([...])` array, itself reflectable) and (b) reflect the label-map constant, then (c) `assertSame([], array_diff($enum, array_keys($labels)))`. Concretely: add `GroupsQueue`'s 3-value status maps as named consts (`GroupsQueue.tsx`'s own const, or a shared `hr-frontend/src/lib/statusLabels.ts` alongside `escalationReasons.ts`) and add one more coverage-test method per new enum, following `EscalationReasonLabelCoverageTest.php:65-80`'s exact shape. `DocumentsPage`/`DocumentDetailPanel`'s `retrieval_status`/`tagging_status` (3 values each, `DocumentController.php:457-458`) get the same treatment. This is mechanical, not novel — the sprint doesn't need a new testing pattern, just more instances of the one Correction-02 already proved out.

---

## E. Employee chat (spec §2.5)

### E.1 Current components

Single file: `hr-frontend/src/pages/chat/ChatScreen.tsx` (401 lines, page + all subcomponents). Route: `/app` → `EmployeeShell` (`shells/EmployeeShell.tsx:19-20`) → `ChatScreen` (`App.tsx:13-19`).

| Component | Lines | Renders |
|---|---|---|
| `ChatScreen` | `228-400` | full list + input bar |
| `AnswerBlock` | `109-118` | assistant answer card + source line + 👍/👎 |
| `HumanReplyBlock` | `172-179` | HR human reply, clearly attributed |
| `EscalationBlock` | `182-190` | escalated notice |
| `CategoryPickBlock` | `195-226` | salary category quick-pick (the one existing quick-reply pattern) |
| empty state | `337-342` | static `<p className="chat-empty">` — no component, no suggested questions |

CSS: `.chat-empty` (`index.css:1103-1107`, centered, max-width 460px), `.chat-input-bar` (`:1323-`), `.category-pick`/`.category-pick-option` (`:1262-1271` — the button style the welcome-screen chips should reuse, not invent new CSS).

### E.2 Proposed welcome screen

New component `WelcomeScreen` in `ChatScreen.tsx` (or extracted to `WelcomeScreen.tsx` if it grows), replacing the static `<p className="chat-empty">` block at `:337-342`:

```tsx
function WelcomeScreen({ onPick }: { onPick: (q: string) => void }) {
  return (
    <div className="chat-empty">
      <p>Pregúntame sobre tu convenio: jornada, vacaciones, permisos, festivos…</p>
      <div className="category-pick" role="group" aria-label="Preguntas frecuentes">
        {SUGGESTED_QUESTIONS.map((q) => (
          <button key={q} type="button" className="btn btn-secondary category-pick-option"
                  onClick={() => onPick(q)}>{q}</button>
        ))}
      </div>
    </div>
  );
}
```

`onPick` sets `input` to the question text and calls the existing `submit()` — no new API surface, no answer-loop touch; it's the same code path as a typed question.

**Suggested questions — static, deterministic, grounded in what the corpus answers today** (not the spec's example list verbatim — see why below):

| # | Question (es) | Grounded in |
|---|---|---|
| 1 | ¿Cuántos días de vacaciones me corresponden al año? | Estatuto art. 38 — clean own-chunk, confirmed answerable (`sprint-10a/plan.md` §C.8/D.10.1) |
| 2 | ¿Cuánto dura el periodo de prueba en mi convenio? | Estatuto art. 14 — clean own-chunk |
| 3 | ¿Cuál es la jornada máxima anual? | Estatuto art. 34 |
| 4 | ¿Cuántas horas extraordinarias puedo hacer al año? | Estatuto art. 35 |
| 5 | ¿Cuánto preaviso tengo que dar si me voy de la empresa? | Estatuto arts. 49/53 — clean own-chunks |
| 6 | Quiero hablar con una persona de Recursos Humanos | `explicit_request` — always available by design; this **is** the escalation path, so it can never fail awkwardly |

**Deliberate deviations from the spec's own example list** (vacaciones, **permisos**, periodo de prueba, jornada, **nómina**, hablar con RRHH), flagged as an open question rather than silently substituted:
- **Permisos** (matrimonio/fallecimiento, Estatuto art. 37) is currently **buried inside a mis-chunked passage** whose first line is about night work, not permisos (`sprint-10a/plan.md` §C.8, "D1" — a real, already-documented chunking defect, fix tracked as Sprint 10a's F2, not yet shipped as of this writing). Suggesting this question today would likely produce a weak/ungrounded answer or an escalation, which is exactly the class of thing a welcome screen shouldn't advertise.
- **Nómina** (salary) is not excluded on principle — the salary path is fully built and correctly designed to escalate gracefully when a convenio's table is missing (a data-completeness gap, not a bug, per `corpus-coverage.md`) — but a large fraction of convenios have `✗ NO_SALARY_SOURCE` today (12+ of 26 rows), so it would visibly escalate for a meaningful share of the seeded test population, which is a worse first impression than the six above.

This is a corpus-state judgment call, not a code judgment — **§G.3 Q6** asks Pedram to confirm or override it (e.g. keep permisos/nómina in the list anyway, accepting the current escalation odds, especially if F2 ships before this sprint's build starts).

---

## F. Staging fixed OTP (spec §2.6)

### F.1 The flow today, end to end

- **Routes:** `POST /auth/request-code` → `AuthController::requestCode`, `POST /auth/verify-code` → `AuthController::verifyCode` (`routes/api.php:31-35`; `apiPrefix` is `''`, Caddy's `handle_path /api/*` strips the prefix, `hr-docs/infra/compose/Caddyfile:23-25`).
- **Request** (`AuthController.php:26-59`): resolves an *active* employee/admin only (`:123-136` — inactive = treated as unregistered, ADR-0018); generates `random_int(0,999999)` zero-padded (`:42` — **cryptographically random, no fixed/bypass value exists in code today**, confirmed by repo-wide grep for `123456`/`STAGING_FIXED_OTP_CODE` — the only hits are `README.md:52`'s API example and `LoginPage.tsx:89`'s placeholder text); stores only `Hash::make($code)` (`:47`), 10-minute TTL (`CODE_TTL_MINUTES`, `:18`); sends via `Mail::to($email)->send(new LoginCodeMail(...))` (`:53`, synchronous); always returns a generic 200 (`:56-58`, no email-enumeration leak).
- **Verify** (`AuthController.php:64-118`): loads the latest unconsumed code for the email, checks expiry (`:85-89`), caps attempts at 5 (`MAX_VERIFY_ATTEMPTS`, `:20`, `:92-97`), `Hash::check()`s the submitted code (`:99-101`), marks consumed (`:104`), issues a ~24h Sanctum token (`:111`) with `IdentityPresenter::present()` (`:116`).
- **Rate limits** (`AppServiceProvider.php:31-46`): `otp-request` 1/min + 5/hour per email; `otp-verify` 10/10min per email+IP.
- **Staging mail today:** `MAIL_MAILER=log` (`.env.staging.example:70`, `docker-compose.staging.yml` env) — the OTP lands in `storage/logs/laravel.log`; `hr-docs/infra/compose/otp.sh` greps it out for scripted login verification. This is the "convenience" the spec's flag replaces — not a literal `123456` bypass (none exists), but the practice of reading a log file by hand/script every time.
- **ADR-0003 intent** (`hr-docs/architecture/decisions/0003-email-otp-auth.md`): passwordless 6-digit email OTP, hashed at rest, short TTL, single-use, rate-limited; production = Postmark; dev/staging = log/MailHog. Nothing in the ADR anticipates a fixed code — this sprint is a genuinely new, additive convenience layered on top, not a variant of something already designed.

### F.2 Proposed flag, allowlist, and boot-time refusal

**Flag:** `STAGING_FIXED_OTP_CODE` (env var, e.g. a 6-digit string), read via `config('app.staging_fixed_otp_code')` (new `config/app.php` entry, `env('STAGING_FIXED_OTP_CODE')`).

**Allowlist:** a new backend const, same shape as `GuardrailPolicy::CONVERTIBLE_REASONS_BASELINE` (`GuardrailPolicy.php:46-51`) — e.g. `AuthController::STAGING_FIXED_OTP_ALLOWED_DOMAINS = ['example.com', 'hr-staging.internal']`.

**Verify-path change** (`AuthController::verifyCode`, additive branch **before** the existing `LoginCode` lookup at `:73`): if the flag is non-empty, the submitted `email`'s domain is in the allowlist, and the submitted `code` matches the flag's value exactly — resolve the account via the existing `findAccount()` (`:138-145`) and issue a token directly, **skipping the `LoginCode` table entirely** (no row read, no row written, no attempts counter touched). This means:
- **The real OTP path for real accounts is completely untouched** — same code, same table, same rate limits, same hashing, zero new branches in that path.
- The fixed code is **not single-use** (deliberately — that's the whole convenience: a tester types the same code every time without reading a log).
- `requestCode` needs **no change at all** — a tester can still call it for realism (a real code still generates and logs, harmlessly unused) or skip straight to `verifyCode` with the fixed code.
- Non-allowlisted email + the fixed code's literal value → falls through to the normal `LoginCode` lookup, finds no matching row (or a real one, unrelated), and fails exactly as it does today — no special-casing needed to make this safe, it's safe by the branch simply not matching.

**Boot-time refusal in production:** extract the check into a small, directly unit-testable method — e.g. `App\Support\StagingFixedOtpGuard::assertSafeToBoot()` — called from `AppServiceProvider::boot()` (`AppServiceProvider.php:23-26`, alongside the existing `configureRateLimiters()` call, same file/class, same pattern): if `app()->environment('production')` **and** `config('app.staging_fixed_otp_code')` is non-empty → `throw new \RuntimeException('STAGING_FIXED_OTP_CODE must not be set when APP_ENV=production.')`. Extracting it to a static method (rather than inlining the check in `boot()`) makes it directly callable from a unit test with different `app()->environment()`/config combinations, without needing to actually boot a fresh process per test case — there's no existing precedent for an env-gated boot check in this codebase (grepped `config('app.env')`/`env('APP_ENV')` across `app/` and `bootstrap/` — no hits), so this is genuinely new machinery, but it's a small, ordinary Laravel `ServiceProvider::boot()` addition, not a new subsystem.

**Documentation:** a new `deploy.md` checklist line under the existing Postmark item (`deploy.md` §2, near line 32) — "Remove `STAGING_FIXED_OTP_CODE` from staging's env once Postmark lands (real accounts already require the real OTP; the flag becomes pure convenience-debt once test accounts aren't the only login path worth speeding up)."

### F.3 Invariant tests — `Sprint11aStagingOtpInvariantTest`

| T | Assertion |
|---|---|
| T1 | Allowlisted-domain email + fixed code (flag set) → 200, valid token, correct identity |
| T2 | Non-allowlisted email + the fixed code's exact value (flag set) → 422 (falls through to the real path, which has no matching row) |
| T3 | Flag unset (env absent/empty) → the fixed code's value, submitted by anyone, on any domain → 422 (feature is fully inert without the flag — this is the test that proves "off by default" isn't just documentation) |
| T4 | **Boot refusal.** `StagingFixedOtpGuard::assertSafeToBoot()` throws when `app()->environment()` returns `production` and the config value is set; does not throw for `staging`/`local`/`testing`, or when the config value is empty, regardless of environment |
| T5 | A real, seeded active admin/employee account whose email is **not** on an allowlisted domain still requires — and successfully uses — a real generated OTP (regression proof that the new branch doesn't leak into the real path) |
| T6 | The fixed-code path never touches `login_codes` — assert row count unchanged after a fixed-code login, direct proof of "real OTP flow entirely untouched" |

---

## G. Plan output

### G.1 Ordered build steps — tokens first, everything else sits on them

| # | Step | Depends on | Risk | Status |
|---|---|---|---|---|
| 1 | **Token recolor** (§A.2): swap the ~14 existing token values, add `--brand-warm`/`--brand-warm-bg`, add dark-theme `--accent`/`--accent-hover`/`--accent-contrast` (missing today), fix the two `rgba` backdrop literals into a new `--overlay-backdrop` token (§A.3), add the `.badge.ai`/`.node.ai` CSS gap fix (§A.6, if authorized). **No component changes yet.** | none | Low — pure CSS value edits, `--provenance-ai` untouched by construction (different token, not touched by this step) | ✅ **Done** (`hr-frontend/src/index.css`) |
| 2 | **Self-hosted fonts** (§A.4): convert Montserrat/Playfair Display variable fonts to woff2, subset latin+latin-ext, add `@font-face` + imports, swap `--font-sans`/add `--font-display`. | step 1 (shares the same CSS file, sequenced to avoid two people editing `index.css` at once — not a real dependency) | Low | ✅ **Done** (`src/assets/fonts/`, `src/main.tsx`; `@fontsource/inter` removed) |
| 3 | **⏸ CP-1 — static brand preview page.** New admin-gated view (reachable via `#view=brand-preview`, deliberately **not** added to the rendered `<nav>` list so it doesn't leak into production nav before approval), rendering every token, status color, button variant, input, badge, card, and the fuchsia AI badge, in both themes via the existing `ThemeToggle`. | steps 1-2 | none (read-only display) — but **nothing past this point starts** until Pedram approves | ✅ **Built, deployed to staging** — see `review.md`. Awaiting approval. |
| 4 | **Theme config + asset folder** (§A.5): `brand.ts` + `assets/brand/`, swap the two hardcoded "HR Platform" strings to read from it. | CP-1 approval | Low | ✅ **Done** — `theme/brand.ts`; a fresh grep found 4 UI-facing sites (not 2) + `index.html` `<title>`, all swapped |
| 5 | **Nav grouping + icons** (§B.2): add `lucide-react`, wrap the nav in five labeled groups, update the matching `<h2>` copy for the re-homed items (History, Admins). **Revised post-CP-2-feedback:** relocated from a top bar into a collapsible left sidebar (230px / 56px collapsed), same 5 groups/gating, `localStorage`-persisted collapse, instant tooltips + `aria-label`s on collapsed items. | CP-1 approval (shares the token/brand foundation) | Low — zero ability/routing logic touched | ✅ **Done**, then ✅ **revised** — `AdminShell.tsx`, 5 groups (Conocimiento/Atención/Análisis/Personas/Gobierno), now a sidebar; see `review.md` §10 |
| 6 | **Per-role nav snapshot test** (§B.3). | step 5 | none | ✅ **Done**, then ✅ **updated for the sidebar** — `AdminShellNav.test.tsx`, 8 tests: 4 role fixtures + brand-preview-never-visible + 3 new collapse-mode tests |
| 7 | **⏸ CP-2 — nav approval.** | steps 5-6 | — | ⏸ **Sidebar revision live on staging, awaiting re-review** — see `review.md` §10-11 |
| 8 | **Shared `FilterToolbar` component** (§C.2) + adoption on History, Escalations, Documents, Review·Reference-facts (and the chrome-only wrap on the other four Review tabs). | CP-2 approval | Medium — four screens touched, but each screen's own filter-wiring code is untouched by construction; risk is purely "did the wrapper preserve the exact visual behavior" | ✅ **Done** — `components/FilterToolbar.tsx`, adopted on all 5 named screens + chrome-only wrap on the other 3 Review tabs |
| 9 | **Human-status labels** (§D.2): `HistoryPage.tsx` RESULTADO, `AnalyticsPage.tsx` sub_outcome, `GroupsQueue.tsx` (3 sites), `DocumentsPage.tsx` list badges, `DocumentDetailPanel.tsx` detail `<dl>` labels-with-key. | independent of 1-8 — can run in parallel | Low — additive label lookups, no data/behavior change | ✅ **Done** — `lib/statusLabels.ts`, applied at all named sites |
| 10 | **Extend the enum guard test** (§D.3) to the new label maps. | step 9 | none | ✅ **Done** — as a **frontend** Vitest guard (`statusLabels.test.ts`, 11 tests), not backend PHPUnit: these 5 maps have no backend PHP label-array counterpart to `ReflectionClass` against, and reading a sibling repo's `.ts` file from `hr-backend` would be new, unprecedented cross-repo test coupling (see `review.md` §7 step 10 for the full reasoning) |
| 11 | **Employee chat branding + welcome screen** (§E.2). | steps 1-4 (needs the finished token/brand layer) | Low — additive component, reuses `.category-pick` CSS, no answer-loop touch | ✅ **Done** — `lib/suggestedQuestions.ts` (Pedram's exact 5, §G.3 Q6), `WelcomeScreen` in `ChatScreen.tsx` |
| 12 | **Staging fixed OTP** (§F.2-F.3) — fully independent of 1-11, can build in parallel from day one. | none | Medium — touches auth, but scoped to one new branch + one boot check, both independently testable before touching the real path | ✅ **Done** — `AuthController::tryStagingFixedOtp()`, `StagingFixedOtpGuard`, `Sprint11aStagingOtpInvariantTest` (T1-T6, all passing); full backend suite (711 tests) green |
| 13 | **⏸ CP-3 — final eyes-on** (spec §5), in a real browser against staging, light and dark, every role. | all of the above | — | Not started |

**Steps 4-11 are built and live on staging** (nav grouping, filter toolbar, human statuses, theme config/asset folder, employee chat welcome screen) — full verification (`tsc -b`, `eslint`, `vitest`, `vite build`, `php artisan test`) all clean, see `review.md` §7-9 for the complete build/verify/deploy record. **Step 5's nav was then revised** per CP-2 feedback from a top bar to a collapsible left sidebar — same content/gating, new layout — rebuilt, re-tested, and re-injected to staging; see `review.md` §10-11. **CP-2 is live, awaiting Pedram's re-review.**

### G.2 Tests

- `AdminShellNav.test.tsx` (§B.3) — per-role nav snapshot.
- `FilterToolbar` component test — renders with 0/1/3 active filters, asserts count badge and clear-button behavior; per-screen tests confirm the exact same API calls fire before/after wrapping (snapshot the `listHistory`/`listEscalations`/`listDocuments` call args).
- Extended `*LabelCoverageTest` methods (§D.3) — one new enum per method, mechanical extension of the existing pattern.
- `Sprint11aStagingOtpInvariantTest` (§F.3) — T1-T6.
- **Unchanged, re-run green:** `Sprint7cAdditivityRegressionTest`, `EscalationReasonLabelCoverageTest`, `EscalationExplainerGuardTest`, `Sprint8AnalyticsAccessTest`, the full backend + frontend suites (`tsc -b --noEmit`, `vitest run`, `npm run build`, `eslint`) — this sprint touches zero answer-loop code, so the standing regression bar is "everything that passed before still passes," not new coverage of the answer loop itself.
- Eyes-on (spec §5), real browser, staging, both themes, every role — the standing rule this project has learned the hard way (`deploy.md` §6a's `crypto.randomUUID()` story) applies double to a presentation-layer sprint: nothing here is meaningfully verified by an API test.

### G.3 Open questions — RESOLVED (Pedram, 2026-09-22)

1. **`--teal`/`--teal-weak`** — **Retire.** Repointed its two use sites (`.lens-row--ruling`, `.lens-node--ruling`) to `--brand-warm`/`--brand-warm-bg`. Done in step 1 (build).
2. **Status-color option A vs B** — **Option B** (the retuned set). Done in step 1 (build) — light `--danger #b42318`/`--warning #946200`/`--success #2f7a3d`/`--info #2a6ea6` + their `-bg` tints.
3. **The second `Playfair/` (non-Display) family** — **Ignored, not bundled.** Only `Playfair_Display/`'s two variable-font files were subset/converted. Additionally: **`@fontsource/inter` removed** from `package.json` now that Montserrat is in (done in step 2 — nothing references Inter anymore).
4. **Add a baseline CSP this sprint** — **Not this sprint.** Added as a roadmap item under `deploy.md` §6b (go-live ops, alongside the TLS blocker it shares a Caddyfile with), with an explicit note that **Sprint 11c's proposed 3D graph library must be smoke-tested under it once it exists** (a library that self-hosts everything through bundled ES imports needs no exception; one that loads a WASM/worker/URL resource might).
5. **The `GroupsQueue.tsx` `.badge.ai`/`.node.ai` styling gap** — **Fixed, in step 1.** `.badge.ai`/`.node.ai` CSS rules added, mirroring `.ai-pill`/`.ai-marked` exactly.
6. **Welcome-screen question list** — **Pedram's exact five**, overriding my corpus-gap-based substitution (the art. 37 mis-chunking fix he cites shipped in Sprint 10a, and "¿Cuánto preaviso...?" is flagged as a known-escalating question from that sprint's eval — both facts this plan's §E.2 reasoning had stale). Final list, static config, **nómina stays out**:
   1. ¿Cuántos días de vacaciones me corresponden al año?
   2. ¿Cuántos días de permiso tengo por matrimonio?
   3. ¿Cuánto dura el periodo de prueba en mi convenio?
   4. ¿Cuál es mi jornada anual?
   5. Quiero hablar con una persona de Recursos Humanos

   ✅ **Built** — step 11 (§G.1), `lib/suggestedQuestions.ts` + `WelcomeScreen` in `ChatScreen.tsx`, live on staging. See `review.md` §7 step 11.

### G.4 ⏸ Checkpoints

| ⏸ | When | What Pedram does | Why it needs a human |
|---|---|---|---|
| **CP-1** | After build step 3 | Review the static brand-preview page (`#view=brand-preview` on staging) — every token, status color, button/input/badge/card, the fuchsia AI badge, light **and** dark — before anything is applied across real screens | This is the whole visual system in one place, on the actual deployed host, before it touches a single production surface. Nothing past this point starts without approval. **✅ Approved, 2026-09-22. See `review.md` §1.** |
| **CP-2** | After build step 7 | Approve the nav grouping + icon choices (§B.2), **revised to a collapsible left sidebar** (both themes, expanded and collapsed, at least one non-`super_admin` role) | A navigation change is felt by every admin on every visit; the icon choices and the sidebar layout in particular are a judgment call worth a second pair of eyes before they ship. **✅ Approved, 2026-09-22 (sidebar revision). See `review.md` §11.** |
| **CP-3** | After build step 13 | Final eyes-on (spec §5) — real browser, staging, every role, both themes | Standing project rule: nothing here is meaningfully verified by an API test; the `crypto.randomUUID()` incident (`deploy.md` §6a) is the standing proof of why. **✅ Passed, 2026-09-22. See `review.md` §11.** |
| *(optional)* **CP-0** | Before step 1, if wanted | A live staging walkthrough of the *current* state (today's nav/badges/OTP) before approving the *proposed* changes, since this plan's staging verification was HTTP-level (§1), not a logged-in walkthrough | Only needed if the reviewer wants to see "before" in a browser, not just in this document, before approving "after" |

---

## STOP — CP-1 (approved)

Steps 1, 2, 3, and 12 were built and (per `review.md`) went live on staging via uncommitted-code injection — nothing committed anywhere, per the standing no-commit rule. Pedram approved CP-1 on 2026-09-22 (brand system confirmed in both themes, including the round-1 map-edge and Groups dark-mode fixes) and instructed the build to proceed through steps 4-11, stopping at CP-2.

## STOP — CP-2

Steps 4-11 are built and live on staging (§G.1) — full verification (`tsc -b`, `eslint`, `vitest`, `vite build`, `php artisan test`) all clean, see `review.md` §7-9 for the complete record.

**CP-2 feedback (round 1):** before approving, Pedram asked for a design revision — the admin nav (step 5) moves from a top bar into a collapsible left sidebar (230px / 56px collapsed, `localStorage`-persisted, instant tooltips on collapsed items). Same View union, same hash routing, same ability gating — JSX/CSS relocation only. Rebuilt, `AdminShellNav.test.tsx` updated (8 tests, same per-role assertions plus 3 new collapse-mode tests), full verification re-run clean, re-injected to staging. See `review.md` §10-11 for the complete record, including two explicitly flagged judgment calls (top bar disappears entirely rather than shrinking; the logo renders alone, as its own `alt` text, rather than beside a separately-visible product-name label).

**Build stops here, as instructed.** Step 13 (CP-3, final eyes-on) does not start until Pedram approves CP-2 — now specifically the sidebar revision, both themes, every role, on staging.

## CLOSED — CP-2 and CP-3 approved, sprint complete

Pedram approved CP-2 (sidebar revision, both themes) and then performed and passed CP-3 (final eyes-on, spec §5), both 2026-09-22. Step 13 is done. All 13 build steps and all three checkpoints are now closed. `hr-frontend`, `hr-backend`, and `hr-docs` were committed on `sprint-11a` and merged `--no-ff` into `main`, pushed; staging was redeployed from the merged `main` SHAs via `deploy.sh` (replacing every prior round's uncommitted-code injection) and re-verified. See `review.md` §11-13 for the complete approval and close-out record, including exact commit SHAs, the post-deploy verification table, and the RDS snapshot record.
