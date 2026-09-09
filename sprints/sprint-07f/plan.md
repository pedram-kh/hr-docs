# Sprint 7f — Plan: structured group-scope modeling

> Location: `hr-docs/sprints/sprint-07f/plan.md`
> Status: **plan-gate — written from inspection only. No code, no ingest, no migration, nothing committed.**
> Spec: [`sprint-07f-spec.md`](sprint-07f-spec.md) · Roadmap: `roadmap.md` §Sprint 7f (lines 170–182) · Branch: `sprint-7f` in all four repos.
> Read-first done: `ReferenceFactAnswerService` (whole file), `sprint-07c/review.md` §7, the roadmap 7f entry, `ReferenceFact`/`Employee`/`ConvenioJobCategory` models + migrations, `ReferenceFactProposalService`, `TagProposalService`, `SegmentReferenceSource`, `ProposeDocumentTags`, `EmployeeDirectoryController`, `EmployeeCsvImporter`, `DirectoryPage.tsx`, `ReviewQueuePage.tsx`, `ReferenceFactPanel.tsx`, hr-ai `main.py`/`config.py`/`claude.py`, the 7b-2 eval harness, ADR-0007/0011/0015/0016/0020/0021/0022/0023.
> **Staging was queried read-only** (SSH → EC2 → `psql` → RDS, the `09-restore-rehearsal.sh` path) for the category, convenio, fact, employee and endpoint facts in §1. Every number in §1.2 is live, not recalled.

---

## 0. TL;DR — what the real data changed

I inspected the substrate before proposing a schema, and the real data moves three of the spec's working assumptions. All three moves are toward *less* inference, not more.

1. **`convenio_job_categories.group_code` cannot carry the group model, and cannot even seed it reliably.** 22 of 94 rows are non-null, and **zero of those 22 are a clean group code attached to a distinct job-category name**. Nine are salary figures or a year mis-parsed into the label column; seven are rows where the "category" *is* the code (`name == group_code`); six are OCR-damaged prose titles that appear only on the first row of each spreadsheet block. So: **a separate `convenio_groups` table, and `group_code` is demoted to evidence for the proposer — never normalized in place, never read by the matcher.** (§1.2, §3.1)

2. **The convenios that the sprint must demo have no categories at all.** Convenio 21 Hostelería Navarra — the whole eyes-on sequence, tests (a)–(c) — has **0 `convenio_job_categories`** and 30 pages of convenio text. So does COEAS Andalucía (4) and COEAS Estatal (11). The spec's "closed-set-validated against the convenio's categories" has an empty closed set exactly where it matters most. **Groups must be their own vocabulary, minted on human approval from the convenio text; category membership is an optional overlay that is simply empty for convenio 21.** ADR-0011 forbids minting *categories*; it does not forbid a new vocabulary with its own propose→approve gate. (§1.2, §3.1, §4.1)

3. **A single `(group_id, sub_area_id)` pair on `reference_facts` cannot represent the fact the sprint is named after.** *"Grupo 1 (todas las áreas) y Grupo 2 (área 5)"* is a **compound** scope spanning two different groups. With one FK pair it is unrepresentable, so it would stay unbound, so spec test (c) — *a Grupo 1 employee gets 90/75/60* — would escalate instead of answering, failing acceptance criterion 4. **The fact→scope binding needs to be a join table.** (§3.3)

Consequences for my kickoff lean: **deriving the employee's group from `job_category.group_code` should be dropped.** Against real data it yields a usable default for essentially no one, and for convenio 21 there is no category to derive from. The defensible version is to derive a *proposed* default from **approved group→category membership** (a Phase 2 output), shown pre-filled and confirmed, never silent. (§3.6, §7)

Everything else in the spec survives intact: Phase 0 as a rerun, AI-proposes/human-approves, sub-areas only where values differ, the digit regex deleted, unresolved escalates, no AI at answer time.

---

## 1. What exists (reality check)

### 1.1 The matcher and the four-tier ladder

The ladder is documented in the class docblock and implemented in one method. Tier 2 is the whole of the bug.

```94:121:hr-backend/app/Services/ReferenceFactAnswerService.php
        // --- Q2 resolution: most-specific, ELSE ESCALATE (never guess a group) --
        // Tier 1 — exact job_category_id (the finest scope).
        if ($employee->job_category_id !== null) {
            $byCategory = $candidates->where('job_category_id', $employee->job_category_id)->values();
            if ($byCategory->isNotEmpty()) {
                return $this->resolveAndAnswer($byCategory, $rf, 'job_category', null);
            }
        }

        // Tier 2 — the employee's CONFIDENTLY-resolved group (else skip — never guess).
        $groupCode = $this->resolveEmployeeGroupCode($employee);
        if ($groupCode !== null) {
            $byGroup = $candidates->filter(fn (ReferenceFact $f) => $this->factMatchesGroup($f, $groupCode))->values();
            if ($byGroup->isNotEmpty()) {
                return $this->resolveAndAnswer($byGroup, $rf, 'group_label', $groupCode);
            }
        }

        // Tier 3 — the convenio-wide fact (null job_category_id AND null group_label).
        $wide = $candidates->filter(fn (ReferenceFact $f) => $f->job_category_id === null && $f->group_label === null)->values();
        if ($wide->isNotEmpty()) {
            return $this->resolveAndAnswer($wide, $rf, 'convenio_wide', null);
        }

        // Tier 4 — verified facts exist, but only per-group/per-category ones that
        // DON'T apply to this employee's resolvable scope (or the group can't be
        // confidently resolved). Escalate — NEVER answer from a guessed group.
        return $this->escalate($rf, 'only per-group/per-category facts exist; employee scope does not confidently match one (group unresolved or different group) — never guess');
```

The employee's group is read only through the category:

```194:201:hr-backend/app/Services/ReferenceFactAnswerService.php
    /** The employee's group code (e.g. "1"), or null when it can't be resolved. */
    private function resolveEmployeeGroupCode(Employee $employee): ?string
    {
        $code = $employee->jobCategory?->group_code;
        $code = $code !== null ? trim((string) $code) : '';

        return $code === '' ? null : $code;
    }
```

And the fact's group is matched by a bare-digit regex against free prose — the thing 7f deletes:

```203:216:hr-backend/app/Services/ReferenceFactAnswerService.php
    /**
     * True when the fact's free-text `group_label` confidently names the
     * employee's group code as a standalone token ("Grupo 1", "Grupos 1 y 2" both
     * match code "1"; "Grupo 10" does NOT match code "1"). Conservative — a label
     * that doesn't clearly name the group is not a match (Tier 4 escalates).
     */
    private function factMatchesGroup(ReferenceFact $fact, string $groupCode): bool
    {
        if ($fact->group_label === null || $fact->group_label === '') {
            return false;
        }

        return (bool) preg_match('/(?<!\d)'.preg_quote($groupCode, '/').'(?!\d)/u', $fact->group_label);
    }
```

The failure is exactly as `sprint-07c/review.md` §7 (lines 113–118) and the roadmap 7f entry record it: for convenio 21, `group_code = "2"` matches *"Grupo 1 (todas las áreas) y Grupo 2 (área 5)"* (90/75/60) as readily as *"Grupo 2 (resto áreas)"* (60/45/30). The regex has no way to see that "área 5" narrows the scope; it sees a standalone `2` and answers. Whichever fact sorts first by validity wins — a confident, verified, cited, wrong answer.

