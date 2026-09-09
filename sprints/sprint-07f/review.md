# Sprint 7f — Review

> Location: `hr-docs/sprints/sprint-07f/review.md`
> Plan: [`plan.md`](plan.md) · Spec: [`sprint-07f-spec.md`](sprint-07f-spec.md)
> Branch: `sprint-7f` in all four repos. **Not merged.**
> Status: **Phase 0 complete, awaiting Checkpoint 1 (Pedram).**

---

## Phase 0 — the reference-fact flow on staging

Precondition, not optional: there were **0 `reference_facts` on staging** before this sprint, so flow 3 had never run on real infrastructure and there was nothing for 7f to scope.

### 0.1 The §2.7 gate — run 1 of 3 (before ingest)

The roadmap's hard precondition (roadmap.md line 173) in executable form. Must return zero rows:

```sql
SELECT e.email, c.name AS convenio, jc.name AS category, jc.group_code
FROM employees e
JOIN convenio_job_categories jc ON jc.id = e.job_category_id
JOIN convenios c ON c.id = e.convenio_id
WHERE jc.group_code IS NOT NULL AND trim(jc.group_code) <> ''
  AND EXISTS (SELECT 1 FROM reference_facts rf
              WHERE rf.convenio_id = e.convenio_id
                AND rf.status = 'verified' AND rf.group_label IS NOT NULL);
```

**Run 1 result: `(0 rows)`** ✅ — with `reference_facts` at 0 and `reference_source` documents at 0, trivially satisfied.

### 0.2 Ingest

The three 7b-2 fixtures ingested as `reference_source` via `DocumentIngestor` (the `documents:ingest-folder` ops path), run inside the live `hr-backend` container through the shared SSM `entrypoint.sh`.

**The queue worker was stopped for the duration of the ingest and restarted afterwards.** This is deliberate and worth recording: `DocumentIngestor` dispatches `SegmentReferenceSource` after commit, and `ReferenceFactProposalService` reads `$document->validity_start/_end` **at job time** (lines 121–122) and folds them into the upsert logical key. Ingesting with the worker live would have segmented against null validity, and setting validity afterwards would have produced a second, non-colliding key on re-segment — duplicate facts. Stopping the worker puts validity on the document before the job runs, in one pass, with no duplicates.

| Doc | UUID | Fixture | Type | Pages | Validity |
|---:|---|---|---|---:|---|
| 105 | `cb9487d6-95e6-42cc-90c8-7511f5952987` | `PERIODOS DE PRUEBA.docx` | `reference_source` | 7 | 2022-01-01 → 2025-12-31 |
| 106 | `052a3228-0053-4d8f-b2f8-91bceda7bca2` | `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx` | `reference_source` | 1 | 2026-01-01 → open |
| 10 | `7d5cb553-9dcf-427f-a3b3-6d13e9929259` | `Tablas acuerdo parcial_Alhambra.xlsx` | (see finding F-1) | 1 | 2026-01-01 → open |

Validity windows follow the gold set's own `validity_hint` ("pre-2026 (file 1)", "2026 (file 2)"). The consequence is intended: as of today, only file-2 facts are in-validity, so file 2 is the version that answers and file 1 is the superseded history.

#### Finding F-1 — the xlsx fixture deduped onto an existing corpus document (found, fixed, recorded)

`Tablas acuerdo parcial_Alhambra.xlsx` is not only a 7b-2 fixture; it is **already in the staging corpus** as document 10. Ingesting it as a `reference_source` matched on checksum and **re-typed the existing document** `salary_tables` → `reference_source`, set its validity to 2026-01-01, and added a `document_page`.

Assessed before acting: document 10 had **0 `salary_tables` rows and 0 `document_chunks`** (it is one of the eight unbound salary workbooks that `salary:import` never imported, having no resolved convenio). **No corpus data was affected** — the 14 salary tables / 179 rows / 3,626 chunks are untouched.

The routing-test result was captured first (0 facts, §0.4), then document 10 was **restored** to its sibling profile — `document_type_id = 3`, validity null, the added page deleted — with an `admin_manual` `tag_events` row recording what happened and why. The four `filename_parse`/`system` events from the re-ingest are append-only provenance and were deliberately left in place. Post-restore: 106 documents, 2 `reference_source`.

**Consequence for reproducibility:** re-running the xlsx routing test requires re-ingesting it and re-restoring afterwards. Noted in the runbook rather than left as a trap.

### 0.3 Segmentation

Ran on the restarted worker. Model confirmed **`claude-sonnet-4-5`** — hr-ai `/health/config` reports it, and `HR_AI_ANSWER_MODEL` is unset on staging so `config/services.php` line 31's default applies. **Identical to the 7b-2 local run**, so this is a genuine provider-side drift check and not a config comparison.

| Fixture | Job time | Facts proposed |
|---|---:|---:|
| `PERIODOS DE PRUEBA.docx` | 1m 08s | 39 |
| `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx` | 2m 01s | 49 |
| `Tablas acuerdo parcial_Alhambra.xlsx` | 13s | **0** |

All 88 landed `status = needs_review`, `source = ai_agent` — inert, as ADR-0020/0021 require. No output truncation; the 7b-2 streaming+salvage fix held.

**Cost and time.** Segmentation wall-clock **3m 22s**, plus ~7s ingest. The fixtures are far smaller than the plan assumed (file 1 is 2,059 chars across 7 pages; file 2 is 5,110 chars in one) — they are dense per-territory listings, roughly one fact per line. With the candidate vocabulary (27 convenios + 94 categories) dominating the input at roughly 12–15k input and 5–6k output tokens per call, the three calls cost approximately **$0.40** against a planned budget of under $5. The plan's estimate assumed ~45k input tokens per fixture and was about 6× too high.

### 0.4 The eval — does it reproduce?

Scored with the **unmodified** `sprint-07b-2/eval/score_eval.py` against the **unmodified** `gold-set.json`. Proposals exported per source document into the field shape the 7b-2 API export carries (`convenio_numero`, `territory`, `sector`, `group_label`, `value`, `confidence`, `uncertainty`, `is_possible_duplicate`); the exports are committed in [`eval/`](eval/).

| | Local baseline (7b-2 §5.2) | **Staging (7f Phase 0)** | |
|---|---:|---:|---|
| **file 1** proposed | 39 | **39** | |
| correct (scope+value) | 39 | **37** (effective **39/39**, see below) | |
| **mis-scoped** | 0 | **0** | ✅ |
| wrong-value | 0 | **0** | ✅ |
| **file 2** proposed | ~49 | **49** | |
| correct (scope+value) | 44 (effective 45/45) | **43** (effective **45/45**) | |
| **mis-scoped** | 0 | **0** | ✅ |
| wrong-value | 0 | **0** | ✅ |
| **xlsx** facts | 0 | **0** | ✅ |

**Verdict: reproduces.** All four plan §2.3 criteria are met.

1. **`mis-scoped == 0` on all three fixtures** — the criterion. `score_eval.py` printed *"mis-scoped: NONE"* for each.
2. **`correct` within −2 of baseline** — file 1 −2, file 2 −1, and both deltas are the scorer's known group-label synonym tolerance, not model error (below).
3. **Spot-checks hold** (§0.5).
4. **Zero salary-shaped facts** — the xlsx produced nothing at all, and a scan of both docx exports for `€`/`EUR`/`SMI`/`salario base`/`/h` returns 0.

