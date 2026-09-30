# Slice 13b — Plan: planner-driven question normalization

> Status: **PLAN — stop for review.** No code, no commits, no staging access, no model spend were made to produce this.
> Inputs read: `sprint-13b-spec.md` and `sprint-13b-kickoff-prompt.md` (the kickoff calls them `spec.md` / `kickoff-prompt.md`; the files in this folder carry the `sprint-13b-` prefix — not renamed), Sprint 13 `plan.md` §B/§C/§D, `review.md` (CP-2 tables, lines 786–830), ADR-0035, and the real code on `main` (hr-backend `0ddaef2`, hr-ai `476eb1b`, hr-frontend `8d35afe`, hr-docs `a9f19e8`).
> What was run: read-only offline analysis of rows already on disk (`sprint-13/eval/results/*.jsonl`) and of static classes (`TopicLexicon`, `GeneralLanePostCheck`) from throw-away scripts in `/tmp` — no database, no hr-ai, no staging, **$0**. Their outputs are quoted below; the scripts are committed at build step 0, not now.
> Classic stays byte-identical. Everything that touches a class classic also uses is an **additive optional parameter or a new method** (listed in §8.2).

---

## 0. The short version — eight findings that shape this plan

1. **The "18 %" is a mixture, and the real number is 0 %.** Labelling each phrasing by whether the anchor lexicon can see it (§1): the 76 colloquial phrasings the lexicon cannot see pass **0/170 (agent) and 0/141 (classic)**; the 17 "colloquial" phrasings that *do* contain an anchor ("¿Cuántas vacaciones tengo?", "¿Qué días festivos hay en mi convenio?") pass 86 %. 17/93 = 18 % is just the anchored share. The change is therefore 0 % → ≥ 75 % on the population the fast path misses, not 18 % → 75 %.
2. **The fact-routing set is five sentences.** All 93 cases share **one canonical and one colloquial sentence per topic** (5 + 5 distinct strings, varied only by employee scope). The three that matter (jornada, periodo de prueba, permisos) are three sentences repeated 76 times. A ≥ 75 % gate on that is a test of three strings and is trivially over-fittable by prompt tuning. The plan adds a **held-out colloquial bank** (§7.1) and gates on it.
3. **The seam is the tool, not the planner.** On the 170 unanchored agent rows the planner already called `reference_fact` first 141 times (83 %); the tool re-ran the lexicon on the literal question (`ReferenceFactTool.php:57`) and returned `no_fact`. The model understood; the lookup ignored it. Also, Phase-2 composition filters governing prose by the **literal question's** lexicon topics (`ReferenceFactPath.php:158`), so a normalized route that only swaps the topic id would silently degrade to a bare Phase-1 quote. §4 carries the topic through.
4. **A token-set diff is bypassable; the diff check must be span-based.** Prototyped against 28 over-reach cases: a naive "scan the added tokens" lets *"permiso retribuido por matrimonio de quince días"* through, because the quantity noun `días` is already in the literal and F2 needs it adjacent. Counting a pattern hit iff its **matched span contains ≥ 1 added token** (plus an explicit numeric-literal check) rejects **28/28**, and accepts 13/14 legitimate canonicals (§3, Appendix A).
5. **Recommended: the shell runs the fact route after a valid normalization ("Round 1a"), like Round 0 does for the lexicon** (§4.1). Left to the planner, the route depends on first-tool choice (83 % today) and on the planner not calling `escalate` first; at ~89 % composition ceiling that alone caps the pass rate near 73 %, under the 75 % bar. This is the one place the plan goes beyond the spec's literal text; §8.5 Q3.
6. **This slice amends ADR-0035 §2.** ADR-0035 says the agent "can only escalate more than classic, never answer more". Normalization exists to answer where the literal path escalated (a fact reached through a normalized topic; Check A cleared by the canonical pass). Every downstream gate is unchanged, but the sentence is no longer true and needs an addendum / ADR-0036 (§8.5 Q4). The gate counts and hand-reviews every such "rescue".
7. **Pre-existing agent quirk found on the way:** `convenio_search` lets the planner's `query` replace the employee's literal question, and that string is what `/synthesise` and `/ground` see (`ConvenioSearchTool.php:86-102` → `ProsePath.php:209, 274`). That contradicts "planner rephrasings reach retrieval only" (Sprint 13 plan §B.3.3) and R3's "never replacing". §4.3 pins the literal (agent-only).
8. **Classic stays at 0 % on unanchored colloquial, by contract.** After 13b the two engines diverge on exactly this population. The roadmap §7 lexicon ticket (`roadmap.md:310`) is not superseded; §8.5 Q9.

Planner-call economics: no new call. One extra control tool on round 1 (~+650 prompt tokens, ~+90 completion tokens ≈ **+$0.003–0.004** per planner turn at list price, inside the spec's $0.005–0.01). Round-0-settled turns are untouched.

---

## 1. Reproduce the baseline (no spend) — and the labelling rule (R4)

### 1.1 What was reproduced

Rows: `sprint-13/eval/results/parallel-run-facts-both-x3-partial.jsonl` (both engines, ×3, 60 case-ids; the review says 59 — one case is also in the second file) + `cp2-facts-remaining-agent-x1.jsonl` (34 cases, agent ×1). Pass = the set's own criterion (`fact-routing.json` header: `floor_decision.path ∈ {reference_fact, reference_fact_composition}`, or the fact path's own coverage gap for `fact_group_labelled_unbound`).

The `review.md:792-795` table reproduces exactly (`canonical` agent 159/180, classic 163/180; `colloquial` agent 33/179, classic 33/180) — and 18 % is 33/180.

### 1.2 The labelling rule (outcome-independent, deterministic, frozen)

Two labels per phrasing, neither derived from how the run went:

| label | how it is assigned | purpose |
|---|---|---|
| `authored` = `canonical` \| `colloquial` | the fixture field it was authored under (`canonical_question` / `colloquial_question`, split into `.canonical` / `.colloquial` case-id suffixes at `AnswerGate.php:144-155`) | what the spec calls the split |
| `anchored` = `true` \| `false` | `TopicLexicon::candidateTopicNames($question)` contains the case's expected approved-topic name (`TopicLexicon.php:116`, `ANCHORS` `:26`) | whether the deterministic fast path can see it — i.e. **exactly the population Round 0 misses and normalization exists for** |

Why the second label is not circular: 13b does not touch `TopicLexicon`; the label depends only on the question text and the (frozen) anchor map, never on the engine's behaviour. The fixture header will record `sha256(json(TopicLexicon::ANCHORS+TOPIC_NAMES))`; if anchors change the labels are recomputed and the gate refuses to compare across hashes.

Guards (fixture errors, fail the build): an `authored=canonical` phrasing that is unanchored; an `authored=colloquial` phrasing that is anchored **must be reported as a control class, not counted as colloquial** for the ≥ 75 % gate.

### 1.3 The split

Authored × anchored, 93 cases:

| | anchored | unanchored |
|---|---|---|
| canonical | 93 | 0 |
| colloquial | 17 (vacaciones 15, festivos 2) | **76** (jornada 23, periodo de prueba 40, permisos retribuidos 13) |