The 7c invariant tests that cover the ladder are in `hr-backend/tests/Feature/Sprint7cReferenceFactAnswerTest.php`; the two group ones are `test_per_group_only_fact_with_unresolved_group_escalates_not_guesses` (lines 165–177) and `test_per_group_fact_answers_when_employee_group_resolves` (lines 179–189), plus the tier-precedence test at lines 191–203. All three construct a category with a **clean digit `group_code`** (`'1'`) — a value that, as §1.2 shows, does not occur anywhere in the real corpus. The tests are correct as unit tests and green; they simply do not describe the data.

### 1.2 The real `group_code` distribution — the load-bearing data

Live from staging, all 94 rows. **22 non-null, 72 null.**

| Convenio | Cats | With `group_code` | The actual values | What they really are |
|---|---:|---:|---|---|
| 3 · OCIO EDUCATIVO (Álava) = **COEAS Álava** | 30 | 0 | — | Real job-category names, no group anywhere |
| 6 · DEPORTE CANTABRIA | 4 | 4 | `2.1` `3.1` `3.2` `4.1` | `name == group_code` — these rows *are* codes, not categories |
| 10 · AGENCIAS DE VIAJES | 5 | 3 | `3` `4` `6` | `name == group_code`; the other 2 rows are `Coordinacion`, `Plus transporte` (pay concepts, not categories) |
| 15 · INFORMACIÓN Y DOCUMENTACIÓN | 5 | 0 | — | The group is **in the name**: `Grupo I. Jefes de Área` … `Grupo V. Atención Directa` |
| 18 · ACCIÓN E INTERVENCIÓN SOCIAL (Navarra) | 12 | 8 | `13184.92` `11781` `7683.26` `5393.83` `3595.89` `2480` `2000` `1500` | **Salary figures** mis-parsed into the label column. The 4 null rows are named `GRUPO 1`…`GRUPO 4` |
| 19 · COEAS NAVARRA | 32 | 6 | `Grupo I: personal di rectivo`, `Grupo II: personal directivo y de gestion`, `Grupo III: personal de atención directa en equipamientos de cul tura…`, `Grupo IV: personal de atención directa`, `Grupo V: personal de administración`, `Grupo VI: personal de servicios generales` | Prose titles, OCR-damaged (`di rectivo`, `cul tura`), present **only on the block-leading row** (ids 65, 66, 67, 83, 86, 90) — the other 26 rows are null |
| 22 · LIMPIEZA (Navarra) | 5 | 1 | `2024` | A **year** mis-parsed |
| 28 · DEV FIXTURE | 1 | 0 | — | Placeholder |

Classifying the 22 non-null values:

- **9 are not group codes at all** — 8 salary figures (c18) + 1 year (c22).
- **7 are codes on rows that are not categories** — c6 `2.1`–`4.1` and c10 `3`/`4`/`6`, all with `name == group_code`.
- **6 are prose group titles**, sparse and OCR-damaged (c19).
- **0 are a clean group code attached to a distinct job-category name.**

That last line is the finding. The shape the 7c tests assume, and the shape an additive-column design would need, **does not exist in the corpus even once.**

Why: `group_code` is only ever written by `salary:import`, from the leftmost label column of a salary spreadsheet:

```364:378:hr-ai/app/salary.py
        # Label columns → group_code (leftmost) + job_category_name (rightmost),
        # skipping genuinely empty cells (never the literal string "None").
        labels = []
        for c in label_cols:
            if c < len(r) and r[c] is not None:
                s = re.sub(r"\s+", " ", str(r[c])).strip()  # collapse embedded newlines
                s = s.strip("'\u2019\u2018\"`").strip()  # strip wrapping quotes/apostrophes (e.g. "2.1'" → "2.1")
                if s:
                    labels.append(s)
        if not labels:
            continue
        job_category_name = labels[-1]
        group_code = labels[0] if len(labels) >= 2 else (
            labels[0] if re.match(r"^\d+(\.\d+)?'?$", labels[0]) else None
        )
```

It is a *spreadsheet layout heuristic*, not a semantic field. When a workbook has one label column holding a number, that number becomes both the name and the code — which is how a salary figure and a year became "group codes". This is working as designed for salary; it is simply not a group vocabulary. The roadmap already said as much ("across the corpus it is decimals/prose/null, never a clean digit", line 172); §1.2 is the quantified version.

**The other half of the finding — the demo convenios have no vocabulary at all:**

| Convenio | Categories | Convenio text | Role in this sprint |
|---|---:|---|---|
| **21 · HOSTELERIA NAVARRA** | **0** | doc 51, **30 pages** | The entire eyes-on sequence; tests (a), (b), (c) |
| 3 · COEAS Álava | 30 (all `group_code` null) | doc 56, 43 pages | Test (d) — undivided group matches on group alone |
| 4 · COEAS Andalucía | **0** | doc 89, 65 pages | Cross-province spot-check |
| 11 · COEAS Estatal | **0** | docs 76/87, 74+62 pages | Cross-province spot-check |
| 19 · COEAS Navarra | 32 (6 prose codes) | doc 34, 16 pages | The richest membership case |

Convenio 21 has the text to propose from and **nothing to validate membership against**. Any design in which a group is defined by, or requires, its categories produces an empty structure for the one convenio the sprint is judged on.

### 1.3 The fact side

`reference_facts` is created by `2026_06_26_120001_create_reference_facts_table.php`, extended additively by `…_120002_add_segmentation_fields…` (7b-2: `group_label`, `confidence`, `uncertainty`, `source_excerpt`, `proposal_batch_id`, `duplicate_of_id`, and `rejected` on `status`) and `2026_06_27_100002_add_resolution_fields…` (7d: `resolution`, `superseded_by_id`, `resolved_by`, `resolved_at`).

`group_label` is in the logical key, and the docblock explains precisely why — it is the same "no categories exist" pressure, met in 7b-2 with a free-text string:

```35:47:hr-backend/app/Models/ReferenceFact.php
    /**
     * The logical-key columns the 7b-2 AI writer upserts on (see class docblock).
     *
     * EXTENDED in 7b-2 (Q1) with `group_label`: because `convenio_job_categories`
     * is salary-derived and unseeded for the periodo convenios, `job_category_id`
     * is usually null, so the group ("Grupo 1/2/3") MUST be a first-class identity
     * discriminator or per-group facts collide on the key and the upsert clobbers
     * them. `group_label` carries that (common) case; `job_category_id` is used
     * too when a real category resolves.
     *
     * @var list<string>
     */
    public const LOGICAL_KEY = ['convenio_id', 'topic_id', 'job_category_id', 'group_label', 'validity_start', 'validity_end'];
```

There is no DB unique constraint; the key is enforced in `ReferenceFactProposalService` (`findByLogicalKey`, used at lines 142–175). **`group_label` stays exactly as it is** — it is the printed provenance string and the 7b-2 upsert identity. 7f adds binding *alongside* it and changes neither.

`ReferenceFactProposalService` reads pages (lines 65–73), builds a convenio-scoped closed vocabulary including each convenio's job categories (lines 313–329), calls hr-ai, and persists inert `needs_review` rows with `source = 'ai_agent'` plus a `tag_events` row per fact (`logEvent`, lines 289–301). 7d resolution is `POST /admin/reference-facts/{uuid}/resolve-duplicate` (`routes/api.php` line 190) → `FactResolutionService`, supersede / coexist / reject, append-only, never deletes.

The 7b-2 eval harness is intact and unmodified: `eval/gold-set.json` (39 gold facts for file 1; 45 + 2 skip-blocks for file 2; 0 for the xlsx routing test), `eval/score_eval.py` (stdlib-only, scope scored by convenio identity `numero == convenio_hint`), `eval/README.md` (the runbook), and the three fixtures. The captured local outputs `facts_file1.json` / `facts_file2.json` / `facts_xlsx.json` are also present — those are the **local baseline** Phase 0 compares against.

### 1.4 The employee side

`employees` carries `convenio_id` (NOT NULL), `territory_id` (NOT NULL), `job_category_id` (nullable FK). **No group column of any kind.** Create/edit validation is inline in the controller — there is no FormRequest for employees — and includes the convenio-scoping rule that the group picker will copy verbatim:

```253:265:hr-backend/app/Http/Controllers/Admin/EmployeeDirectoryController.php
        // job_category must belong to the chosen convenio (no cross-convenio scope).
        if (! empty($data['job_category_id'])) {
            $belongs = ConvenioJobCategory::where('id', $data['job_category_id'])
                ->where('convenio_id', $data['convenio_id'])
                ->exists();
            if (! $belongs) {
                abort(response()->json([
                    'message' => 'La categoría profesional no pertenece al convenio seleccionado.',
                    'errors' => ['job_category_id' => ['La categoría no pertenece al convenio.']],
                ], 422));
            }
        }
