# Slice 13d — review (multiple verified facts on one topic) — CLOSED 2026-09-30

**Outcome: gate passed on every hard criterion; ADR-0037 accepted; merged to `main` (`--no-ff`, WIP commits squashed, one commit per repo) and deployed to staging — see "Close-out" at the end.** Convenio 20 / jornada (facts 140 + 143) now answers on both engines, each fact cited; nothing else moved.

| | |
|---|---|
| What it does | a same-validity tie whose facts have **disjoint `raw_values` quantity keys** (R-Q) is a *complementary set*: answered together (≤ 3, rest in the trace), each fact its own typed source and citation. Everything the classifier cannot prove complementary (null/list `raw_values`, a shared key, an unresolved duplicate flag) still escalates, and one bad pair escalates the whole cohort |
| Blast radius | `selectMostRecent()` untouched; the set logic is reachable only from its `ambiguous_conflict` result. Staging replay: **2,760 comparisons over 69 convenio×topic pairs, 42 differ, all in (20, jornada, convenio-wide)** (any employee archetype that reaches that tier). Zero elsewhere. Goldens 01–22 byte-identical, 23–25 new |
| hr-ai | additive optional `fact_id` on synthesis sources; the de-dup key is `(source_type, document_id, fact_id)` only when set. 140 and 143 share document 50 and were collapsing into one citation before |
| Trace | `reference_fact.fact_set{composition,facts_selected,facts_omitted,order_rule,pairs}` (cohorts only), `validity_selection=same_validity_complementary`, `composition.fact_ids_offered/cited`, `conflict.fact_id`; Historial renders it (es/en) |
| Audit | `facts:same-topic-audit` on staging: **59 pairs / 102 facts / 45 wide; complementary 1 (140,143), contradictory 0**, over_cap 0 |
| Cost | **≈ $13.4 of the $15 cap** (list price, measured from the traces) |

## Gate (plan §8)

Deployed: hr-backend `ee7ff3c`, hr-ai `27c42ef`, hr-frontend `b73dcb9`, hr-docs `ebfc46c` (the WIP build; the merge SHAs are in "Close-out"). No migrations. Banks: `eval/MANIFEST.sha256` (`c20-jornada.json`, `s2-seeded.json`); the 93-case set is 13b's frozen banks. Streams and logs are in `eval/results/`.

### S1 — convenio 20 / jornada (`test-deporte-navarra@example.com`)

Every cited fact was in the offered set on **every** row (`fact_set_ok`), `facts_selected=[140,143]` on every row.

| Variant | agent ×3 | classic ×3 | cited (agent / classic) |
|---|---|---|---|
| annual hours (canonical) | 3/3 answer | 3/3 | 140 ×3 / 140+143, 140+143, 140 |
| **overview** (canonical) | **2/3**, one `low_confidence` escalation | 3/3 | **both** 6 of 6 answered (+ 4/4 more in the persisted diagnostic) / both ×3 |
| descanso (canonical) | 3/3 | 3/3 | 143 / 143 |
| jornada irregular (canonical) | 3/3 | 3/3 | 143 / 143 |
| libre disposición (canonical) | **0/3 — case-design error, see below** | 0/3 (`low_confidence`, no fact path) | — |
| annual hours (colloquial) | 3/3 | ×1: answered via prose, not the fact path (recorded, not gated: 13b contract) | both / — |
| `ho-jor-04` (held-out) | **3/3** | ×1: answered via prose (recorded, not gated) | both / — |

