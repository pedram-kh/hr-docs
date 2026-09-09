# Sprint 7f — Cursor kickoff prompt (plan-gate)

> Paste into a **fresh** Cursor thread. Inspect, plan, and **stop** — no code, no fixture ingest, until the plan is reviewed.

---

You are in the `hr-platform` workspace. Sprints 0–7e and Correction-salary-01 are merged on `main` and deployed to staging (`corpus-coverage.md` is current: 104 docs, 3,626 chunks, 14 salary tables / 179 rows / 94 `convenio_job_categories`, **0 `reference_facts`**). **This is Sprint 7f — structured group-scope modeling, the last sprint of 7.** Read `roadmap.md` Sprint 7f and `hr-docs/sprints/sprint-07f/spec.md`.

7f has a precondition and three phases: **Phase 0** runs the reference-fact flow on staging for real (there are no facts there to scope); **Phase 1** makes group scope structured on the fact and the employee; **Phase 2** has the AI *propose* each convenio's group structure (sub-areas only where values differ) for a human to approve; **Phase 3** replaces the digit-regex matcher with an exact comparison. No AI at answer time. Unresolved still escalates.

Before anything, read in full and cite real lines:
- **The bug:** `ReferenceFactAnswerService` — the four-tier resolution ladder and `factMatchesGroup` (the bare-digit regex), `resolveEmployeeGroupCode` (via `job_category.group_code`), and the 7c invariant tests that cover the ladder. `sprint-07c/review.md` §7 and the roadmap 7f entry (the exact facts 202/203 case).
- **The vocabulary that exists:** `convenio_job_categories` on staging — the 94 rows, their `group_code` values as actually stored (decimals, prose, nulls, clean digits — report the real distribution per convenio, this decides the schema), `name`, and which convenios have none. `pagas_count`/labelled monthlies from Correction-salary-01.
- **The fact side:** `reference_facts` schema (`group_label` in the logical key, `job_category_id`, the 7b-2 additive fields), `ReferenceFactProposalService`, the 7d resolution surface (supersede/coexist/reject), and the 7b-2 eval harness (`sprint-07b-2/eval/gold-set.json`, `score_eval.py`, the three fixtures in `sprint-07b-2/fixtures/`). Confirm the segmentation model/config path on staging (`ANSWER_MODEL` vs a segmentation setting) and that `POST /segment-facts` is present (401 not 404).
- **The employee side:** `employees` scope columns (`convenio_id`, `territory_id`, `job_category_id`), the directory create/edit form and CSV bootstrap (`DirectoryPage`, the CSV validate→report→apply path), `staging:seed-test-users --chat-profiles`.
- **The propose pattern to reuse:** `ProposeDocumentTags`/`TagProposalService` (7a), `SegmentReferenceSource` (7b-2), the review-queue tabs, fuchsia `--provenance-ai`, append-only `tag_events`. ADR-0007/0011/0015/0016/0020/0021/0022/0023.
- **The frozen loop:** `Sprint7cAdditivityRegressionTest` (must stay green) and the 7c reference-fact tests (extended, not weakened).

Your task this turn: **inspect the real substrate and data, and plan — no code, no ingest.**

Produce `hr-docs/sprints/sprint-07f/plan.md`, then **STOP and wait for review.** Cover:

1. **What exists (reality check).** The matcher and ladder with line cites; the **real `group_code` distribution** across the 94 categories (this is the load-bearing data — show it); the employee scope fields; the fact schema; the staging segmentation config; the 7b-2 eval harness state. What is reuse vs new.
2. **Phase 0 plan.** Ingest the three fixtures as `reference_source` on staging; run segmentation; run `score_eval.py` against the gold set on staging (state what "reproduces" means and how a delta would be reported); the Pedram verification checkpoint (the cross-province spot-check + the Navarra Hostelería trio); the 7d resolution pass for the file-1/file-2 pairs; the two 7c baseline checks (convenio-wide fact answers; group-scoped fact escalates for a group-less employee). Cost/time estimate.
3. **Phase 1 — the schema, decided against the real data.** Propose the structured shape: a `convenio_groups` table (`convenio_id`, `group_code` normalized, `label` as printed, optional `parent`/`sub_area`) vs an additive shape on `convenio_job_categories` — pick one **from the real `group_code` distribution**, and show how the 94 categories map onto it (deterministic where `group_code` is clean; proposed where it isn't). The scope key `(convenio_id, group_id[, sub_area_id])`; additive columns on `reference_facts` and `employees`; the directory/CSV picker (convenio-scoped, existing vocabulary only, blank = unresolved). Normalization rules for `group_code` (`I`/`1`/`Grupo 1`/`1.1`?) — stated, deterministic, tested.
4. **Phase 2 — the propose flow.** `POST /propose-groups` (inputs: convenio text pages + categories + existing `reference_facts.group_label`s; output: groups, labels, category membership, **sub-areas only where the text assigns different values, each with a justifying excerpt**; closed-set validated; writes nothing). The `ProposeConvenioGroups` job; the Groups review surface (tree, excerpts, categories, the facts that would bind); approve/edit/reject with the **fact-binding diff** the human confirms; provenance. The **group eval**: gold structures for the fixture convenios, scored for exact/over/under-split — **under-split is the failure metric**.
5. **Phase 3 — the exact matcher.** The new Tier-2 comparison rules (exact group+sub-area; fact-has-sub-area + employee-blank → escalate; undivided group matches on group; unbound `group_label`-only facts not group-matchable); delete the digit regex; tests (a)–(f) from the spec; what stays untouched in 7c; the golden-trace gate.
6. **Migrations & build order** (Phase 0 → 1 → 2 → 3, with the Pedram checkpoints), all additive; hr-ai gains one read-only endpoint, no migration; **ADR-0028**.
7. **Assumptions & open questions** — esp. the schema choice, `group_code` normalization, whether to derive employee group from `job_category.group_code` automatically (my lean: yes where the code is clean and unique, shown as a *proposed* default the admin confirms, never silent), the CSV column, and anything the real category data makes non-obvious.

Hard constraints:
- **No AI at answer time** (ADR-0015/0016); the matcher is an exact comparison on approved structured scope. **Unresolved → escalate**, never guess. **The digit regex is deleted.**
- **AI proposes structure; a human approves; nothing exists until approved** (ADR-0020). **Sub-areas only where values differ.** **Never mint categories** (ADR-0011).
- **Employees are never auto-assigned silently**; a derived default is shown and confirmed.
- **Additive migrations; hr-backend owns writes; hr-ai reads/returns, never migrates.** **Answer loop untouched beyond the matcher's comparison**; golden trace green.
- **Phase 0 is a rerun of the proven 7b-2 flow, not a redo** — drift is recorded and fixed narrowly.
- **Feature sprint:** plan → review → build → review → commit. Work on a `sprint-7f` branch; no direct-to-main commits.

Do not create or modify any file other than `hr-docs/sprints/sprint-07f/plan.md` this turn. After writing it, stop and say it is ready for review.