```

The CSV bootstrap is `POST /admin/employees/import/validate` (dry-run, writes nothing) → per-row report → `POST /admin/employees/import` (apply, **per-row transaction**, valid rows only, invalid rows reported and skipped). Optional column resolution is by normalized name within the convenio (`EmployeeCsvImporter` lines 156–166). The picker is fed by `GET /admin/job-categories?convenio_id=` (`VocabularyController::jobCategories`), and `DirectoryPage.tsx` reloads it on convenio change and clears the selection (lines 221–225, 303).

Current staging employees — **all 12 have a null effective `group_code`**, which is why the bug is latent:

| Email | Convenio | Category | `group_code` |
|---|---|---|---|
| `test-ocio-alava@` | 3 COEAS Álava | Director/a gerente | null |
| `salary-test@` | 18 Acción e Interv. Social | GRUPO 1 | null |
| `test-navarra@` | 22 Limpieza Navarra | Jefe Grupo General Interior | null |
| `test-gestores-gipuzkoa@` | 15 Información y Doc. | Grupo I. Jefes de Área | null |
| (8 others) | 4, 9, 12, 13, 20, 28 | mostly none | null |

No employee exists on convenio 6 or 10 — the only two convenios holding clean digit codes. §2.7 turns that observation into an explicit gate.

### 1.5 Staging: config, endpoints, current state

Verified live:

- **`reference_facts`: 0 rows.** `documents`: 104, of which **`document_type = reference_source`: 0**. Flow 3 has genuinely never run here. `tag_events`: 571.
- **Segmentation model.** hr-ai `/health/config` reports `answer_provider: claude`, `answer_model: claude-sonnet-4-5`. There is **no `SEGMENTATION_MODEL`** anywhere — segmentation reuses the answer model, passed per call as `provider_config` by hr-backend (`ReferenceFactProposalService` lines 77–81). `HR_AI_ANSWER_MODEL` is **not set** in the staging compose or the hr-backend container env, so it resolves to the `config/services.php` default, line 31: `'answer_model' => env('HR_AI_ANSWER_MODEL', 'claude-sonnet-4-5')`. **This is the same model string the 7b-2 local eval ran on**, so a config-drift delta is not expected; any delta is provider-side drift behind the alias. That is exactly the thing Phase 0 is worth running to learn.
- **Endpoints** (probed in-network from the hr-backend container, `POST` with an empty body): `/segment-facts` → **401**, `/propose-tags` → **401**, `/propose-groups` → **404**, `/health` → 405 (GET-only). So the segmentation surface is present and auth-guarded as required, and `/propose-groups` is confirmed new work.
- Topic 1 is `periodo de prueba`; `document_types` id 1 is `reference_source`.

### 1.6 The 7b-2 eval baseline (what Phase 0 must reproduce)

From `sprint-07b-2/review.md` §5.2 / §5.3, the local run after the two additive prompt rules:

| Fixture | Gold | Correct (scope+value) | Mis-scoped | Notes |
|---|---:|---:|---:|---|
| `PERIODOS DE PRUEBA.docx` | 39 | 39 | **0** | cross-province Álava G1 = 5 intact |
| `PERÍODOS PRUEBA ACTUALIZADOS 2026.docx` | 45 | 44 (effective 45/45 — 4 captured under a synonym group-label) | **0** | statutory-estatal binds closed 10 → 0 |
| `Tablas acuerdo parcial_Alhambra.xlsx` | 0 | — | **0** | jornada over-extraction closed 6 → 0 |

Zero mis-scoped and zero wrong-province across all three. Known residual: the duplicate detector keys on exact `group_label`, so a version that **re-groups** slips the check (file 2's Navarra Intervención G2 = 4 meses vs file 1's compound "Grupos 1 y 2" = 6 meses is correctly scoped but not linked).

### 1.7 Reuse vs new

| Layer | Reuse unchanged | New in 7f |
|---|---|---|
| hr-ai | `require_internal_token` (`main.py` 54–57), the request/response envelope, provider-error-at-200, closed-set validation idiom in `claude.py` (1141–1168), streaming+salvage from 7b-2 | `POST /propose-groups` + its prompt + `claude.py::propose_groups` (read-only, **no migration** — ADR-0007) |
| hr-backend job | `ShouldQueue`, `$tries = 1`, dispatch-after-commit, swallow-and-log (`SegmentReferenceSource` 44–49, `ProposeDocumentTags` 34–58) | `ProposeConvenioGroups` (dispatched **manually per convenio**, not on ingest) |
| hr-backend service | page-text read, `AnswerModelSetting` key path, closed-vocabulary builder, DB-transaction persist, `logEvent` | `ConvenioGroupProposalService` + `GroupApprovalService` (the binding diff) |
| hr-backend client | `ExtractionClient` shape (`segmentFacts`, 378–407) | `proposeGroups()` |
| Provenance | `tag_events`, append-only, `source = 'ai_agent' | 'admin_manual'` | new `entity_type = 'convenio_group'`, facets `group` / `sub_area` / `fact_binding` |
| Frontend | `--provenance-ai` fuchsia (`index.css` 29–36), `.ai-pill` / `.ai-marked`, `ReviewQueuePage` tab pattern (22–43), `ReferenceFactPanel` action idiom | a **Groups** tab + tree/excerpt/diff surface |
| Directory | convenio-scoping rule, CSV validate→report→apply, picker reload-on-convenio-change | group picker + `group` CSV column |
| Answer loop | everything (pre-check, only-verified, validity, authority, skip-ground, composition) | Tier 2's comparison **only** |
| 7d | supersede / coexist / reject, `FactDuplicatePanel` | nothing |

---

## 2. Phase 0 — run the reference-fact flow on staging

A rerun of a proven flow. The deliverable is *evidence and facts*, not code. If the eval drifts, that is a finding to record and fix narrowly (spec line 50).

### 2.1 Ingest the three fixtures

Upload each of `sprint-07b-2/fixtures/` as `document_type = reference_source` through the admin UI, setting validity **at ingest** (the agent never parses dates from prose — ADR-0022): file 1 → pre-2026 window, file 2 → 2026 window, the xlsx → its own window. Ingest auto-dispatches `SegmentReferenceSource` (`DocumentIngestor` lines 304–312); `POST /admin/reference-sources/{uuid}/segment` re-runs it. Expected after ingest: `documents` 104 → 107, `reference_source` count 0 → 3.

### 2.2 Segmentation

Confirm before spending: `AnswerModelSetting` holds a working key; hr-ai `/health/model` returns `model_present: true` (it does); the worker container is up (`hr-staging-hr-backend-worker-1` — it is). Watch for the 7b-2 output-truncation issue on file 2; streaming + salvage is already in hr-ai, so this is a watch-item, not expected work.

### 2.3 Scoring — what "reproduces" means, and how a delta is reported

Export per fixture (`GET /admin/reference-facts?source=ai_agent&queue=true`, save the `rows` array), then run the unmodified scorer:

```bash
python3 score_eval.py --gold gold-set.json --facts facts_file1_staging.json \
    --fixture "PERIODOS DE PRUEBA.docx"
