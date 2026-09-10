# Sprint 8 — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread. Inspect, plan, and **stop** — no code until the plan is reviewed. Branch `sprint-8`.

---

You are in the `hr-platform` workspace; Sprints 0–7g are merged on `main` and live on staging. **This is Sprint 8 — Analytics, coverage gaps, quality sampling.** Read `roadmap.md` Sprint 8 and `hr-docs/sprints/sprint-08/spec.md`.

Sprint 8 is **read-only measurement over data that already exists**: every turn records `router_decision`/`floor_decision.{path,outcome,escalation_reason,authority_used}`; every card records `reason` + (since 7g) `explanation_facts.{sub_outcome,fix_action,fix_surface,fix_link}`; `corpus:coverage` already computes the coverage grid; `employees` carries scope + group. Five instruments, one optional feedback signal, one gated decision. **No change to the answer loop.**

Before anything, read in full and cite real lines:
- `message_traces` (the `floor_decision`, `router_decision`, `guardrail_check` shapes and which fields are always present vs path-dependent), `chat_messages`/`chat_sessions` (roles incl. `hr_agent`; session windows), `escalation_cards` + `escalation_events` (statuses, timestamps, `explanation_facts`), `conversation_access_log` (ADR-0018 — what must be logged when a reviewer opens a turn), `employees` (scope + `convenio_group_id`), the roles/abilities (`EnsureCan`, which roles may see what).
- **`corpus:coverage`** (the command behind `corpus-coverage.md`): its queries, reason codes, and Part-4 logic — this becomes the shared query behind the coverage screen.
- The embedding primitives available for clustering questions (`/retrieve`'s query embedder; `/compare-scope`; the 7d harness) — confirm a **read-only** way to embed N question strings and get cosine neighbours without a new model or an hr-ai migration.
- **The design system and the admin page patterns to reuse (ADR-0012/0013):** the token files (`index.css` — colour/spacing/type tokens, light/dark), the **Knowledge-Center hierarchy component** (`Hierarchy.tsx`, graph + list, leaf-opens-card — the coverage map reuses it as a lens), the **Review-queue table/tab pattern** (`ReviewQueuePage`, with the 7g pagination + totals), the **card/drawer** pattern (`DocumentDetailPanel`, `EscalationCardDrawer`), the **status badges** (`--provenance-ai`, `--warning`, `--danger`, `--neutral`), the Map's hand-rolled SVG graph (the charting precedent). The Sprint-4 board (assignment/resolution timestamps) and the publish fence outcomes (`escalation_events.detail` with 7d scores).
- `authority_used` semantics (ADR-0015/0023) and the 2c national-law gold tests + the 7d anchors (for the Estatuto item).
- Real staging counts: turns, answered/escalated by path, cards by reason/sub_outcome, employees per convenio — paste them; the plan should be designed against the real (thin) distribution.

Your task this turn: **inspect the real substrate and plan — no code.**

Produce `hr-docs/sprints/sprint-08/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The exact fields each instrument needs and whether they're always populated; the real staging counts; what `corpus:coverage` already computes and what §4 adds (headcount join, the no-registry-convenio rows, trend, export); the embedding path for clustering; reuse vs new.
2. **Deflection & outcomes (§1).** The definitions (state them exactly — what counts as answered/escalated/needs_category; how `hr_agent` turns are treated), the period/scope filters, the `path` and `authority_used` splits, and the **rollup strategy** (nightly aggregate table vs live queries — decide for 1,500-employee scale; the `stats:*` commands that reproduce every number).
3. **Escalations by fix (§2).** The reason → sub_outcome → fix_action grouping; links to cards and fix surfaces; board throughput (time-to-resolution per agent, conversion rate, fence outcomes from `escalation_events.detail`). How pre-7g cards (null `explanation_facts`) are handled (backfill first, or an "unexplained" bucket — decide).
4. **Top/unanswered questions (§3).** The topic-anchor grouping; the **embedding clustering** (algorithm, threshold, medoid as the label, no LLM), its cost/cadence (nightly job?), the "unanswered" ranking (escalation rate × volume × headcount).
5. **Coverage map (§4).** The shared query (`corpus:coverage` refactored into a service both the screen and the export call), the headcount join, the reason-code vocabulary reuse, the no-convenio rows, the gaps-closed trend (snapshot table), the export button, and the **agreement test** (screen == ledger).
6. **Quality sampling (§5).** `quality_samples` schema, the stratified draw (by path × territory, seeded, reproducible), the review screen (one decision per turn: correct/partially/wrong + kind + note), access logging, the "wrong → task with fix link" rule, the monthly trend, roles.
7. **Feedback (§6) — recommend keep or drop** for staging; if keep: `message_feedback` schema, the chat component, where it shows.
8. **Estatuto (§7).** The exact evidence you'll pull from `authority_used` + the national-law gold tests to decide; the re-chunk procedure if triggered (2c splitter, gold tests, 7d anchors re-run).
9. **Access & privacy.** Which roles see which screens (spec §6), server-gating, and how every drill-down to a conversation goes through the existing logged path — never a new unlogged view.
10. **Design — per screen, which existing component it reuses.** Analytics (KPI tiles + a bar/line chart — the only new primitives, added to the token system, hand-rolled SVG on tokens), escalations-by-fix + clusters + samples (the Review-queue table/tab pattern with pagination + totals), the coverage map (the Knowledge-Center hierarchy component as a coverage lens; a gap cell opens the existing document card), drill-downs (the card/drawer pattern), reason codes and verdicts (the signature badges). Flag anything that would need a new primitive; no third-party charting theme, no Tailwind, no new framework.
11. **Migrations & build order** (additive: `quality_samples`, `message_feedback`, rollup/snapshot tables), hr-ai = none (state how clustering reuses the existing embedder), **ADR-0030**, tests (definitions, agreement test, stratification, access matrix, golden trace).
12. **Assumptions & open questions** — esp. the deflection definition edge cases, the cluster threshold, rollup cadence, what "current year" means for salary ✓, and anything the real data makes non-obvious.

Hard constraints:
- **Read-only over the answer path**: no change to routing/retrieval/synthesis/grounding/escalation decisions; golden trace green.
- **Every number reproducible** from a documented query/command; **the coverage screen and the ledger share one query**.
- **No LLM labelling or summarising of analytics**; clusters are embedding-based with a real question as the label.
- **ADR-0018 everywhere**: aggregates are free; any view of a conversation is role-gated and logged.
- **Design system only (ADR-0012/0013):** the existing tokens, light/dark, and the named admin components; no new UI framework, no Tailwind, no third-party charting theme; charts are hand-rolled SVG on tokens or plain tables; any genuinely new primitive is added to the token system, not styled ad hoc.
- **Additive migrations; hr-ai never migrates; no new model.** Feature gate on `sprint-8`.

Do not create or modify any file other than `hr-docs/sprints/sprint-08/plan.md` this turn. After writing it, stop and say it is ready for review.
