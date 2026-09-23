# Sprint 11b — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-11b/kickoff-prompt.md`

---

You are planning **Sprint 11b** in the hr-platform workspace. Read `hr-docs/sprints/sprint-11b/spec.md` first. Write `hr-docs/sprints/sprint-11b/plan.md`, then **STOP — no code, no commits.**

Ground rules: inspect the real code; cite `path:line`; measure, don't estimate. The §2 boundary is absolute — if a string is produced by the backend as content (answers, caveats, explanations, data), it is not touched, and the plan must show it knows exactly where that boundary runs.

## A. The strings, measured

1. Count the real user-facing string literals in `hr-frontend`, per file/area (admin pages, employee shell, 11a's statusLabels/FilterToolbar/welcome, 11c's grafo module). State the method (AST-based or grep + manual pass) and the number. Flag R2 cases: interpolations, plurals, concatenations.
2. Inventory backend-sourced strings that reach the UI as chrome (validation messages, error toasts, API error bodies rendered verbatim): where each renders, and the proposed v1 disposition (frontend-mapped vs left raw), as a table.
3. List the §2 protected strings with their exact source locations (caveat constants, fallback caveat, escalation explanation texts, welcome questions) — these are the guard test's fixture list.

## B. The mechanism

4. Recommend react-i18next vs a small typed hand-rolled `t()` for exactly 2 locales, no RTL, no lazy loading: measured bundle cost of each, type-safety story (missing-key = compile error?), interpolation/plural support for the R2 cases found in A.1. Recommend one with reasons.
5. Dictionary layout (per-shell? per-page? one file?), Spanish as source of truth, and how English review happens (a single `en.ts` a human can read top to bottom).
6. The anti-rot guard (R3): propose the eslint rule or CI grep that fails on new hardcoded user-facing literals, with its allowlist mechanism (technical strings, class names, keys).
7. Locale switcher: exact placement in the admin sidebar footer and the chat hamburger (cite the 11c components), persistence key, default resolution order (localStorage → 'es').
8. `Intl` date/number formatting: list the render sites showing dates/numbers today and which formatter each moves to.

## C. Plan output

9. Ordered build steps (mechanism + guard first, then extraction area by area, English last), test inventory (including the §2 guard test), open questions, and ⏸ checkpoints — at minimum:
   - **CP-1:** mechanism + first area (the admin sidebar + one full page) live on staging in both languages, switcher working — Pedram approves the pattern before mass extraction.
   - **CP-2:** final eyes-on (spec §6).

Then **STOP** and wait for review.