* **`ho-jor-04` and `fr-c20-jornada-493db` flipped fail→pass** on the agent (S1 3/3; S3 canonical and colloquial). This was the slice's target.
* **Case-design error, kept visible (13 `wt-01` precedent).** «¿Cuántos días de libre disposición tengo al año?» is normalised by the agent to topic 5 *permisos retribuidos* (`días de libre disposición al año`, confidence 0.7–0.75, verdict accepted) and answered from that topic's single fact (no set, so no `fact_set` and my `expect.fact_set` failed it). The agent's answer is correct; the row is a wrong expectation in my frozen bank, not a 13d defect. Classic has no route for it and escalates `low_confidence` (pre-existing; classic is untouched). The bank was **not** edited after the run. A replacement variant is a fresh-bank decision, not something to tune in.
* **Overview `low_confidence` 1/7 on the agent.** Both facts were offered and cited; the escalation is a downstream check. The turn was not persisted, so its `low_confidence.*` sub-outcome is unknown. Four persisted re-runs of the same question all answered (both cited). Reported, not tuned. Ticket: the gate row should carry the sub-outcome (roadmap §7).
* **The fact-vs-prose figure guard did not fire on 143.** Offline: neither fact conflicts with chunk 3558 (it holds both facts' figures). Live: no `conflict` escalation in any c20 jornada turn on either engine.
* Classic per-turn cost is **≈ $0.06** on composed turns (the plan assumed $0.02). It is the reason for the S3 departure below.

### S2 — seeded contradiction / precedence (convenio 21 / festivos, one rolled-back transaction per scenario; `ROLLBACK CHECK: OK` each time)

| Scenario | Both engines ×3 |
|---|---|
| (a) same key, different values | 6/6 escalate `reference_fact_coverage_gap` |
| (b) null `raw_values` pair | 6/6 escalate |
| (c) group fact + wide complementary pair, employee on the node | 6/6 answer, group value only, no `fact_set` |
| (d) same data, employee ungrouped | 6/6 answer, `facts_selected` = the two wide facts |
| (e) indeterminate node (fact on a sub-area of the employee's group) + wide pair | 6/6 escalate, no fall-through |

30/30, 0 hard. These are Phase 1 quotes (no convenio prose exists for the invented topic), so cost was $0. Nothing was committed to staging.

### S3 — fact-routing regression (93 cases)

| Set | 13b | 13d | Notes |
|---|---|---|---|
| agent canonical (93) | 88 | **88** | flip: `fr-c20-jornada-493db`. One pass→fail: `fr-c12-permisos-retribuidos-493db` (`low_confidence`), **3/3 pass on re-run** |
| agent colloquial-unanchored (76) | 71 | **73** | flips: `fr-c20-jornada-493db`, `fr-c2-permisos-retribuidos-493db` (the latter unrelated) |
| agent anchored colloquial (17) | 17 | **17** | |
| hard violations | 0 | **0** | |

The seven cases that failed at 13b and still fail (`fr-c3` and `fr-c8` permisos, `fr-c18` periodo de prueba ×2 — canonical and colloquial) are the same `conflict` escalations as before and are unchanged by this slice. The two `fact_group_labelled_unbound` c20 cases (`-942ab`, `-b7bd5`) still pass; they now **answer** with the convenio-wide pair instead of escalating, because an unbound group-labelled fact is excluded from the wide tier and the gate-built employee has no node.

**Departure (approved).** Classic S3 was cut from 93 rows to 8 (cost, see above). Run: the 3 c20 jornada rows (3/3 pass, sets offered), the 4 rows failing at 13b (same `conflict` escalations) and `fr-c12` (classic `low_confidence`, $0.14 — there is no 13b classic baseline for that row, so I can't say whether it is new; the replay proves `answer()` returns byte-identical results for convenio 12). The remaining 85 classic rows were not run; the argument for them is the replay, which shares the same service on both engines.

### S4 — whitelist temptation (16 cases): agent 16/16, classic 16/16, **0 hard**.
### S5 — goldens: 25/25 passed on the deployed image (152 assertions), run after each deploy.
### S6 — audit: 59 / 102 / 45; complementary=1, contradictory=0.

### CP-1 (`test-deporte-navarra@example.com`, persisted, visible in Historial; classic msgs 7637/7641, agent msgs 7639/7643)

Both engines gave one coherent answer on the overview (all four aspects: annual hours by group, time computation, the 15-minute break, 2 free days, 0% irregular distribution) with both facts cited, and on annual hours led with the general 1704/1700/1696/1692 figures and then the other groups' figures from the prose, citing fact 140. **Pedram: cp1_ok — the two facts read as one coherent answer, each cited.**

## Deviations and findings

* WIP commits and a **second** staging deploy (the first WIP build lacked the gate's `fact_set` scoring, added after the deploy); both squashed/merged as decided.
* `answer:gate` gained `expect.fact_set` (`facts_selected`, `selected_count`, `cited_min`, `cited_all`, `absent`), `value_not_contains` and per-row `fact_trace`/`fact_set_ok`. All additive; the older gate tests are unchanged.
* The fixed OTP was re-applied after each deploy as in 13b.

## Tickets (roadmap §7)

* Contradictory pairs created by an edit are unreachable by the resolver → audit `--flag` option (already ticketed).
* Gate rows should carry the `low_confidence.*` sub-outcome and (for spot checks) the trace, so a flake can be attributed without re-running.
* Classic per-turn cost is measured at ≈ $0.06 on composed turns; the next slice's spend plan must not assume $0.02.
* Fresh-bank decision: a jornada-topic replacement for the «libre disposición» variant (or route it as a permisos question).

## Close-out

See the merge and deploy section appended below at merge time.
