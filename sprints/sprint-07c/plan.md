# Sprint 7c — Plan: the multi-source composition layer

> Location: `hr-docs/sprints/sprint-07c/plan.md`
> Status: **plan for review — no answer-path code written this turn.**
> Scope: the ONE sprint that touches the frozen 2b answer loop, built as two internal phases under one review. **Phase 1** = the routed reference-fact answer (the salary parallel; lands first and clean). **Phase 2** = the fact+convenio-prose merge (composes on top). This document inspects the real loop, proves additivity for both phases, and lays out the strict build order. Read with `sprint-07c-spec.md`, `architecture.md` §5, `data-model.md` §6, ADR-0006/0015/0016/0020/0021.

The thesis of 7c, confirmed against the real code: the loop **already answers from an exact-structured path (salary) beside the vector path (prose)**. `reference_facts` was built in 7b to mirror the salary schema precisely. So **Phase 1 does for reference facts exactly what `SalaryAnswerService` does for salary** — a new routed branch beside salary, touching none of the salary/prose internals. Phase 2 is the one genuinely-new piece: composing a fact **and** convenio prose into one grounded answer with authority preserved.

---

## 1. What exists — the salary precedent (the pattern Phase 1 mirrors)

I inspected the real classes. The reference-fact path is a **clean parallel** beside each of these, reusing the same shapes and touching none of their internals.

### 1.1 The router + the deterministic salary pre-classifier (`RouterService`)

The router runs *after* the hardcoded guardrail baseline and classifies `salary | prose | off_domain` (ADR-0016 lines 11–15). Layer 1 is a **deterministic, no-LLM, no-key salary pre-classifier** — a high-precision regex pre-empt that routes obvious salary to SQL before any `/route` call:

```47:52:hr-backend/app/Services/RouterService.php
    private const SALARY_PATTERNS = [
        '/\b(salario|sueldo|n[oó]mina|retribuci[oó]n|remuneraci[oó]n)\b/iu',
        '/\btablas?\s+salarial(es)?\b/iu',
        '/\bpagas?\s+extra/iu',
        '/\bcu[aá]nto\s+(gano|gana|gan[aá]is|cobro|cobra|cobr[aá]is|ingreso|ingresa|me\s+pagan?|se\s+(gana|cobra|paga))\b/iu',
    ];
```

```68:97:hr-backend/app/Services/RouterService.php
        // --- Layer 1: deterministic salary pre-classifier (no LLM, no key) ------
        if ($this->matchesSalary($question)) {
            ...
            return $this->decision(
                label: self::SALARY,
                confidence: 1.0,
                source: 'deterministic_salary',
                ...
            );
        }
```

The **fail-safe** spine (ADR-0016 line 13; RouterService 99–110, 144–156): uncertainty / provider error / no key → the safe **prose** path, never a confident misroute. **This is the exact slot Phase 1's pre-check occupies** — deterministic, before the LLM router, fail-safe to fall-through. The salary pre-classifier and the reference-fact pre-check are siblings in the same layer.

### 1.2 `SalaryAnswerService` (SQL, exact, year-aligned — ADR-0006)

The class docblock states the discipline verbatim: *"A salary figure comes ONLY from the typed `salary_table_rows` cell … No LLM, no /synthesise, no /ground"* (lines 13–20). The pieces Phase 1 mirrors one-for-one:

- **Scope + as-of resolution.** `answer()` (line 50) loads the convenio, resolves the table year-aligned: exact year, else the most-recent table with `year <= as-of`, else escalate (never quote a future-only table):

```163:188:hr-backend/app/Services/SalaryAnswerService.php
    private function resolveTable(int $convenioId, int $asOfYear): array
    {
        $exact = SalaryTable::where('convenio_id', $convenioId)->where('year', $asOfYear)->first();
        if ($exact) {
            return [$exact, 'exact'];
        }
        $mostRecentLe = SalaryTable::where('convenio_id', $convenioId)
            ->whereNotNull('year')
            ->where('year', '<=', $asOfYear)
            ->orderByDesc('year')
            ->first();
        ...
        return [null, $hasFuture ? 'future_only' : 'no_table'];
    }
```

- **The exact-cell answer.** Queries the typed `salary_table_rows` cell by `(salary_table_id, job_category_id)` and composes a stated category+year answer (lines 119–142).
- **The `chunk_id = null` citation.** Cites the salary-table source document with no chunk and no page — the structured-data citation shape:

```283:294:hr-backend/app/Services/SalaryAnswerService.php
        return [[
            'chunk_id' => null, // salary is structured data, never a vector chunk (ADR-0006)
            'document_id' => $doc->id,
            ...
            'snippet' => "Tabla salarial {$table->year}".($doc->convenio?->name ? ' — '.$doc->convenio->name : ''),
            'is_salary_table' => true,
        ]];
```

- **Skip `/ground`.** The service never calls synthesise/ground; the answer is SQL-grounded by construction.
- **`salary_coverage_gap`.** No usable table/row → escalate with a distinct reason (not `low_confidence`):

```301:313:hr-backend/app/Services/SalaryAnswerService.php
    private function escalate(array $salary): array
    {
        $salary['outcome'] = 'escalate';
        return [
            'outcome' => self::OUTCOME_ESCALATE,
            'answer' => self::COVERAGE_GAP_MESSAGE,
            'citations' => [],
            'escalation_reason' => 'salary_coverage_gap',
            ...
        ];
    }
```

### 1.3 How salary plugs into the turn pipeline (`ChatService`) + the answer-or-escalate decision

`ChatService::handleMessage` (line 125) is the orchestrator: scope → guardrail baseline → router → `{salary | prose | off_domain}` → persist. The salary branch is self-contained and emits its own trace + `floor_decision.path`:

```255:267:hr-backend/app/Services/ChatService.php
            $result = $this->salary->answer($employee, $asOfDate, $selectedJobCategoryId);
            $trace['salary'] = $result['salary'];

            $outcome = $result['outcome'];
            if ($outcome === SalaryAnswerService::OUTCOME_ANSWER) {
                $trace['floor_decision'] = [
                    'path' => 'salary_sql',
                    'outcome' => 'answer',
                    'escalation_reason' => null,
                    'note' => 'exact figure from salary_tables (year '.($result['salary']['year'] ?? '?').')',
                ];
                return $this->persistTurn($session, $employee, $question, $result['answer'], $result['citations'], $trace, 'answer', null);
            }
```

Key observations for additivity:
- The salary branch sets `$trace['salary']` (a dedicated trace block) and `floor_decision.path = "salary_sql"` with **no Check A/B retrieval gates** — it is structured-grounded, exactly the shape Phase 1 reuses (`reference_fact` block + `path:"reference_fact"`).
- `persistTurn` (line 1052) is **generic** — it writes user/assistant messages, citations (incl. `chunk_id = null`), trace, and an `escalation_card` only on escalate. Phase 1 reuses it untouched.
- The escalation reason is carried through `persistTurn` and the `escalation_cards.reason` enum (see §5 — Phase 1 adds one value here).

### 1.4 The prose path: `/retrieve` · precedence re-rank · `/synthesise` · `/ground` · Check A/B

The prose path (`answerProse`, line 306) is what must be **byte-for-byte untouched** for non-reference-fact turns:
- **Recall-hardened `/retrieve` union** (line 544) + the **widened-pool precedence re-rank** (`precedenceRerank`, line 626) using the topic-anchor lexicon `TOPIC_ANCHORS` (lines 86–103).
- **`/synthesise`** with convenio chunks ordered before `national_law` (`orderByAuthority`, line 769); the precedence prompt lives in the provider.
- **Check A** (top score ≥ retrieval floor, line 363) ∧ **Check B** (valid in-set citations, lines 444–453) ∧ figure-guard pre-check ∧ **`/ground`** per-claim entailment (lines 477–496).

### 1.5 The authority-precedence rule (the convenio governs)

Encoded in `hr-ai` (claude provider) and computed deterministically for the trace. The rank places convenio/ruling above national_law:

```40:44:hr-ai/app/providers/claude.py
_AUTHORITY_RANK = {
    "internal_hr_ruling": 0,
    "official_convenio": 0,
    "national_law": 1,
}
```

The synthesis prompt states it: *"prioriza el convenio donde regule el tema; usa la ley nacional solo para lo que el convenio no cubra"* (claude.py lines 124–131); `authority_used` is ordered by this rank for the trace (lines 645–647). **Phase 2 slots `structured_reference` below `official_convenio` in this same ordering — it does not change the rule.**

### 1.6 Confirmation: `reference_facts` is a clean parallel to the salary table

`reference_facts` was built (7b-1/7b-2) to mirror salary precisely (data-model §6, lines 276–305): scoped via `convenio_id` (territory/sector derive), `job_category_id`/`group_label` for finer scope, `topic_id` (approved only), `value` + `raw_values` verbatim, `validity_start/end`, `source_document_id`, and the structurally-bounded `authority_level`. The model exposes exactly what Phase 1 needs:

```47:47:hr-backend/app/Models/ReferenceFact.php
    public const LOGICAL_KEY = ['convenio_id', 'topic_id', 'job_category_id', 'group_label', 'validity_start', 'validity_end'];
```

```128:132:hr-backend/app/Models/ReferenceFact.php
    /** Inert-until-verified gate (ADR-0020): only a verified fact is answerable (7c). */
    public function isVerified(): bool
    {
        return $this->status === 'verified';
    }
```

The verified-fact query is a clean SQL parallel to `resolveTable` + the typed-row lookup: filter `convenio_id` (+ optional group/category), `status = 'verified'`, `topic_id`, and validity-contains-as-of — then read `value`/`raw_values` and cite `source_document_id` with `chunk_id = null`. **No salary/prose internal is read or modified.**

---

## 2. Phase 1 — the routed reference-fact answer (build first, land clean)

Phase 1 is **the salary parallel, generalized to one new topic-scoped structured class.** A new routed branch + a new service + a new trace block + one additive escalation reason. Nothing in `/retrieve`/`/synthesise`/`/ground`/the re-rank/the salary path changes.

### 2.1 The deterministic reference-fact pre-check (the salary-pre-classifier slot)

A new deterministic step, **no LLM**, in the same layer as the salary pre-classifier — after the guardrail baseline, with/just-after the salary pre-check, before the LLM router. It answers a single question: *does the employee's resolved scope (convenio + as-of) have a **verified** reference fact whose topic matches this question's topic?*

- **Topic match reuses the existing lexicon — no new classifier.** The check anchors the **question** against the same `TOPIC_ANCHORS` terms the precedence re-rank already uses (vacaciones, jornada, permisos, periodo de prueba, …, ChatService lines 86–103) and maps a matched anchor to an **approved** `topics` row by name (the lexicon anchor terms *are* topic-name fragments — e.g. anchor `periodo_prueba` → `['periodo de prueba','período de prueba']` → the seeded `topics.name = 'periodo de prueba'`, migration `…_seed_periodo_de_prueba_topic.php`). It then asks `ReferenceFactAnswerService` whether a verified, in-scope, in-validity fact exists for that `topic_id`.
- **Ordering vs salary.** The salary pre-classifier runs first and wins an obvious salary question (salary keeps its exact path). The reference-fact pre-check runs only on a **non-salary** question (after `matchesSalary` returns false), so the two structured paths never contend. A salary+reference compound is escalate-with-note (mirrors the existing `cross_path` posture — see §6 open questions).
- **Fail-safe (ADR-0016).** No matching verified in-scope fact, any ambiguity, or any error → **fall through to the existing path** (the LLM router → prose/salary as today). The pre-check only ever *adds* a route when a matching verified fact exists; it can never suppress or alter today's routing. This is the byte-for-byte additivity guarantee at the routing layer.

**Lexicon reuse mechanics (additive refactor, no behavior change):** `TOPIC_ANCHORS` is currently a `private const` inside `ChatService` used to anchor *chunk content* in the re-rank. To let the pre-check anchor the *question* on the same source of truth, the constant is lifted into a shared, read-only location (e.g. a `TopicLexicon` helper) that both `ChatService::chunkTopics()` and the new pre-check consume. This is a pure relocation — the re-rank's inputs and outputs are unchanged (proven by the regression check, §4).

### 2.2 `ReferenceFactAnswerService` (the salary sibling)

A new deterministic `hr-backend` service mirroring `SalaryAnswerService`. Given resolved scope + matched `topic_id` + as-of:

- **The verified-in-scope-in-validity query.** `reference_facts WHERE convenio_id = :resolvedConvenio AND topic_id = :topic AND status = 'verified' AND (validity_start IS NULL OR validity_start <= :asOf) AND (validity_end IS NULL OR validity_end >= :asOf)`, with scope refinement: prefer a fact matching the employee's `job_category_id`/resolved `group_label`; else fall back to the **convenio-wide** fact (null `job_category_id` and null `group_label`). This is the direct analog of `resolveTable` + the typed-row lookup. **`status = 'verified'` is non-negotiable** — the ADR-0020/0021 inert-until-verified gate lifts *exclusively* for verified facts (`ReferenceFact::isVerified()`, model line 129).
- **The `value` + `raw_values` answer.** State the fact's `value` and, where present, the relevant `raw_values` breakdown (e.g. 90/75/60 días by contract type), quoted from the verified fact — no paraphrase, no generation.
- **The `chunk_id = null` citation.** Build the citation from `source_document_id` with `chunk_id = null`, no page, in the **exact shape `salaryCitation()` returns** (SalaryAnswerService lines 283–294) — adding a structured-source marker analogous to `is_salary_table` (e.g. `is_reference_fact: true`, see frontend §5).
- **`authority_used = structured_reference`.** Recorded in the trace; the citation's `authority_level` is `structured_reference` (ReferenceFact `AUTHORITY_LEVEL`, model line 33 — structurally bounded, can never be higher).
- **Skip `/ground`.** The answer quotes an exact verified value — there is no generated claim to entail, exactly as salary skips it. (This is the P1 side of the skip-ground-vs-ground line, §3.)
- **No usable fact → `reference_fact_coverage_gap`.** No verified in-scope in-validity match (only `needs_review`/`rejected`, or future-only validity) → escalate with a **distinct** reason (the `salary_coverage_gap` precedent), never `low_confidence`, and **never quote an unverified or out-of-validity fact.** Note: in practice the pre-check (§2.1) only routes here when a match exists, so this branch is the safety backstop for the as-of/validity edge (e.g. a fact that is verified-but-future-only) and is tested hardest.
- **Two verified facts match → a deterministic safe rule.** Pick the **most-recent validity** (`validity_start` desc, open-ended last); if still ambiguous (e.g. two open-ended same-scope verified facts with differing values), **escalate** rather than guess. Rich conflict/version **resolution is 7d** — Phase 1 ships only the safe deterministic rule.

### 2.3 The trace + `floor_decision`

A `reference_fact` trace block parallel to `salary`, set in `ChatService` exactly where the `salary` block is set:

```
$trace['reference_fact'] = {
  convenio_id, topic_id, job_category_id | group_label, as_of_date,
  fact_id, validity_selection, value, authority_used: "structured_reference",
  outcome, note
};
$trace['floor_decision'] = { path: "reference_fact", outcome: "answer"|"escalate", escalation_reason: null|"reference_fact_coverage_gap", note };
```

No Check A/B retrieval checks (structured-grounded), mirroring `path:"salary_sql"` (ChatService lines 260–265).

### 2.4 Phase 1 is independently shippable

After Phase 1: a verified reference fact is answerable on the proven pattern; a non-reference-fact question is byte-for-byte unchanged. Phase 1 is built, regression-checked (§4), and **committed clean before Phase 2 begins** — it is the deliberate fallback if Phase 2's merge proves too delicate (§4.4). Phase 1 has **zero dependency on Phase 2**: it never calls `/synthesise`/`/ground`, so it cannot be destabilized by the composition work.

---

## 3. Phase 2 — the fact + convenio-prose merge (build on top of a clean Phase 1)

Phase 2 adds the genuinely-new piece: when a question needs **both** a verified reference fact **and** governing convenio prose, the engine composes **one grounded answer** with authority preserved — the convenio governing, the fact as the lower-authority structured datum beneath it. `/synthesise` and `/ground` are **reused, not rewritten**: the fact becomes an **added typed, authority-labelled source**.

### 3.1 Detecting a composition turn

Detection **extends** Phase 1's pre-check (it does not add a new classifier):
1. The pre-check finds a verified in-scope fact on the question's topic (Phase 1).
2. Phase 2 additionally runs the **existing** prose retrieval for the same question (`retrieveUnion`, unchanged) and asks whether **governing convenio prose on the topic is present** — i.e. an `official_convenio`/`internal_hr_ruling` chunk that carries the topic anchor and clears Check A (reusing the existing `chunkTopics`/re-rank machinery).

Decision table:
- Fact only (no governing prose on topic) → **Phase 1** (fact-only answer, skip ground).
- Prose only (no verified fact) → **today's prose path** (byte-for-byte unchanged).
- **Both** → the composition path (§3.2).

### 3.2 Composing one grounded answer (authority preserved)

