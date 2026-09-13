# Sprint 10b — Plan (plan gate)

> Status: **PLAN — awaiting review.** No application code, migrations, or tests written. Nothing committed.
> Spec: `hr-docs/sprints/sprint-10b/sprint-10b-spec.md`. Depends on ADR-0011, 0015/0016, 0023, 0029.
> Everything below was checked against the **real code on the working tree** (`/Users/pedram/Desktop/PROJECT/JV/HR-AI`, all four repos) and the **live staging DB** (`hr-staging-db`, read-only queries run through the running `hr-backend` container on `52.211.251.235`, 2026-09-12). Every file reference is `path:line` against the working tree. Staging state at inspection: 5 containers healthy, 192 escalation cards, 16 employees, 284 user chat messages (39 distinct question strings), 50 question clusters.

---

## 0. Verdict up front

**R4 is not a caveat here — it is close to the whole finding for §A.** I pulled every distinct real/test question that has ever been asked on staging (39 strings, §A.1). **Not one of them uses a colloquial or situational phrasing.** No "paga extra", "finiquito", "nómina", "asuntos propios", "plus de transporte" — zero hits searching all 284 messages, case-insensitive, for any of those terms or their obvious variants. No "mi jefe me ha denegado…"-style situational framing either — the real corpus is already close to convenio register ("¿Cuántos días de vacaciones me corresponden?", "¿Cuánto dura el periodo de prueba?"). The spec's own illustrative examples (§1: "paga extra", "mi jefe me ha denegado las vacaciones") are **not drawn from real traffic** — they are Pedram's illustrations of the *shape* of the problem, not observed instances of it. I say this plainly rather than launder it into a table that implies real motivation where there is none.

The one exception, and it is a clean one: **`explicit_request` has exactly one real motivating case, and it is unambiguous.** Card 9 (`escalation_cards.id = 9`, 2026-09-10 04:33:34): *"Quiero hablar con una persona de Recursos Humanos, por favor."* — currently misrouted to `off_domain`. This is the one piece of §A with real staging evidence behind it, and every piece of HR-facing infrastructure for the fix already exists and needs zero change (§C.4).

Given that, my read of the three sub-features' evidentiary weight is uneven, and it changes the recommended build order from what the spec's illustrative framing alone would suggest:

1. **`explicit_request` (§2.3)** — real motivating case, zero-risk (one new deterministic pre-check, everything downstream already wired), zero migration. Build first.
2. **Colloquial lexicon (§2.2)** — real salary traffic exists (5 distinct real salary questions, §A.2) but it is already correctly routed by the *existing* lexicon; no real question in the corpus is currently mis-routed for want of a colloquial term. The additions are anticipatory, not corrective. Still worth building (additive, cheap, low-risk) — but the mapping table is genuinely a **hypothesis about future traffic**, not a fix for observed traffic, and I have marked it as such term-by-term.
3. **Situational decomposition (§2.1)** — the riskiest item on the spec's own admission, and now also the one with the least real evidence: zero situational phrasings exist to decompose. I still spec it below (the mechanism is sound and additive-by-construction), but the eval will be substantially constructed, and I recommend it stay last, exactly as spec §10 already directs.

One more finding that changes a design decision in §C: **`TopicLexicon::ANCHORS['despido']` already contains `'finiquito'`** (`hr-backend/app/Support/TopicLexicon.php:41`) — and separately, `GuardrailService::SENSITIVE_PATTERNS`' disciplinary bucket **already matches `finiquito`** and escalates it `sensitive_topic` (`hr-backend/app/Services/GuardrailService.php:36`), which runs *before* any pre-classifier ever sees the question (`ChatService.php:185`). So "finiquito" is not an undecided case needing a destination — it already has one, today, and it's a defensible one (a settlement figure is frequently entangled with a termination circumstance). Sprint 10b is out-of-scope for guardrail changes (spec §3), so my proposed mapping for finiquito is **destination = none, no change** — flagged per spec's instruction as the debatable case, with a recommendation attached rather than left open.

---

## A. The real data (staging, mined 2026-09-12)

### A.1 Every distinct question ever asked on staging

284 total `user`-role `chat_messages`, 16 employees (seeded test accounts), **39 distinct question strings** (`SELECT DISTINCT content FROM chat_messages WHERE role='user'`). Full list, verbatim:

```
¿cómo funcionan los contratos fijos discontinuos?          ¿Cuál es el SMI este año?
¿Cuál es la capital de Mongolia?                            ¿Cuál es la jornada máxima anual?
¿cuál es mi periodo de prueba?                               ¿Cuál es mi salario base mensual y mi salario anual según las tablas salariales vigentes?
¿Cuántas horas extraordinarias puedo hacer al año?           ¿Cuánto cobro de salario base?
¿Cuánto cobro este mes según mi tabla salarial?               ¿Cuánto descanso semanal me corresponde?
¿cuánto dura el periodo de prueba?                            ¿Cuánto dura el periodo de prueba en mi convenio?
¿Cuánto dura el permiso por nacimiento?                       ¿cuánto gana mi categoría este año?
¿Cuánto gano?                                                  ¿Cuánto preaviso tengo que dar si me voy?
¿Cuánto puede durar mi periodo de prueba?                     ¿Cuántos días de permiso por matrimonio? (×2, one without leading ¿)
¿Cuántos días de permiso tengo por matrimonio según mi convenio?
¿Cuántos días de vacaciones me corresponden? / …al año? (×4 casing/¿ variants)
¿cuántos días de vacaciones tengo?                            ¿Cuántos días festivos al año?
¿Cuántos días libres tengo en total al año sumando todos mis permisos y vacaciones?
¿Cuánto tiempo tienen las faltas leves, graves y muy graves para prescribir?
Estoy sufriendo acoso por parte de un compañero, quiero denunciarlo, ¿cómo lo hago?
¿Me pueden despedir durante el periodo de prueba?             pregunta de prueba fence 7d
¿Pueden sustituirme las vacaciones por dinero?                ¿puedo hacer funciones de dos grupos profesionales?
¿puedo trabajar a distancia?                                  ¿Qué permiso tengo por fallecimiento de un familiar?
¿Qué preaviso me deben dar en un despido objetivo?            ¿qué vacaciones tengo?
Quiero denunciar un caso de acoso laboral por parte de mi jefe, ¿qué debo hacer?
Quiero hablar con una persona de Recursos Humanos, por favor.
```

