# Sprint 11b — Spec: Interface translations (ES/EN)

> Save as: `hr-docs/sprints/sprint-11b/spec.md`
> Status: SPEC — plan gate next. No code until the plan is reviewed and build is authorized.
> Depends on: 11a (statusLabels, brand shell), 11c (its strings get extracted too). Last piece of Sprint 11.

---

## 1. Goal

The full interface — admin and employee — available in **Spanish (default)** and **English**, switchable per user, with the choice persisted. Interface chrome only.

## 2. The hard boundary (non-negotiable)

**Translate the chrome, never the content.** These are NOT translated, not wrapped, not touched:
- Answer text produced by the answer loop, including the fixed legal caveat and the Estatuto-fallback caveat (deterministic strings pinned by golden traces and tests)
- Escalation explanation texts shown to employees
- Names of convenios, documents, topics, groups, job categories (data, not chrome)
- Fact values, source excerpts, trace payloads
- Anything the backend serves as content

The suggested welcome questions stay Spanish-only in v1 (they are sent as literal employee messages into the Spanish answer loop; an English UI user still sends the Spanish question). The plan states this in the welcome component.

## 3. In scope

- **String extraction:** every user-facing literal in `hr-frontend` (admin + employee shells, all pages, 11a's statusLabels + FilterToolbar + welcome screen, 11c's Grafo UI — chips, captions, side card, tooltips) moves to a dictionary. Spanish is the source of truth; English translated from it.
- **Mechanism:** the plan proposes the library (react-i18next vs a small typed hand-rolled `t()` — argue bundle size, plural/interpolation needs, and type safety; we have no RTL, no lazy locale loading needed at 2 locales).
- **Switcher:** in the admin sidebar footer and the employee chat menu (the 11c hamburger). Persisted (localStorage; plan may propose profile-level later). Default: Spanish.
- **Backend-sourced chrome:** the plan inventories backend strings that reach the UI as interface (validation errors, API error messages shown in toasts) and proposes the pragmatic v1 line: translate frontend-side where mapped, leave raw where not — listed, not hidden.
- **Formats:** dates and numbers shown in the UI follow the active locale (`Intl`), without touching stored data.
- **Guard test:** a test asserting the protected strings of §2 (caveat text etc.) contain no i18n wrapping — the boundary is enforced, not hoped.

## 4. Out of scope

- Translating answers, caveats, explanations, or any data (§2). Basque/Catalan (future locale = new dictionary file, nothing structural). RTL. Server-side locale negotiation. Email templates (Postmark, go-live ops).

## 5. Acceptance criteria

1. Language switcher works in both shells; choice persists across reloads; Spanish default on first visit.
2. Zero hardcoded user-facing literals left in components (grep/lint rule enforced — plan proposes the mechanism, e.g. an eslint rule or a CI grep with an allowlist).
3. English UI: every screen readable, no `missing key` artifacts, no mixed-language screens (except §2 content, which stays as-is by design).
4. §2 guard test green; golden traces untouched; full suites green.
5. Bundle: locale dictionaries add ≤ 15 KB gzip to the entry (two locales, no lazy loading needed — plan reports real numbers).
6. Eyes-on (§6) on staging in both languages, both shells, both themes.

## 6. Eyes-on (staging)

1. Admin in Spanish: walk the five sidebar groups — everything Spanish, including Grafo chips and FilterToolbar.
2. Switch to English in the sidebar footer → every screen flips; reload → still English.
3. Review queue in English: labels/badges English, but fact values, source excerpts, convenio names stay Spanish (correct — data).
4. Employee chat in English: chrome English; ask a question → the answer and caveat arrive in Spanish, unchanged (correct — §2); welcome questions still Spanish.
5. Mobile: language option reachable in the chat hamburger.
6. Both themes, quick pass.

## 7. Risks / plan-gate questions

- **R1:** string count — the plan measures the real number before committing to a timeline.
- **R2:** concatenated/interpolated strings that don't survive naive extraction (plurals, "X de Y") — plan lists them.
- **R3:** the eslint/CI guard against future hardcoded strings — without it, 11b rots in a month.
- **R4:** anything in `hr-backend` responses that is chrome-not-content — inventoried, v1 line drawn explicitly.