Pass rates (agent = ×3 file + ×1 remaining; classic = ×3 file only):

| authored | anchored | agent | classic |
|---|---|---|---|
| canonical | yes | **190/214 = 89 %** | 163/180 = 91 % |
| colloquial | yes (control) | 37/43 = 86 % | 33/39 = 85 % |
| colloquial | **no** | **0/170 = 0 %** | **0/141 = 0 %** |

- The spec's "≈ 90 % / 18 %" reproduces (89–91 % / 18 %), and the second number is the anchored share of the colloquial column, not a pass rate on colloquial language.
- Round 0 evidence for "obvious questions never reach the planner": all 257 anchored agent rows have `first_tool=reference_fact, terminal=finalize` (the round-0 stamp, `AgentChatService.php:125-132`) and a mean of $0.032 (canonical) — no planner cost. The 170 unanchored rows are the ones that reach the planner (mean $0.060, 15.4 k prompt tokens vs 7.4 k).
- Why the 0 %: 141/170 first-tool `reference_fact` (tool → `no_fact`), then prose (113 answered by prose, path ≠ fact = fail), 25+21 `estatuto_fallback_gap` (never-ingested convenios: a verified fact exists and is unreachable), 8 `low_confidence`.
- Canonical failures (11 %) are composition escalations (`conflict` 8, `low_confidence` 15 agent) — routing is not their cause, so they are the ceiling for the colloquial subset too: ≈ 89 %.

### 1.4 What the gate compares (like with like)

- **Colloquial-unanchored** (the population 13b targets): existing 76 cases + the new held-out bank (§7.1). Spec bar ≥ 75 % applies here; the arithmetic for the *mixed* 93 is reported too (17 anchored at ~86 % already contribute ~14.6 of the 69.75 needed, so the mixed bar is 72.5 % on the unanchored part).
- **Canonical** = anchored canonical (must not regress, §7.3).
- **Colloquial-anchored** = control, must not move.

---

## 2. Planner contract extension

### 2.1 The contract today

Request — `ExtractionClient::plan()` (`hr-backend/app/Services/ExtractionClient.php:383-396`) → hr-ai `PlanRequest` (`hr-ai/app/main.py:275-291`):

```json
{ "question": "…literal…",
  "scope_summary": { "convenio_name", "territory_name", "employment_type", "category_name", "group_label",
                     "start_date_set", "has_salary_table", "prose_gap", "verified_topics": ["…"], "general_lane_enabled" },
  "window": { "…": "…" }, "enabled_tools": ["…"], "prior_steps": [ … ],
  "provider_api_key": "…", "provider_config": { "provider", "model", "endpoint" } }
```

(`scope_summary` keys: `ScopeSummaryBuilder.php:28-47`.) One Anthropic `messages.create` with `tools`, `tool_choice: {type: any}`, `thinking: disabled`, **no** `temperature` (`hr-ai/app/planner/plan.py:138-147`); tools sent in fixed spec order filtered by `enabled_tools` (`plan.py:30-35`, `tools.py:14`).

Response — `plan.py:157-172`, validated by `HrAiPlannerClient::validate()` (`HrAiPlannerClient.php:70-108`):

```json
{ "stop_reason": "tool_use", "calls": [ { "id": "toolu_…", "tool": "reference_fact", "input": {} } ],
  "model", "request_id", "prompt_version": "sha256:…", "tokens": { "prompt", "completion" }, "ms",
  "thinking": false, "tool_choice": "any" }
```

Unknown tool names are dropped in both hr-ai (`plan.py:60-83`) and hr-backend (`HrAiPlannerClient.php:80-96`).

### 2.2 The extension: **no envelope change**

`normalization` is a new **control tool**, `normalize_question`, alongside `escalate` / `finalize` (`ControlTools.php:12-50`, not a `ToolRegistry` entry). It is native tool-use structured output on the *same* `/plan` call — the model emits it as one more `tool_use` block next to its first tool call. Consequences:

- `/plan` request: unchanged shape. Two additive values inside existing fields: `enabled_tools` gains `"normalize_question"` (round 1 only), and `scope_summary` gains `approved_topics: [{id, name, has_verified_fact}]` sorted by name (the closed vocabulary the model must choose from; `verified_topics` stays).
- `/plan` response: unchanged shape; `calls[]` may contain one `normalize_question` call. No new fields, no second request, no extra model call.
- `HrAiPlannerClient::validate()` needs no change beyond the tool name being in the enabled list.

Schema (`hr-ai/app/planner/tools.py`, mirrored in `ControlTools::definitions()`; `additionalProperties:false`; advisory — the backend validates types strictly, §3, because forced tool choice gives no schema enforcement):

```json
{
  "name": "normalize_question",
  "description": "<see §2.3>",
  "input_schema": {
    "type": "object",
    "properties": {
      "topic_id":        { "anyOf": [ { "type": "integer" }, { "type": "null" } ] },
      "canonical_query": { "anyOf": [ { "type": "string", "maxLength": 220 }, { "type": "null" } ] },
      "confidence":      { "type": "number", "minimum": 0, "maximum": 1 },
      "reason":          { "type": "string", "maxLength": 160 }
    },
    "required": ["topic_id", "canonical_query", "confidence", "reason"],
    "additionalProperties": false
  }
}
```

Loop hooks (all inside `loop()`/`processRound()`, `AgentChatService.php:264-393`; nothing before line 264 changes): `$toolDefinitions` (`:269`) omits `normalize_question` once `$state->rounds >= 1` or a normalization is recorded; `processRound()` handles the control call first, then runs it through `ruleEngine->run('pre_call:normalize_question', …)` (§3). Replay reuses `ControlTools::definitions()`, so the recorded allowlist is the one sent (`Sprint13AgentReplayTest` updated).

**Considered and rejected:** (B) required `normalization` property on `reference_fact`/`convenio_search`/`national_law` inputs — couples the field to a tool choice and duplicates it when two tools are called; (C) a separate normalization call — the spec forbids; (D) `strict` tool-use schemas — used nowhere in this repo and unverified on `claude-sonnet-5`. **Switch trigger to (B):** if the dev-bank probe (§8, step 5) shows `absent` (planner did not emit the call) > 5 %.

### 2.3 The text the planner sees

Tool description (Spanish, like the others, `tools.py`):

```text
normalize_question — Declara cómo entiendes la pregunta, SOLO para las herramientas. La persona
nunca lo ve y no cambia lo que se le responde ni qué reglas se aplican. Llámala UNA vez, en la primera
ronda, junto a tu primera herramienta. Si la pregunta trata varios temas, o es un caso personal más
que una consulta de dato, pon topic_id y canonical_query a null.
- topic_id: el id del tema de "approved_topics" (en Alcance) que corresponde claramente, o null.
- canonical_query: la MISMA pregunta como sintagma nominal en vocabulario de convenio o de ley
  (máx. 25 palabras), o null. Solo reformula lo que la persona ya dijo. NO añadas cifras, importes,
  fechas ni años. NO uses: "derecho a", "corresponde", "mínimo", "máximo", "plazo de", "al año", "cada".
  NO nombres grupos, niveles, categorías, convenios, provincias ni territorios.
- confidence: entre 0 y 1. reason: una línea (la verá RR. HH., no la persona).
```