**Table 1 — Situational phrasings actually observed.** *Per R4: none.* I searched all 284 messages (not just the 39 distinct) case-insensitively for the situational framings the spec illustrates ("mi jefe me ha denegado", "denegad-") and for the general shape (a grievance-style narrative preceding a legal question): zero matches beyond the two harassment/*acoso* sentences, which are narrative because the topic is sensitive (and already correctly escalate `sensitive_topic` — they are not a decomposition target). The 39 real questions are, without exception, already close to canonical register — direct interrogatives naming the topic ("¿cuánto dura el periodo de prueba?", "¿qué vacaciones tengo?"). **I am not proposing a situational-phrasing table with rows, because there is nothing to fill it with.** The decomposition eval (§E.9) will therefore be built from constructed situational variants of existing gold questions, exactly as spec §2.1 already anticipates ("stated honestly in the review as constructed where real traffic is thin") — except here it isn't thin, it's absent.

**Table 2 — Colloquial term candidates.** *Per R4: every row below is constructed (no real question motivated it), except the salary row, which is motivated by real traffic that already routes correctly.* Pedram approves the full mapping before build.

| Term | Real question that motivated it | Proposed destination | Note |
|---|---|---|---|
| "paga extra" | *none found* — 0/284 messages | salary | Already covered structurally: `RouterService::SALARY_PATTERNS` line 3 is `/\bpagas?\s+extra/iu` (`RouterService.php:50`) — **this is already in the lexicon**, not a gap. No change needed; listed so the mapping table is complete, not because it's new. |
| "nómina" | *none found* | salary | Already covered: `SALARY_PATTERNS` line 1, `\bn[oó]mina\b` (`RouterService.php:48`). No change needed. |
| "asuntos propios" | *none found* | none (already prose, via `TopicLexicon`) | Already an anchor under topic `permisos` (`TopicLexicon.php:29`: `'dias de asuntos propios', 'asuntos propios'`). Not a salary concept — it's a leave-type name; the existing prose/reference-fact path is correct. No change needed. |
| "plus de transporte" | *none found* | salary (proposed addition) | Genuinely absent from `SALARY_PATTERNS`. A plus/complemento is a salary-table line item (`salary_table_rows.raw_values`), so this is a real gap **if** this vocabulary ever appears. Proposed as an additive pattern (§C.5). |
| "finiquito" | *none found* — and the guardrail already intercepts it (see §0) | **none — no change** | Already caught by `GuardrailService::SENSITIVE_PATTERNS`'s disciplinary bucket (`GuardrailService.php:36`) and already an anchor for topic `despido` in `TopicLexicon::ANCHORS` (`TopicLexicon.php:41`, used by the precedence re-rank on *chunk* content — that use is unaffected and correct). Sprint 10b is out of scope for guardrail changes (spec §3). Routing it to salary would be wrong (it's a settlement concept, not a wage-table row) and is also structurally unreachable — the guardrail baseline runs at `ChatService.php:185`, before `RouterService`/`ReferenceFactRouter` ever see the question (`ChatService.php:229-230`). **This is the debatable case the spec names; my recommendation is leave it alone.** |
| "días de asuntos propios" (variant spellings) | *none found* | none (see "asuntos propios" above) | Same entry as above; listed separately because it's the fuller phrase. |

I looked for more candidates by grepping all 284 messages for a broader net (`jefe`, `denegad`, `excedencia`, `indemnizacion`, `antiguedad`) — only `jefe` (1 hit, inside the harassment sentence, not a salary/reference term) and `baja`/`despido` (15 each, all the same two repeated gold questions, already correctly handled). **There is no sixth real colloquial term to add.** The mapping table above is complete because the corpus is thin, not because I stopped looking.

**Table 3 — `explicit_request` phrasings.**

| Phrasing | Source | Proposed list membership |
|---|---|---|
| "Quiero hablar con una persona de Recursos Humanos, por favor." | **Real** — card 9, `escalation_cards.id=9`, 2026-09-10 | ✅ closed-list positive |
| "quiero hablar con una persona" (bare) | Constructed generalization of the above | ✅ closed-list positive (the load-bearing phrase; "persona" + "hablar con" + first-person request) |
| "necesito hablar con alguien de RRHH" / "recursos humanos" | Constructed | ✅ closed-list positive (near-paraphrase) |
| "¿con quién hablo para pedir vacaciones?" | Spec's own example (§4.2) | ❌ near-miss negative — must NOT fire (asking *how to route a request*, not asking *to be routed to a human directly*) |
| "¿con quién hablo para pedir mis vacaciones?" | Spec's own example (§6.5) | ❌ near-miss negative |
| "necesito hablar con mi jefe sobre las vacaciones" | Constructed | ❌ near-miss negative — "hablar con" + a person, but not RRHH and not a request for escalation |

I searched all 284 real messages for `persona`, `hablar`, `humano`, `recursos humanos`, `agente`, `contactar`, `llamar`, `asesor`: **the only hits, for every one of the first three terms, are the single card-9 sentence.** There is no real near-miss in the corpus either — the negatives above are constructed, same as spec's own examples. §C.6 proposes the closed list and placement.

### A.2 Question clusters (Analítica) and their escalation reasons

50 clusters exist (`question_clusters`, τ=0.80). The ones with real weight (`member_count` ≥ 8, i.e. hit repeatedly across test runs) map cleanly onto the existing 10a taxonomy, not onto anything 10b introduces:

| Cluster medoid | members | top reason |
|---|---|---|
| ¿Cuántos días de vacaciones me corresponden al año? | 36 | `estatuto_fallback_gap` |
| ¿Cuánto puede durar mi periodo de prueba? | 16 | `reference_fact_coverage_gap` |
| ¿Qué preaviso me deben dar en un despido objetivo? | 9 | `sensitive_topic` |
| ¿Cuánto cobro de salario base? | 9 | `salary_coverage_gap` |
| Quiero hablar con una persona de Recursos Humanos, por favor. | 1 (×3 cluster rows) | `off_domain` — **the misroute** |

No cluster's top reason is anything a colloquial-lexicon or decomposition fix would move — the volume is dominated by `estatuto_fallback_gap`/`reference_fact_coverage_gap` (10a's own territory: convenios genuinely thin on ingested prose/verified facts) and `sensitive_topic` (correct escalation). This reinforces §0: the real, measurable staging problem right now is corpus coverage, not vocabulary — 10b's features are legitimate anticipatory investment, not a response to a measured failure mode, except for the one `explicit_request` misroute.

### A.3 Reference-fact topic coverage (relevant to §C.5's "reference-fact" destination option)

`reference_facts` on staging carries facts under **exactly one topic**: "periodo de prueba" (6 `verified`, 82 `needs_review`). Every other approved topic (`vacaciones`, `jornada`, `permisos`, `festivos`, …) has **zero** reference facts. This matters for the colloquial-lexicon destination column: proposing "reference-fact" as a destination for a term under any topic other than periodo de prueba is currently a no-op in practice (`ReferenceFactRouter::detectTopic`, `ReferenceFactRouter.php:41-91`, requires an *existing verified* fact — §C.2) — correct by design (fail-through to prose), but worth stating so nobody reads "destination: reference-fact" as "this will answer from a fact today."

---

## B. Decomposition design (spec §2.1)

### B.1 The real `/route` contract, and why `decomposed_queries[]` is additive

**hr-backend caller:** `RouterService::classify()`, `hr-backend/app/Services/RouterService.php:66-166`. It calls `$this->ai->route($question, $decryptedKey, $routerConfig)` (line 112) — `ExtractionClient::route()`, `hr-backend/app/Services/ExtractionClient.php:312-328`:

```312:328:hr-backend/app/Services/ExtractionClient.php
    public function route(string $question, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(60)
            ->acceptJson()
            ->post("{$this->base()}/route", [
                'question' => $question,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);

        if (! $response->successful()) {
            return ['error' => 'router_unavailable', 'detail' => "hr-ai /route failed ({$response->status()})"];
        }

        return $response->json();
    }
```

**Line 327, `return $response->json();`, is the whole answer to "what happens to an unknown field."** The entire decoded JSON body is returned unfiltered. `RouterService::classify()` then reads exactly four keys off it — `$resp['error']` (line 116), `$resp['label']` (129), `$resp['confidence']` (133), `$resp['subqueries']` (134-137), `$resp['trace_fragment']` (155/165). **Any other key hr-ai adds to the response — including a new `decomposed_queries`— is silently present in `$resp` and silently ignored** by every line that exists today. No PHP-side change is required for a new key to arrive harmlessly; a PHP-side change (one new line reading `$resp['decomposed_queries'] ?? []`) is required to *use* it. This is exactly the additive shape the spec is asking to confirm.

I grepped the whole hr-backend tree for other `/route` consumers: `RouterService::classify()` is the **only** caller of `ExtractionClient::route()`, and `ChatService::handleMessage()` (`ChatService.php:248`) is the only caller of `RouterService::classify()`. There is no second consumer whose response-shape assumptions could break.

**hr-ai endpoint:** `POST /route`, `hr-ai/app/main.py:779-806`. Request model `RouteRequest` (`main.py:232-238`, plain `pydantic.BaseModel`, no `extra="forbid"` anywhere in this codebase — default Pydantic v2 behavior is to **ignore** unknown request fields, not reject them, confirmed by reading every `class …Request(BaseModel)` in `main.py`; none sets `model_config`). The endpoint delegates to `provider.classify()` (`ClaudeProvider.classify`, `hr-ai/app/providers/claude.py:1068-1125`) and returns a **hand-built dict** (`main.py:799-805`), not a Pydantic response model — so the response shape is whatever `main.py` puts in the dict; nothing validates or strips it.

**The prompt already asks for exactly this kind of decomposition — for a different purpose.** `ROUTER_SYSTEM_PROMPT` (`claude.py:234-254`):

```234:254:hr-ai/app/providers/claude.py
ROUTER_SYSTEM_PROMPT = (
    "Eres un clasificador de preguntas para un asistente de Recursos Humanos "
    "especializado en convenios colectivos españoles. Clasifica CADA pregunta en "
    "una sola etiqueta:\n"
    "- \"salary\": pide una CIFRA de retribución/salario/sueldo/nómina/tablas "
    "salariales/pagas/€ por hora (cuánto se cobra/gana en una categoría).\n"
    "- \"prose\": cualquier otra duda sobre condiciones laborales del convenio o la "
    "ley (jornada, vacaciones, permisos, periodo de prueba, excedencias, etc.).\n"
    "- \"off_domain\": no es una cuestión de RR. HH./laboral (p. ej. cocina, "
    "deportes, política, fiscalidad personal).\n\n"
    "ADEMÁS, si la pregunta es COMPUESTA (contiene DOS O MÁS subpreguntas o temas "
    "distintos, normalmente unidos por 'y'/'además'/comas o varios signos de "
    "interrogación), descomponla en una lista de subpreguntas autónomas, una por "
    "tema, reformulada para buscarse por separado. Si es de un solo tema, devuelve "
    "subqueries vacío. La etiqueta de una pregunta compuesta es la del tema "
    "predominante (normalmente \"prose\").\n\n"
    "FORMATO: devuelve EXCLUSIVAMENTE un objeto JSON válido, sin texto alrededor:\n"
    '{"label": "salary|prose|off_domain", '
    '"confidence": <número entre 0 y 1>, '
    '"subqueries": [<subpreguntas autónomas, o vacío si es de un solo tema>]}'
)
```

`subqueries` splits a **compound** question into its constituent topics (multiple distinct questions in one message). `decomposed_queries` needs to do something adjacent but distinct: rephrase a **situational/colloquial** question — possibly single-topic — into corpus vocabulary, for retrieval only. These are genuinely two different transformations (splitting vs. rephrasing) that can both apply to the same message (a compound, colloquial question). **Recommendation: add a second, parallel array, not a variant of `subqueries`.**

**B.1 recommendation — the additive prompt/schema change:**

```python
ROUTER_SYSTEM_PROMPT = (
    ...  # unchanged through "predominante (normalmente \"prose\")."
    "ADEMÁS, reformula el tema legal subyacente de la pregunta en vocabulario de "
    "convenio/ley cuando la pregunta use lenguaje coloquial o situacional (p. ej. "
    "'mi jefe me ha denegado las vacaciones, ¿puede?' → 'régimen de disfrute y "
    "fijación de vacaciones'). Devuelve estas reformulaciones en "
    "'decomposed_queries'. Si la pregunta ya está en vocabulario de convenio, o "
    "no hay reformulación que añada nada, devuelve una lista vacía. No es lo "
    "mismo que 'subqueries' (que separa temas distintos): "
    "'decomposed_queries' reformula, no separa; puede haber una para una "
    "pregunta de un solo tema, y ninguna para una compuesta ya bien fraseada.\n\n"
    "FORMATO: devuelve EXCLUSIVAMENTE un objeto JSON válido, sin texto alrededor:\n"
    '{"label": "salary|prose|off_domain", '
    '"confidence": <número entre 0 y 1>, '
    '"subqueries": [...], '
    '"decomposed_queries": [<reformulaciones en vocabulario de convenio, o vacío>]}'
)
```

- `claude.py:1111`, add: `decomposed_queries = [str(s).strip() for s in (envelope.get("decomposed_queries") or []) if str(s).strip()]`
- `claude.py:1113-1125` (`RouterResult(...)`), add the field; `RouterResult` dataclass definition (`hr-ai/app/providers/base.py`) needs the new field added with a `= field(default_factory=list)`-style default so every other call site constructing a `RouterResult` (the parse-error branch, `claude.py:1099-1105`; the fail-safe branches in `RouterService.php`) doesn't need to change.
- `main.py:799-805`, add `"decomposed_queries": result.decomposed_queries,` to the response dict.
- `RouterService.php:143-166` (the `decision()` builder, `RouterService.php:280-299`), add a `decomposedQueries` parameter defaulting to `[]`, threaded through every `decision(...)` call site the same way `subqueries` already is.
- `ChatService.php:253` (`trace['router_decision']`), add `'decomposed_queries' => $decision['decomposed_queries'],`.

Every one of these is a new key with a `[]`/absent default at every existing call site — the fake `ExtractionClient` stub inside `Sprint7cAdditivityRegressionTest` returns a fixed array without the key, `?? []` resolves it to empty, and the byte-for-byte assertions (`Sprint7cAdditivityRegressionTest.php:122-146`) are unaffected. **The preferred design (extend `/route`) is feasible with no restructuring.** I do not think the fallback separate-call design is needed and have not built out its latency cost — flag if you want it anyway as a documented rejected-alternative.

### B.2 Where the union assembles sub-queries, and how `decomposed_queries` joins it

`ChatService::retrieveUnion()`, `hr-backend/app/Services/ChatService.php:1106-1191`. The exact assembly:

```1106:1136:hr-backend/app/Services/ChatService.php
    private function retrieveUnion(string $question, array $subqueries, ?int $convenioId, string $asOf, bool $fallback = false): array
    {
        $byChunkId = [];
        $passes = [];
        $maxEligible = 0;
        $poolK = (int) config('hr.retrieval_pool_k', 25);
        $nlK = (int) config('hr.retrieval_national_law_k', 8);
        ...
        $scopeConvenioId = $fallback ? null : $convenioId;

        // The main question + each sub-query: scoped (convenio + national law).
        $queries = array_merge([$question], $subqueries);
        foreach ($queries as $i => $q) {
            $resp = $this->safeRetrieve([
                'query' => $q,
                'convenio_id' => $scopeConvenioId,
                'include_national_law' => true,
                'retrieval_status' => ['active'],
                'as_of_date' => $asOf,
                'k' => $poolK,
            ]);
            ...
        }
```

Pool-size limits (`hr-backend/config/hr.php:55,60`): `retrieval_pool_k = 25` (per scoped pass), `retrieval_national_law_k = 8` (the one fixed national-law-only pass, unaffected by sub-query count). The precedence re-rank entry point is `ChatService::precedenceRerank()`, called at `ChatService.php:1183`, defined `ChatService.php:1210-1268` — it runs on the **full merged pool, before truncation**. The synthesis cap: `ChatService.php:1187`, `$cap = self::SYNTHESIS_CHUNK_CAP + self::COMPOUND_CAP_PER_SUBQUERY * count($subqueries);` with `SYNTHESIS_CHUNK_CAP = 10` (`ChatService.php:117`) and `COMPOUND_CAP_PER_SUBQUERY = 2` (`ChatService.php:129`).

**The join is line 1125.** `decomposed_queries` become additional entries in the same `array_merge`:

```php
$queries = array_merge([$question], $subqueries, $decomposedQueries);
```

Each entry in `$queries` is issued as its own `/retrieve` pass at line 1127-1134, with `'query' => $q` the **only** thing that varies — `convenio_id`, `include_national_law`, `retrieval_status`, `as_of_date`, `k` are fixed from the enclosing scope for every pass, decomposed or not (§B.4 — this is the deterministic guard). Passing `$decomposedQueries` from `ChatService::answerProse()` (`ChatService.php:803`, called at line 342 with `$decision['subqueries']`) means adding a second argument, `$decision['decomposed_queries']`, threaded the same way.

**Maximum pool growth.** Before dedup, the worst case is `(1 + |subqueries| + |decomposed_queries|) × 25 + 8` chunks fetched (one call per query in `$queries`, plus the fixed national-law pass). Today, with subqueries capped implicitly by the router's own compound-detection (typically 1-3 in practice) and no decomposition, a compound question issues at most ~4 scoped passes (~100 chunks) + 8 = ~108 before dedup. Adding, say, 2 decomposed queries to a single-topic question raises this to `(1+0+2)×25+8 = 83` for a question that today issues only `(1+0)×25+8 = 33` — **roughly 2.5×** the pre-dedup pool for the common single-topic case that decomposition targets.

**One design gap I found, not in the spec, that the plan needs to close before build:** the synthesis cap formula (`ChatService.php:1187`) grows only with `count($subqueries)` — it does **not** know about `decomposed_queries`. If decomposition adds queries without widening the cap, the extra candidate chunks compete for the *same* truncation slots as before, which is fine (the re-rank already sorts by effective score, so added noise can only lose ties, never displace a higher-scoring real chunk) — **but** it also means a *good* decomposition's chunks aren't guaranteed the same "room to be found" that a compound `subqueries` chunk gets. Given spec's own acceptance criterion ("the union must be a no-op when the message is already canonical or the decomposition adds nothing new" — i.e., decomposition must never *cost* recall on a canonical question, but *is allowed* to add recall on a situational one), I recommend widening the cap symmetrically:

```php
$cap = self::SYNTHESIS_CHUNK_CAP + self::COMPOUND_CAP_PER_SUBQUERY * (count($subqueries) + count($decomposedQueries));
```

This is additive (canonical question → `$decomposedQueries = []` → identical cap → byte-for-byte with today) and gives a genuine situational rephrasing the same recall headroom a compound question already gets.

**One scope note for the builder:** `retrieveUnion()` has a **second call site**, `ChatService.php:452` (`$this->retrieveUnion($question, [], $employee->convenio_id, ...)`), inside the reference-fact answer path's supporting-citation retrieval — always called with `subqueries = []`. Decomposition is a router-response feature (`RouterService::classify()` → `answerProse()`), and the reference-fact path never calls the router (`ChatService.php:229-233` short-circuits before it) — so `decomposed_queries` has no natural value to thread through this second call site, and the new parameter should default to `[]` there too, not be wired to anything. Flagging only so the second call site doesn't get overlooked or, worse, wired to something meaningless during build.

### B.3 The deterministic guard — decomposed queries reach retrieval text only

Confirmed directly from the code at the call site (`ChatService.php:1127-1134`): the `/retrieve` call's `query` parameter is the only thing that varies per entry in `$queries`; `convenio_id` (`$scopeConvenioId`, fixed at line 1122 from the employee's own resolved scope, or `null` on the 10a estatuto-fallback branch — never from a sub-query), `include_national_law` (always `true`), `retrieval_status` (always `['active']`), `as_of_date` (fixed per turn), `k` (fixed `$poolK`) are all closed over from the enclosing `retrieveUnion()` call, not read from `$q`. There is **no code path** by which a string appearing in `$queries` can influence scope resolution (`ChatService.php:158-180`, computed once at the top of `handleMessage`, before the router even runs), routing precedence (`RouterService::classify()` runs once on the *original* `$question`, never on a decomposed query — decomposition happens *inside* the router's own response for the original question, it does not get separately routed), or authority (`_AUTHORITY_RANK`/precedence live entirely in `precedenceRerank()`, keyed off each *returned chunk's* `authority_level`, never off which query produced it).

**The guard, concretely:** a decomposed query is a `string` consumed at exactly one place (`ChatService.php:1128`, `'query' => $q`) and nowhere else in the request-building code. To smuggle scope/authority through it, hr-ai's `/retrieve` itself would have to derive scope from free text — it does not; `/retrieve`'s scope filters are separate, named request fields (`convenio_id`, `include_national_law`, `retrieval_status`, `as_of_date`), unrelated to `query`'s content, per the retrieval primitive's contract (architecture.md §5, "3b — Vector primitive"). This is the same guard the existing `subqueries` mechanism already relies on — I am not proposing a new safety mechanism, I am confirming decomposed_queries reuses the one that already exists, by construction (same array, same loop, same call).

---

## C. Lexicon + `explicit_request` placement

### C.1 Salary pre-classifier — current lexicon and mechanism for additions

`RouterService::SALARY_PATTERNS`, `hr-backend/app/Services/RouterService.php:47-51`:

```47:51:hr-backend/app/Services/RouterService.php
    private const SALARY_PATTERNS = [
        '/\b(salario|sueldo|n[oó]mina|retribuci[oó]n|remuneraci[oó]n)\b/iu',
        '/\btablas?\s+salarial(es)?\b/iu',
        '/\bpagas?\s+extra/iu',
        '/\bcu[aá]nto\s+(gano|gana|gan[aá]is|cobro|cobra|cobr[aá]is|ingreso|ingresa|me\s+pagan?|se\s+(gana|cobra|paga))\b/iu',
    ];
```

Invoked via `matchesSalary()` (`RouterService.php:194-205`), called from `ChatService::handleMessage()` at two points: `ChatService.php:229` (gates the reference-fact pre-check to non-salary questions) and inside `RouterService::classify()` itself (`RouterService.php:68`, layer 1, no LLM). **Mechanism for colloquial additions: append new regex entries to this same `const array`** — same structure, purely additive (a new pattern can only make `matchesSalary()` return `true` in more cases than before, never fewer — no existing pattern is touched). Proposed addition per §A.1 Table 2: `'/\bplus(es)?\s+de\s+transporte\b/iu'`.

**Regression set — every existing gold salary/fact question.** There is no pre-existing, named "salary gold set" file to point to (I checked `hr-backend/tests` for a `SalaryAnswerServiceTest`/`RouterServiceTest`/gold fixture — none exists; salary routing is covered only incidentally, one case each, inside `Sprint7cAdditivityRegressionTest.php` and `Sprint7cReferenceFactAnswerTest.php`). The regression set has to be assembled fresh; I propose the 5 real salary-routed questions from §A.1 (`¿Cuál es mi salario base mensual…`, `¿Cuánto cobro de salario base?`, `¿Cuánto cobro este mes según mi tabla salarial?`, `¿Cuánto gano?`, `¿cuánto gana mi categoría este año?`) plus the deterministic-pattern unit cases already implicit in `SALARY_PATTERNS` itself (one positive per pattern, one adjacent negative — e.g. the doc-comment's own worked example, "¿me paga el gimnasio?" must stay `false`). This becomes `sprints/sprint-10b/eval/salary-lexicon-gold.json` (§E.9).

### C.2 Reference-fact pre-check — current lexicon and mechanism for additions

`TopicLexicon::ANCHORS`, `hr-backend/app/Support/TopicLexicon.php:26-43` — 14 topics, e.g. `'vacaciones' => ['vacaciones', 'vacacional', 'periodo vacacional']`, `'permisos' => ['permiso', 'permisos', 'licencia', 'licencias', 'dias de asuntos propios', 'asuntos propios']`. This is **the same lexicon** used by two consumers, confirmed by the class's own docblock (`TopicLexicon.php:8-14`) and by code: `ChatService::precedenceRerank()` calls `chunkTopics()` (delegating to `TopicLexicon::matchTopicKeys()`) on **chunk content** (`ChatService.php:1214`), and `ReferenceFactRouter::detectTopic()` calls `TopicLexicon::candidateTopicNames()` on the **question text** (`ReferenceFactRouter.php:50`). **This means a colloquial anchor added to `TopicLexicon::ANCHORS` simultaneously improves both the precedence re-rank's chunk-topic detection and the reference-fact pre-check's question-topic detection — one lexicon, two consumers, already unified (no split to create).**

`ReferenceFactRouter::detectTopic()` (`ReferenceFactRouter.php:41-91`) then requires a `verified`, in-scope, in-validity `reference_facts` row to exist for the matched topic (lines 68-76) before it returns non-null — fail-through to prose otherwise (line 87: "anchored, but no verified in-scope in-validity fact → fall through"). §A.3 already flagged that only `periodo de prueba` currently has verified facts on staging, so a colloquial anchor added under any other topic is additive-and-currently-inert (correct, safe, just worth knowing).

**Mechanism for colloquial additions:** append terms to the relevant `ANCHORS['<topic_key>']` array. E.g., "asuntos propios" is **already present** under `permisos` (§A.1 Table 2) — no addition needed there. No other colloquial term from Table 2 maps to a topic requiring a `TopicLexicon` change (the destinations proposed are salary or none).

**Regression set:** every question in `hr-docs/sprints/sprint-07c/` and `sprint-07f/` fixtures that exercises `ReferenceFactRouter` (I did not find a single consolidated "reference-fact gold question list" either — `Sprint7cReferenceFactAnswerTest.php` and `Sprint7fGroupProposalInvariantTest.php` each carry their own inline cases). Propose consolidating the union of those inline questions into `sprints/sprint-10b/eval/reference-fact-lexicon-gold.json` alongside the salary set, so both regressions are re-runnable from one harness (§E.9).

### C.3 Guardrail baseline — order and why `explicit_request` sits after it

`GuardrailService::check()`, `hr-backend/app/Services/GuardrailService.php:82-105`: three pattern buckets, checked in order — `SENSITIVE_PATTERNS` (line 84-88, includes the disciplinary bucket that already catches "finiquito"), `LEGAL_MEDICAL_PATTERNS` (90-94), `OTHER_EMPLOYEE_PATTERNS` (100-104). This is invoked from `ChatService::handleMessage()` at line 185, unconditionally, before anything else. The admin-configurable additive layer runs next (`GuardrailPolicy::blockedTopicMatch()`, called at `ChatService.php:204`).

### C.4 Exact orchestration order in `ChatService::handleMessage()`, confirmed by reading the file top to bottom

| Step | Code | Line |
|---|---|---|
| Scope resolve → trace skeleton | inline | 158-180 |
| **Hardcoded guardrail baseline** | `$this->guardrail->check($question)` | 185 |
| **Admin guardrail layer (additive union)** | `$this->policy->blockedTopicMatch($question)` | 204 |
| *(proposed insertion point — §C.6)* | **`explicit_request` pre-check** | *new, ~217* |
| Reference-fact pre-check (non-salary only) | `$this->router->matchesSalary()` guard + `$this->referenceFactRouter->detectTopic()` | 229-233 |
| Router (salary pre-classifier, then LLM `/route`) | `$this->router->classify()` | 248 |
| Off-domain escalate | `$decision['label'] === RouterService::OFF_DOMAIN` | 261 |
| Salary SQL path | `$decision['label'] === RouterService::SALARY` | 280 |
| Prose path | `$this->answerProse(...)` | 342 |

**Placement recommendation: immediately after the admin guardrail layer (after line ~216), before the reference-fact pre-check (line 229).** Reasoning, spelled out per spec's own question ("what happens if a message is both sensitive and a human request?"):

- The hardcoded and admin guardrail layers must win over `explicit_request` — a message that is simultaneously sensitive (e.g. a harassment disclosure that also says "y quiero hablar con una persona") must escalate `sensitive_topic`, not the weaker, contentless `explicit_request`, because the sensitive-topic path carries its own conservative handling and because §0's guardrail-precedence rule ("admins cannot weaken the baseline") only makes sense if nothing downstream can pre-empt it. Placing `explicit_request` after both guardrail layers guarantees this — by the time it runs, the message has already been cleared of every sensitive/off-domain-by-policy/admin-blocked pattern.
- `explicit_request` should win over the reference-fact pre-check and the router, because a bare human-request phrase ("quiero hablar con una persona de RRHH") has no topic to anchor on and no salary/prose content to classify — running those checks first would either fall through harmlessly (no anchor found) or, worse, risk a false topic match on an incidental word. Checking `explicit_request` first, on a narrow closed list, is strictly safer and cheaper (deterministic, no LLM, mirrors the existing pre-check pattern the spec asks to match).
- This ordering also means `explicit_request` **never competes with `off_domain`** for card 9's own case: today "Quiero hablar con una persona de Recursos Humanos, por favor." reaches the LLM router (having passed both guardrail layers and the reference-fact pre-check, which finds no topic anchor) and gets classified `off_domain` (confirmed on staging — card 9's actual `reason` is `off_domain`). With the new pre-check inserted before the reference-fact check, this exact message is caught deterministically, before the router call, and never reaches the LLM at all.

**7g matrix entry for `explicit_request`:** already present, verbatim, at `EscalationExplainer.php:44` (`'explicit_request.explicit_request'`, commented `// --- reserved, not currently emitted, still explainable ---`) with a full registry builder at `EscalationExplainer.php:389-398`:

```389:398:hr-backend/app/Support/EscalationExplainer.php
            'explicit_request' => [
                'explicit_request' => fn (array $t) => [
                    'asked' => 'El empleado pidió explícitamente hablar con una persona de Recursos Humanos.',
                    'found' => 'No aplica — es una petición directa, no una búsqueda fallida.',
                    'stopped_reason' => 'Petición explícita del empleado.',
                    'fix_action' => 'Atender la conversación normalmente; no hay nada que corregir en el sistema.',
                    'fix_surface' => 'ninguna — atención directa',
                    'fix_link' => null,
                ],
            ],
```

And `detectSubOutcome()` already maps `'explicit_request' => 'explicit_request'` unconditionally (`EscalationExplainer.php:162`) — it doesn't inspect the trace shape at all, so giving the reason a real producer requires **zero** change to this file.

**Completeness guard confirmed covered, already, today.** `EscalationExplainerGuardTest::test_every_live_escalation_reason_is_covered_by_at_least_one_matrix_entry()` (`hr-backend/tests/Unit/EscalationExplainerGuardTest.php:43-60`) hardcodes `explicit_request` into its `$liveReasons` array (line 44) — it is checked today even though nothing produces it. **No test change is needed for the completeness guard itself; it already passes and will continue to pass.** (What *does* need a new small test is the new pre-check's own unit coverage — positives fire, near-misses don't — which is the `Sprint10bInvariantTest`, §E.10.)

Two more pieces of the HR-facing surface, checked and already wired, that the spec doesn't explicitly ask about but that a plan-gate should confirm before claiming "zero downstream change":

- `EscalationController::REASON_LABELS['explicit_request'] => 'Petición explícita'` — already present (`hr-backend/app/Http/Controllers/Admin/EscalationController.php:42`).
- `GuardrailPolicy::CONVERTIBLE_REASONS_BASELINE` already includes `'explicit_request'` (`GuardrailPolicy.php:50`) — Save-as-knowledge conversion is already permitted for it (sensible: HR might want to convert a human-request pattern into an FAQ entry).
- The frontend board/history filter dropdowns (`EscalationBoardPage.tsx`'s `REASON_FILTERS`, `HistoryPage.tsx`'s `REASONS`) already list `explicit_request` as a filterable value (confirmed via `sprint-10a/review.md`'s Item-2 audit, which enumerated these exact 8-entry arrays and found `explicit_request` present, unlike three other reasons it flagged as missing).

**This is, genuinely, the lowest-risk item in the sprint: one new deterministic method, one new call site, zero schema migration, zero explainer change, zero frontend change.**

### C.5 The colloquial-addition mechanism, generalized

Both lexicons (`RouterService::SALARY_PATTERNS`, `TopicLexicon::ANCHORS`) are plain `const` PHP arrays of literal strings/regexes, matched by `preg_match`/`str_contains` — no external config, no admin UI, no database row (deliberately: these are **code**, same tier as `GuardrailService`'s hardcoded patterns, not the Sprint-6 admin-configurable layer, which only ever adds *topics to block/off-domain*, never routing vocabulary). Additive-only in the same sense the guardrail baseline is additive-only: a new pattern in either array can only cause a question to match that didn't before; no existing pattern's behavior changes. This is the same append-only discipline the spec asks for, and it is already the codebase's convention — 10b doesn't need to invent a new mechanism, only use the existing one.

### C.6 The `explicit_request` closed phrase list

Proposed lexicon (mirrors `RouterService::SALARY_PATTERNS`'s structure and conservatism — narrow first, per spec R5):

```php
private const EXPLICIT_REQUEST_PATTERNS = [
    // "quiero/necesito hablar/contactar con una persona/alguien de RRHH" — the
    // real card-9 phrasing and its narrow paraphrases. Deliberately requires
    // BOTH a first-person request verb AND ("persona"/"alguien" + RRHH context
    // OR an explicit "recursos humanos" mention) so a bare "¿con quién hablo…"
    // (asking HOW to reach someone, not asking directly FOR someone) does not
    // match — see the near-miss negatives in the eval set.
    '/\b(quiero|necesito|me\s+gustar[ií]a)\s+(hablar|contactar)\s+con\s+(una\s+persona|alguien)(\s+de\s+(recursos\s+humanos|rr\.?\s?hh\.?))?\b/iu',
    '/\b(quiero|necesito|me\s+gustar[ií]a)\s+(hablar|contactar)\s+con\s+recursos\s+humanos\b/iu',
];
```

Positives (closed list, §A.1 Table 3): the real card-9 sentence, plus its narrow paraphrases. Negatives (must NOT fire): "¿con quién hablo para pedir vacaciones?" (interrogative "¿con quién…", no first-person request verb — the pattern requires "quiero/necesito/me gustaría", which this lacks), "necesito hablar con mi jefe…" (a person, but not RRHH/"una persona"/"alguien" — "mi jefe" doesn't match the noun-phrase alternation). Every approved term gets at least one negative in the eval per spec R3.

---

## D. Ops hardening (spec §2.4)

### D.1 `/ground`'s truncation-retry, and the mirrored `/synthesise` change

`ClaudeProvider.ground()`, `hr-ai/app/providers/claude.py:1127-1250`. The retry:

```1144:1166:hr-ai/app/providers/claude.py
        # Call once at the (generous) budget. If the model still stops at the token
        # cap (stop_reason == "max_tokens") the JSON is truncated — retry ONCE at a
        # larger budget before giving up (Correction-04). A truncation is a budget
        # problem, never evidence of a fabricated claim.
        started = time.monotonic()
        resp = client.messages.create(
            model=config.model,
            max_tokens=GROUND_MAX_TOKENS,
            system=GROUND_SYSTEM_PROMPT,
            messages=[{"role": "user", "content": user_prompt}],
        )
        budget = GROUND_MAX_TOKENS
        retried_on_truncation = False
        if getattr(resp, "stop_reason", None) == "max_tokens":
            retried_on_truncation = True
            budget = GROUND_MAX_TOKENS_RETRY
            resp = client.messages.create(
                model=config.model,
                max_tokens=GROUND_MAX_TOKENS_RETRY,
                system=GROUND_SYSTEM_PROMPT,
                messages=[{"role": "user", "content": user_prompt}],
            )
        elapsed_ms = int((time.monotonic() - started) * 1000)
```

Budgets: `GROUND_MAX_TOKENS = 4096`, `GROUND_MAX_TOKENS_RETRY = 8192` (`claude.py:310-311`, a straight double). `synthesise()` (`ClaudeProvider.synthesise()`, `claude.py:868-1011`) currently calls once, `max_tokens=4096` (line 893, raised from 1024 in Sprint 10-M per the comment at lines 884-892), **with no retry at all** — a truncation there today lands directly in the unparseable branch (lines 903-918) and escalates, indistinguishable in the trace from a genuinely bad completion.

**Mirrored change, additive, no prompt text touched (confirming the spec's own constraint):**

```python
SYNTHESISE_MAX_TOKENS = 4096
SYNTHESISE_MAX_TOKENS_RETRY = 8192  # double, matching /ground's own ratio

# in synthesise():
resp = client.messages.create(model=config.model, max_tokens=SYNTHESISE_MAX_TOKENS, system=SYSTEM_PROMPT, messages=[...])
budget = SYNTHESISE_MAX_TOKENS
retried_on_truncation = False
if getattr(resp, "stop_reason", None) == "max_tokens":
    retried_on_truncation = True
    budget = SYNTHESISE_MAX_TOKENS_RETRY
    resp = client.messages.create(model=config.model, max_tokens=SYNTHESISE_MAX_TOKENS_RETRY, system=SYSTEM_PROMPT, messages=[...])
```

`SYSTEM_PROMPT` and `user_prompt` (built by `_build_user_prompt`, unchanged) are referenced, never edited — confirming "no prompt text changes." The existing unparseable branch (lines 903-918) then also gets `retried_on_truncation` in its `trace_fragment` (mirroring `ground`'s line 1198), and — still truncated after retry — should get its own distinct outcome the same way `ground()` does (lines 1172-1185: a dedicated "still truncated" branch, distinct from a genuine parse failure, with `"synthesis_truncated": True` rather than conflating it with `"parse_error": True`). This is a direct, line-for-line port of an already-proven pattern; no new design risk.

### D.2 The `parse_error` trace-write sites, and the two fields to add

I read every `parse_error`/truncation branch in `claude.py`. **Correction against my own first pass:** there are **eight** such sites in the file, not four — I initially listed only the four inside the answer/router/grounding loop (`synthesise`, `classify`, `ground`×2) and missed four more in the admin-tooling provider methods (`explain`, `propose_tags`, `segment_facts`, `propose_groups`) plus the OCR one, which I'd noted separately. Full, verified list (confirmed by grepping every `"parse_error": True`/`parse_error=True`/`layout="parse_error"` literal in the file):

| Site | Line(s) | Currently in `trace_fragment` | Missing | In the chat answer loop? |
|---|---|---|---|---|
| `synthesise()` unparseable | `claude.py:907-919` | `provider, model, synthesis_ms, parse_error:true` | `stop_reason`, `completion_tokens` | **Yes** — spec §2.4 names this one explicitly |
| `classify()` (`/route`) unparseable | `claude.py:1096-1105` | `provider, model, router_ms, parse_error:true` | `stop_reason`, `completion_tokens` | Yes |
| `ground()` still-truncated-after-retry | `claude.py:1168-1185` | `provider, model, ground_ms, grounding_truncated:true, retried_on_truncation, max_tokens` | `stop_reason` (redundant with `grounding_truncated` but cheap/consistent), `completion_tokens` | Yes |
| `ground()` unparseable (non-truncation) | `claude.py:1191-1199` | `provider, model, ground_ms, parse_error:true, retried_on_truncation` | `stop_reason`, `completion_tokens` | Yes |
| `explain()` unparseable | `claude.py:1056-1062` | passthrough `{**trace_fragment, parse_error:true}` — `trace_fragment` built earlier already carries `completion_tokens` from the success-path prep, just not `stop_reason` | `stop_reason` | No — escalation-card explanation path, not the answer loop |
| `propose_tags()` unparseable | `claude.py:1282-1296` | `provider, model, propose_ms, parse_error:true` | `stop_reason`, `completion_tokens` | No — admin tag-proposal tooling |
| `segment_facts()` unparseable | `claude.py:1424-1433` | `provider, model, segment_ms, parse_error:true` | `stop_reason`, `completion_tokens` | No — admin ingestion tooling |
| `propose_groups()` unparseable | `claude.py:1563-1574` | passthrough `{**base_trace, parse_error:true, group_count:0}` — `base_trace` already carries `completion_tokens` | `stop_reason` | No — admin group-proposal tooling |
| `ocr_page()` unparseable | `claude.py:1820-1826` | passthrough `{**trace_fragment, parse_error:true}` — already carries `completion_tokens` | `stop_reason` | No — ingestion OCR tooling |

At **every** one of these sites, `resp` (the raw `anthropic.types.Message` object, or `stream.get_final_message()` for `segment_facts()`) is already in scope as a local variable when the branch executes — confirmed by reading the surrounding code: `resp.stop_reason` and `resp.usage.output_tokens` are exactly the attributes `ground()`'s own success path already reads at lines 1172 and 1244 respectively, just not captured in these specific dict literals. The two-field addition is therefore literally:

```python
"stop_reason": getattr(resp, "stop_reason", None),
"completion_tokens": getattr(resp.usage, "output_tokens", None) if getattr(resp, "usage", None) else None,
```

added into each dict literal above (four sites already have `completion_tokens` via an earlier `{**trace_fragment, ...}`/`{**base_trace, ...}` spread — those only need `stop_reason` added to the spread source, not a new key).

Spec §2.4 names `/synthesise`'s site specifically — that's the only one required for this sprint's acceptance criteria. The other seven fall into two groups: three more sit on the live chat answer loop (`/route`, `/ground`×2) and are worth doing for the same "instrument the path a real employee is on" reason as `/synthesise`; the remaining four (`explain`, `propose_tags`, `segment_facts`, `propose_groups`, `ocr_page` — admin/ingestion tooling, never on the employee chat path) are lower priority but essentially free to include at the same time, since it's the same two-line pattern repeated. **Revised open question (§E.6):** ship all four chat-loop sites (`synthesise`, `route`, `ground`×2) as the sprint's actual scope, and treat the four admin-tooling sites as an optional, separately-flaggable cleanup — or do all eight in one pass? My recommendation is the same as before (do all of them, it's a few minutes of mechanical work), just corrected for the true count.

**Confirming no consumer breaks on the extra fields.** hr-backend never reads `parse_error`/`stop_reason`/`completion_tokens` off a decoded response by name to drive a branch — it reads the semantic `grounded`/`answer`/`citations` fields and stores the **entire** `trace_fragment` blob verbatim into the trace JSON column:

```
ChatService.php:612   'trace_fragment' => $synth['trace_fragment'] ?? [],
ChatService.php:626   'trace_fragment' => $groundingResult['trace_fragment'] ?? [],
ChatService.php:1010  'trace_fragment' => $synth['trace_fragment'] ?? [],
ChatService.php:1030  } elseif (($groundingResult['trace_fragment']['grounding_truncated'] ?? false)) {
ChatService.php:1056  'trace_fragment' => $groundingResult['trace_fragment'] ?? [],
```

Every one of these is `$x['trace_fragment'] ?? []` (whole-blob passthrough into `message_traces.trace`, a `jsonb` column with no schema) or a permissive `?? false` key read. Two new keys arriving inside that blob changes nothing at any of these five sites. **This mirrors the exact reasoning `synthesise()`'s own code comment already gives for its `cost_usd` addition** (`claude.py:975-981`: "every existing caller of /synthesise ... already ignores unknown trace_fragment keys") — same guarantee, same mechanism, already precedented in this file.

---

## E. Evals + plan output

### E.1 Gold sets

Following the established convention (`sprints/sprint-10a/eval/`: named JSON fixture + a driver script/artisan command, e.g. `estatuto:gold-eval --profile=positive|negative|second-negative` against `eval/fallback-gold.json`; `sprints/sprint-07d/eval/anchors.json` for the semantic-fence calibration):

1. **`sprints/sprint-10b/eval/explicit-request-gold.json`** — **real + constructed, labelled.** One real positive (card 9's exact sentence), 2-3 constructed paraphrase positives, 3+ constructed near-miss negatives (§C.6). Metric: 100% of positives fire `explicit_request`; 0% of negatives do. Harness: a new PHPUnit test (part of `Sprint10bInvariantTest`, §E.10) — this is a pure string-classification check, no LLM, no staging DB needed, so a unit test is the right harness (not a `php artisan …` staging driver like 10a's, since there's no staging state to exercise — it's a closed deterministic function).

2. **`sprints/sprint-10b/eval/lexicon-gold.json`** — **entirely constructed**, per §0/§A: no real question currently fails for want of a colloquial term. Positive cases: the approved additions (e.g. "plus de transporte" → salary) with a synthetic question each. Regression cases: the 5 real salary questions from §A.1 + the existing `SALARY_PATTERNS`/`TopicLexicon::ANCHORS` inline test cases consolidated from `Sprint7cReferenceFactAnswerTest.php`/`Sprint7fGroupProposalInvariantTest.php` (§C.1-C.2). Metric: every regression case routes identically (same label/topic as before); every new positive case now matches. Harness: same `Sprint10bInvariantTest`, since these are also pure deterministic pattern checks.

3. **`sprints/sprint-10b/eval/situational-decomposition-gold.json`** — **constructed**, explicitly labelled as such (per §0/§A.1, there is no real situational phrasing to draw from). Built as: (a) situational variants of the existing 2c/10a gold questions (e.g. "¿cuánto dura el periodo de prueba?" [real, already canonical] → a constructed situational variant "llevo tres meses en la empresa, ¿cuándo dejo de estar a prueba?"), each paired with its canonical twin; (b) the canonical gold questions themselves, unchanged, to prove the no-op requirement. Metrics per spec §4.2: retrieval hit-rate delta on situational forms (does adding `decomposed_queries` recover the same top chunk the canonical form gets, that the situational form alone misses?); zero change on canonical forms (`decomposed_queries` empty, or non-empty but changing no citations — assert on the union's chunk-id set, not just the final answer text, since the answer could coincidentally match while the evidence set differs). Harness: a driver script in the `sprint-10a/eval/gold-answer-run.php` style — drives the real `ChatService::handleMessage()` loop against a test employee for each situational/canonical pair, prints `trace.router_decision.decomposed_queries`, the retrieval union's chunk-id set, and the final citations, so a reviewer can eyeball whether the decomposition actually helped, changed nothing, or (the failure mode to watch for) added noise that changed the *citations* without changing the *answer's substance*.

### E.2 The 10a positive-set no-regression run

Include, as an explicit build step (not merely "should still pass" — actually run it): `php artisan estatuto:gold-eval --profile=positive` (and `--profile=negative`, `--profile=second-negative`) against the existing `sprints/sprint-10a/eval/fallback-gold.json` (15 questions, `hr-docs/sprints/sprint-10a/eval/fallback-gold.json`), re-run once after 10b's changes land, diffed against the recorded 10a review numbers (`sprints/sprint-10a/review.md`: positive profile 10/13 answerable questions answered; negative profiles 0 answered with no `floor_decision.fallback` key). Any drift here would mean 10b's changes (most likely the lexicon or the reference-fact-adjacent `TopicLexicon` edit) leaked into the estatuto-fallback path, which shares `ChatService::retrieveUnion()`/`answerProse()` with the prose path 10b also touches.

### E.3 Build order

Per spec §10's own directive, confirmed by §0's evidentiary read to be the right order for reasons beyond just risk-management:

1. **`explicit_request` pre-check (§C.6)** — real motivating case, deterministic, cheap, zero migration, every downstream surface already wired. Build and ship first.
2. **Colloquial lexicon additions (§C.1, C.2, C.5)** — deterministic, cheap, additive-only append to existing `const` arrays. No migration. Build second, alongside item 1 if convenient (same PR is reasonable — both are "append to a closed list, add a regression test").
3. **Ops hardening (§D.1, D.2)** — hr-ai-only, no hr-backend change, no interaction with 1/2/4. Spec's "wherever it minimizes rebase friction" — since it touches a completely different file region (`claude.py`'s provider methods) from the routing changes (`RouterService.php`/`ChatService.php`), it can land at any point; I'd put it third, right after the cheap deterministic items, so the riskiest item (decomposition) is the only thing left outstanding and gets full attention/rollback isolation.
4. **Situational decomposition (§B)** — one risk at a time, last, exactly as spec directs. This is the only item touching the LLM-facing prompt/schema and the retrieval union's pool-growth math (§B.2's cap-formula fix).

### E.4 Invariant tests (spec §4.1 minimum) — `Sprint10bInvariantTest`

Following the naming convention (`Sprint6GuardrailInvariantTest`, `Sprint7aTagProposalInvariantTest`, `Sprint7b1ReferenceFactInvariantTest`, `Sprint7fGroupProposalInvariantTest`, `Sprint10aInvariantTest`, all in `hr-backend/tests/Feature/`), a new `hr-backend/tests/Feature/Sprint10bInvariantTest.php` asserting, minimum:

- Decomposition union is additive: given a canonical question and a stub router response with a non-empty `decomposed_queries`, the retrieval union's final chunk-id set is a **superset** of (or, when the decomposed query returns nothing new, **identical to**) the set retrieved without it — never a subset (never a *loss* of a chunk the canonical form alone would have surfaced).
- Decomposed queries cannot reach scope resolution: assert `retrieveUnion()`'s `/retrieve` calls all carry the same `convenio_id`/`retrieval_status`/`as_of_date` regardless of which query string is being issued (a mock/spy on `safeRetrieve()` asserting the non-`query` keys are identical across all calls in one turn).
- Lexicon adds matches only: every case in the C.1/C.2 regression set routes identically before/after.
- `explicit_request` fires on the approved phrases and only those (the full positive/negative list from §C.6/E.1).
- Matrix/guard coverage: `EscalationExplainerGuardTest` continues to pass unmodified (already covers `explicit_request`, §C.4) — assert this by simply running the existing test, not duplicating its logic.
- `/synthesise` retry fires once, and only on `stop_reason == "max_tokens"` (a stubbed provider response asserting exactly one retry call, at the doubled budget, and zero retries on a normal completion).
- Enriched `parse_error` fields present on a forced parse failure (a hr-ai stub returning unparseable JSON; assert `stop_reason`/`completion_tokens` present in the resulting trace fragment).

### E.5 Migrations

**None.** `escalation_cards.reason`'s CHECK constraint already includes `explicit_request` from its original creation (`hr-backend/database/migrations/2026_06_20_131021_create_escalation_cards_table.php:17`) — confirmed by reading the migration directly; no `add_explicit_request_to_…` migration exists because none was ever needed. No new columns are proposed anywhere in this plan (the `decomposed_queries` trace field lives inside the existing `jsonb` `message_traces.trace` column, same as every other trace block since 2b-2).

### E.6 Open questions

1. **§D.2's scope**: add `stop_reason`/`completion_tokens` to all eight parse-failure branches in `claude.py`, to just the three other chat-answer-loop sites alongside `/synthesise` (`/route`, `/ground`×2), or only `/synthesise`'s (the one the spec names explicitly)? Recommendation: all eight — it's the same two-line pattern repeated, four sites already have one of the two fields via an existing `{**trace_fragment, ...}` spread — for long-term consistency (this is exactly the kind of "instrument now, thank yourself during the next incident" the Sprint 10-M postmortem argues for).
2. **§B.2's cap-formula fix** (`SYNTHESIS_CHUNK_CAP` growth should count `decomposed_queries` too, not just `subqueries`) is not asked for in the spec text but falls directly out of the spec's own no-regression acceptance criterion. Confirm before build — it's a two-token code change but changes the pool-growth math stated in §B.2.
3. **Is "plus de transporte" worth adding at all**, given §A.1 found zero real motivation for it (unlike the salary/reference-fact "already covered" entries, this one is a genuine net-new pattern, not a confirmation of an existing one)? I lean yes (cheap, additive, plausible future term) but it's the one line in Table 2 that's a real judgment call rather than a "no change" confirmation.
4. Should the `explicit_request` closed list in §C.6 ship narrower still (just the two patterns shown) or include a couple more paraphrase variants ("me gustaría contactar con RRHH", "prefiero hablar con alguien de recursos humanos")? Spec R5 says start narrow; I've already kept it to two regex patterns covering the one real case plus its nearest paraphrases — flagging in case Pedram wants it narrower (exact-match on card 9's sentence only) or slightly wider.

### E.7 ⏸ Checkpoints

- ⏸ **CP-1 (this plan gate).** Pedram approves: the term→destination mapping table (§A.1 Table 2, five entries, four of them "no change") and the `explicit_request` phrase list (§C.6, two positives-pattern regexes + the near-miss negative set) — both explicitly required by the kickoff prompt before build starts.
- ⏸ **CP-2.** After items 1-2 (explicit_request + lexicon) build: Pedram/HR eyes-on staging per spec §6 items 3-5 (colloquial salary phrasing routes correctly; "quiero hablar con una persona…" escalates `explicit_request` with the matrix explanation visible on the card; the near-miss does not).
- ⏸ **CP-3.** After item 3 (ops hardening) build: confirm the `Sprint7cAdditivityRegressionTest` and the 10a gold-eval re-run (§E.2) are still green, since this item touches the same `claude.py` file the answer/grounding path depends on even though it changes no prompt text.
- ⏸ **CP-4 (before item 4 begins).** Review the situational-decomposition gold set (§E.1 item 3) once constructed, given §0/§A's finding that it will be substantially hypothetical — confirm the constructed variants are ones Pedram considers realistic before spending build effort measuring against them.
- ⏸ **CP-5.** After item 4 builds: spec §6 eyes-on items 1-2, 6 (the situational vacaciones question; the canonical-form no-op check; admin trace rendering of `decomposed_queries`).

---

Then **STOP** and wait for review.
