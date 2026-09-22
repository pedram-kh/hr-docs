# Sprint 11a — Spec: Brand identity, navigation, filter toolbar, human statuses, staging login

> Save as: `hr-docs/sprints/sprint-11a/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Sprint 11 is phased: **11a** look & feel (this spec) · **11b** full ES/EN translation · **11c** 3D knowledge graph (2D/3D toggle) on the Map.
> Depends on: ADR-0012/0013 (token design system), ADR-0018 (server is the boundary; nav gated by abilities), ADR-0020 (fuchsia = unverified AI only), Sprint 10a Correction-02 (shared reason labels + enum guard test).
> Brand assets: provided by Pedram under `hr-docs/sedena/` (logo `LOGO-SEDENA-01.svg`, palette `sedena_cromatic_range.png`, Montserrat, Playfair Display).

---

## 1. Goal

Make the platform immediately recognisable as part of the client's digital ecosystem, and easier to scan — without changing a single answer, decision, or permission. Everything in 11a is presentation, plus one staging-only login convenience.

## 2. In scope

### 2.1 Brand theme layer
- Map the client palette onto the existing tokens:
  - `#F3F4F2` Gris Claro → page backgrounds, neutral surfaces
  - `#173F3E` Verde Profundo → structure, navigation, high-contrast text
  - `#2B6565` Verde Sedena → primary accent, CTAs, active navigation, icons
  - `#D9B56D` Arena → warm accent, highlights, secondary data, signage (**not** body text on light backgrounds — contrast)
  - `#DCE9E6` Verde Niebla → soft states, active filters, supporting backgrounds
- **Typography:** Montserrat (UI text) + Playfair Display (headings / display), **self-hosted in the bundle** (no external font CDN — the CSP must stay closed).
- **Status colours** (error / warning / success / info) do not exist in the palette: design them to harmonise with it, and meet **WCAG AA** contrast in both light and dark mode.
- **Dark mode:** derived variants for every token, same contrast bar.
- **Components restyled via tokens:** buttons (primary/secondary/ghost/danger), inputs, selects, badges, cards, tables, drawers/modals, tabs.
- **AI provenance stays fuchsia (`--provenance-ai`) exactly as today** — every AI-related surface (AI proposal badges, unverified AI content, AI summaries). It must remain visually distinct from every brand colour.
- **White-label rule:** the client name, logo and product name live in one theme config + asset folder. **No client name hardcoded in components.** (Standing project rule: the code prefix is `hr-`, the codename never appears in code.)

### 2.2 Navigation redesign (icons + labels, grouped)
Group today's 12 items; labels shortened; one icon per item (lucide, bundled per icon):
- **Conocimiento:** Mapa, Documentos, Revisión
- **Atención:** Escalaciones, Historial
- **Análisis:** Analítica, Cobertura, Calidad
- **Personas:** Directorio, Administradores
- **Gobierno:** Guardrails, Ajustes

Role-based visibility is preserved exactly: an item a role can't access is not shown; a group with no visible items is not shown. The server remains the boundary (the nav is a convenience, never the permission).

### 2.3 Filter toolbar (one pattern, reused)
One shared toolbar component: **main search field prioritised**, secondary filters (convenio, territory, status, reason, dates) grouped behind a **"Filtros"** control with an active-filter count and one-click clear. Applied to: History, Escalations board, Review queue (all tabs), Documents. Behaviour of every filter unchanged — layout only.

### 2.4 Human-readable statuses everywhere
Extend the shared label source (`escalationReasons.ts` + backend `REASON_LABELS`) so **no raw internal key** (`salary_coverage_gap`, `estatuto_fallback_gap`, `low_confidence`, `explicit_request`, …) renders anywhere in the admin UI — including History's RESULTADO column (currently raw). Also humanise other raw enums that surface in the UI (fact/document statuses, sub-outcomes) where the plan finds them. The technical key stays visible in detail views (card drawer, trace) as secondary text for engineers. The enum guard test extends to every place labels render.

### 2.5 Employee chat — branded + welcome screen
- Brand the employee chat (logo, palette, typography) — this is the surface 1,500 employees see.
- **Welcome screen** on an empty conversation: short greeting + 4–6 suggested questions (e.g. vacaciones, permisos, periodo de prueba, jornada, nómina, hablar con RRHH). Clicking one sends it as the employee's message. The list is a **static, deterministic config** — never AI-generated.

### 2.6 Staging login convenience (replaces "password 123456")
Keep the OTP flow. Add a **staging-only fixed OTP code**:
- Enabled only by an explicit env flag (e.g. `STAGING_FIXED_OTP_CODE`), and only for allowlisted test domains (`@example.com`, `@hr-staging.internal`).
- **Hard-refused in production:** if the flag is set while `APP_ENV=production`, the app fails loudly at boot. Invariant test proves it.
- Real accounts on staging still require a real OTP.
- Documented in `deploy.md`; removed from the go-live checklist when Postmark lands.

## 3. Out of scope
- Translations (11b) — 11a keeps current language; new strings go through whatever the plan proposes so 11b extracts them cleanly.
- Knowledge graph (11c).
- Any change to the answer loop, answer text, caveat, escalation explanations, routing, permissions, or data.
- Any password system.

## 4. Acceptance criteria
1. Every screen uses brand tokens; no hardcoded colours remain in components (grep-verifiable). Fuchsia used only for AI provenance.
2. Contrast check (AA) recorded for every text/background token pair, light and dark.
3. Fonts served from the bundle; production CSP unchanged and verified on the deployed host.
4. Nav: per-role snapshot test — each role sees exactly the items it did before, now grouped.
5. No raw reason/status key renders in any admin list view (guard test over the live enum, extended).
6. Staging fixed-OTP: works for allowlisted test accounts on staging; refused for real emails; app refuses to boot with the flag in production (test).
7. `Sprint7cAdditivityRegressionTest` green; full suite green; no answer text changes.
8. Eyes-on (§5) in a real browser on staging, light and dark mode.

## 5. Eyes-on (staging, real browser)
1. Log in as `admin@` with the fixed code → works. Try a non-allowlisted email → fixed code refused.
2. Walk every nav group as `super_admin`, then as `hr_agent` and `auditor` → correct items per role, icons + labels, active state clear.
3. History, Escalations, Review, Documents → single toolbar, search first, "Filtros" opens the rest, active-filter count correct.
4. History RESULTADO and the board show human labels; card drawer still shows the technical key as secondary text.
5. Review queue: AI proposals still fuchsia; manual facts neutral badge; nothing else fuchsia anywhere.
6. Employee chat: branded, welcome screen with suggested questions; clicking one sends it and gets the normal answer.
7. Toggle dark mode on every page above.

## 6. Risks / plan-gate questions
- **R1 contrast:** Arena and Verde Niebla as text colours — plan proposes where each may and may not be used.
- **R2 CSP + fonts:** self-hosted font files must load under the production CSP; verify on the deployed host, not locally.
- **R3 nav regressions:** the nav key in the identity payload was a real Sprint 8 bug — snapshot per role.
- **R4 hidden raw keys:** the plan must grep every render site for raw enum strings, not rely on the known list.
- **R5 fixed-OTP safety:** boot-time refusal in production is the core safety; the allowlist is the second line.