```

**"Reproduces" is defined as, in priority order:**

1. **`mis-scoped == 0` on all three fixtures.** This is the criterion. Mis-scoped means a fact bound to a convenio outside every gold scope — the confident-wrong-province harm the architecture exists to prevent. **Non-zero is a stop-and-report, not a proceed.**
2. **`correct (scope+value)` within −2 of the local baseline** per fixture (39, 44, 0). Beyond −2, investigate before proceeding.
3. **The three named spot-checks hold**: Álava COEAS G1 = 5 (not 6), Andalucía G1 = 6, Estatal G1 = 6; and the Navarra Hostelería compound group carries an `uncertainty` flag.
4. **The xlsx still yields zero salary-shaped facts.** Any wage figure, €/h or SMI is an eval failure regardless of the other numbers.

**A delta is reported as** a `review.md` §Phase-0 table with local-vs-staging columns per fixture, the verbatim `score_eval.py` mis-scoped enumeration, and for each differing line: the fact, its confidence, whether it carried an `uncertainty` flag, and a classification — **model drift** (same prompt, same model alias, different output), **config drift** (something differs from §1.5 — not expected, since the model string is identical), or **data drift** (the registry changed since 7b-2). Prompt changes are out of scope unless a *mis-scope* appears; that is the one case that justifies reopening the prompt, and it gets its own narrow decision.

The scorer, gold set and fixtures are **not** to be modified. If the scorer needs a change to run on staging exports, that is a finding.

### 2.4 The Pedram verification checkpoint

On the Reference-facts tab, verify a working set — not all ~85 proposals:

- **The cross-province trio**: COEAS Álava (3) G1 = **5 meses**, COEAS Andalucía (4) G1 = **6 meses**, COEAS Estatal (11) G1 = **6 meses**. Álava reading 5 while its neighbours read 6 is the trap; if it reads 6, stop.
- **The Navarra Hostelería trio** (convenio 21): *Grupo 1 y área 5 de Grupo 2* = 90/75/60, *Grupo 2 resto áreas* = 60/45/30, *Grupo 3* = 45/30/15. These three are the substrate for Phases 2 and 3 — verify them in **both** file-1 and file-2 form so the 7d pass has a pair to resolve. (Note: the roadmap calls these "facts 202/203"; those were local IDs. Staging IDs will differ — the labels are the identity.)
- The COEAS Álava/Andalucía/Estatal G2 and G3–6 lines, to give test (d) an undivided group to match on.

Each verify is a `status → verified` transition plus an append-only `tag_events` row. Rejects and edits are equally valid outcomes and get recorded.

### 2.5 The 7d resolution pass

Both docx fixtures cover overlapping scopes, so file-1/file-2 pairs will be flagged with `duplicate_of_id`. Resolve through the existing surface (`POST /admin/reference-facts/{uuid}/resolve-duplicate`) — **supersede** where file 2 is a newer version of the same scope, **coexist** where the validity windows genuinely differ. Never delete. Expect the known 7b-2 gap to reappear: the Navarra Intervención Social G2 re-group (file 1 "Grupos 1 y 2" = 6 meses → file 2 "Grupo 2" = 4 meses) will **not** be auto-flagged, because the detector keys on exact `group_label`. Resolve it by hand and record it. (7f makes this class of miss structurally fixable later, since bound groups compare exactly — but binding-aware duplicate detection is *not* in this sprint's scope.)

### 2.6 The two 7c baseline checks

- **Convenio-wide fact answers.** Pick a verified fact with null `job_category_id` and null `group_label` — the Gipuzkoa Información y Documentación line is the natural one, and `test-gestores-gipuzkoa@example.com` sits on convenio 15. Ask the topic question in chat; expect `floor_decision.path = reference_fact`, `authority_used = structured_reference`, `chunk_id = null`, and no grounding block.
- **Group-scoped fact escalates for a group-less employee.** A convenio-21 test employee (no categories exist there, so no group can resolve) asking *"¿cuál es mi periodo de prueba?"* must escalate `reference_fact_coverage_gap`. **This escalation is the sprint's baseline** — the thing Phase 3 converts into 60/45/30. Capture the trace; it is the before-picture in `review.md`.

This needs a convenio-21 test employee, which does not exist today. Add one to `ChatTestUserSeeder` behind `staging:seed-test-users --chat-profiles` — with **`job_category_id = null`** (there is nothing to point at) — so the group-less baseline and the Phase 3 demo use the same profile.

### 2.7 The safety gate that is not yet written down

The roadmap's hard precondition (line 173) is stated as *"do not seed `convenio_job_categories` with clean digit `group_code`s for a convenio that has verified group-scoped facts before 7f lands."* Phase 0 is the first time the other half of that condition — **verified group-scoped facts** — becomes true on staging, and the digit matcher is still live until Phase 3. So the precondition needs an executable form. Before and after Phase 0:

```sql
-- MUST return zero rows, before verifying and after.
SELECT e.email, c.name, jc.name, jc.group_code
FROM employees e
JOIN convenio_job_categories jc ON jc.id = e.job_category_id
WHERE jc.group_code IS NOT NULL AND trim(jc.group_code) <> ''
  AND EXISTS (SELECT 1 FROM reference_facts rf
              WHERE rf.convenio_id = e.convenio_id
                AND rf.status = 'verified' AND rf.group_label IS NOT NULL);