**The four apparent deltas are all one artifact, and none is a model error.** In each case the agent proposed the correct value against the correct convenio, but attached a *group label* where the gold expected `group: null`; `score_eval.py::group_match` only treats a proposed label as equivalent to gold-`null` when it is literally one of `todo / todos / general / todos los grupos`, so each of these counts once as "missed" and once as "over-proposed":

| Fixture | Gold | Proposed label | Value |
|---|---|---|---|
| file 1 | Gipuzkoa · Intervención Social · `null` | `Todo (general)` | matches |
| file 1 | Navarra · COEAS · `null` | `Establecido en COEAS ESTATAL` | matches, and correctly flagged `uncertainty=value` |
| file 2 | Gipuzkoa · Información y Documentación · `null` | `Contratos para la práctica profesional` | matches |
| file 2 | Navarra · Limpieza · `null` (one combined 15/30/6-meses line) | split into `Personal obrero y subalterno` + `Personal técnico y administrativo` | matches, **finer** than gold |

This is the same phenomenon the 7b-2 review recorded for file 2 ("+4 captured under synonym group-label → effective 45/45"). The last row is an over-*split*, which under this sprint's own risk model is the safe direction. **Per the build authorization, the scorer, gold set and fixtures were not modified** — no genuine mis-scope appeared, so the only trigger to touch them never fired.

The remaining 4 file-2 over-proposals are correctly-scoped sub-clause facts (Valencia's nullity-on-pregnancy clause, Andalucía's formación-en-alternancia prohibition, Cantabria's art. 15.2 limit, Navarra Limpieza's emprendedores line) — the "lower-harm sub-clause pattern" 7b-2 §5.2b already documented. **The statutory-estatal block stayed unbound** (0 facts on the `99…` convenios from file 2), so the 7b-2 prompt rules held on staging.

### 0.5 The named spot-checks

**The cross-province trap holds** — Álava reads 5 while its neighbours read 6:

