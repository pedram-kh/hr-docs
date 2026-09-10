# Sprint 8 — Analytics, coverage gaps, quality sampling

> Location: `hr-docs/sprints/sprint-08/spec.md`
> Reviewer: Claude (architecture) · eyes-on: Pedram (on staging)
> Read first: `data-model.md` §8 (`message_traces` — `router_decision`, `guardrail_check`, `floor_decision.{path, outcome, escalation_reason, authority_used, grounding}`), §9 (`escalation_cards` — `reason`, and since 7g `explanation_facts.{sub_outcome, fix_action, fix_surface, fix_link}`, `explanation_text`), `conversation_access_log` (ADR-0018 — every read of a conversation is logged, incl. the sampling review), `employees` (scope columns + `convenio_group_id`), `corpus-coverage.md` **Part 4** (the hand-run coverage grid this sprint makes live), `deploy.md` §5/§4b (the known gaps), `roadmap.md` Sprint 8, ADR-0016/0018/0023/0029, the 7d eval/anchors (`fence:calibrate-semantic`) and the 2c national-law gold tests (for the Estatuto item).
> **Why now, ahead of the data pass.** Sprint 8 is *read-only over data that already exists* — every turn and every escalation already records what it did and why. Building the instruments first makes the go-live data pass **targeted**: the coverage map ranked by headcount says which gaps to close first; escalations-by-fix says whether "group not on file" or "no topic tag" is the bigger fire. The first numbers will look bad. That is honest, not broken.

## Goal
Give HR (and the project) the five instruments that show whether the platform is working — **deflection**, **what people ask**, **what it can't answer and why**, **where the holes are (by headcount)**, and **whether it is still right (monthly sampling)** — as read-only dashboards over existing tables, role-gated, with every number reproducible from a query. Plus one optional signal (employee thumbs-up/down) and one gated technical decision (the Estatuto re-chunk). **No change to the answer loop. Built entirely on the existing design system and admin page patterns (§8).**

## In scope

### 1. Deflection & outcomes (the headline number)
- Per period (day/week/month, filterable by territory/sector/convenio): turns, **answered vs escalated vs needs_category**, **deflection rate** = answered / (answered + escalated). Split answered by `floor_decision.path` (prose / `salary_sql` / `reference_fact` / `reference_fact_composition`) and by `authority_used` (convenio-governed vs Estatuto-baseline vs structured_reference) — the last one matters: a high "answered from the Estatuto" share on a topic the convenio should cover is a coverage smell, not a success.
- HR replies and `hr_agent` turns counted separately (human work), so deflection is honest about who did the work.

### 2. Escalations by reason → sub-outcome → fix (the to-do list)
- Volume by `reason`, then by `explanation_facts.sub_outcome`, then **grouped by `fix_action`/`fix_surface`** — so the board reads *"41 escalations would be prevented by assigning employee groups; 18 by importing one salary xlsx; 9 by approving convenio X's group tree."* This is the data pass, ranked. Each row links to the cards and (via `fix_link`) to the surface that closes it.
- Time-to-resolution and open/assigned/resolved counts per agent (the Sprint-4 board, measured). Conversion-to-knowledge rate (resolved → published ruling) and publish-fence outcomes (blocked / acknowledged / passed, with the 7d `detail` scores) — the flywheel's throughput.

