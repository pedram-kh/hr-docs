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

*(Phase 2 follows.)*