| Convenio | file 1 | file 2 |
|---|---|---|
| COEAS **Álava** (`01100635012017`) G1 | **Cinco meses** | **5 meses** |
| COEAS Andalucía (`71103505012022`) G1 | Seis meses | 6 meses |
| COEAS Estatal (`99100055012011`) G1 | Seis meses | — (correctly unbound: file 2's estatal block is the statutory fallback) |

**The Navarra Hostelería trio is present in both files**, and the compound group is flagged `uncertainty=group` in both — the agent knew it was compound:

| Fact | Group label | Value |
|---:|---|---|
| 44 | `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` ⚑ | 90 / 75 / 60 días |
| 45 | `Grupo 2 (resto áreas)` | 60 / 45 / 30 días |
| 46 | `Grupo 3 (todas las áreas)` | 45 / 30 / 15 días |

Fact 44 is the sprint's headline object, and it confirms plan §3.3 empirically: **one fact, two groups.** A single `(group_id, sub_area_id)` pair on `reference_facts` could not carry it.

The 7d duplicate detector fired correctly on the file-1↔file-2 version pairs it should catch (Álava G1/G2, Andalucía G1/G2 — `uncertainty=version`, `duplicate_of` set). The known re-group blind spot (decision #10) is expected to reappear on the Navarra Intervención Social pair and is not a 7f regression.

---

## ✅ Checkpoint 1 — cleared

Pedram verified **five facts** on staging (Review queue → Reference facts): the cross-province pair **41** (COEAS Álava, `Grupo 1` = *5 meses*) and **79** (COEAS Andalucía, `Grupo 1` = *6 meses*), and the Hostelería Navarra trio **44 / 45 / 46**.

All five are from **document 106** — the 2026→open window, i.e. the version that answers a question dated today. The file-1 counterparts (8, 11) were deliberately left unverified; §0.6 records why that turns out to matter.

Verified state: 5 `verified`, 83 `needs_review`. All 5 verified facts are group-scoped, which is the condition the rest of Phase 0 measures against.

---

### 0.6 The 7d resolution pass

Two of the eight flagged pairs have a **verified** newer fact, and only those two were resolved:

| newer | older | convenio · group | older validity | boundary | moved? |
|---:|---:|---|---|---|---|
| 41 `supersedes` | 8 `superseded` | 3 COEAS Álava · `Grupo 1` | 2022-01-01 → 2025-12-31 | 2025-12-31 | **no** |
| 79 `supersedes` | 11 `superseded` | 4 COEAS Andalucía · `Grupo 1` | 2022-01-01 → 2025-12-31 | 2025-12-31 | **no** |

`supersede()` closes the older window at `newer.validity_start − 1 day` = **2025-12-31**, which is exactly where §0.2's pre-segmentation validity stamping had already put it. So the service's shorten-only guard left `validity_end` untouched and wrote only the lineage (`superseded_by_id`, `resolution`, `resolved_by/at`, plus a `tag_events` row on each side). `deleted = 0` on both, and both older facts **kept `needs_review`** — status is deliberately not touched by a supersede.

That the boundary landed on the existing date is a real check, not a coincidence: it means the validity the ingest stamped and the validity the resolver derives from the *other* document agree. Had §0.2 raced the queue and left validity null, this pass would have had to move dates.

**The other six pairs were left flagged**, and this is a judgement worth stating rather than a task skipped. On all six, *both* sides are `needs_review` — nobody has adjudicated either value. Superseding there would assert "this proposal is the newer version of that proposal" on the strength of two unreviewed AI outputs, which is the one thing the resolution service is built not to do. They stay in the queue for a human.

#### Finding F-2 — 7d's extended pass already closes the re-group blind spot, and shows us the next one

Decision #10 carried the 7b-2 blind spot forward as "noted, not a 7f regression". Running `facts:scan-duplicates --dry-run` over the real 88 facts shows **7d already fixed it** — `scanForOverlappingGroupDuplicates` uses digit-token overlap instead of exact-label equality, and it catches the documented Navarra Acción e Intervención Social pair:

| fact | version of | group A | group B | value A | value B |
|---:|---:|---|---|---|---|
| 54 | 27 | `Grupo 1` | `Grupos 1 y 2` | 6 meses | Seis Meses… |
| 55 | 27 | `Grupo 2` | `Grupos 1 y 2` | **4 meses** | Seis Meses… |
| 43 | 10 | `Grupos 3, 4, 5 y 6` | `Grupos 3,4,5 y 6` | 1 mes, salvo titulados | Un mes (excepto titulados |
| 81 | 13 | `Grupos 3, 4, 5 y 6` | `Grupos 3,4,5 y 6` | 1 mes (titulados: 2 meses) | Un mes (excepto titulados |
| 52 | 32 | `Grupos 3, 4 y 5` | `Grupos 3,4 y 5` | 1 mes | Un mes |

Five true positives — the re-group pair plus three that the exact-key detector missed for nothing more than **a space after a comma**.

But the same run produces **five false positives, and every one of them is on a sub-area fact**:

| fact | "version of" | group A | group B | why it is wrong |
|---:|---:|---|---|---|
| 45 | 44 | `Grupo 2 (resto áreas)` | `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` | not versions — **different sub-areas of Grupo 2**, both true at once, values legitimately differ (60/45/30 vs 90/75/60) |
| 46 | 36 | `Grupo 3 (todas las áreas)` | `Grupo 3` | cross-window, cross-shape |
| 44 | 35 | `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` | `Grupo 2 excepto área cinco` | overlapping-but-not-equal compound scopes |
| 35 | 34 | `Grupo 2 excepto área cinco` | `Grupo 1 y área cinco de Grupo 2` | the same split, within file 1 |
| 85 | 83 | `Contratos por circunstancias…` | `Grupos Profesionales 1 y 2` | pure tokenisation noise — the digits in an article reference collide with group numbers |

**This is the digit-matcher pathology in a second place.** `GroupLabel::relate` and `factMatchesGroup` fail for one shared reason: a bare digit token cannot express *"área 5 of Grupo 2"*, so `{1,2}` vs `{2}` looks like overlap whether the facts are two versions of one rule or two halves of a split. The three verified Hostelería facts 44/45/46 — the facts Phases 2–3 build on — are exactly the ones it gets wrong.

**Consequence, and what was NOT done.** The pass is flag-only, so running it for real would have written five wrong `duplicate_of` + `uncertainty` flags onto facts a human had just verified, immediately before Phase 2 reads them. **It was therefore run only as `--dry-run`; no flag was written**, and the flag counts in §0.7 are still the eight from segmentation. Making the detector binding-aware is explicitly out of scope for this sprint (plan §7.10) — recorded here so the decision is deliberate. Once Phase 1's structured scope exists, the same comparison becomes exact and both failure directions close together; that is the natural follow-up sprint and it now has real evidence behind it rather than a hypothesis.

### 0.7 The two 7c baseline checks — the before-picture

Both drive the real `ChatService::handleMessage` loop (router → guardrail → answer), not `ReferenceFactAnswerService` in isolation, so what is captured is the answer an employee actually gets. Question in both cases: *"¿cuál es mi periodo de prueba?"*. Two employees were added to `ChatTestUserSeeder` for this (both with `job_category_id = null`, explicitly and permanently — see the seeder's comment).

**Check B — group-scoped fact, group-less employee. This is the sprint's baseline.**

`test-hosteleria-navarra@example.com` (convenio 21, no categories exist there at all, so no group can resolve) against verified facts 44/45/46:

```
escalated : true
reason    : reference_fact_coverage_gap
trace.reference_fact: {"convenio_id":21,"topic_id":1,"job_category_id":null,"group_label":null,
  "as_of_date":"2026-09-09","fact_id":null,"validity_selection":null,"value":null,
  "authority_used":"structured_reference","outcome":"escalate",
  "note":"only per-group/per-category facts exist; employee scope does not confidently
          match one (group unresolved or different group) — never guess"}
```

Exactly the documented behaviour, and the right behaviour: three verified facts say 90/75/60, 60/45/30 and 45/30/15, and the system refuses to pick. **Phase 3 must turn this single escalation into 60/45/30 (or 90/75/60) without loosening anything** — that is the whole sprint, and this trace is what it will be diffed against.

**Check A — convenio-wide fact. It did not go as the plan expected, and the result is better than what was asked for.**

`test-deportivas-alava@example.com` (convenio 2, no category, no group) against convenio-wide fact **40** (*"El periodo de prueba no podrá exceder de dos meses en ningún caso"* — `group_label` null, `job_category_id` null, 2026-01-01→open):

```
escalated : false
trace     : profile, scope_filters, router_decision, guardrail_check, floor_decision
            — NO reference_fact block at all
answer    : "Tu período de prueba no podrá exceder de dos meses [Fuente 1]. …"
```

The content is right, but it came from the **prose** path: fact 40 is still `needs_review`, and an unverified fact is completely invisible to the answer loop — not down-weighted, not caveated, absent. The plan's §2.6 wording ("a convenio-wide **verified** fact answers a scoped test employee") assumed a verified convenio-wide fact existed; §0.1's scope census now shows the corpus has only **three** convenio-wide facts, on convenios 2, 23 and 25, none verified and none with an employee — which is why one of the two new seeder profiles is on convenio 2.

So check A is currently the **negative** half of the test, and it is worth having: *the verification gate is what admits a fact to an answer, not its existence.* Verifying fact 40 should flip this same question from the prose path to `reference_fact`/Tier 3 with no other change — a clean A/B on one row. That is the one small item Checkpoint 1 did not cover; it is carried as **open item O-1** below and does not block Phase 1.

Incidental correction for later diffs: the plan's shorthand `path: reference_fact` is not a trace key. The reference-fact outcome lives in `trace.reference_fact.outcome` + `authority_used = structured_reference`; `floor_decision.path` is the separate prose/salary selector.

**Check A, re-run after O-1 closed — it flipped, on one row, with nothing else touched.**

Pedram verified fact 40 at 2026-09-09 18:22 UTC (`verified_by = 1`). Re-running the identical script, same question, same employee:

```
escalated : false
trace.reference_fact:
  {"convenio_id":2,"topic_id":1,"job_category_id":null,"group_label":null,
   "as_of_date":"2026-09-09","fact_id":40,"validity_selection":"single",
   "value":"El periodo de prueba no podrá exceder de dos meses en ningún caso.",
   "authority_used":"structured_reference","match_kind":"convenio_wide",
   "validity_start":"2026-01-01","validity_end":null,"outcome":"answer"}
answer    : "Tu periodo de prueba no podrá exceder de dos meses [Fuente 1]. …"
```

The `reference_fact` block that was **absent** an hour earlier is now present and answering: `outcome = answer`, `match_kind = convenio_wide` (Tier 3), `fact_id = 40`, `authority_used = structured_reference`. The only thing that changed in the system was one row's `status`, `needs_review` → `verified`. So the positive half of check A now reads: **the verification gate, not the fact's existence, is what admits a fact to an answer** — and Tier 3 works. O-1 is closed.

Both halves of the 7c before-picture are now on the record, and they say different things, which is what makes them useful as a pair: Tier 3 (check A) resolves correctly *today*, and Tier 2 (check B) escalates *today*. Phase 3 must move check B to an answer while leaving check A byte-for-byte as it reads above.

### 0.8 Gate re-run, ledger, snapshot

**§2.7 gate — run 2 of 3 (after verifying): zero rows.** This is the run that matters most. Before Phase 0 the gate was trivially satisfied because no verified group-scoped fact existed; now five do, on convenios 3, 4 and 21, and it *still* returns nothing — no employee has a non-empty `group_code` on their category. Five employees sit in scope of a verified group-scoped fact (`test-ocio-alava`, both `test-andalucia*`, `test-hosteleria-navarra`), and all five escalate rather than match, which is the safe state the live digit matcher needs. Run 3 comes after Phase 3, when the gate retires.

**Ledger re-run:** `corpus-coverage.md` updated at 2026-09-09 17:40 UTC. `reference_facts` moves 0 → **88**; the old placeholder reason-code row splits into a populated `FACT_NEEDS_REVIEW` (83 facts, 21 convenios) and `GROUP_SCOPED_FACT` (5 facts, 3 convenios, 5 employees, unblocked only by this sprint); documents 105/106 get a reference-facts sub-table, since a `reference_source` file is multi-convenio and does not fit §2's per-convenio grid. Its "tinker is not usable on staging" note was also corrected — the diagnosis was right, the conclusion wasn't; the working invocation is now written down.

**Snapshot:** manual RDS snapshot **`hr-staging-7f-phase0`** taken from `hr-staging-db` — the restore point immediately before Phase 1's four additive migrations.

### Open items carried out of Phase 0

| # | item | blocks? |
|---|---|---|
| ~~**O-1**~~ | ~~Verify convenio-wide fact **40** (convenio 2) so check A's positive half can be taken~~ — **CLOSED** 2026-09-09 18:22 UTC. Verified by Pedram; check A re-run and it flipped to `reference_fact` Tier 3 (`match_kind = convenio_wide`, `fact_id = 40`). Recorded in §0.7 above. | closed |
| **O-2** | Six duplicate pairs left flagged with `needs_review` on both sides, awaiting a human | no |
| **F-2** | Binding-aware duplicate detection (replaces digit-token overlap) — evidenced, deliberately out of scope | no — follow-up sprint |

---

## Phase 1 — the schema

Four additive migrations, all hr-backend, no data backfilled and no existing column reshaped. hr-ai gains nothing here (ADR-0007).

| # | migration | shape |
|---|---|---|
| 1 | `create_convenio_groups_table` | the two-level node tree — the vocabulary the matcher will compare |
| 2 | `create_convenio_group_categories_table` | category → node membership (a *suggestion* source only) |
| 3 | `create_reference_fact_group_scopes_table` | fact → node binding, **many-to-many** |
| 4 | `add_convenio_group_id_to_employees` | one nullable FK; null = unresolved = escalate |

`reference_facts` gained **no columns** and `group_label` is untouched — asserted, not assumed (`test_reference_facts_gains_no_columns_and_group_label_survives_binding` checks all three column names are absent).

### 1.1 Three places the plan's shorthand needed correcting against the real Postgres

**(a) `status`/`source` are plain VARCHARs, not Laravel `enum`s.** §3.2 wrote them as enums. The codebase already learned this one: `add_resolution_fields_to_reference_facts` documents that a Laravel `enum` becomes a Postgres CHECK, and adding a value later then forces the introspect-drop-readd dance 7b-2 had to perform for `reference_facts.status`. The allowed sets live in `ConvenioGroup::STATUSES`/`SOURCES` and the FormRequests instead. Followed the codebase over the plan.

**(b) One `UNIQUE (convenio_id, parent_id, code_normalized)` would not have constrained top-level groups at all.** In Postgres NULLs are distinct, so two rows with `parent_id = NULL` and the same code both satisfy it — a convenio could hold two "Grupo 2" nodes, which is exactly the ambiguity an exact matcher must never face. Split into two partial unique indexes: one on `(convenio_id, code_normalized) WHERE parent_id IS NULL`, one on `(convenio_id, parent_id, code_normalized) WHERE parent_id IS NOT NULL`. The second is also correct on its own terms — `todas-las-areas` legitimately exists under both Grupo 1 and Grupo 3.

**(c) `UNIQUE (job_category_id)` on membership would have made re-proposing a convenio fail.** §3.2's flat unique reads correctly as an invariant ("a category belongs to at most one node") but breaks once proposals are rows in the same table: a second proposer run offering the same category would be un-insertable, so re-proposing would error instead of producing a reviewable alternative. Scoped it to `WHERE status = 'approved'`, which keeps the invariant that matters — a category resolves to one group, so the directory's suggested default is never ambiguous — while letting competing proposals coexist for a human to choose between.

### 1.2 Depth ≤ 2 is enforced by a trigger, and writing it found a hole

§3.2 specified a CHECK. A CHECK cannot express this: the rule is about the *parent's* row ("a node with a parent must have a parent that is itself a root") and Postgres forbids subqueries in CHECK. The invariant is load-bearing — Phase 3's match rule is a single self-join that assumes a grandparent cannot exist — so application-level validation alone would leave a bulk insert or a manual SQL fix able to create a three-level tree that makes the matcher silently wrong rather than loudly broken. Hence `convenio_groups_depth_check`.

**The first version was incomplete, and the test caught it.** It only looked *up* ("my parent must be a root"). That misses demotion: making Grupo 2 — which already has `área 5` under it — a child of Grupo 3 gives `área 5` a grandparent **without ever touching `área 5`'s row**, so no upward check fires. The trigger now also looks *down* (a node becoming a child must have no children of its own) and fires on `UPDATE OF parent_id` as well as insert. It closes the cross-convenio hole in the same pass, since a sub-area of a group in another convenio would make the scope key meaningless.

Also worth recording because it will bite the next person: the trigger function must be `CREATE OR REPLACE`. `migrate:fresh` — and therefore `RefreshDatabase` between test classes — drops all *tables* but not *functions*, so a plain `CREATE FUNCTION` in a migration fails on the second run with "function already exists".

### 1.3 `GroupCodeNormalizer`

One class, seven rules, **44 unit tests, one per rule plus the cases the plan flagged as load-bearing**. Every label tested is a real string from the corpus, the 7b-2 gold set, or staging's verified facts.

The rules behave as §3.5 specified, with three worth calling out:
- **`Grupo I` and `Grupo 1` normalize to the same node** (`1`). Not cosmetic — file 1 prints "Grupo 1" and file 2 prints "Grupo I" for the same Valencia groups, and an exact matcher must see one node.
- **`2.1` stays `2.1`.** The conservative reading is confirmed and tested both ways (`test_rule_4_a_decimal_is_not_the_same_node_as_its_integer`).
- **A compound label is not decomposed.** `Grupos 1 y 2` → `1-y-2`, and the test asserts it is neither `1` nor `2`. One label is one node; a fact spanning two groups is expressed by binding, which is why migration 3 is many-to-many.

`TextNormalizer` gained one additive public method, `deaccent()`, and `key()` was refactored to call it. The point is that there is only ever **one accent map** in the codebase — `GroupCodeNormalizer` needs the de-accenting but not `key()`'s punctuation handling, since `key('2.1')` returns `2 1` and would destroy exactly the decimal rule 4 exists to protect. All 228 backend tests pass after the refactor, which is the check that mattered (`key()` backs territory and sector matching).

### 1.4 Assignment surfaces — 22 tests, all defending one rule

**A group is never assigned without an admin saying so, and never to unapproved structure.**

- **Picker** (`GET /admin/groups?convenio_id=`): approved nodes only, parent-first with `depth` and `path_label`. A `needs_review` node is invisible here as it is everywhere. Tested that it works for **a convenio with zero job categories** — convenio 21's real situation, and the reason groups are their own vocabulary.
- **The suggestion is a default, not an assignment.** Where the employee's category maps to exactly one *approved* node, the form pre-fills it and says *"sugerido a partir de la categoría — confírmalo"*. The test asserts the employee's `convenio_group_id` is **still null** after the suggestion is returned. An unapproved membership suggests nothing. Changing the category discards a group that was only a suggestion, so the notice never describes a default derived from a category no longer selected.
- **Directory validation** mirrors the `job_category_id` rule and adds the status condition: wrong convenio → 422, unapproved node → 422, omitted → cleared to null.
- **CSV `group` column.** Resolves by printed label or normalized code, with `Grupo 2 > resto áreas` for a sub-area. **Ambiguity fails the row and names the options** rather than picking: `todas las áreas` when it exists under both Grupo 1 and Grupo 3 is the real shape of the Hostelería data, and taking the first match would be the same class of silent guess the digit matcher makes. Unknown, unapproved and three-level values each fail their row; blank is not an error; a file with no `group` column imports exactly as it did in Sprint 5.

### 1.5 Verified on staging, against the real corpus

Migrations applied to `hr-staging` (snapshot `hr-staging-7f-phase0` is the restore point, `available`):

| check | result |
|---|---|
| `convenio_groups` / `convenio_group_categories` / `reference_fact_group_scopes` | **0 / 0 / 0** rows — nothing invented |
| `employees` | 14 rows, **0 with a group** — no backfill, every employee still unresolved |
| documents · facts · chunks · salary rows · categories · verified facts | 106 · 88 · 3,626 · 179 · 94 · 5 — **all unchanged** |
| `convenio_groups_depth_check` trigger | present |
| the three partial unique indexes | present |
| **§2.7 gate — run 3** | **zero rows** |

Because every employee reads null, Phase 1 changes no answer: the group-scoped escalation of §0.7 check B is still exactly what a convenio-21 employee gets. That is the intended state — Phase 1 is the vocabulary, Phase 3 is the matcher.

**Test totals:** 228 backend tests green (83 of them new in 7f), including the `Sprint7cAdditivityRegressionTest` golden-trace gate and the full 7c ladder suite. Frontend typechecks and builds.

---

## Phase 2 — the AI proposes a tree, a human approves it

### 2.1 `hr-ai POST /propose-groups`

Read-only, writes nothing, never migrates (ADR-0007). It gets one convenio's own text (page-ordered), its categories as a **closed set** with `group_code` marked *evidence only*, and the `group_label` strings its **verified** facts already use. Verified only, deliberately: an unverified label is itself an unreviewed AI guess, and feeding it back as a requirement would let one proposal justify the next.

**No AI at answer time.** This is a one-off structural read whose output is inert until approved; the answer path never calls it.

The prompt's load-bearing rule is **granularity**: propose a sub-area *only* where the text assigns the slices different values, and cite the line. Everything the database enforces is enforced again in the provider, before hr-backend sees the payload, so a malformed tree degrades to *fewer nodes* rather than to a rejected batch — two levels only, no orphan sub-areas, an uncited split dropped, category ids validated against the closed set (ADR-0011). Labels are passed through **unnormalized**: `GroupCodeNormalizer` in hr-backend owns the comparison key, so there is exactly one implementation of the thing Phase 3 compares. `scripts/propose_groups_validation_test.py` pins all of it with the Anthropic call stubbed — no network, no cost.

### 2.2 Persist, and the rule that makes approval safe

`ExtractionClient::proposeGroups` → `ProposeConvenioGroups` (queued) / `groups:propose` (CLI, inline, prints cost) → `ConvenioGroupProposalService`. Every node lands `ai_agent`/`needs_review`. Re-running upserts on `(convenio, parent, code)` and **never reopens a node a human approved or rejected** — an approved node may already be bound to facts and assigned to employees, and a rejected one was rejected on purpose.

**`FactGroupBindingPlanner`** reads a fact's `group_label` and works out which approved nodes it means. It proves the corpus's real shapes and **refuses the rest, by design**. That refusal is the fix, not a gap: the matcher this sprint deletes always produced an answer, and replacing one over-confident parser with a cleverer over-confident parser would fix the symptom and keep the disease. An unresolved label costs a click; a wrongly resolved one is a wrong answer about someone's probation period.

| proved | refused |
|---|---|
| `Grupo 1`, `Grupo I` → one node | `Resto de grupos` — defined by what it excludes |
| `Grupos 3, 4, 5 y 6` → four nodes | `Grupo 2 excepto área cinco` — a complement |
| `Grupo 3 (todas las áreas)` → the group itself | `Grupo 2` **where G2 is split** — under-specified |
| `Grupo 2 (resto áreas)` → the child | `Contratos de formación en alternancia` — not a group |
| `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` → two nodes | `Grupo 1 y Grupo 9` — **partial parses bind nothing** |

The last row matters most. Binding the half that parsed would answer confidently for Grupo 1 and silently drop the rest of the fact's scope, and nothing about it would look wrong.

**`approve()` never writes a binding.** `GET .../binding-diff` is a read that shows exactly which facts would bind; `approve()` writes `reference_fact_group_scopes` rows *only* for the ids the reviewer sends back, and re-checks each one against the grammar inside the transaction, so a stale payload cannot bind a fact whose label points elsewhere. A sub-area cannot be approved under a pending parent, an approved node cannot be renamed (that would change the key Phase 3 compares, under facts and people already attached), and a node with bindings cannot be rejected out from under them.

### 2.3 Found by reading the real text: one group, three keys

Hostelería Navarra's article 19 prints **"Grupo Prof. 1.º"**, its verified reference fact writes **"Grupo 1"**, and its annex writes **"GRUPO PROFESIONAL PRIMERO"**. Those normalized to `prof-1`, `1` and `profesional-primero` — **three nodes for one group**, which is this entire bug re-entering through typography. Rule 2 now strips the `profesional`/`prof.` qualifier and trims ordinal markers at both ends; rule 3 reads ordinal words. All 44 Phase 1 normalizer tests still pass unchanged; 13 new ones pin the convergence and guard against swallowing a genuine prose group name (`Profesionales de oficio` stays itself).

### 2.4 Found by running it: a cited split was being thrown away

The first live run on convenio 21 read article 19 **correctly** and said so in its own notes — *"divide el Grupo profesional 2.º en dos áreas exclusivamente porque les asigna periodos de prueba distintos"* — but returned Grupo 2's two areas **without Grupo 2 itself**. Both areas were therefore orphans, both were dropped, and what survived was 2 roots and no split: **precisely the under-split the eval gates on.** That is how someone entitled to 90 días gets told 60.

Dropping a *cited* split is the worst outcome available, so a missing parent is now **reconstructed** instead of discarded. It invents nothing — the printed label comes verbatim from the child's own `parent_code_label`, the child carries the excerpt proving the group is split, and the node lands `needs_review` flagged as reconstructed. An **uncited** orphan is still dropped and conjures nothing. Reconstruction never targets a label the model used for an *area*, which would defeat the two-level limit by making one area both an area and a root. The prompt now also states the requirement directly, so reconstruction stays a fallback — and on the re-run the model emitted all three roots itself, citing Anexo I for the groups and article 19 for the areas.

### 2.5 The group eval

`eval/gold-trees.json` — gold trees hand-built by **reading each convenio's own text on staging**, page cited per entry so a disagreement is settled by looking. The two errors are scored separately because they are not symmetric: an **under-split** binds one value to a group the convenio prices in halves and answers 60 días to someone entitled to 90 (a wrong answer — **gated at zero**), while an **over-split** demands a distinction that does not exist and escalates (worse service, not wrong information — reported). Scoring is by group **identity**, not spelling; two proposed roots matching one gold group is itself reported as a duplicate-node bug.

**Live staging run, all six fixture convenios — `PASS`:**

| id | convenio | gold | proposed | exact | **under** | over | missing | extra | time | cost |
|---|---|---|---|---|---|---|---|---|---|---|
| 21 | HOSTELERIA NAVARRA | 3 | 3+2 | 3 | **0** | 0 | 0 | 0 | 24.0s | $0.1107 |
| 3 | COEAS Álava | 6 | 6+0 | 6 | **0** | 0 | 0 | 0 | 16.0s | $0.1948 |
| 4 | COEAS Andalucía | 6 | 6+0 | 6 | **0** | 0 | 0 | 0 | 18.6s | $0.1906 |
| 11 | COEAS Estatal | 6 | 6+0 | 6 | **0** | 0 | 0 | 0 | 25.5s | $0.1899 |
| 18 | Acción e Intervención Social Navarra | 4 | 4+0 | 4 | **0** | 0 | 0 | 0 | 24.8s | $0.1875 |
| 19 | COEAS Navarra | 6 | 6+0 | 6 | **0** | 0 | 0 | 0 | 12.8s | $0.0718 |
| | **total** | **31** | **33 nodes** | **31** | **0** | **0** | **0** | **0** | **122s** | **$0.9453** |

**31/31 exact.** Convenio 21 is the only one that splits, and it splits exactly where the convenio does. The five unsplit convenios stayed unsplit — the over-split column is the one that would have caught a proposer that split for the sake of it, and it is zero.

**Membership, ungated:** COEAS Navarra's forward-fill recovered **28 of 32** (88%). Its salary sheet printed each group header once and left the following rows blank, so only 6 of 32 categories carry a `group_code` and 26 inherit theirs from the row above; the proposer offered all 32 as `needs_review` memberships with the block excerpt. Ungated on purpose: membership only pre-fills a picker, it never decides an answer, and 72 of the corpus's 94 categories have no group at all. Gating on it would fail the eval for a signal nothing depends on.

**Also worth recording:** four of the six convenios hit `text_truncated` at the 180k-character cap (3, 4, 11, 18 — convenio 11 is 136 pages). All four still scored exact, because a convenio's classification article sits early, but the cap is a real limit and is carried as **O-3** below rather than left implicit.

### 2.6 Test totals

**288 backend tests green, 1,087 assertions** (143 new in 7f), including `Sprint7cAdditivityRegressionTest` byte-for-byte unchanged and the full 7c ladder suite. Frontend typechecks and builds. `propose_groups_validation_test.py` all checks pass.

Nothing in Phase 2 changes an answer: every node is `needs_review`, `reference_fact_group_scopes` is still **empty**, every employee's `convenio_group_id` is still **null**, and §0.7 check B still escalates. Phase 3 is the matcher.

### Open items carried out of Phase 2

| # | item | blocks? |
|---|---|---|
| **O-3** | `PROPOSE_GROUPS_TEXT_CAP` (180k chars) truncated 4 of 6 convenios. Harmless here — classification articles sit early — but a convenio that classifies late would silently lose its structure. Wants either a targeted article-finding pass or a chunked read | no — all six scored exact |
| **O-4** | Convenio 18's categories 57-64 are salary *amounts* parked in the category table (the model spotted this and refused to attach them). Corpus hygiene, not 7f | no |

---

## ⏸ CHECKPOINT 2 — Navarra Hostelería tree + binding diff — **passed** 2026-09-09

Pedram approved all five nodes. Approving them surfaced two gaps that only a real reviewer working the real surface could have found, and the second is a design point rather than a missing button.

### 2.7 What approval revealed: binding is a decision separate from approval

Phase 2 shipped with binding available at exactly one moment — the approval diff. Approve a node with a fact unticked and there was no way back to it: facts **44** and **34** sat `sin vincular` on an approved Grupo 1, correctly listed, with no door. The reviewer's first pass was final, which is not a policy anyone chose; it is what falls out of putting the only write behind a screen that renders for `pending` nodes.

Binding now has its own endpoint (`POST /convenio-groups/{id}/bind`) and its own affordance, in **two lanes**:

**The grammar lane** is what 44 and 34 needed. Their labels *do* resolve to Grupo 1 — the planner reads them fine — they were simply never ticked. The lane re-plans inside the transaction and refuses anything that does not resolve to the node, so a stale payload still cannot bind sideways.

**The override lane** is the interesting one, and fact **35** is why it exists. Its label is `Grupo 2 excepto área cinco`. That *does* mean `resto áreas` — but only because someone read the convenio and knows which areas the other facts claim. The planner refuses it as a `complement`, and refusing is the right behaviour: a complement is defined by exclusion, so resolving it means deciding what the other facts cover, which is a judgement. The mistake would be to teach the parser to guess — that is precisely the shape of the bug this sprint exists to delete. The digit matcher answered confidently from an inference nobody sanctioned.

So the design is: **the planner declines to infer, and a human may decide.** What makes that safe is not the decision but its filing. An overridden binding is written under `facet = group_scope_manual`, and the event keeps the planner's refusal reason *beside* the human's note:

> `dato vinculado al nodo "resto áreas" (resto-areas) — decisión humana sobre una etiqueta que el analizador no resuelve. El analizador NO resuelve esta etiqueta (complement): La etiqueta se define por exclusión…. Nota: Checkpoint 2, instrucción de Pedram: el complemento del Grupo 2 menos el área 5 es exactamente resto áreas.`

A later reader can tell an **asserted** scope from a **read** one. That distinction is the whole value of the lane; without it, override would just be the digit matcher wearing a badge.

Override is refused in two cases where it would be a contradiction rather than a judgement. A label that resolves to a *different* node is rejected with "fix the label, don't bind past it" — the fact's own text is wrong and editing it is the honest repair. And a **convenio-wide** fact is rejected outright: it already answers for the whole workforce at Tier 3, so attaching a group would *narrow* a universal rule. That is the inverse of this sprint's bug and just as wrong, which is worth saying plainly because the safe direction is not symmetric — under-scoping escalates, over-scoping answers.

### 2.8 The join table, after Checkpoint 2

Three bindings were added through the real HTTP endpoints as `admin@hr-staging.internal`, the same super_admin who approved the nodes. Eight rows, on convenio 21:

| fact | `group_label` | → nodes | lane |
|---|---|---|---|
| 44 | `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` | Grupo 1, área 5 | grammar |
| 34 | `Grupo 1 y área cinco de Grupo 2` | Grupo 1, área 5 | grammar |
| 45 | `Grupo 2 (resto áreas)` | resto áreas | grammar |
| 35 | `Grupo 2 excepto área cinco` | resto áreas | **override** |
| 46 | `Grupo 3 (todas las áreas)` | Grupo 3 | grammar |
| 36 | `Grupo 3` | Grupo 3 | grammar |

The two compound facts (44, 34) each hold **two** rows, which is the case the join table was added for in the first place: a fact bound to half its scope answers confidently for one group and silently omits the other — a failure that looks like success from the inside.

Fact 35 still appears in *"Datos que no se vinculan solos"*, because the planner still cannot read its label — that remains true and the surface should not pretend otherwise. It now reads `vinculado a mano → resto áreas` rather than looking untouched, so the list is a description of the parser's limit and not a standing verdict.

**Incidental, logged not fixed (F-3):** `infra/compose/otp.sh` extracts the login code by taking the first six-digit run after the subject line, which can be a fragment of the `Message-ID` header immediately below it — `<6c7671882fdb…>` yielded `767188` and a spurious "Invalid or expired code". Anchoring on the code's own letter-spaced `<p>` is reliable. Staging-only convenience script, disappears when Postmark lands; not touched on the sprint branch.

**Ownership drift, fixed:** `/opt/hr-staging` had root-owned git objects from one `sudo`-run deploy, which broke the next `git fetch` as `ubuntu` (`insufficient permission for adding an object`). `chown -R ubuntu:ubuntu` restored it; `ubuntu` is in the `docker` group, so deploys never needed `sudo`.

Phase 3 now has what it needs: verified group-scoped facts bound to approved nodes on a real convenio, and check B still escalating.

---

## 3. Phase 3 — the exact matcher

### 3.1 What was deleted, and what replaced it

Two methods are gone from `ReferenceFactAnswerService`, and with them the only place the answer path read a group as text:

- **`factMatchesGroup()`** — the digit regex, including its `(?<!\d)…(?!\d)` lookarounds. Those lookarounds worked: "Grupo 10" never matched code `1`. They were never the problem. The problem was that `Grupo 2 excepto área cinco` contains a `2`.
- **`resolveEmployeeGroupCode()`** — the read of `job_category.group_code`. Per §1.2, nine of the corpus's 22 non-empty values are not group codes, and 72 of 94 categories have no value at all.

Verified against the deployed container, not the working copy: `private function factMatchesGroup` **absent**, `private function resolveEmployeeGroupCode` **absent**, `preg_match` **absent** from the whole file.

Tier 2 is now four rules over integers, per fact, against the employee's node E: a bound node **is** E → match; a bound node is a **child** of E → escalate; a bound node is E's **parent** and that parent has approved children → escalate; otherwise no match. Rule (1) is evaluated across all of a fact's nodes before (2)/(3), which is what lets the compound fact answer for Grupo 1 on the strength of its Grupo 1 binding.

**The hard stop deserves its own paragraph, because it is the one place the ladder's shape changed.** An escalate from rule (2) or (3) ends Tier 2 rather than falling through. It would have been easy — and wrong — to let an indeterminate group drop to Tier 3 and answer convenio-wide. That answer is *less specific than the evidence the matcher just looked at*, delivered with the same confidence and the same citation shape. `test_an_indeterminate_group_does_not_fall_through_to_the_convenio_wide_fact` pins it with a perfectly good convenio-wide fact sitting in the candidate set, unused.

One case the plan did not name and the code has to answer anyway: a node **rejected** out from under an assigned employee. It is not a match and not an escalation — the ladder continues as if no group were set, which is where a null node already lands. Rejecting a node must not start escalating turns that used to answer.

### 3.2 Tests (a)–(h), plus three

All on the convenio-21-shaped fixture: G1 and G3 undivided, G2 split into *área 5* / *resto áreas*, **no job categories** — the real shape.

| | case | result |
|---|---|---|
| (a) | employee in G2›*resto áreas* | **60/45/30**, `match_kind = group`, and asserts 90 días is **not** in the answer — the bug, as a test |
| (b) | employee on G2, the split parent | **escalate**, note names the sub-area |
| (c) | employee in G1 | **90/75/60** via the compound fact's `{G1}` binding |
| (d) | undivided childless group | matches on the group alone |
| (e) | fact with a `group_label` and **zero** bindings | escalates — never group-matchable, and Tier 3 needs a null label |
| (f) | fact bound to node `12`; employees on G1 and G2 | **neither** matches |
| (g) | compound fact read from G1 and from G2›*área 5* | identical answer, identical citations, same `fact_id`; only `group_node_id` differs |
| (h) | `convenio_group_id = null` | behaves exactly as before, **and** asserts `group_node_id` is absent from the trace |

Plus rule (3) on its own (a group-level fact on a since-split group does not answer a sub-area employee), the hard stop above, and the rejected-node case.

(h) is the one that matters on day one: all 1,500 real profiles have a null node, so (h) *is* production for now. Its second assertion is the quieter half — the trace shape of a non-group answer must not change, which is why `group_node_id` / `group_node_label` are written only on a group match rather than initialized to null for every path.

**Five existing tests were rewritten onto structured scope, not deleted or weakened** — three in `Sprint7cReferenceFactAnswerTest` and, less obviously, two in `Sprint7dFactResolutionTest`. The 7d pair reached the answer path through the digit matcher (a category with `group_code = '2'` and facts labelled "Grupo 2"), so they failed the moment the regex went. Their invariant has nothing to do with digits — it is that a same-validity conflict escalates until a human supersedes, and that history keeps its old value afterwards — so each got an approved node and bound facts, and both assertions stand unchanged.

**309 tests, 1,153 assertions, green.** `Sprint7cAdditivityRegressionTest` was run **first** after the matcher change, per §5.5, and stayed byte-for-byte green (3 tests, 39 assertions). `Sprint7cCompositionTest` needed no edit, as predicted.

### 3.3 The live proof on staging

One employee (`test-hosteleria-navarra@example.com`, #13, convenio 21, no job category), one question — *"¿cuál es mi periodo de prueba?"* — moved across three nodes. Full traces:

**G2 › *resto áreas* (node 35) — the case that used to be wrong:**

```
outcome  : answer
trace.reference_fact:
  {"convenio_id":21,"topic_id":1,"job_category_id":null,
   "group_label":"Grupo 2 (resto áreas)","as_of_date":"2026-09-09","fact_id":45,
   "validity_selection":"single",
   "value":"60 días (indefinidos), 45 días (temporales > 3 meses) y 30 días (temporales hasta 3 meses)",
   "authority_used":"structured_reference","match_kind":"group",
   "group_node_id":35,"group_node_label":"resto áreas",
   "validity_start":"2026-01-01","validity_end":null,"outcome":"answer"}
answer   : "Según el dato de referencia verificado de tu convenio: 60 días (indefinidos), 45 días…"
citations: document 106 "PERÍODOS PRUEBA ACTUALIZADOS 2026", chunk_id null, is_reference_fact true
```

**Grupo 1 (node 31) — the compound fact, reached from its other binding:**

```
outcome  : answer
trace.reference_fact:
  {"convenio_id":21,"topic_id":1,"job_category_id":null,
   "group_label":"Grupo 1 (todas las áreas) y Grupo 2 (área 5)","fact_id":44,
   "validity_selection":"single",
   "value":"90 días (indefinidos), 75 días (temporales > 3 meses) y 60 días (temporales hasta 3 meses)",
   "authority_used":"structured_reference","match_kind":"group",
   "group_node_id":31,"group_node_label":"Grupo 1","outcome":"answer"}
```

**Grupo 2 (node 32), the split parent — escalates:**

```
outcome  : escalate
trace.reference_fact:
  {"convenio_id":21,"topic_id":1,"group_label":null,"fact_id":null,"value":null,
   "authority_used":"structured_reference","match_kind":"group","group_node_id":32,
   "outcome":"escalate",
   "note":"group scope is indeterminate, escalate rather than answer less specifically
           than the evidence: fact 45 is scoped to sub-area \"resto áreas\" of the
           employee's group \"Grupo 2\" — the employee's sub-area is unknown; fact 44
           is scoped to sub-area \"área 5\" of the employee's group \"Grupo 2\" —
           the employee's sub-area is unknown"}
answer   : the coverage-gap message, verbatim and unchanged
citations: []
```

Read the first two together: **the same question, the same convenio, the same verified data, two different correct answers, selected by an integer.** The old matcher gave 90 días for the first of them. Note also that `group_label` and `group_node_label` disagree in the first trace — the fact is printed as *"Grupo 2 (resto áreas)"* and the match was made on the node *resto áreas*. Keeping both is what makes the trace auditable; folding one into the other is how the old matcher hid its reasoning.

The third trace is worth reading as a *feature*. It is the only honest answer available: the convenio pays two different periods inside Grupo 2, and nothing in the system knows which side of the split this employee is on.

### 3.4 Baseline check A, re-run after the matcher change — unchanged

```
employee : #14 test-deportivas-alava@example.com (convenio 2, group null)
escalated: false
trace.reference_fact:
  {"convenio_id":2,"topic_id":1,"job_category_id":null,"group_label":null,
   "as_of_date":"2026-09-09","fact_id":40,"validity_selection":"single",
   "value":"El periodo de prueba no podrá exceder de dos meses en ningún caso.",
   "authority_used":"structured_reference","match_kind":"convenio_wide",
   "validity_start":"2026-01-01","validity_end":null,"outcome":"answer"}
```

Byte-for-byte identical to §0.7's record, including the absence of any `group_node_*` key. Tier 3 did not move.

### 3.5 §2.7 gate — run 3 of 3, and its retirement

**Zero rows**, as in runs 1 and 2 — but for a different reason, which is the point. The gate asked whether any employee had a non-empty digit `group_code` while their convenio had a verified group-scoped fact. That combination was dangerous *because the digit matcher read those two strings against each other*. It no longer does. The query is now **vacuous**: it would be harmless if it returned rows, because `group_code` is not read by the answer path at all.

The gate is retired. It moves out of `deploy.md`'s go-live list, and the `roadmap.md` precondition it enforced is closed. What replaces it as the standing invariant is narrower and stronger: **`employees.convenio_group_id` and `reference_fact_group_scopes` only ever reference `approved` nodes**, which the matcher enforces by construction rather than by a query someone has to remember to run.

---

## 4. Sprint close

### 4.1 What shipped

| | |
|---|---|
| **Migrations** | 4, all additive: `convenio_groups`, `convenio_group_categories`, `reference_fact_group_scopes`, `employees.convenio_group_id`. Depth-2 and uniqueness enforced in Postgres (trigger + partial unique indexes), not by convention |
| **hr-ai** | `POST /propose-groups` — read-only, writes nothing, never migrates; closed-set category validation; missing-parent reconstruction |
| **hr-backend** | `GroupCodeNormalizer`, `FactGroupBindingPlanner`, `ConvenioGroupProposalService`, `ProposeConvenioGroups`, `ConvenioGroupController` (tree / binding diff / approve / bind / edit / reject / unbind), `groups:propose`, `groups:export-trees`, the new Tier 2 |
| **hr-frontend** | Groups review tab (tree, excerpts, categories, binding diff, link affordances, manual-bind picker), employee group picker, CSV columns, the group node in the chat trace |
| **Deleted** | `factMatchesGroup()`, `resolveEmployeeGroupCode()` |
| **Tests** | 309 backend (1,153 assertions), incl. tests (a)–(h) + 3; `Sprint7cAdditivityRegressionTest` byte-for-byte green; `propose_groups_validation_test.py` all checks |
| **Cost** | $0.95 and 122s for six convenios' proposals; **$0 at answer time** — there is no AI in the matcher |

### 4.2 Both checkpoints

**Checkpoint 1** (Phase 0) — Pedram verified the cross-province pair (41, 79) and the Navarra Hostelería trio (44, 45, 46), then fact 40, which closed O-1 and flipped baseline check A from the prose path to `reference_fact` Tier 3.

**Checkpoint 2** (Phase 2) — Pedram approved all five nodes on convenio 21 and, in doing so, found the two gaps in §2.7 that no amount of test-writing would have surfaced: binding reachable only at the approval moment, and no lane for a label the parser must refuse. Both are now closed, with the join table confirmed at 8 rows in §2.8.

The second checkpoint is the one worth drawing a lesson from. Every invariant test passed before Pedram touched the surface; what failed was reachability — a write that existed but had no door, and a decision the system was right to refuse but wrong to make final. Neither is expressible as "the code computes the wrong value," which is the only kind of wrong a unit test looks for.

### 4.3 What is deliberately still open

| # | item | why it is not in 7f |
|---|---|---|
| **O-2** | Six duplicate fact pairs flagged `needs_review` on both sides | asserting a version relationship between two unreviewed proposals is a human's call (7d) |
| **O-3** | `PROPOSE_GROUPS_TEXT_CAP` truncated 4 of 6 convenios. All still scored exact — classification articles sit early — but a convenio that classifies late would silently lose its structure | wants an article-finding pass or a chunked read; no observed failure to fix yet |
| **O-4** | Convenio 18's categories 57–64 are salary *amounts* parked in the category table | corpus hygiene |
| **F-1** | The checksum-dedupe re-typing trap (an ingest fixture re-typed existing document 10) | logged at Pedram's instruction for the post-7f follow-on |
| **F-2** | 7d's token-overlap duplicate detector must become **binding-aware** now that facts carry nodes — compare nodes, not digit tokens. Its five false positives on convenio 21 are the evidence | same follow-on; the detector is advisory, so this misleads a reviewer rather than an employee |
| **F-3** | `infra/compose/otp.sh` can extract a `Message-ID` fragment instead of the login code | staging convenience script; disappears with Postmark |
| **7g** | **Escalation explanations.** 7f escalates in strictly more cases, on purpose — rules (2) and (3) refuse what the digit matcher answered. Right now all of them produce the same neutral coverage-gap message | its own sprint with its own prompt: one fixed message for the employee, AI prose over deterministic facts plus a structured fix link for HR |

The 7g dependency is the one to read twice before calling this sprint finished in a user-facing sense. **7f made the system correct; 7g makes it explicable.** An employee on a split group who asks about their probation period now gets a referral instead of a wrong number — better, and still not good. The escalation is right, the experience of it is not yet.

### 4.4 The one thing that would have caught this bug earlier

Not a test. The 7c review recorded this exact hazard, in prose, with the exact convenio and the exact two facts — and it stayed latent for three sprints because the *second* condition (an employee with a resolvable `group_code`) happened not to be true yet. What made it safe was luck about which convenios had salary sheets. The §2.7 gate turned that prose warning into a query someone could run, which is the difference between a known risk and a documented one; it is worth doing that translation earlier next time a review says "latent."
