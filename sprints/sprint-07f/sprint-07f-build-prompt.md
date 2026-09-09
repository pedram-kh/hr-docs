# Sprint 7f — Cursor build-authorization prompt

> Paste into the Sprint 7f Cursor thread (the one that wrote `plan.md`). The plan is approved — its three real-data findings (`group_code` can't carry the model; the demo convenios have no categories; the headline fact is compound) are correct and every correction moves toward less inference. All six §7 decisions are confirmed below. This authorizes the **build on a `sprint-7f` branch**, Phase 0 → 1 → 2 → 3, with **two Pedram checkpoints** and the **§2.7 SQL gate** enforced. Feature-sprint gate: **no merge to main until reviewed.**

---

The Sprint 7f plan (`hr-docs/sprints/sprint-07f/plan.md`) is approved as written. Work on `sprint-7f` in all four repos. Apply the decisions below exactly.

## The six §7 decisions — confirmed
1. **Tree, depth ≤ 2** (`convenio_groups` self-referencing, `parent_id`, `UNIQUE (convenio_id, parent_id, code_normalized)`, the depth CHECK). A sub-area is a child node; the employee and the fact point at a node at either level. Confirmed over the spec's two-field wording — the spec described the concept, the tree implements it better.
2. **Fact → scope via a join table** (`reference_fact_group_scopes`), never by splitting a compound fact. A verified fact is never mutated; one fact may bind to several nodes.
3. **No derivation from `job_category.group_code`.** The employee's pre-filled default comes from **approved** group→category membership only, shown and confirmed on save, never silent. `resolveEmployeeGroupCode` and `factMatchesGroup` are **deleted**.
4. **Decimals are never split** (`2.1` stays `2.1`). If a decimal encodes a real hierarchy, the proposer says so with an excerpt.
5. **COEAS Navarra forward-fill is a proposal**, justified by the block structure, never applied deterministically.
6. **CSV: two columns** — `group`, `sub_area` — matched against the approved tree by `code_normalized` or exact `label`; unknown → row-level validation error (the existing validate→report→apply discipline), never a guess.

Also confirmed: #8 — do **not** clean the mis-filed category rows in convenios 15/18; log as a salary-import follow-up. #9 — **pin `HR_AI_ANSWER_MODEL=claude-sonnet-4-5` explicitly in the staging compose** now (deploy hygiene; commit on `main` separately, one line, so model drift is a deliberate change). #10 — the re-group blind spot is noted, not a 7f regression.

## Phase 0 — reference facts on staging (the precondition)
1. **Run the §2.7 gate first** — the SQL that must return **zero rows** (verified group-scoped facts × employees with a resolvable digit group). Record the result.
2. Ingest the three fixtures from `sprint-07b-2/fixtures/` as `reference_source`; let `SegmentReferenceSource` run (`claude-sonnet-4-5`, the 7b-2 model). Record cost/time.
3. `score_eval.py` against the 7b-2 gold set **on staging**. "Reproduces" = 0 mis-scoped and the same statutory/jornada restraint (0/0). Any delta: report it as a finding with the exact facts; **do not touch the 7b-2 prompt, gold set, or scorer** unless a genuine mis-scope appears (the only trigger).
4. **⏸ CHECKPOINT 1 — Pedram verifies.** Print the Reference-facts tab links. He checks the cross-province spot-check (COEAS Álava G1 = 5 meses; Andalucía/Estatal = 6) and verifies the working set: the **Navarra Hostelería trio** (*Grupo 1 y área 5 de Grupo 2 = 90/75/60*, *Grupo 2 resto áreas = 60/45/30*, *Grupo 3 = 45/30/15*) + the COEAS Álava/Andalucía/Estatal groups. Wait for "done."
5. The 7d resolution pass on the file-1/file-2 pairs (supersede, never delete; the re-group pair stays flagged per #10).
6. **The two 7c baseline checks:** a convenio-wide verified fact answers a scoped test employee (`path: reference_fact`); a **group-scoped** fact **escalates** `reference_fact_coverage_gap` for a group-less employee. **Re-run the §2.7 gate — still zero rows.**
7. Ledger re-run (`reference_facts` > 0; the new reason-code rows). Snapshot `hr-staging-7f-phase0`.

## Phase 1 — the schema (§3, all additive, hr-backend only)
`create_convenio_groups_table` → `create_convenio_group_categories_table` (membership join, outside the salary-owned table) → `create_reference_fact_group_scopes_table` → `add_convenio_group_id_to_employees` (one nullable FK). `code_normalized` rules exactly as §3.5, applied at propose time only, shown beside the printed `label`; **never re-derived at answer time**. `GET /admin/groups?convenio_id=` picker (approved tree); `DirectoryPage` two-level select cleared on convenio change; CSV two columns. Migration tests + the normalization table test.

## Phase 2 — AI proposes, human approves (§4)
- hr-ai `POST /propose-groups` (read-only, writes nothing, never migrates): inputs = the convenio's `document_pages` text + its categories (+ `group_code` as **evidence only**) + its `reference_facts.group_label`s; output = the proposed tree with printed labels, category memberships, and **sub-areas only where the text assigns different values — each with the justifying excerpt**; closed-set validation of any category ids; the forward-fill offered as a proposal with the block excerpt.
- `ProposeConvenioGroups` job (the 7a/7b-2 pattern) → inert `ai_agent`/`needs_review` proposals → the **Groups** review tab (tree, excerpts, categories, the facts whose `group_label` would bind to each node) → **approve / edit / reject per node**, append-only provenance; approval creates the nodes and shows the **fact-binding diff** the human confirms before any `reference_fact_group_scopes` row is written.
- **The group eval:** gold trees for the fixture convenios (Navarra Hostelería with the área-5 split; COEAS Álava/Andalucía/Estatal unsplit; Navarra Intervención Social G1/G2), scored exact / over-split / **under-split**. Gate on **0 under-splits**; report membership accuracy without gating. Record the numbers in the review and ADR-0028.
- **⏸ CHECKPOINT 2 — Pedram approves the Navarra Hostelería tree** (expect: G2 split into *área 5* / *resto áreas* with the periodo excerpt; G1 and G3 unsplit) and confirms the binding diff (the compound fact → {G1, G2›área 5}; the resto fact → {G2›resto áreas}; G3 → {G3}). Wait for "done."

## Phase 3 — the exact matcher (§5)
Tier 2 exactly as §5.1 (integer node equality; child-of-employee → **escalate**; parent-with-approved-children → **escalate**; escalate is a **hard stop for the tier**, never a fall-through to a less-specific answer); zero-bound facts never group-matchable. **Delete** `factMatchesGroup` and `resolveEmployeeGroupCode`. `match_kind = 'group'`, trace records the node id + printed label. Tiers 1/3/4, the pre-check, only-verified, validity, authority, skip-ground/composition — untouched.

**Tests (a)–(h)** as §5.3, on the convenio-21-shaped fixture (no categories — the real shape): (a) G2›resto → **60/45/30**, never 90; (b) G2 parent → escalate; (c) G1 → **90/75/60** via the compound binding; (d) undivided Álava G1 matches on the group; (e) unbound `group_label`-only fact → escalate; (f) "Grupo 12" never matches G1/G2; (g) the compound fact answers identically from G1 and G2›área 5; **(h) `convenio_group_id = null` behaves exactly as today** — the day-one no-regression invariant for every real profile. `Sprint7cAdditivityRegressionTest` green; full suite green.

**The live proof on staging:** set the Navarra Hostelería test employee to G2›*resto áreas* → *"¿cuál es mi periodo de prueba?"* → **60/45/30**, cited, `path: reference_fact`; switch to G1 → **90/75/60**; set to G2 (parent) → escalates. Paste all three traces. **Re-run the §2.7 gate one last time** (it is now vacuous — the digit matcher is gone — say so).

## Hard constraints (carry)
- **No AI at answer time** (ADR-0015/0016); the matcher compares approved node ids. **Unresolved → escalate, never guess.** **The digit regex is deleted.**
- **Nothing exists until a human approves** (ADR-0020); sub-areas only where values differ; **never mint categories** (ADR-0011); `salary:import`/`salary.py` untouched.
- **No employee is ever assigned a group without an admin saving the form** (or a validated CSV row).
- **Additive migrations; hr-backend owns writes; hr-ai reads/returns, never migrates.** Answer loop untouched beyond Tier 2; golden trace green.
- **Phase 0 is a rerun, not a redo.** The 7b-2 prompt/gold/scorer are not modified absent a genuine mis-scope.
- **Feature sprint on `sprint-7f`; no direct-to-main commits** (the one exception: the model-pinning compose line, which is deploy hygiene on `main`).
- **Escalation explanations (Phase 4) are NOT in this sprint** — a follow-on with its own prompt (employee: one fixed neutral message; HR: AI prose over deterministic facts + a structured fix link).

## Docs at close
**ADR-0028 — Structured group scope**: granularity follows value differences; a depth-2 tree, not `group_code`; facts bind via a join table (compound facts are bound, never split); AI proposes with excerpts / human approves / nothing exists until approved; exact node comparison at answer time, no digit matching, no AI; unresolved escalates; the §1.2 `group_code` finding that made the old matcher unworkable; the §2.7 gate; the group-eval numbers. `architecture.md`, `data-model.md` (the three tables + the employee FK + the scope key), `deploy.md` (reference facts on staging ✓; the HR group-assignment task + CSV columns in the go-live list; the §8 follow-up; the model pin), `corpus-coverage.md` re-run, `roadmap.md` (**7f DONE → Sprint 7 complete**). `sprint-07f/review.md` with the Phase-0 eval, the group eval, tests (a)–(h), the three live traces, and both checkpoints. Then **STOP — do not merge until I review.**