### 3. Top questions & unanswered questions
- Top questions **by topic** (the existing topic lexicon anchor on the question) and **by meaning cluster** — reuse the 7d embedding machinery (`/retrieve`-style embed of the question text, cosine-cluster nearest neighbours; no new model, no LLM labelling) so "¿cuántos días de vacaciones tengo?" and "vacaciones que me corresponden" count as one question. Show the cluster's representative phrasing (the medoid), count, answer rate, and top escalation reason.
- **Unanswered questions** = clusters whose escalation rate is high, ranked by volume × headcount of the asking scopes. This is the *demand-side* gap map (what people want and can't get), complementing the supply-side map in §4.

### 4. The coverage-gap map (live; replaces the ledger's Part 4)
- For every **active convenio in the registry** × the four knowledge types: **prose ✓/✗** (≥1 active, embedded document; amendment-only flagged), **salary ✓/✗** (a table for the current year), **facts ✓/✗ (group-only?)**, **rulings**. Joined to **`employees` headcount per scope** (convenio, and group where set) and ranked by people affected. Territory × sector combinations that have employees but **no registry convenio at all** surface as their own row (the "we don't even have the agreement" gap).
- Each gap cell names its **reason code** (the ledger's codes: `SCAN_NO_TEXT`, `UNDER_REVIEW_SCOPE`, `NO_CONVENIO_MATCH`, `SALARY_PDF_NOT_IMPORTED`, `EXPIRED_NO_SUCCESSOR`, `GROUP_SCOPED_FACT`, `FACT_NEEDS_REVIEW`, `MISTAG`) and its **unblocking action + link** — the same vocabulary as `corpus-coverage.md`, so the two agree by construction. **`corpus:coverage` becomes the query behind this screen**; the markdown ledger stays as the point-in-time export (a "Export snapshot" button writes it), no longer hand-maintained.
- Trend: gaps closed per week (so the data pass shows progress).

### 5. Monthly accuracy sampling (the drift alarm)
- `quality:sample --month=YYYY-MM --n=N` draws a **random, stratified** sample of *answered* turns (stratified by path and by territory so a rare path isn't missed), creating `quality_samples` rows. A **review screen** (Review → *Calidad*) shows HR the question, the answer with citations, the trace summary, and three verdicts — **correct / partially / wrong** — plus a free note and the failure kind (wrong scope / wrong figure / stale document / unclear / other). Every review is a logged conversation access (ADR-0018). Reviewer ≠ the agent who handled any related card.
- Trend over months: accuracy by verdict, by path, by territory; **any "wrong" verdict opens an escalation-style task** with the fix link (a wrong answer is a defect to chase, not a statistic). This is the human-in-the-loop eval, made routine — the same "measure, don't assume" discipline as 7b-2/7d/7e, on live traffic.

### 6. Employee feedback (optional, small)
- A thumbs-up/down on each answer in chat (one click, optional comment), stored per assistant message, shown in §1/§3 as a satisfaction rate. **The only item that touches the employee surface** — one component, no answer-path change. Include unless Sedena says otherwise.

### 7. The Estatuto re-chunk — a decision, gated (roadmap follow-up)
- Using §1's `authority_used` data + the 2c national-law gold tests: report whether Estatuto-baseline answers show weak/wrong precedence (e.g. Estatuto cited where a convenio chunk existed; the *trabajo a distancia → national_law* case). **If the data shows a problem** → re-chunk doc 73 with the 2c splitter behind the national-law gold tests, re-run the 7d calibration anchors after (the fence compares against these chunks). **If not** → record "no evidence, not done" and leave it. Either way, the decision is written down with the numbers.

### 8. Design — the existing design system and page patterns, nothing new
- **Tokens only:** every screen uses the vanilla-CSS token system (ADR-0012/0013 — colour, spacing, type scale, light-default + dark toggle). No new UI framework, no Tailwind, no third-party charting theme.
- **Reuse the established admin patterns:** the **Knowledge-Center hierarchy component** (graph + list, leaf-opens-card) for the coverage map — it *is* the Map with a coverage lens, and a gap cell opens the same document card HR already knows; the **Review-queue table/tab pattern** (with the 7g pagination + visible totals) for every list (escalations-by-fix, clusters, samples); the **card/drawer** pattern for drill-downs; the **signature status badges** (`--provenance-ai` fuchsia, `--warning`, `--danger`, `--neutral`) for reason codes and verdicts.
- **Charts:** hand-rolled SVG on the design tokens (the Map's graph is the precedent), or a plain table where a table says it better. Expect at most two genuinely new primitives — a KPI tile and a bar/line chart — and those are added **to the token system**, not styled ad hoc.
- The plan names, per screen, which existing component it reuses and flags any new primitive.

## Out of scope
- Any change to routing, retrieval, synthesis, grounding, precedence, or escalation *decisions* (read-only measurement; golden trace stays green). No new knowledge type. No LLM-generated summaries or labels of analytics (clusters are embedding-based; representative text is a real question). No per-manager dashboards, exports beyond the ledger snapshot, or alerting/monitoring (pre-go-live ops). No GDPR retention/erasure (Sprint 9) — but every new table respects ADR-0018 (access logged, role-gated).

## Acceptance criteria
1. **Analytics** (Admin → *Analítica*) shows §1 deflection/outcomes with period + scope filters, §2 escalations by reason → sub-outcome → fix with counts and links, §3 top/unanswered clusters — every figure reproducible by a documented query (a `stats:*` command set that prints the same numbers).
2. **Coverage map** (Admin → *Cobertura*) shows every active convenio × four types with reason codes, unblocking links, headcount ranking, the no-registry-convenio rows, and a gaps-closed trend; **its numbers equal `corpus:coverage`** (a test asserts the screen's query and the ledger export agree); "Export snapshot" writes `corpus-coverage.md`.
3. **Quality sampling:** `quality:sample` draws a stratified sample; the review screen records verdicts with logged access; a "wrong" verdict creates a task with a fix link; the monthly trend renders; a test proves the sample is stratified and reproducible from its seed.
4. **Feedback:** thumbs-up/down stored and shown (if kept in scope).
5. **Estatuto decision** recorded with evidence; if re-chunked, national-law gold tests + 7d anchors green.
6. **Design:** every screen on the token system and the named existing components (§8); no new framework/theme; any new primitive lives in the token system.
7. **Access:** analytics/coverage readable by `super_admin`/`hr_agent`/`auditor`; quality review by `hr_agent`/`super_admin`; every conversation view logged; `knowledge_editor` sees coverage only. Server-gated (`EnsureCan`), API-tested.
8. **Additivity:** no answer-loop change (golden trace green); additive migrations only (`quality_samples`, `message_feedback`, any aggregate cache table); hr-ai gains nothing (clustering reuses `/retrieve`'s embedder via the existing endpoint or a read-only sibling — the plan decides).

## Eyes-on (staging)
Open *Analítica*: see the real (small) staging numbers — deflection over the test traffic, escalations grouped by fix ("assign employee group" should top the list after 7f). Open *Cobertura*: the 8 full-gap convenios ranked by headcount, each with its reason code and a link that opens the right review surface; export the snapshot and diff it against the last hand-run ledger — identical. Run `quality:sample --n=5`, review the five as HR (mark one "partially"), see the trend. Thumbs-down an answer in chat as a test employee; see it in the satisfaction rate. Ask a prose and a salary question → answered exactly as before.

## Risks / notes
- **Small data on staging.** The instruments are correct but the numbers are thin; design for the 1,500-employee scale (aggregates, not row scans on every load — a nightly rollup table is fine) and make filters work with sparse data (no empty-state crashes).
- **Clusters must never be LLM-labelled** — a representative *real* question is the label. Anything else is the model narrating the data.
- **The coverage map and the ledger must agree by construction** (shared query), or the two will drift and nobody will trust either.
- **Sampling is a human workflow** — keep the review screen to one decision per turn; HR won't do it monthly if it's tedious.
- **Privacy:** analytics show aggregates; the drill-down to a conversation goes through the existing role-gated, logged path — never a new unlogged view (ADR-0018).

## Definition of done
All criteria; Pedram eyes-on; docs — `architecture.md` §10.5 (the analytics module: the five instruments, the shared coverage query, the sampling workflow), `data-model.md` (`quality_samples`, `message_feedback`, rollups), `deploy.md` (the ledger is now an export; the sampling cadence in the runbook), `corpus-coverage.md` regenerated by the export, `roadmap.md` (8 DONE; the Estatuto decision recorded), **ADR-0030 — Measurement: read-only, reproducible, embedding-clustered, human-sampled; the coverage map and ledger share one query**. `sprint-08/review.md`. Feature gate on `sprint-8`; stop before merge.