```

It returns zero today (all 12 employees have a null effective `group_code`, §1.4). The convenios at risk are **6 (Cantabria: `2.1`–`4.1`) and 10 (Agencias: `3`/`4`/`6`)** — the only clean codes in the corpus — and neither has an employee. The gate goes in `review.md` and in `deploy.md`'s go-live list, and it retires the moment Phase 3 lands.

### 2.8 Cost and time

| Item | Estimate |
|---|---|
| Model spend, one clean pass over 3 fixtures (`claude-sonnet-4-5`, ~45k in / ~8k out per fixture) | ~**$0.80** |
| Allowing 2–3 runs (a truncation retry, a re-segment) | ~**$2–3**, budget **< $5** |
| Wall-clock, ingest + segmentation + export + score | ~**45–60 min** |
| **Pedram verification + 7d resolution (the real cost)** | ~**2–3 h** across ~85 proposals, verifying a working set |

Phase 0's risk is not money, it is time-boxing. If the eval reproduces and the working set verifies, stop — do not verify all 85, and do not reopen the 7b-2 prompt.

---

## 3. Phase 1 — the schema, decided against the real data

### 3.1 The choice: a `convenio_groups` table, not an additive shape on categories

**Decision: `convenio_groups`.** Not close, given §1.2.

The additive-on-`convenio_job_categories` option would mean normalizing `group_code` in place (or adding `group_id` beside it) and reading group structure out of the category rows. Against the real data that fails four ways:

1. **Coverage.** 72 of 94 rows have nothing to normalize. Of the 22 that do, 9 are salary figures or a year and 7 are rows that aren't categories. The usable residue is 6 prose titles in one convenio.
2. **The demo convenios have no rows at all.** Convenio 21 has zero categories, so an additive column gives it zero groups — and convenio 21 is tests (a), (b), (c) and the entire eyes-on sequence. A model that cannot represent the headline case is not a model.
3. **Sub-areas are unrepresentable.** *Área 5* of Grupo 2 is not a job category; it is a slice of a group that the convenio prices differently. There is no category row to hang it on, and inventing one would mint categories (ADR-0011).
4. **It welds two lifecycles together.** Categories are minted by `salary:import` from spreadsheets. Groups come from convenio prose via human-approved proposal. Sharing a table means a salary re-import can silently disturb approved group structure. Separate tables keep `salary:import` entirely untouched — which it must be, one sprint after Correction-salary-01.

The corollary that follows from §1.2 and must be stated plainly: **`group_code` is not migrated, not normalized, not backfilled, and not read by the matcher.** It stays exactly where it is, doing its salary job. Phase 2 passes it to the proposer as *evidence* — a hint that the source spreadsheet grouped things a certain way — and the human decides. Nine of its 22 values are wrong on their face; a normalization rule that ingests them is a rule that launders bad data into approved structure.

**And the second corollary: groups are a new vocabulary, minted on approval, not derived from categories.** ADR-0011 forbids minting *categories* — the closed set the answer path binds to. A group node is a different object with its own human gate, and for convenio 21 it is the only object available. What stays closed-set is **category membership**: the proposer may only attach *existing* categories to a group, never invent one. For convenio 21 that set is empty, the proposed structure is groups-and-sub-areas only, and that is completely sufficient — the employee binds to the group directly.

### 3.2 The shape

I recommend a **self-referencing tree of depth ≤ 2** over two parallel tables, and flag it for review since the spec words the scope key as `(convenio_id, group_id[, sub_area_id])`:

```
convenio_groups
  id
  convenio_id        FK convenios, NOT NULL
  parent_id          FK convenio_groups NULL   -- null = a group; set = a sub-area of that group
  code_normalized    varchar NOT NULL          -- the comparison key: '1', '2', 'area-5', 'resto-areas'
  label              varchar NOT NULL          -- as printed: 'Grupo 2', 'área 5', 'resto áreas'
  source_excerpt     text NULL                 -- the line that justifies this node (sub-areas: required)
  status             enum('needs_review','approved','rejected')  NOT NULL
  source             enum('ai_agent','admin_manual')             NOT NULL
  proposal_batch_id  uuid NULL
  approved_by        FK admins NULL
  approved_at        timestamp NULL
  timestamps
  UNIQUE (convenio_id, parent_id, code_normalized)
  CHECK: a node whose parent_id is set must have a parent with parent_id IS NULL  -- depth ≤ 2
```

A sub-area is a child node. The spec's `sub_area_id` is then simply "a `group_id` that has a parent", which satisfies the scope key while removing a column, a null-combination, and a class of `(group=X, sub_area=Y-belonging-to-Z)` inconsistency that no constraint could easily catch. It also makes the escalate rule in Phase 3 a clean ancestor test rather than a two-field case analysis. **Trade-off:** slightly less literal against the spec's wording, and recursive-ish reads — bounded to two levels, so a single self-join.

Membership, applicable only where categories exist:

```
convenio_group_categories
  convenio_group_id  FK convenio_groups (a node at either level)
  job_category_id    FK convenio_job_categories
  status / source / approved_by / approved_at
  UNIQUE (job_category_id)   -- a category belongs to at most one node
```

Kept as a join table rather than a column on `convenio_job_categories` so that Phase 2's proposals live *outside* the salary-owned table and a `salary:import` re-run can never touch approved memberships.

### 3.3 Facts bind through a join table, because the headline fact is compound

The spec says `reference_facts` gains nullable `group_id` + `sub_area_id`. **That cannot represent fact "Grupo 1 (todas las áreas) y Grupo 2 (área 5)"**, which spans two different groups. With a single pair the fact stays unbound, so it is not group-matchable, so a Grupo 1 employee escalates — and spec test (c) requires that employee to receive 90/75/60. The single-pair design fails acceptance criterion 4 on the sprint's own example.

So:

```
reference_fact_group_scopes
  reference_fact_id   FK reference_facts (cascade on delete)
  convenio_group_id   FK convenio_groups   -- a group node OR a sub-area node
  bound_by            FK admins            -- binding is always a human act
  bound_at            timestamp
  UNIQUE (reference_fact_id, convenio_group_id)