- **Both sources, typed and authority-labelled.** Hand `/synthesise` the convenio chunks at `official_convenio` **and** the reference fact as a source typed `structured_reference`, ordered by the **existing** precedence rule (claude.py `_AUTHORITY_RANK`) extended so `structured_reference` sits **below** `official_convenio`/`internal_hr_ruling` and at/above `national_law` (its slot is fixed by ADR-0021 — a fact can never outrank a convenio).
- **The convenio governs.** Synthesis uses the fact as the structured datum **under** the convenio's governing text — never letting the lower-authority fact override or contradict an official convenio statement on the same point. This reuses the **existing authority-precedence prompt discipline** (convenio governs; baseline-where-silent), extended to name the new level. No prompt rewrite — an added typed source and one new authority label.
- **Same-point conflict → escalate, never blend.** On a genuine fact-vs-convenio conflict on the same point → escalate (a conflict / `low_confidence` reason), never blend or silently prefer the fact — the same no-silent-override spine as the salary/Estatuto precedence and the Sprint-4 ruling gate (architecture.md §8.3, ADR-0021 invariant 1).
- **Grounding applies (the key difference from Phase 1).** A composed answer **is synthesized** — it combines/paraphrases across sources — so it **goes through `/ground`**: each substantive claim entailed against **its** cited source (the fact's claim against the fact; the prose claim against its chunk). The citation set = the fact (`chunk_id = null`) **and** the convenio chunks.

### 3.3 Passing the fact as a typed source (reuse, not rewrite)

The reuse boundary, confirmed against the real request shapes:
- `/synthesise` already carries a per-source `authority_level` (main.py `SynthesisChunk`, lines 109–117) and orders by it — so **authority-labelled sources already exist**. The one gap: `SynthesisChunk.chunk_id` and `document_id` are required **non-null ints**, but a fact has `chunk_id = null`. So the fact-as-source needs an **additive** representation: either (a) relax `chunk_id` to `int | None` plus an additive `source_type` discriminator, or (b) add an additive `facts: list[FactSource]` field alongside `chunks`. **Recommended: option (a)** — a nullable `chunk_id` + `source_type ∈ {chunk, reference_fact}` keeps one ordered source list and one citation-mapping path (least churn to the precedence/citation code). Either is purely additive: a prose-only turn sends no fact source and the request is identical to today.
- `/ground` (`GroundChunkBody`, main.py lines 144–148) similarly needs the fact represented as a cited source with `chunk_id = null` + its content (`value`/`raw_values`) so the fact's claim entails against the fact. Same additive nullable-`chunk_id` + `source_type` treatment.
- **hr-backend** wires the fact into the synthesis source list and the grounding citation set, and into `resolveCitations`/`MessageCitation` (the citation row already accepts `chunk_id = null` — ChatService line 1084).

This is an **additive field on the request shapes**, not a rewrite of synthesis/ground logic — the entailment, citation-mapping, and precedence-ordering code is reused as-is. (The exact field choice is flagged in §6 for review sign-off.)

### 3.4 The trace

The composition turn records both sources, `authority_used` listing both levels in precedence order (`["official_convenio","structured_reference"]`), the grounding verdict, and a `composition` marker — so an auditor sees *answered from convenio (governing) + reference fact (structured datum), grounded*. `floor_decision.path = "reference_fact_composition"` (distinct from P1's `"reference_fact"` and from `"salary_sql"`).

### 3.5 The skip-ground (P1) vs ground (P2) line — drawn explicitly

- **Phase 1 quotes a verified value → skips `/ground`.** The answer *is* the fact's `value`/`raw_values`, verbatim and exact (like the salary cell). There is no generated claim to entail; grounding would be checking a string against itself. SQL/structured-grounded by construction.
- **Phase 2 generates a composed answer → must `/ground`.** The answer combines and paraphrases the fact *and* convenio prose into new sentences. Those are synthesized substantive claims, so each must be entailed against its cited source (the full per-claim gate), exactly as a prose answer is. This is the core grounding-correctness call of the sprint.

### 3.6 Scope discipline (don't over-reach)

Composition is scoped to **fact + convenio-prose on a shared topic**. A salary+fact+prose triple, or arbitrary multi-topic compounds, stay **escalate-with-note** (the existing `cross_path` posture) — the richer per-clause decomposition is a later follow-up (spec "Out of scope").

---

## 4. The additivity proof + the regression check (load-bearing)

This is the discipline of the whole sprint: prose and salary turns must be **byte-for-byte unchanged** vs today, after **both** phases.

### 4.1 What changes

**Phase 1:**
- `RouterService` (or a sibling): a new deterministic reference-fact pre-check in the salary-pre-classifier layer (additive branch; fall-through is the default).
- New `ReferenceFactAnswerService` (a new class; touches nothing existing).
- `ChatService`: a new branch that calls the pre-check and, on a hit, the new service → `persistTurn` (reused unchanged); a new `reference_fact` trace block; `floor_decision.path = "reference_fact"`. `TOPIC_ANCHORS` relocated to a shared lexicon (pure move).
- One additive migration: `reference_fact_coverage_gap` added to the `escalation_cards.reason` enum.
- `hr-frontend`: render the fact answer + its `structured_reference` citation (reuse the salary-citation rendering path).
- `hr-ai`: **nothing** (DB query + answer assembly is hr-backend, like salary).

**Phase 2:**
- `ChatService`: composition-detection (extends the pre-check) + the typed-source synthesis wiring + the grounding citation set; a `composition` trace marker; `floor_decision.path = "reference_fact_composition"`.
- `hr-ai`: an **additive field** on `/synthesise` + `/ground` request shapes to carry the fact as a typed source (nullable `chunk_id` + `source_type`); the precedence ordering gains the `structured_reference` label. **No rewrite** of synthesis/ground logic.
- `hr-frontend`: render the composed answer + the multi-source citation set (fact + convenio chunks).
- No migration in Phase 2 (the enum value lands in Phase 1; a Phase-2 conflict escalation reuses an existing reason — `low_confidence`/`conflict`).

### 4.2 What does NOT change

For **every non-reference-fact turn**, the control flow is identical to today:
- The guardrail baseline and admin layer (ChatService lines 149–186) are untouched and still run first.
- The salary pre-classifier still wins an obvious salary question first; `SalaryAnswerService`, `salary` trace block, and `floor_decision.path:"salary_sql"` are unchanged.
- The reference-fact pre-check **falls through** whenever there is no verified in-scope topic-matching fact — which is the case for every prose/salary turn that exists today (no fact exists for that topic+scope, or the topic doesn't anchor) — so the LLM router and the prose path (`/retrieve` union · precedence re-rank · `/synthesise` · Check A∧B · figure-guard · `/ground`) run exactly as before.
- `/retrieve`, `/synthesise`, `/ground` request/response shapes are **backward-compatible**: Phase 2's additive fields are absent on a prose-only turn, so the payloads are byte-for-byte identical to today.
- `persistTurn`, citation persistence, and trace persistence are reused unchanged.

**The single load-bearing claim:** the reference-fact path is reachable *only* when a `verified` reference fact in the asker's scope matches the question's topic. Until 7c, no fact was answerable, so today no turn takes this branch; after 7c, only the newly-answerable verified-fact turns diverge. Every other turn is unchanged by construction.

### 4.3 The regression check

A test that pins the frozen loop, run after Phase 1 and again after Phase 2:
1. **A representative prose question** (e.g. Navarra *vacaciones* → "37 días laborables", cited to convenio chunk 7721, never the Estatuto) — assert the **answer text, citations, and the full trace** (`router_decision`, `retrieval.passes`, `rerank.boosted`, `floor_decision`, `authority_used`) are **identical to the pre-7c baseline**.
2. **A salary question** (e.g. "¿cuánto gana un peón?") → assert the exact figure, the `chunk_id=null` salary citation, and `floor_decision.path:"salary_sql"` are identical to the pre-7c baseline.
3. Both must use a scope/topic that has **no verified reference fact**, so the pre-check provably falls through (assert no `reference_fact` trace block is present).
4. Capture a golden trace snapshot from the current build *before* any 7c code lands; the regression test diffs against it. The check is part of the Phase 1 commit gate **and** re-run as a Phase 2 gate.

The existing invariant tests (`Sprint7b1ReferenceFactInvariantTest`, `Sprint7b2SegmentationInvariantTest`) plus new 7c tests cover the only-verified gate: a `needs_review`/`rejected`/out-of-validity/future-only fact is **never** quoted (the eyes-on "same question for a `needs_review` scope → does NOT answer from the fact").

### 4.4 How Phase 1 stays separable (the deferral fallback is real)

- Phase 1 introduces **no** call to `/synthesise`/`/ground` and **no** change to their shapes — so Phase 1 can be built, regression-checked, and **committed clean** with zero Phase-2 scaffolding.
- The `reference_fact_coverage_gap` migration and the fact-answer/citation rendering are Phase-1-only and self-contained.
- If Phase 2's merge proves too delicate mid-build, Phase 1 ships alone (facts answerable, fact-only); Phase 2 becomes a clean follow-up. Nothing in Phase 1 is half-built without Phase 2. **The build order is strict: Phase 1 fully built + regression-clean + (recommended) committed before Phase 2 begins.**

---

## 5. Migrations & build order

**Strict order: Phase 1 fully built + regression-clean + (recommended) committed before Phase 2 begins.**

### Phase 1 (build, regression-check, commit)

**hr-backend**
1. Migration (additive): add `reference_fact_coverage_gap` to the `escalation_cards.reason` CHECK enum — following the **exact pattern** of the salary migration (introspect the constraint name, `DROP IF EXISTS`, re-`ADD` with the full value set, working `down()`):

```26:32:hr-backend/database/migrations/2026_06_23_100001_add_salary_coverage_gap_to_escalation_cards_reason.php
    private const PRIOR = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat'];
    ...
    private const CURRENT = ['low_confidence', 'sensitive_topic', 'off_domain', 'explicit_request', 'conflict', 'salary_not_in_chat', 'salary_coverage_gap'];
```

The new migration's `CURRENT` appends `reference_fact_coverage_gap`. **This is the only schema migration in 7c.**
2. Lift `TOPIC_ANCHORS` into a shared `TopicLexicon` (pure relocation; re-rank behavior unchanged).
3. New `ReferenceFactAnswerService` (the verified-in-scope-in-validity query; `value`+`raw_values` answer; `chunk_id=null` citation; `authority_used=structured_reference`; skip `/ground`; the two-verified safe rule; `reference_fact_coverage_gap`).
4. The deterministic pre-check (topic-match via the lexicon; fail-safe fall-through) in `RouterService`/sibling.
5. `ChatService`: the new routed branch + the `reference_fact` trace block + `floor_decision.path:"reference_fact"` + citation wiring through the existing `persistTurn`.

**hr-frontend**
6. Surface the fact answer + its source citation — **reuse the salary-citation rendering**. The `Citation` interface already supports `chunk_id: null` and `is_salary_table?` (api.ts lines 433–444); add an analogous `is_reference_fact?` and a `structured_reference` badge in `CitationList` (lines 5–19, beside the `Tabla salarial` branch). Trace panel surfaces the `reference_fact` block.

**hr-ai**
7. **None.** Phase 1 is a DB query + answer assembly in hr-backend, exactly like salary.

### Phase 2 (only after Phase 1 is committed-clean)

**hr-backend**
8. Composition-detection (extends the pre-check; runs the existing prose retrieval to see if governing convenio prose on the topic is present).
9. The typed-source synthesis wiring (add the fact to the ordered source list) + the grounding citation set (fact `chunk_id=null` + convenio chunks); the `composition` trace marker + `floor_decision.path:"reference_fact_composition"`; same-point conflict → escalate (reuse `conflict`/`low_confidence` — **no new reason, no migration**).

**hr-ai**
10. **No rewrite.** Add the additive request field so `/synthesise` + `/ground` can receive the fact as a typed source (recommended: nullable `chunk_id` + `source_type ∈ {chunk, reference_fact}` on `SynthesisChunk`/`GroundChunkBody`); extend `_AUTHORITY_RANK`/the authority label to include `structured_reference` below `official_convenio`. Synthesis/ground/entailment/citation-mapping logic is reused as-is.

**hr-frontend**
11. The composed answer + multi-source citations (fact + convenio chunks) — reuses the citation list with both source types.

### Migration inventory
- **Phase 1:** one additive enum migration (`reference_fact_coverage_gap` on `escalation_cards.reason`).
- **Phase 2:** **none.**
- No new tables, no new permissions, no `reference_facts` schema change (7b built the columns 7c queries). hr-ai never migrates (ADR-0007).

### The ADR (flagged)
A new ADR for the composition layer, beside ADR-0006/0015/0016/0021, covering: the routed reference-fact answer (the salary parallel); only-verified answers (the gate lifts exclusively for `verified`); the skip-ground (P1) vs ground (P2) line; the `structured_reference` precedence slot below `official_convenio`; and the convenio-governs composition (escalate-not-blend on same-point conflict). Written at sprint close with the review.

---

## 6. Assumptions & open questions

1. **Topic-match mechanism (recommended decision).** Reuse `TOPIC_ANCHORS`: anchor the **question** against the same terms the re-rank uses, then map a matched anchor to an **approved** `topics` row by name (anchor terms are topic-name fragments). *Open:* the lexicon's internal keys (`periodo_prueba`) differ from `topics.name` (`periodo de prueba`); the bridge is a small static anchor→topic-name map (or matching the anchor terms directly against `topics.name`/aliases). The `topics` table has no `aliases`/`slug` column (migration `…_create_topics_table.php`), so the static map in the shared `TopicLexicon` is the pragmatic choice. **Confirm:** match strictly on the lexicon (no LLM), accepting that a topic with no anchor simply falls through (safe).
2. **Group/job_category vs convenio-wide resolution.** Recommended: prefer the most-specific verified fact (employee `job_category_id`, else resolved `group_label`), else the convenio-wide fact (both null). *Open:* the employee profile resolves a `job_category_id` but **not** a `group_label`; most periodo facts are keyed by `group_label` (job categories unseeded for those convenios — 7b-2 note). So convenio-wide vs per-group selection may often land on "convenio-wide or escalate-if-ambiguous." **Confirm** the group-resolution rule with Pedram (it borders 7d resolution).
3. **Validity / as-of selection.** Mirror salary's "most-recent `≤ as-of`" as "validity contains as-of" (`validity_start ≤ as-of ≤ validity_end-or-open`). A future-only verified fact → `reference_fact_coverage_gap` (never quote a not-yet-effective fact — the salary `future_only` precedent).
4. **Reference-fact-vs-salary ordering.** Salary pre-classifier wins first (salary keeps its exact path); the reference-fact pre-check runs only on non-salary questions. A salary+reference compound is escalate-with-note (the existing `cross_path` posture), not a triple-compose. **Confirm** this ordering is acceptable.
5. **Composition-turn detection (P2).** "Governing convenio prose present" = an `official_convenio`/`internal_hr_ruling` chunk on the topic that clears Check A in the existing retrieval. *Open:* the exact presence threshold (reuse Check A's retrieval floor vs a dedicated one). Recommended: reuse Check A so detection rides the proven gate.
6. **Skip-ground (P1) vs ground (P2) boundary.** P1 = quoted verified value (no generated claim → skip). P2 = synthesized composition (generated claims → must ground). The boundary is "did we generate prose or quote a value." Drawn explicitly in §3.5.
7. **How the fact is passed as a typed source (additive field — needs sign-off).** The request shapes **already** carry per-source `authority_level`, but `chunk_id`/`document_id` are required non-null ints. Recommended additive change: nullable `chunk_id` + a `source_type` discriminator on `SynthesisChunk`/`GroundChunkBody` (option a), keeping one ordered source list. Alternative: a separate additive `facts: []` field (option b). **Decision needed at review.**
8. **Does the new escalation reason need a migration?** Yes — one additive enum migration in Phase 1 (`reference_fact_coverage_gap`), pattern-identical to the salary one. Phase 2 needs none.
9. **Non-obvious from the real code:**
   - The salary citation already proves the `chunk_id = null` + `is_salary_table` rendering path end-to-end (api.ts, `CitationList`, `MessageCitation` accepting null `chunk_id`) — Phase 1's citation is a near-copy.
   - `persistTurn` and the trace persistence are fully generic — no change needed to add a new path.
   - `authority_used` is computed deterministically in the provider and ordered by `_AUTHORITY_RANK`; Phase 2 must add the `structured_reference` rank there so the audit ordering is correct.
   - The `reference_facts` logical key includes `group_label`, but the **employee** side has no `group_label` — so group-scoped fact selection is the trickiest scope-resolution detail (Q2).

---

## 7. Hard-constraint compliance (self-check)

- **Purely additive / frozen-loop discipline.** ✔ Prose & salary turns byte-for-byte unchanged after both phases (§4); the reference-fact path is a new branch beside salary; Phase 2 reuses `/synthesise`/`/ground` by adding the fact as a typed source (§3.3), never rewriting them or the salary/prose internals. **No internal change was required** to make facts answerable/composable — if implementation reveals otherwise, stop and flag.
- **Phase 1 first, separable, shippable.** ✔ §2.4, §4.4 — committed-clean before Phase 2; Phase 2 deferrable without a half-built tangle.
- **Only verified facts answer.** ✔ `status = 'verified'` in the query; the gate lifts exclusively for verified (§2.2); tested hardest (§4.3).
- **Precedence preserved.** ✔ `structured_reference` below `official_convenio`; a fact never overrides the law; same-point conflict escalates, never blends (§3.2).
- **Skip-ground (P1) vs ground (P2).** ✔ Drawn explicitly (§3.5).
- **hr-backend owns the deterministic decision.** ✔ Pre-check, service, decision, trace all in hr-backend; additive migrations only (§5).
- **Reuse.** ✔ Salary routed-path pattern, salary citation rendering, the existing topic lexicon, `/synthesise`/`/ground`/the precedence re-rank, the `floor_decision`/trace shapes — all reused.

---

## 8. Definition of done (for the build turn, not this plan turn)

All acceptance criteria pass (both phases, or Phase 1 alone with Phase 2 explicitly deferred if the merge can't land safely); Pedram eyes-on (the Navarra fact reaching chat; the `needs_review` non-answer; the composed grounded answer; the prose/salary additivity); docs updated (`architecture.md`, `data-model.md` — the `reference_fact` trace block + `floor_decision.path` + the composition marker + the new escalation reason, `roadmap.md`); the new ADR; and `review.md` (both phases, the additivity/regression proof, the only-verified test, the composition grounding). **This turn produces only this plan — no answer-path code. Ready for review.**
