# Slice 12b — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-12b/kickoff-prompt.md`

---

You are planning **Slice 12b** in the hr-platform workspace. Read `hr-docs/sprints/sprint-12b/spec.md`, then Sprint 11a's plan/review (sidebar, FilterToolbar, tokens), 12a's glossary (`alcance`; `Status`/`Flags` stay English), and `statusLabels.ts`. Write `hr-docs/sprints/sprint-12b/plan.md`, then **STOP — no code, no commits, no staging writes.**

Cite `path:line`. Everything is presentation except item 2, which is a one-off staging data operation and must be planned as such (predicate, dry run, counts, rollback = snapshot).

1. **Item 2 — eval-traffic predicate (R1).** Read-only query on staging: how many escalations are open, by status; how many originate from `@example.com` / `@hr-staging.internal` accounts, from `answer:gate` sessions (session label / gate marker), from CP/eyes-on runs. Propose the exact predicate, the dry-run output format, the service method used to close (never raw SQL), the reason code `eval_traffic_pre_pilot`, and what stays open. Report the numbers in the plan.
2. **Item 8 — collapsible groups.** Current sidebar structure (`AdminShell.tsx`), the icon-only mode and mobile overlay; propose the fold mechanics, persistence key per group, the active-group rule, keyboard/a11y, and how the per-role snapshot tests change (R3).
3. **Item 4 — panel separation.** The shared drawer/modal/panel CSS today; propose the token(s) (`--panel-canvas`, block card surface/border/radius/margin), show every drawer/modal that inherits them, and the contrast rows for both themes (R2).
4. **Item 6 — analytics labels (R4).** Grep every Analítica chart, legend, axis and table for values rendered raw; table of key → proposed Spanish → English; how `statusLabels`/the enum guard extends to cover them.
5. **Items 1, 7 — flags.** Where `VITE_SHOW_CHUNK_HEALTH` and `VITE_SHOW_COVERAGE` are read; defaults; how the demo build sets them off and the normal build on; hash route for Cobertura stays reachable.
6. **Items 3, 5.** Escalation card conversation collapse (default collapsed, question visible, per-session memory); FilterToolbar one-line layout at ≥ 1280 px with graceful wrap — show the current toolbar markup and the change.
7. **Build order, tests, eyes-on list, open questions, the single CP-1** (everything live on staging; item 2 executed last, after Pedram confirms the dry-run counts).

Then **STOP** and wait for review.