```

The Hostelería trio then binds as: file-2 fact "Grupo 1 (todas las áreas) y Grupo 2 (área 5)" → **two** rows, `{G1}` and `{G2/área 5}`; "Grupo 2 (resto áreas)" → one row `{G2/resto áreas}`; "Grupo 3 (todas las áreas)" → one row `{G3}`. Tier 2 becomes: *the fact matches if **any** of its bound scope rows matches the employee's node.* Each of tests (a)–(d) then falls out directly.

`reference_facts` itself gains **no columns**. `group_label` is untouched — still the printed provenance string, still in the logical key, still what 7b-2's upsert keys on. A fact with no rows in this table is simply unbound, exactly as Phase 3 requires.

### 3.4 How the 94 categories map onto it

Given §1.2, honestly: **almost nothing is deterministic.**

| Convenio | Deterministic membership | Proposed (needs a human) |
|---|---|---|
| 19 COEAS Navarra | **6** — the block-leading rows carry an explicit `Grupo I:`…`Grupo VI:` on the row itself; the code parses cleanly even though the description is OCR-damaged | 26 — the remaining rows are null because the spreadsheet printed the group once per block. Forward-fill is a *plausible hypothesis*, not a fact, and it is exactly the kind of guess this sprint exists to route through a human |
| 15 Información y Documentación | 0 | 5 — the names *are* groups (`Grupo I. Jefes de Área`); propose 5 group nodes and let the human decide whether these rows are groups mis-filed as categories |
| 18 Acción e Intervención Social | 0 | 12 — 4 rows named `GRUPO 1`…`GRUPO 4` (groups mis-filed as categories) + 8 salary-figure rows that should map to **no group** |
| 3 COEAS Álava | 0 | 30 real category names, no group signal at all — membership comes from the convenio text (doc 56, 43 pages) or stays unmapped |
| 6 Cantabria · 10 Agencias · 22 Limpieza | 0 | 12 rows, mostly `name == group_code` artifacts and pay concepts (`Coordinacion`, `Plus transporte`, `Nocturnidad`) — likely **no** group membership, and saying so is a valid proposal outcome |
| **21 Hostelería Navarra** | — | **0 categories.** Groups and sub-areas only, from doc 51's 30 pages. The critical path, and it needs no category at all |

**6 of 94 deterministic (6.4%).** Which is the argument for the whole propose-then-approve design: there is no honest deterministic path here, and the alternative to a human gate is a heuristic guessing on OCR-damaged, sparsely-populated, sometimes-wrong data. Note also that unmapped categories are **fine** — membership only ever produces a *suggested default* for an employee (§3.6); the matcher never reads it.

### 3.5 Normalization rules for `code_normalized`

These apply to the group code the **proposer extracts from convenio text**, never to `group_code` (§3.1). Deterministic, in a single `GroupCodeNormalizer` class, unit-tested per rule:

1. Unicode NFKD → strip accents → lowercase → collapse whitespace (the `TextNormalizer::key()` idiom already in the codebase).
2. Strip a leading group word: `grupo`, `grupos`, `grup`, `nivel`, `categoría profesional`. Strip trailing punctuation (`.`, `:`, `)`).
3. **Roman → arabic, only when the whole remaining token is a roman numeral in I–X**: `i`→`1` … `x`→`10`. So `Grupo I` and `Grupo 1` normalize alike, which is required — file 1 writes *"Grupo 1"* and file 2 writes *"Grupo I"* for the same Valencia groups.
4. **A decimal is preserved verbatim, never split.** `2.1` normalizes to `2.1`, **not** to group `2` sub-area `1`. Cantabria's `2.1` is a salary-table code of unknown semantics; inventing a hierarchy from a dot is precisely the digit-regex mistake in new clothing. If a convenio genuinely uses `2.1` as "sub-area 1 of group 2", the *proposer* says so with an excerpt and a human approves it.
5. **Sub-area codes are slugs, not numbers**: `área 5` → `area-5`, `resto áreas` → `resto-areas`, `todas las áreas` → `todas-las-areas`. They are unique only within their parent, so `(convenio_id, parent_id, code_normalized)` is the unique key.
6. **Ambiguity never resolves silently.** A label that normalizes to empty, or to something matching no rule (`Obreros y subalternos`, `Técnicos titulados`, `Emprendedores` — all real gold group labels), keeps its **label as its own normalized slug**. These are legitimate groups that simply aren't numbered; they must be representable, they compare exactly like any other node, and nothing about them is inferred.
7. Normalization is **only** applied at propose time and shown to the human alongside the printed `label`. It is never re-derived at answer time — Tier 2 compares `convenio_groups.id`, an integer, not a string.

Rule 6 matters more than it looks: roughly a third of the gold set's group labels are prose, not numbers.

### 3.6 Employees

`employees` gains **one** nullable column: `convenio_group_id` FK → `convenio_groups`. With the §3.2 tree, no `sub_area_id` is needed — the employee points at whichever node describes them, a top-level group or a sub-area.

- Validated convenio-scoped and approved-only, copying the `job_category_id` rule at `EmployeeDirectoryController` lines 253–265 verbatim: the node's `convenio_id` must equal the employee's, and its `status` must be `approved`.
- **Blank means unresolved → the matcher escalates.** Exactly today's behaviour, and the default for all 12 existing employees and all ~1,500 real ones.
- **Never auto-assigned.** Where the employee's `job_category_id` maps to exactly one approved node via `convenio_group_categories`, the directory form **pre-fills** the picker and labels it *"sugerido a partir de la categoría — confirma"*. Nothing is written until the admin saves. Where the mapping is absent or ambiguous, the field stays blank. Per §3.4 this default is available for almost nobody today, and for convenio 21 it is structurally unavailable — that is fine; it is a convenience, not a mechanism.
- Changing an employee's `convenio_id` clears `convenio_group_id`, mirroring `DirectoryPage.tsx` line 303's handling of the category.

### 3.7 Directory and CSV

- **Picker**: a new `GET /admin/groups?convenio_id=` on `VocabularyController`, modelled on `jobCategories`, returning the approved tree (id, parent_id, code_normalized, label) for that convenio. `DirectoryPage.tsx` reloads it on convenio change, renders it as a two-level indented select, and clears the selection when the convenio changes.
- **CSV**: one new optional column, **`group`**, resolved within the convenio by matching against `code_normalized` **or** `label` (the same normalized-key comparison `EmployeeCsvImporter` already uses for `job_category`, lines 156–166). To address a sub-area, `group` accepts `Grupo 2 > resto áreas` — the `>` separator is explicit, so no delimiter is guessed. Blank leaves the employee unresolved. An unmatched value is a **row error** in the dry-run report, never a silent null and never a minted group. `CsvImportPanel`'s column documentation gains the new column. `apply` stays per-row transactional as it is today.

### 3.8 Migrations (all additive, hr-backend only)

1. `create_convenio_groups_table`
2. `create_convenio_group_categories_table`
3. `create_reference_fact_group_scopes_table`
4. `add_convenio_group_id_to_employees` (nullable FK, `nullOnDelete`)

No column is dropped, no data is backfilled, no existing table is altered except `employees` gaining one nullable FK. hr-ai gains a read-only endpoint and **no migration** (ADR-0007).

---

## 4. Phase 2 — AI proposes the group structure; a human approves

### 4.1 `POST /propose-groups` (hr-ai, read-only, writes nothing)

Same shape as `/segment-facts` (`main.py` 778–838): `dependencies=[Depends(require_internal_token)]`, decrypted key in the body, provider failure returned as HTTP 200 with `{error: "provider_error"}`.

**Inputs**: `convenio_id`, `convenio_name`, `pages_text` (the convenio's `document_pages`, concatenated as in `ReferenceFactProposalService` 65–73), `candidate_categories` (id + name + the raw `group_code` **explicitly labelled as an unreliable spreadsheet hint, not ground truth** — §1.2), and `existing_group_labels` (the distinct verified `reference_facts.group_label` strings for that convenio, which is what the structure has to be able to bind).

**Output**: a proposed tree —

```json
{"groups": [
  {"code": "2", "label": "Grupo 2", "source_excerpt": "…",
   "sub_areas": [
     {"code": "area-5", "label": "área 5", "source_excerpt": "El personal del área 5 del Grupo 2…90 días…"},
     {"code": "resto-areas", "label": "resto áreas", "source_excerpt": "…resto de áreas del Grupo 2…60 días…"}],
   "category_ids": []}],
 "trace_fragment": {...}}
```

**Rules encoded in the prompt:**

- **A sub-area exists only where the text assigns the sub-slices different values.** Undivided is the default and the common case.
- **Every sub-area carries a `source_excerpt` that shows the differing values.** A sub-area without one is dropped by validation — this is the mechanical enforcement of "only where values differ".
- **Bias toward splitting on any value difference** (spec risk note: under-splitting is the dangerous error).
- **Never mint a category.** `category_ids` is validated against the passed set and out-of-set ids are dropped, exactly as `claude.py` 1141–1168 already does for convenios and topics. An empty `category_ids` is a valid, expected answer (convenio 21).
- **Never invent a group with no textual basis**; a group node also carries an excerpt.

`ExtractionClient::proposeGroups()` mirrors `segmentFacts` (378–407), 180s timeout, `groups_unavailable` on non-2xx, never throws.

### 4.2 `ProposeConvenioGroups` (hr-backend)

Mirrors `SegmentReferenceSource` — `ShouldQueue`, `$tries = 1`, guard-and-return, swallow-and-log. **Dispatched manually per convenio** (`POST /admin/convenios/{id}/propose-groups`, gated `knowledge.edit`), *not* on ingest: this runs once per convenio, is human-initiated, and there is no ingest event that means "this convenio's group structure changed".

`ConvenioGroupProposalService` persists nodes as `status = 'needs_review'`, `source = 'ai_agent'`, one `proposal_batch_id` per run, plus a `tag_events` row per node (`entity_type = 'convenio_group'`, `source = 'ai_agent'`, `actor_id = null`) via the existing `logEvent` idiom. Re-running upserts on `(convenio_id, parent_id, code_normalized)`; an **approved** node is never modified or reverted by a re-run — only `needs_review` nodes are replaced, and a previously **rejected** node returns to `needs_review` with its rejection visible in the timeline (the 7b-2 "never resurrect silently" posture).

### 4.3 The Groups review surface

A fifth tab on `ReviewQueuePage` (`'groups'`, alongside `tagging | reference-facts | vocabulary | expiry`, lines 22–43), listing convenios with a pending proposal. Selecting one opens a panel carrying, per proposed node:

- the **tree** (group → sub-areas), fuchsia `.ai-marked` while unapproved, using the existing `--provenance-ai` token (`index.css` 29–36) with no new styling;
- the **justifying excerpt** — for a sub-area, the lines showing the differing values, which is what the human is actually adjudicating;
- the **categories** proposed as members (empty for convenio 21, and shown as empty rather than hidden);
- **the facts that would bind to this node** — each verified `reference_facts` row whose `group_label` matches, with its value, so the consequence is visible before approval.

### 4.4 Approve / edit / reject, and the fact-binding diff

Per node: **approve**, **edit** (label, code, membership, promote/demote a sub-area), **reject**. All append-only to `tag_events` with `source = 'admin_manual'` and the actor.

Approving is **two confirmations, not one**. Approving a node creates the `convenio_groups` row. Binding facts is a **separate, explicit diff** the human confirms:

```
Grupo 2 › resto áreas
  + bind  fact #NNN  "Grupo 2 (resto áreas)"  = 60/45/30 días