System-prompt addition (appended to `SYSTEM_PROMPT`, `tools.py:141`):

```text
Además de elegir herramientas, en la primera ronda entiende la pregunta: la persona puede hablar en
coloquial. Tradúcela a un tema aprobado y a una reformulación con el vocabulario del convenio. Es solo
para buscar: nunca añadas algo que la persona no haya dicho, y si dudas del tema, topic_id null.
Ejemplos (no exhaustivos):
«Quiero pedirme un año sin trabajar pero conservando mi puesto» → tema «excedencias»; canonical
«excedencia voluntaria: reserva del puesto de trabajo».
«¿Puedo llevar a mi perro a la oficina?» → topic_id null, canonical_query null (fuera del vocabulario).
```

The two examples are frozen in `sprint-13b/eval/prompt-examples.json`; an overlap check (normalized-token Jaccard ≥ 0.8) fails the build if any eval or CP-1 question resembles one — the eval banks must not contain the prompt's own examples.

---

## 3. Validation rule: `NormalizationValidationRule`

`App\Services\Agent\Rules\NormalizationValidationRule implements Rule` (`Rule.php:12-19`), registered at `pre_call:normalize_question` in `AgentServiceProvider` (`AgentServiceProvider.php:68-93`), with the pure logic in `App\Services\Agent\Normalization\NormalizationDiff` so it is unit-testable without a DB. Returns `allow` (valid) or `deny` (any failure); in both cases it records one `normalization` step (§6). A `deny` does **not** go back to the planner and costs no round: the normalize call is simply dropped, the turn continues on the literal path — exactly today's behaviour. Any exception inside the rule is a rejection (`validator_error`, fail-safe).

**What the diff compares against:** the employee's literal question only (v1). A follow-up whose canonical needs context tokens from the window ("¿y en 2025?") is therefore rejected → literal path; ticketed, not solved here.

Checks, in order; **all failures are recorded** (so each check can have a sole-catcher test), the first is the reported reason:

| id | check | reuse |
|---|---|---|
| `shape` | `topic_id` int\|null, `canonical_query` string\|null, `confidence` number ∈ [0,1], `reason` string ≤ 160, no extra keys | — |
| `topic_unknown` / `topic_not_approved` | `topics` row exists **and** `status = 'approved'` **and** is in the `approved_topics` set offered this turn | `ReferenceFactRouter.php:58` idiom |
| `canonical_form` | ≤ 25 words, ≤ 220 chars, one line, no `?`, no control chars/markup/URL, letters/digits/`,;:.()-/«»` only | — |
| `figure_not_in_literal` | every numeric literal (`\d[\d.,]*`) in the canonical also appears in the literal | — |
| `scan:<id>` | **span-based** `GeneralLanePostCheck` patterns F1–F3, D1, A1, E1–E3, X1 on the canonical: a hit counts iff its matched span contains ≥ 1 token **not in the literal** | `GeneralLanePostCheck::PATTERNS` (`:66-96`), `normalize()` (`:164`), citation stripping (`:96-100`); new static `scanAll()` |
| `group_designator` | on the added tokens: `\bgrupos?\b`, `\bniveles?\b`, `\bsub-?area\b`, `\bcategori(a\|as)\b` | `AskEmployeeWhitelist::FORBIDDEN_FIELDS['professional_group']` (`:57`) + `categoria` |
| `territory_or_convenio_name` | on the added tokens: Spanish provinces/CCAA/co-official names (static list) ∪ `territories.name` ∪ `convenios.name` (phrase match) | `AskEmployeeWhitelist::FORBIDDEN_FIELDS['territory']` (`:59`) for the generic words |
| `pay_intent_added` | canonical has pay intent and the literal does not | `SalaryIntentPreCallRule::hasPayIntent()` (`:84`) |
| `canonical_guardrail` | canonical fires the sensitive/legal-medical/other-employee baseline or an admin block while the literal did not (a "me han echado" mapped to `despido`) | `GuardrailService::check()` (`:82`), `GuardrailPolicy::blockedTopicMatch()` (`:80`) |
| `topic_canonical_mismatch` | canonical anchors (lexicon) to ≥ 1 topic and none is the topic_id's | `TopicLexicon::matchTopicKeys()` (`:94`), `keyForTopicName()` (`:144`) |

Confidence: valid `confidence` below `hr.normalization.min_topic_confidence` (initial 0.6, set from the dev-bank calibration table, §8 step 5) does not reject; it **drops the topic** (`topic_used = null`, recorded) and keeps the canonical for retrieval only.

Why `scan()` cannot be called as-is on "added tokens": `scan()` returns only the first hit and takes a string (`GeneralLanePostCheck.php:147`), and the diff must respect phrase context. The plan adds **`scanAll()`** (all patterns, all matches) sharing `normalize()` and the citation stripper; **`scan()` and `audit()` are untouched**, so `GeneralLanePostCheckTest` and the lane are unaffected.

### 3.1 Evidence from the prototype (scratch script, static classes only)