Grupo 1
  + bind  fact #MMM  "Grupo 1 (todas las áreas) y Grupo 2 (área 5)"  = 90/75/60 días
Grupo 2 › área 5
  + bind  fact #MMM  (same fact, second scope — compound)
  ⚠ fact #MMM binds to 2 scopes
```

The compound case is shown as what it is. Suggested bindings come from normalized `group_label` matching; **the human's confirmation is the only thing that writes `reference_fact_group_scopes`**, and each row records `bound_by`/`bound_at`. Unbinding is available and equally logged. An approval guard rejects the incoherent case: a fact bound to a parent node that *has* approved sub-areas (if the group is split by value, a parent-level binding is ambiguous by construction).

Provenance for the whole flow lives in `tag_events` with `entity_type ∈ {'convenio_group', 'reference_fact'}` and facets `group` / `sub_area` / `fact_binding` — no new event table, matching how reference facts reuse `tag_events` today.

### 4.5 The group eval

A new `sprint-07f/eval/` mirroring the 7b-2 discipline: a hand-built `group-gold.json` and a stdlib-only `score_groups.py`.

**Gold structures** for the fixture convenios, built from the convenio text and cross-checked against the verified Phase 0 facts:

| Convenio | Expected structure |
|---|---|
| 21 Hostelería Navarra | G1 undivided · **G2 split into `área 5` / `resto áreas`** · G3 undivided |
| 3 / 4 / 11 COEAS Álava, Andalucía, Estatal | G1–G6, **all undivided** |
| 18 Acción e Intervención Social Navarra | G1 / G2 (+ "resto de grupos" as the gold's own label) |

**Metrics**, per convenio and in total:

- **exact** — the node set matches gold, splits exactly where gold splits;
- **over-split** — proposed a sub-area gold does not have. Safe-ish: it makes more employees escalate. Reported, not fatal;
- **under-split** — gold has a sub-area the proposal does not. **This is the failure metric.** An under-split merges two different values into one node, which is the wrong-answer path this sprint exists to close;
- plus **spurious** (a group with no gold counterpart) and **missing** (a gold group not proposed).

**Gate: 0 under-splits across the fixture convenios, or each one individually explained and accepted.** The headline single number is *"under-splits: N"*. Membership accuracy is reported alongside but is **not** gated — it is a UI convenience (§3.6), and for convenio 21 there is nothing to measure.

---

## 5. Phase 3 — the exact matcher

### 5.1 The new Tier 2

Tiers 1, 3 and 4 are untouched. Tier 2 becomes:

```
employeeNode := employee.convenio_group_id            (null → skip Tier 2 entirely, as today)
factNodes(f) := f.reference_fact_group_scopes[*].convenio_group_id

A fact matches iff ANY of its bound nodes n satisfies:
  (1) n.id == employeeNode.id                                  → MATCH
  (2) n is a CHILD of employeeNode  (fact is sub-area-specific,
      employee sits at the group level)                        → ESCALATE — cannot tell which slice
  (3) n is the PARENT of employeeNode and n has approved
      children (a split group carrying a group-level fact)      → ESCALATE — the fact's own scope is ambiguous
  (4) otherwise                                                 → NO MATCH (fall through to Tier 3/4)