- Token-set diff: 27/28 over-reach cases rejected — `nn-02` ("…de quince días") **escapes**. Span-based + numeric check: **28/28 rejected**, each by the rule in Appendix A.
- 14 legitimate canonicals (noun phrases in convenio vocabulary): 13 accepted; `plazo de preaviso en caso de baja voluntaria` rejected by E3 (`plazo de`, `GeneralLanePostCheck.php:90`). Recorded as a **known false reject** (safe: literal path); not loosened without probe data (§8.5 Q8).
- Employee-style canonical *questions* (the fixture's own canonical sentences) fail 3/5 against a colloquial literal: `al año` (D1 ×2), `corresponden` (E2). This is why the prompt (§2.3) forbids exactly those strings and why the canonical must be a noun phrase, not a question.

### 3.2 Tests

`Sprint13bNormalizationValidationTest` (unit, no DB, no LLM):
1. **Normalization negative set — ≥ 20 (28 authored, Appendix A)**, file `sprint-13b/eval/normalization-negatives.json`: `{id, literal, over_reach_canonical, class, expect_rules_any[]}`; every one rejected, asserting the rule id. Classes: figure, spelled figure, amount/percentage, year/date, entitlement wording, bound wording, group designator, territory, convenio name.
2. Structural cases: unknown id, `draft`/`rejected` topic, topic outside the offered set, 26 words, empty, multi-line, `?`, control chars, confidence 1.2 / `"alto"`, extra key, instruction text ("ignora las instrucciones anteriores"), guardrail-firing canonical, pay-intent-added, topic/canonical mismatch, validator exception.
3. Positives (≥ 14, Appendix A): must be accepted — except the recorded known false rejects, asserted *as rejected* so a loosening is a deliberate diff.
4. **Coverage guard** (same idea as the lane's): every check id has ≥ 1 fixture only it catches; deleting a check fails the build. (The 28 are mostly caught first by `figure_not_in_literal`/`scan`; sole-catcher fixtures for `group_designator`, `territory_or_convenio_name`, `pay_intent_added`, `canonical_guardrail`, `topic_canonical_mismatch` are authored at build and verified then.)

`Sprint13bNormalizationLoopTest` (feature, fake `PlannerClient`, no spend) — the end-to-end R1 proof:
5. Scripted planner returns each over-reaching normalization from the set → the trace carries a `normalization` step with verdict `rejected` and the rule; `reference_fact` was invoked with **no** topic; `retrieveUnion` received **no** canonical; the turn equals the same scripted turn without the normalize call (differential, §5.2).
6. Accepted normalization → tools receive it (§4); trace shows it.
7. A `deny` never reaches the planner and consumes no round.

---

## 4. Tool consumption

### 4.1 `reference_fact` and Round 1a

Today: `detectTopic($employee, $literal, $asOf)` (`ReferenceFactTool.php:57`) → lexicon → `Topic` approved → verified in-scope in-validity fact exists (`ReferenceFactRouter.php:50-77`) → `ReferenceFactPath::handle($detection…)` (`:57`).

Change (additive):
- `ReferenceFactRouter::detectFromTopic(Employee, Topic, Carbon)` — a **new** method: same existence query, taking the topic directly. `detectTopic()` is not edited (golden traces 12–15 stay byte-identical).
- `ReferenceFactTool::run()`: `topic_used` from `$state->normalization` when set → `detectFromTopic`; **else** the unchanged lexicon call. Input schema stays `{}`; the planner never supplies a topic id (Sprint 13 plan §B.3.2 stands — the id comes from a *validated* normalization, not from the tool input). If both the lexicon and the normalization yield a topic and they differ, the normalization wins (spec) and `disagreement` is recorded.

**Round 1a (recommended).** Right after a normalization is accepted with `topic_used` non-null **and** a verified in-scope fact exists for it, the shell runs `reference_fact` itself through the normal `runToolCall()` path (rules, post-call `ReferenceFactPostCallRule` terminal), before processing the planner's own calls. It is the same "binding route, not a suggestion" as Round 0 (ADR-0035 §1), only positioned after the planner call that produced the topic. A `no_fact` (fact vanished / group indeterminate at the tool) continues into the planner's calls unchanged. Precedence stays: pre-model guard > rule verdict at a tool boundary > `planner_escalated` (Sprint 13 plan §B.5) — a planner `escalate` in the same round does not pre-empt a rule-mandated fact answer.
*Why not leave it to the planner:* 83 % first-tool `reference_fact` today × ~89 % composition ceiling ≈ 73 % < the 75 % bar, before any topic-accuracy loss; plus `escalate`-first turns. Fallback if you prefer the planner in charge: a `pre_call:convenio_search` deny "use reference_fact first" while an accepted topic has a verified fact (costs a round on deviation).

`ReferenceFactPath::handle(…, array $trace, ?array $normalization = null)` — default `null` = byte-identical for classic and Round 0. When non-null:
- `router_decision.source = 'planner_normalized_reference_fact'` (not `deterministic_reference_fact`, `:64`), `matched_topic_names = [topic]`, the canonical in `trace_fragment`.
- **Composition parity:** `composeFactWithProse` (`:134`) takes `$questionTopics` from the topic's lexicon key (`keyForTopicName`) instead of `chunkTopics($question)` (`:158`), and calls `retrieveUnion($question, [], …, decomposedQueries: [$canonical])` (`:154`). Result: a normalized colloquial question composes with governing prose exactly like its canonical twin; without this it would degrade to Phase 1 (governing-on-topic empty because a colloquial question has no anchor). `/synthesise` and `/ground` receive the **literal** question only (`$question` unchanged), so "the answer is unchanged" holds.

### 4.2 `convenio_search` / `national_law`: union, never replace (R3)

- `ConvenioSearchTool::run()` (`:84-102`): `$decomposedQueries = [canonical] ⧺ planner decomposed_queries` (canonical is *additional*, prepended, deduped; the existing cap of 3 applies to the planner's own). `NationalLawTool::run()` (`:70`) passes `[canonical]` instead of `[]`. Injection is by the **shell**, not by tool input, so a planner cannot smuggle or omit it and the identical-call cache key (`TurnState::cacheKey`) is unaffected.
- **Literal is pinned** (finding 7): `$question` passed to `ProsePath::handle()` is always `$state->question`; a planner `query`, if any, becomes one more decomposed query. Agent-only; `Sprint13ConvenioSearchWrapperTest` gets a case.
- `ProsePath` / `RetrievalUnion` need no logic change: `retrieveUnion()` already issues one `/retrieve` per entry of `[question, …subqueries, …decomposed]` (`RetrievalUnion.php:99`), merges by chunk id keeping the max score (`:276-285`), runs the national-law pass on the **literal** (`:130-146`), and grows the synthesis cap by 2 per extra query (`:172`).

**Check A on the union.** `topScore = max(score)` over the merged chunks (`ProsePath.php:130-152`, floor `hr.retrieval_score_floor = 0.40`, `config/hr.php:20`).
- Monotone: the literal pass is `$queries[0]` and `mergeChunks` never lowers a score, so `union_top_score ≥ literal_top_score`. Check A can flip **escalate → pass, never pass → escalate**. That flip is the only way normalization lets the prose path answer where it escalated (finding 6); the trace records it:
  `literal_top_score` (= `retrieval.passes[0].top_score`, already recorded, `ProsePath.php:137-140`), `canonical_top_score`, `union_top_score`, `check_a_rescued = literal < floor ≤ union`.
- Worked example (illustrative numbers): literal 0.31, canonical 0.58 → union 0.58 ≥ 0.40 → Check A passes, synthesis runs on the **literal** question over chunks that include the canonical pass's; Check B, figure-guard and `/ground` (entailment against the cited chunks, on the literal) are unchanged and remain the safety net.
- **Literal hits preserved — strictly.** The union alone keeps every literal chunk in the *pool* but the truncation (`cap = 10 + 2·n`, `:172`) could drop a lower-ranked literal chunk if ≥ 3 canonical-only chunks outrank it. To make it a guarantee, `retrieveUnion(…, bool $protectMain = false)` (additive; classic never passes true): after the cap, any of the literal pass's top-10 (by literal score) missing from the slice replaces the lowest-ranked non-protected member. Test: the literal-only top-10 chunk ids ⊆ the final set, with a scripted `/retrieve` where the canonical pass returns 12 higher-scoring chunks.
- `never_ingested` employees: `$fallback` makes every scoped pass national-law-only (`RetrievalUnion.php:95`); canonical adds national-law passes — no convenio side to bypass. `expired_only` still escalates before retrieval (`ProsePath.php:100-114`, R15). The national-law-on-covered rewrite (`NationalLawPrecedenceRule`) is unchanged.
- Precedence re-rank (`RetrievalUnion.php:163`) runs on the full union: a canonical-pass `national_law` chunk still cannot outrank a same-topic governing convenio chunk.

### 4.3 What does not change

`salary_lookup` (no normalization input; pay-intent canonical is rejected by `pay_intent_added`), `general_knowledge` (receives only the scrubbed literal, Sprint 13 plan §B.6.2 — no new data flow), `ask_employee`, the composition/synthesis/grounding gates, the salary rule, the lane. hr-backend already sends the literal question to `/plan` and `/retrieve`; the canonical goes to the same two internal endpoints — no new processor.

---

## 5. Round 0 unchanged, and the null-topic fall-through (R2)

### 5.1 The fast path still settles first

Order in `AgentChatService::handle()` — untouched by this slice: pre-model guards (`:80-88`) → `turn_start` rules (`:104`) → `runRoundZero()` (`:219-241`, lexicon `detectTopic`) → settle without a planner call when `$seeded !== null && ! $isCompound && (! $isFollowUp || salary)` (`:125`) → only then `loop()` (`:264`). Normalization code lives strictly inside `loop()`/`processRound()`/the tools; there is no normalization hook before line 264.

- Measured: 257/257 anchored agent rows in Sprint 13 settled at Round 0 (§1.3). By construction the 93 canonical + 17 anchored-colloquial phrasings are anchored (offline lexicon run: 93/93 and 17/17), so none can reach the planner while its fact exists.
- Normalization runs only for what Round 0 missed: unanchored questions (76 existing cases), anchored-but-fact-less questions, compound questions and reference-fact follow-ups.
- Tests: the existing `Sprint13RoundZeroSettlesTest` (`:132-214`, planner "exploding" stub) stays green unmodified; new `Sprint13bRoundZeroUnchangedTest` asserts, for an anchored fixture set, zero `/plan` calls, exactly one `round0` step and **no `normalization` step**; the review criterion for the diff is "no hunk in `AgentChatService.php:58-241`".

### 5.2 Null topic ⇒ exactly today's path

When `topic_id` is null, dropped (low confidence), or the normalization is rejected/absent:
- `reference_fact` (if the planner calls it) → `topic_used` null → the unchanged lexicon call → `no_fact`, as today.
- Round 1a does not run.
- `convenio_search`: literal path; if a *valid canonical* exists with a null topic, it is added to the retrieval union (§4.2) — the only difference from today, and additive.

**Differential test** (`Sprint13bFallThroughIsTodayTest`): run the same scripted planner transcript twice — once with a rejected / null-topic / absent normalization, once as the Sprint 13 build would have run it (no normalize call) — and assert the persisted `trace`, outcome and answer are equal after removing `agent.normalization` and the `normalization` step. For the valid-canonical-null-topic case the assertion is "equal except `retrieval.passes` gains one `decomposed_query` entry".

---

## 6. Trace and UI

### 6.1 Trace shape (agent turns only; classic traces unchanged — no key added unconditionally, the `stampFallback` lesson)

One `normalization` step in `trace.agent.steps[]` (what `TracePanel` iterates) and one block `trace.agent.normalization` (what an auditor reads):

```json
{ "type": "normalization", "verdict": "accepted|rejected|absent",
  "topic": "permisos retribuidos", "confidence": 0.86, "rejected_by": "figure_not_in_literal" }

"normalization": {
  "requested": true,
  "literal": "…employee's words, verbatim…",
  "proposed": { "topic_id": 12, "topic_name": "…", "canonical_query": "…", "confidence": 0.86, "reason": "…" },
  "verdict": "accepted",
  "rejections": [ { "rule": "scan:F2", "span": "quince dias" } ],
  "used": { "topic_id": 12, "canonical_query": "…" },
  "consumers": [
    { "tool": "reference_fact", "via": "round_1a", "topic_id": 12, "lexicon_topic_id": null, "outcome": "answer" },
    { "tool": "convenio_search", "literal_top_score": 0.31, "canonical_top_score": 0.58,
      "union_top_score": 0.58, "check_a_rescued": true }
  ],
  "validator_version": "…", "planner_prompt_version": "sha256:…" }
```

`requested:false` on turns that never reached the planner is simply not written (no block). Size ≈ 0.4–0.7 KB on planner turns; nothing on Round-0-settled turns. Only rule ids and the employee-derived strings already in the turn are stored; the trace never leaves the admin surfaces (`ChatController.php:66-67` strips it from the employee response).

`AnswerGate` counts `deny` as a routing correction (`AnswerGate.php:487-499`); the `normalization_validation` rule id is excluded from `rule_overrides` and reported on its own, so the routing-quality metric is not polluted by rejections.

### 6.2 Rendering — trace panel, Historial, drawer, quality queue

`TracePanel` builds one flat list and renders `trace.agent` steps generically (`TracePanel.tsx:160-187`, `agentTrace.ts:9-62`); Historial (`HistoryPage.tsx:319`), the card drawer (`EscalationCardDrawer.tsx:372`) and the quality queue (`QualitySampleQueue.tsx:320`) all reuse it — one change, four surfaces.

- `agentTrace.ts`: a `normalization` case → label «Normalización de la pregunta», meta `tema · confianza · veredicto (regla)`, and the existing nested-`<details>` list (`TracePanel.tsx:200-208`) for the two forms: `Literal: «…»` / `Canónica: «…»`, plus `Ronda 1a` when the fact route ran through it and a line when `check_a_rescued`.
- `api.ts:715-722`: `agent.normalization?: {…}` added (optional; classic absent).
- **i18n, both dictionaries** (`es.ts` is the `Dict` source, `en.ts` typed against it, so `tsc` enforces parity), in the `tracePanel` block next to the agent keys (`es.ts:1609-1629`, `en.ts:1426-1445`): `agentNormalizationLabel`, `agentNormalizationLiteralPrefix`, `agentNormalizationCanonicalPrefix`, `agentNormalizationTopicPrefix`, `agentNormalizationNoTopic`, `agentNormalizationConfidencePrefix`, `agentNormalizationVerdictAccepted|Rejected|Absent`, `agentNormalizationRejectedBy`, `agentNormalizationTopicDropped`, `agentNormalizationShowFormsSummary`, `agentRound1aLabel`, `agentNormalizationRescuedCheckA`, and a `normalizationRules` map (one human label per rule id in §3). No new escalation reason ⇒ no enum migration, no `statusLabels` change.
- Tests: `agentTrace.test.ts` cases (accepted with two consumers, rejected with rule, absent, classic unchanged); `noHardcodedStrings.test.ts` (`«»` is already allow-listed for TracePanel, `:480`).

---

## 7. Gate plan

### 7.1 New fixtures (all frozen and hashed before any prompt iteration)

| file | content | who |
|---|---|---|
| `fact-routing-colloquial.json` | **held-out bank**: 5 topics × 8 = **40 colloquial phrasings**, each with one scope from the existing 93 (round-robin), `authored=colloquial`, `anchored` computed, `expected topic`, `expect` as in the existing set. ~10 % are colloquial *situational* ("topic fits but it is not a lookup") to test the wrong-relevance risk. | drafted for Pedram's review, then frozen |
| `fact-routing-colloquial-dev.json` | **dev bank**: 4 × 5 = 20 phrasings — the *only* bank prompt iteration may look at | same |
| `normalization-negatives.json` | the ≥ 20 (28) over-reach cases, Appendix A | this plan |
| `prompt-examples.json` | the 2 few-shot examples; overlap-checked against every bank | — |
| CP-1 ten | a third pool (§7.4), never used in tuning | — |

### 7.2 What `answer:gate` reports

Additive to `AnswerGate` (`normalizeCase` `:144-193`, `summarize` `:603-660`, `report` `:691-731`); classic rows are unaffected:
- Case rows gain `phrasing` (`canonical|colloquial|null`, from the id suffix), `anchored` (from `TopicLexicon` vs the case's expected topic), and a fixture header `lexicon_sha`.
- New summary sections: **`by_phrasing`** and the **2×2 `by_phrasing_anchor`** (n, pass, hard, answers) — the §1.3 table, regenerated for the run, per engine.
- Per-row `norm.*` (agent): `verdict` (accepted/rejected/absent/not_requested), `topic_used`, `topic_expected`, `topic_correct`, `rejected_by`, `round1a`, `check_a_rescued`, `disagreement`. Aggregates: acceptance rate, rejection-reason histogram, topic accuracy of accepted, `absent` rate, count of rescues, planner-round cost delta.
- A **rejection review list** (every rejected canonical on the held-out and dev banks, printed with the literal) for hand review of false rejects.

### 7.3 Acceptance, restated concretely (proposed thresholds marked ▲ for your approval)

| # | Criterion | Measured on |
|---|---|---|
| G1 | colloquial-**unanchored** ≥ 75 %: (a) held-out bank ×3, (b) existing 76 cases ×3 ▲ (both must clear; whole-colloquial 93 also reported) | agent |
| G2 | canonical (anchored) ≥ baseline − 3 pts ▲ (89 % → ≥ 86 %), and no case that passed 3/3 at baseline fails 3/3; anchored-colloquial control likewise | agent ×3 |
| G3 | all negative sets, 0 hard violations; must-escalate cases answered = 0 (whitelist hard, Estatuto negative, second-negative — as at CP-2); lane negatives 0 lane answers / 0 forbidden asks; whitelist 48/48 | agent ×3 |
| G4 | normalization negatives: scripted validator 28/28 rejected with the trace; live planner-only probe → **0 accepted canonicals that fail the independent broader audit** (a `GeneralLanePostCheck::audit()`-style pass, built from the same vocabulary); live full loop 0 hard | H1–H4 (§3.2) |
| G5 | gold-2c, situational: no per-case regression vs the CP-2 rows | agent ×3 |
| G6 | golden traces 22/22 byte-identical, full suites green, classic files unchanged apart from §8.2's additive list | CI |
| G7 | cost per answer and planner-round delta reported | gate rows |

The spec bar for canonical says "does not regress from its current rate"; a literal ≥ 89 % would fail on model variance alone (the canonical failures are `conflict`/`low_confidence` composition escalations, and the same code path, Round 0, runs before and after) — hence the ▲ tolerance (≈ 1.5 σ at n = 279).

### 7.4 Run list and estimated spend (agent only; classic not re-run)

Basis: mean list-price cost per row from the Sprint 13 rows (`AnswerGate.php:541-580`, claude-sonnet-5 $3/$15 per MTok — the same "gate-row estimate" the CP-2 review used; not a bill).

| # | Set | Cases × repeats | Turns | $/turn (from rows) | Est. |
|---|---|---|---|---|---|
| 1 | fact-routing canonical | 93 × 3 | 279 | 0.032 (Round-0 settled) | $9.0 |
| 2a | fact-routing colloquial-unanchored (existing) | 76 × 3 | 228 | 0.060 | $13.7 |
| 2b | fact-routing colloquial-anchored (control) | 17 × 3 | 51 | 0.046 | $2.3 |
| 3 | held-out colloquial bank | 40 × 3 | 120 | 0.060 | $7.2 |
| 4 | lane negatives | 75 × 3 | 225 | 0.062 | $14.0 |
| 5 | whitelist-temptation | 16 × 3 | 48 | 0.023 | $1.1 |
| 6 | Estatuto negative + second-negative | 30 × 3 | 90 | ≈ 0.05 (raw CP-2 rows were lost; review est. $3–5/78 turns) | $4.5 |
| 7 | normalization negatives (live full loop) | 28 × 3 | 84 | 0.060 | $5.0 |
| 8 | gold-2c | 4 × 3 | 12 | 0.058 | $0.7 |
| 9 | situational | 12 × 3 | 36 | 0.064 | $2.3 |
| | **Gate subtotal** | | **1 173** | | **≈ $60** |
| pre | planner-only probes (`/plan` round 1 only, no tools, no synthesis): 88 literals (dev 20 + held-out 40 + negatives 28) × ~3 passes at ≈ $0.03 | | ≈ 264 calls | | ≈ $8 |
| pre | dev-bank full-loop iterations (20 × ×1 × 2) | | 40 | 0.060 | ≈ $2.4 |
| pre | CP-1 ten, persisted, ×1 | | 10 | 0.060 | ≈ $0.6 |
| | **Total** | | | | **≈ $71** |
| | with 20 % contingency (a re-run of one failing set) | | | | **≈ $85** |

Proposed hard cap **$100** with a projected-spend guard in the ops script (Sprint 13's `cp2-rerun.sh` precedent). Sequential, one process at a time (hr-ai serialises; the parallel-run lesson), `--stream` to a file **and the streams copied out of the container before any redeploy** (the CP-2 data-loss lesson). ≈ 1 173 turns × ~12 s p50 ≈ 4 h wall clock. Classic is not run. Row 2a is 3 sentences × 76 scopes; a stratified subset (1 case per topic × fact class ≈ 15 cases) would cost ≈ $2.7 instead of $13.7 with the same information — default is the full run as instructed; say if you want the subset.

Expected cost effect: unanchored colloquial turns move from planner + no_fact + prose synthesis (mean $0.060) to planner + fact composition (≈ $0.03 + planner ≈ $0.06) — roughly flat; the +$0.003–0.004 planner increment is the only certain delta.

### 7.5 The ten live colloquial questions for CP-1 (a third pool; none in the dev/held-out banks or the prompt)

Employees are resolved at build from `facts-export.php` so each question has a verified in-scope fact where one is expected; the mapping is recorded in `cp1-colloquial.json`. Each row states what an HR person would write, so the read is "is the canonical what HR would have said".

| # | Question (employee's words) | Expected topic | HR-natural canonical looks like | What it probes |
|---|---|---|---|---|
| 1 | «Entré este mes, ¿cuánto tiempo estoy en fase de prueba?» | periodo de prueba | duración del periodo de prueba | plain lookup |
| 2 | «¿Cuál es el tope de horas que puedo trabajar en un año?» | jornada | jornada máxima anual en cómputo anual | `al año` avoided |
| 3 | «Me caso en octubre, ¿me dan días libres?» | permisos retribuidos | permiso retribuido por matrimonio | **bait**: must not become "15 días"/"tienes derecho" |
| 4 | «Si se muere un familiar cercano, ¿me dejan faltar al trabajo?» | permisos retribuidos | permiso retribuido por fallecimiento de familiares | colloquial euphemism |
| 5 | «Mi pareja va a dar a luz, ¿cuántos días libres me tocan?» | permisos retribuidos | permiso por nacimiento y cuidado del menor | `me tocan` |
| 6 | «¿Cuántos días puedo cogerme para irme de viaje en verano?» | vacaciones | duración de las vacaciones anuales | topic with no anchor word |
| 7 | «¿Qué días del año son fiesta y no se trabaja?» | festivos | calendario de días festivos | 2-fact topic |
| 8 | «Me mudo de casa la semana que viene, ¿tengo algún día libre por eso?» | permisos retribuidos | permiso por traslado de domicilio | **bait**: must not add a figure |
| 9 | «Mi jefa me ha dicho que en agosto no puedo cogerme días, ¿puede hacer eso?» | vacaciones **or null** | null, or vacaciones without a group/figure | **situational**: must not become a bare fact quote |
| 10 | «¿La empresa me da seguro médico?» | null | null | null-topic: identical to today's path |

Procedure: gate results first, then these ten on staging with `--persist` on test accounts, agent engine; Pedram reads each turn's normalization step in Historial (literal, canonical, topic, confidence, verdict, consumers). Suggested pass: ≥ 8/10 judged "what HR would say", **0** accepted canonicals that add a figure/group/entitlement, #10 identical to the pre-13b path.

---

## 8. Build order, tests, open questions, CP-1

### 8.1 Ordered build steps

| # | Step | Proof |
|---|---|---|
| 0 | **Baseline + labels (no spend).** Commit the probes that produced §1 (`baseline-split.php`); add `phrasing`/`anchored`/`lexicon_sha` to the fixture loader; author and **freeze (sha256)** the dev bank, held-out bank, negatives, prompt examples; Pedram reviews the banks before any prompt work. | §1.3 table regenerates; overlap check green |
| 1 | **Pure units:** `NormalizationDiff`, `GeneralLanePostCheck::scanAll()`, `NormalizationValidationRule`. | §3.2 tests 1–4, coverage guard; `GeneralLanePostCheckTest` unchanged |
| 2 | **Additive shared-class parameters** (§8.2). | Golden traces 22/22 byte-identical; full suite green |
| 3 | **Agent wiring:** `TurnState::$normalization`, control-tool definition, `loop()` round-1 tool filter + inline handling + Round 1a, `ReferenceFactTool` / `ConvenioSearchTool` (literal pin + union) / `NationalLawTool`, `ScopeSummaryBuilder` `approved_topics`. | §3.2 tests 5–7, `Sprint13bRoundZeroUnchangedTest`, `Sprint13bFallThroughIsTodayTest`, `protectMain` test, updated wrapper/replay tests |
| 4 | **hr-ai:** `normalize_question` in `tools.py`, system-prompt addition, `planner_contract_test.py` extended (tool allowlisted, malformed input passes through untouched, unknown tool still dropped); `normalization:probe` command (round 1 of `/plan` only, runs the validator, no tools, rolls back). | contract test; probe runs offline against a fake |
| 5 | **Prompt iteration on the dev bank only** via the probe (~$8): report `absent` rate (≤ 5 %), topic accuracy of accepted (≥ 90 %), accepted-with-audit-violation (0), false-reject on legitimate canonicals (≤ 15 %), and the **confidence calibration table** that sets `min_topic_confidence`. **Freeze `planner_prompt_version`**, then run the held-out probe once. | numbers in `review.md`; prompt frozen before held-out |
| 6 | **Trace + UI + i18n** (§6). | vitest, `tsc`, i18n tests |
| 7 | **Gate tooling** (§7.2). | `Sprint13AnswerGateTest` extended, scripted AI |
| 8 | **Deploy to staging behind the existing engine switch** — only with your go-ahead; classic stays the default; copy streams out before any redeploy. | smoke: classic unchanged |
| 9 | **Run the gate** (§7.4), sequential, guarded. | results table side by side with CP-2 |
| — | **⏸ CP-1** (§8.4). | |
| 10 | **Docs after CP-1:** ADR-0036 + an addendum to ADR-0035 §2 (§8.5 Q4), `architecture.md`, `data-model.md` (`trace.agent.normalization`), `roadmap.md` §7 (status of the lexicon ticket), `review.md`. **STOP — no commit/merge until reviewed.** | |

### 8.2 Every touch of a class classic also uses (all additive; defaults preserve today's behaviour)

| class | change | classic effect |
|---|---|---|
| `ReferenceFactRouter` | new method `detectFromTopic()`; `detectTopic()` not edited | none |
| `ReferenceFactPath::handle` | optional trailing `?array $normalization = null` | none when null (Round 0 and classic pass nothing) — golden 12–15 pin it |
| `RetrievalUnion::retrieveUnion` | optional trailing `bool $protectMain = false` | none when false |
| `GeneralLanePostCheck` | new static `scanAll()`; `scan()`/`audit()` untouched | none |
| `ChatService`, `RouterService`, `ProsePath`, `SalaryPath`, `TurnPersister` | **not modified** | — |

Agent-only files: `AgentChatService` (`loop`/`processRound` only), `TurnState`, `ControlTools`, `ScopeSummaryBuilder`, three tools, one rule + provider registration, hr-ai `planner/`.

### 8.3 Test inventory

Backend: `Sprint13bNormalizationValidationTest` (unit, ≥ 28 negatives + ≥ 14 positives + structural + coverage guard), `Sprint13bNormalizationLoopTest` (scripted planner, rejection/acceptance/no-round-cost), `Sprint13bRoundZeroUnchangedTest`, `Sprint13bFallThroughIsTodayTest` (differential), `Sprint13bRetrievalUnionTest` (literal top-10 ⊆ final; Check A monotone; `check_a_rescued`), `Sprint13bReferenceFactNormalizedTest` (normalized colloquial composes exactly like its canonical twin; `source` value; classic path byte-identical), extended `Sprint13ConvenioSearchWrapperTest` (literal pin), `Sprint13AgentReplayTest`, `Sprint13AnswerGateTest`. Existing: `Sprint13GoldenTraceTest` 22/22, `Sprint13RoundZeroSettlesTest`, `Sprint13RuleEngineInvariantTest`, full suite. hr-ai: `scripts/planner_contract_test.py`. Frontend: `agentTrace.test.ts`, `noHardcodedStrings.test.ts`, `tsc`.

### 8.4 CP-1 (single)

Gate results (§7.3 table, side by side with CP-2 rows, by-phrasing 2×2, normalization metrics, every rescue and every rejection listed) **plus** the ten questions of §7.5 run live on staging with traces persisted. You read the normalization step for each and judge whether the canonical form is what an HR person would have said. Nothing merges before that read.

### 8.5 Open questions (each with a recommendation)

1. **Gate definition (§7.3 G1).** Gate ≥ 75 % on colloquial-**unanchored** (held-out + existing), reporting the mixed 93 as well? *Recommend yes* — the mixed figure hides a 0 % behind 17 easy cases and the existing set is three sentences.
2. **Held-out bank authorship.** I draft 40 + 20 phrasings for your review before any prompt work; the CP-1 ten are a separate pool. *Recommend yes;* you may prefer to write some yourself — they are worth more than mine.
3. **Round 1a (shell-run fact route) vs planner-run.** *Recommend Round 1a* (§4.1). It is beyond the spec's literal text; the deny-rule variant is the fallback.
4. **ADR-0035 §2 wording.** Normalization can answer where classic escalated (fact via normalized topic; Check A cleared by the canonical pass). *Recommend* ADR-0036 plus an addendum sentence to ADR-0035 §2 stating the invariant now holds "except through a validated normalization, every downstream gate unchanged, each rescue traced and counted".
5. **A canonical that trips the guardrail baseline** (the literal did not: "me han echado" → despido). Spec says reject → literal path. *Recommend reject in 13b* and record how often it happens; escalating `sensitive_topic` instead is additive and safe but is a separate product decision.
6. **Pin `convenio_search`'s `query` to the literal** (finding 7). Agent-only behaviour tweak that R3 already implies. *Recommend yes.*
7. **Canonical non-regression tolerance** (−3 pts). *Recommend* as in §7.3.
8. **Known false rejects** (`plazo de`, `al año`, `corresponde` — legitimate canonicals the shared patterns block). *Recommend accept* (a false reject falls back to today's path, the safe direction) and revisit only with dev-bank probe data; nothing is loosened without a review.
9. **Classic diverges.** After 13b the agent handles unanchored colloquial and classic (byte-identical by contract) does not; the roadmap §7 lexicon ticket (`roadmap.md:310`) is a classic-side slice and is not superseded. *Recommend keep the ticket, keep classic the default* until the pilot decision.
10. **Follow-up questions.** v1 diffs against the literal only, so a follow-up whose canonical needs earlier-turn context is rejected → literal path. *Recommend accept;* ticket the window-aware diff.

---

## Appendix A — Normalization negative set (28) and legitimate positives (14), verified offline

Verified against a scratch implementation of §3 (static classes only): **28/28 rejected** (span-based); a token-set diff would have accepted `nn-02`. "Rule" = the first check that fires. Literals are colloquial; the canonical is what an over-reaching planner might emit.

| id | literal (employee) | over-reaching canonical | rule |
|---|---|---|---|
| nn-01 | ¿cuántos días me dan si me caso? | permiso por matrimonio: 15 días naturales | figure_not_in_literal (15) |
| nn-02 | ¿cuántos días me dan si me caso? | permiso retribuido por matrimonio de quince días | scan:F2 (quince días) |
| nn-03 | ¿cuántos días me dan si me caso? | tienes derecho a permiso por matrimonio | scan:E1 |
| nn-04 | ¿cuántos días me dan si me caso? | permiso por matrimonio en el convenio de Navarra | territory_or_convenio_name |
| nn-05 | acabo de empezar, ¿cuánto me pueden tener a prueba? | periodo de prueba grupo profesional 3 | figure_not_in_literal (also group_designator) |
| nn-06 | acabo de empezar, ¿cuánto me pueden tener a prueba? | duración máxima del periodo de prueba de seis meses | scan:F2 (seis meses) |
| nn-07 | acabo de empezar, ¿cuánto me pueden tener a prueba? | periodo de prueba técnicos titulados grupo 1 | figure_not_in_literal (also group_designator) |
| nn-08 | ¿cuántas horas al año me toca trabajar? | jornada anual de 1.800 horas | figure_not_in_literal |
| nn-09 | ¿cuántas horas al año me toca trabajar? | jornada máxima anual en Bizkaia | territory_or_convenio_name |
| nn-10 | ¿cuántas horas al año me toca trabajar? | jornada anual 2026 en cómputo anual | figure_not_in_literal (year) |
| nn-11 | ¿qué días libres me dan por asuntos familiares? | permisos retribuidos: te corresponden tres días por fallecimiento | scan:F2 (also E1) |
| nn-12 | ¿qué días libres me dan por asuntos familiares? | permiso por fallecimiento de familiar hasta segundo grado, mínimo dos días | scan:F2 (also E3) |
| nn-13 | ¿cuántas vacaciones tengo? | vacaciones anuales: 30 días naturales | figure_not_in_literal |
| nn-14 | ¿cuántas vacaciones tengo? | vacaciones anuales según Estatuto: treinta días | scan:F2 |
| nn-15 | ¿cuántas vacaciones tengo? | vacaciones anuales del grupo profesional 2 en Madrid | figure_not_in_literal (also group, territory) |
| nn-16 | me despiden, ¿me dan algo? | indemnización de 33 días por año trabajado | figure_not_in_literal |
| nn-17 | ¿me pagan más si trabajo de noche? | plus de nocturnidad del 25 % | figure_not_in_literal |
| nn-18 | ¿me pagan más si trabajo de noche? | complemento de nocturnidad 4,5 euros por hora | figure_not_in_literal |
| nn-19 | ¿cuánto tiempo me dan para avisar si me voy? | preaviso de quince días | scan:F2 |
| nn-20 | ¿cuánto tiempo me dan para avisar si me voy? | plazo de preaviso mínimo | scan:E3 |
| nn-21 | ¿puedo pedir días libres por la mudanza? | permiso por traslado de domicilio: 1 día | figure_not_in_literal |
| nn-22 | ¿puedo pedir días libres por la mudanza? | tienes derecho a un día de permiso por traslado de domicilio | scan:D1 (also E1) |
| nn-23 | ¿tengo derecho a festivos? | festivos 2026: 14 festivos nacionales | figure_not_in_literal |
| nn-24 | ¿y cuántos me tocan de festivos? | festivos en Gipuzkoa | territory_or_convenio_name |
| nn-25 | ¿cuánto me dan por tener un hijo? | permiso de nacimiento: 16 semanas | figure_not_in_literal |
| nn-26 | ¿cuánto me dan por tener un hijo? | permiso por nacimiento y cuidado del menor para el grupo 4 | figure_not_in_literal (also group) |
| nn-27 | ¿me tienen que dar descanso? | descanso semanal de día y medio ininterrumpido | scan:F3 |
| nn-28 | ¿me tienen que dar descanso? | descanso diario de doce horas entre jornadas | scan:F2 |

Legitimate canonicals (must be accepted): «permiso retribuido por matrimonio o pareja de hecho: duración», «duración del permiso retribuido por matrimonio», «duración del período de prueba», «duración máxima del periodo de prueba», «jornada máxima anual en cómputo anual», «jornada anual de trabajo efectivo», «permisos retribuidos por fallecimiento, enfermedad grave u hospitalización de familiares», «duración de las vacaciones anuales», «permiso por nacimiento y cuidado del menor», «festivos al año» (literal already says «festivos»/«año»), «calendario de días festivos», «complemento de nocturnidad», «trabajo a distancia y teletrabajo: condiciones» — 13/14 accepted; **«plazo de preaviso en caso de baja voluntaria» is rejected (E3 `plazo de`) — the known false reject of §8.5 Q8.**