A fact with zero bound nodes is NEVER group-matchable — it falls to Tier 3 (convenio-wide,
which requires null job_category_id AND null group_label, so a group_label-only fact
cannot satisfy it either) and therefore to Tier 4: escalate.
```

Rule (2) is the spec's *"fact has a sub-area and the employee's is blank → escalate"*, expressed as an ancestor test. Case (1) covers both *"exact group + exact sub-area"* (both nodes are the same leaf) and *"undivided group matches on group"* (both are the same childless top-level node) — one comparison, an integer equality, no string handling at answer time.

An escalate from rules (2)/(3) is a **hard stop for the tier**, not a skip: once any bound node proves the employee's scope indeterminate, the turn escalates rather than falling through to a convenio-wide answer that would silently be less specific than the evidence warrants. This is stated explicitly because it is the one place the ladder's "else continue" shape changes, and it changes in the safe direction.

### 5.2 Deletions

- **`factMatchesGroup` is deleted** (lines 203–216) — the whole method, regex included.
- **`resolveEmployeeGroupCode` is deleted** (lines 194–201). `job_category.group_code` is no longer read by the answer path at all, which is the point: per §1.2 nine of its 22 values are not group codes.
- `match_kind` for this tier changes `'group_label'` → `'group'`, and the trace records the bound `convenio_group_id` and its printed label. `$rf['group_label']` keeps carrying the fact's printed string for display and citation continuity.

### 5.3 Tests (a)–(f)

New cases in `Sprint7cReferenceFactAnswerTest` (extended, never weakened), built on a convenio-21-shaped fixture: G1 undivided, G2 split into *área 5* / *resto áreas*, G3 undivided, **no job categories** — the real shape.

| | Case | Expected |
|---|---|---|
| (a) | Employee bound to G2 › *resto áreas*; both facts verified and bound (compound fact → {G1, G2›área 5}) | **60/45/30**, `path: reference_fact`, never 90/75/60 — the exact bug |
| (b) | Employee bound to G2 (parent, which has children); same facts | **escalate** `reference_fact_coverage_gap` (rule 2) |
| (c) | Employee bound to G1 | **90/75/60** — from the compound fact, via its `{G1}` binding. *This is the case a single `group_id` column could not serve (§3.3).* |
| (d) | Employee bound to COEAS Álava G1 (undivided, childless); fact bound to that node | **match** on the group alone |
| (e) | Fact with a `group_label` but **zero** bound nodes | never group-matchable → Tier 3 fails (non-null `group_label`) → **escalate** |
| (f) | Fact labelled *"Grupo 12"*, bound to node code `12`; employees bound to G1 and to G2 | **neither matches** — the digit regex is gone |

Plus two of my own: **(g)** the compound fact reached from G1 *and* from G2›área 5 returns the same value with the same citation, and **(h)** an employee with `convenio_group_id = null` behaves exactly as today (Tier 2 skipped entirely), which is the no-regression case covering all 1,500 real profiles on day one.

The three existing group tests (lines 165–203) are **rewritten onto structured scope, not deleted** — each keeps its invariant (unresolved escalates; a resolvable group answers; job_category outranks group) and changes only how the group is expressed.

### 5.4 What stays untouched in 7c

The pre-check and `ReferenceFactRouter`; only-verified; the validity window; `structured_reference` authority; the `chunk_id = null` citation shape; skip-`/ground` on the Phase 1 path; the `selectMostRecent` two-verified safe rule; Phase 2 composition and its must-ground rule; the coverage-gap message string. `Sprint7cCompositionTest` should not need an edit.

### 5.5 The golden-trace gate

`Sprint7cAdditivityRegressionTest` must stay **byte-for-byte green** — the prose turn, the salary turn, and the annual-only salary turn, each including the assertion that **no `reference_fact` trace block appears** when no verified fact exists. Since 7f touches only Tier 2's comparison, and every employee's `convenio_group_id` is null until an admin sets it, the additivity argument is straightforward: with no bound scopes and no employee group, the ladder behaves identically. Run it first after the matcher change, not last.

```bash
cd hr-backend
php artisan test --filter Sprint7cAdditivityRegressionTest   # the gate
composer test                                                 # full suite
```

---

## 6. Migrations and build order

Sequential, each phase reviewable, Pedram checkpoints marked ⏸.

| # | Step | Repos | Migration |
|---:|---|---|---|
| 0.1 | Add the convenio-21 chat test profile (`job_category_id = null`) | backend | — |
| 0.2 | Ingest 3 fixtures as `reference_source`; segmentation runs | (staging op) | — |
| 0.3 | Export + `score_eval.py` ×3; record local-vs-staging | docs | — |
| 0.4 | ⏸ **Pedram**: cross-province trio + Navarra Hostelería trio; 7d resolution pass | — | — |
| 0.5 | The two 7c baseline checks; the §2.7 gate query; ledger re-run | docs | — |
| 1.1 | `convenio_groups`, `convenio_group_categories`, `reference_fact_group_scopes` | backend | **3 additive** |
| 1.2 | Models, `GroupCodeNormalizer` + its unit tests | backend | — |
| 1.3 | `employees.convenio_group_id` + directory validation/writable fields | backend | **1 additive** |
| 1.4 | `GET /admin/groups?convenio_id=`; picker in `DirectoryPage`; CSV `group` column | backend, frontend | — |
| 2.1 | hr-ai `POST /propose-groups` + prompt + closed-set validation | **hr-ai (no migration)** | — |
| 2.2 | `ExtractionClient::proposeGroups`, `ProposeConvenioGroups`, `ConvenioGroupProposalService` | backend | — |
| 2.3 | Groups review tab: tree, excerpts, categories, would-bind facts | frontend | — |
| 2.4 | Approve/edit/reject + the confirmed fact-binding diff + provenance | backend, frontend | — |
| 2.5 | `sprint-07f/eval/` — `group-gold.json`, `score_groups.py`; run on the fixture convenios | docs | — |
| 2.6 | ⏸ **Pedram**: Groups review for convenio 21 → approve G2's split → watch the three facts bind | — | — |
| 3.1 | Tier 2 rewritten; `factMatchesGroup` + `resolveEmployeeGroupCode` deleted | backend | — |
| 3.2 | Tests (a)–(h); the three 7c group tests rewritten; **golden trace green** | backend | — |
| 3.3 | ⏸ **Pedram**: convenio-21 employee → G2/*resto áreas* → **60/45/30**; → G1 → **90/75/60**; blank → escalates | — | — |
| 4 | Docs: `architecture.md`, `data-model.md`, `deploy.md`, `roadmap.md` (**7f DONE → Sprint 7 complete**), **ADR-0028**, `sprint-07f/review.md` | docs | — |

**4 migrations, all additive, all hr-backend.** hr-ai gains one read-only endpoint and never migrates (ADR-0007). **ADR-0028 is free** — the highest existing is ADR-0027 (*Salary figures are sourced, never derived*).

**ADR-0028 — Structured group scope** will record: granularity follows value differences (a sub-area exists only where the convenio prices the slices differently); groups are a **new vocabulary minted on human approval from convenio text**, distinct from the never-minted salary category vocabulary (ADR-0011), because the demo convenios have no categories; **`group_code` is salary-layout provenance, not a group model**, with §1.2's distribution as the evidence; fact→scope binding is **many-to-many** because real facts are compound; the matcher is an exact node comparison with no AI at answer time (ADR-0015/0016); unresolved escalates.

---

## 7. Assumptions and open questions

**For the review — the decisions I would most like confirmed:**

1. **The tree vs the two-field scope key (§3.2).** I recommend a self-referencing `convenio_groups` with depth ≤ 2 over explicit `group_id` + `sub_area_id`. It removes a column and a class of inconsistency, and makes the Phase 3 escalate rules ancestor tests. But the spec words the key as `(convenio_id, group_id[, sub_area_id])`, so this is a deliberate deviation. **Confirm or overrule.**

2. **The fact→scope join table (§3.3).** I am fairly confident this is forced rather than chosen: the compound fact is the sprint's own headline example and spec test (c) cannot pass without it. The alternative — splitting a compound fact into two facts at approval time — would honour "one fact per scope" (ADR-0021) more literally, but it manufactures two facts from one source line and mutates a fact Pedram verified in Phase 0. I chose binding over splitting. **Worth a second opinion.**

3. **Dropping the `job_category.group_code` derivation (§3.6).** My kickoff lean was "derive the employee's group where the code is clean and unique, as a confirmed default". §1.2 kills the premise: zero rows are a clean code on a real category, and convenio 21 has no categories at all. I propose deriving the pre-filled default from **approved group→category membership** instead — same "shown and confirmed, never silent" behaviour, honest source. **Confirming the reversal.**

4. **Decimals are never split (rule 4, §3.5).** `2.1` stays `2.1`; it does not become group 2 / sub-area 1. Cantabria's `2.1`–`4.1` *might* be a real two-level hierarchy, but reading a hierarchy out of a dot is the digit-regex error re-committed. If those are real sub-areas, the proposer should say so with an excerpt. **Confirm the conservative reading.**

5. **The COEAS Navarra forward-fill (§3.4).** Categories 68–82 and 91–96 have null `group_code` because the spreadsheet printed the group once per block. Forward-filling would map 26 categories in one stroke and is *probably* right. I propose **not** doing it deterministically — offer it as a proposal with the block structure as its justification, and let the human approve. **Confirm; it is the difference between 6 and 32 deterministic memberships for that convenio.**

6. **The CSV sub-area syntax** `Grupo 2 > resto áreas` (§3.7). Explicit and unambiguous, but invented here. An alternative is two columns (`group`, `sub_area`). **Preference?**

**Open questions I cannot resolve by inspection:**

7. **Does convenio 21's text actually define "área 5"?** The facts describe it, but `/propose-groups` reads doc 51 (30 pages) and needs the *structure* — the área enumeration — to be in there. If the convenio references áreas without defining them, the proposer will produce sub-areas justified only by the periodo-de-prueba clause itself. That is still a valid excerpt and still correct, but it is a thinner basis than assumed. **Answered by Phase 0/2, not by planning.** It does not change the schema.

8. **Convenios 15 and 18 have groups mis-filed as categories** (`Grupo I. Jefes de Área`, `GRUPO 1`…`GRUPO 4`), and convenio 18 has 8 salary-figure rows. Should 7f *clean* these? **My proposal: no.** Leave them; propose group nodes alongside; map no membership to the artifact rows. Deleting or renaming salary-minted rows is a salary-import correction, and Correction-salary-01 is one sprint old. **Record as a follow-up, don't do it here.**

9. **`ANSWER_MODEL` is unset on staging** (§1.5), so segmentation runs on the `config/services.php` default `claude-sonnet-4-5`. Identical to the 7b-2 local run, so the eval *should* reproduce. Worth deciding whether to pin `HR_AI_ANSWER_MODEL` explicitly in the staging compose so future drift is a deliberate change rather than a default shift — **out of scope for 7f, but a one-line deploy hygiene item.**

10. **The 7d duplicate detector's re-group blind spot** (§2.5) will reappear in Phase 0. Once facts carry bound scopes, structural duplicate detection becomes possible. **Explicitly not in this sprint** — noting it so it is not mistaken for a 7f regression.

**Assumptions I am proceeding on unless corrected:** Phase 0 verification is a *working set*, not all ~85 proposals; the 7b-2 prompt, gold set, scorer and fixtures are not modified (a mis-scope is the only trigger to reopen the prompt); `reference_facts` gains no columns and `group_label` is untouched; `salary:import` and `salary.py` are untouched; the group eval gates on under-splits only; membership accuracy is reported but not gated; and no employee is ever assigned a group without an admin saving the form.

---

**Status: plan-gate. Nothing built, nothing ingested, nothing committed. Awaiting review before Phase 0.**
