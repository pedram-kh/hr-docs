# Sprint 6 — Guardrails configuration UI · PLAN (plan-gate)

> Location: `hr-docs/sprints/sprint-06/plan.md`
> Status: **plan for review — no guardrail-config code written this turn.**
> Read with: `architecture.md` §7 (the two guardrail layers) + §5 (the answer-or-escalate decision); `sprint-06/sprint-06-spec.md`; ADR-0002 / 0007 / 0015 / 0016 / 0018.
> Spine of the sprint: **additive / raise-only, enforced server-side.** The hardcoded baseline is the floor; the admin layer can only ratchet caution **up**; the engine always applies `stricter_of(baseline, admin)`; a below-floor value is **rejected with a clear message** (never clamped); the `GuardrailService` patterns stay in code and are **not** editable from the UI.

---

## 0. Scope of this turn

Inspect the real substrate and produce this plan. Write **no** guardrail-config code. The only file created/modified this turn is this `plan.md`. After writing it, stop for review.

---

## 1. What exists today (reality check)

The guardrail-relevant substrate is **entirely code + named config today — there is no guardrail data anywhere.** Confirmed: a workspace search for `guardrail` / `tone` / `blocked_topic` in `hr-backend` returns only `ChatService`, `RouterService`, `GuardrailService`, `config/hr.php`, and `MessageTrace` (trace echoes) — **no migration, no table, no model.** This sprint is the *first* to make guardrails data.

### 1.1 The floor constants — `hr-backend/config/hr.php`

Both floors are named config, conservative, and explicitly flagged Sprint-6-exposable / additive-only:

```15:43:hr-backend/config/hr.php
return [

    // Check A (load-bearing): minimum cosine similarity for the top eligible
    // chunk ...
    'retrieval_score_floor' => (float) env('HR_RETRIEVAL_SCORE_FLOOR', 0.40),

    // The answer-confidence floor. NOTE (Sprint 2b-1 design): ... used ONLY as a
    // tiebreaker; it can never, on its own, pass an answer that A/B did not
    // already support.
    'answer_confidence_floor' => (float) env('HR_ANSWER_CONFIDENCE_FLOOR', 0.65),

    'session_window_hours' => (int) env('HR_SESSION_WINDOW_HOURS', 24),

    // Router confidence floor ... A confident off_domain still escalates ...
    'router_confidence_floor' => (float) env('HR_ROUTER_CONFIDENCE_FLOOR', 0.50),
```

Real default values: **`retrieval_score_floor = 0.40`**, **`answer_confidence_floor = 0.65`**, `router_confidence_floor = 0.50` (a candidate additive knob too). The header comment already states the contract: *"the Sprint-6 guardrails UI will let an admin RAISE these but NEVER lower them below the hardcoded defaults here ... Treat the values below as the floor of the floor."*

### 1.2 Where the floors are read in the answer-or-escalate decision — `ChatService`

All reads are `config('hr.…')` (never inline literals), so there is one well-defined set of read-points to retarget:

- **Check A (load-bearing, pre-synthesis)** — `answerProse()`:

```277:278:hr-backend/app/Services/ChatService.php
        $retrievalFloor = (float) config('hr.retrieval_score_floor');
        $confidenceFloor = (float) config('hr.answer_confidence_floor');
```

```327:340:hr-backend/app/Services/ChatService.php
        // --- Check A: pre-synthesis floor ---------------------------------------
        if ($topScore < $retrievalFloor) {
            unset($decryptedKey);
            $trace['floor_decision'] = [
                'retrieval_score_floor' => $retrievalFloor,
                ...
                'escalation_reason' => 'low_confidence',
```

- **Check C (tiebreaker only, never a primary gate)** — recorded, not gated on:

```410:410:hr-backend/app/Services/ChatService.php
        $confidenceBelowFloor = $confidence < $confidenceFloor;
```

```460:462:hr-backend/app/Services/ChatService.php
            'check_c_confidence_tiebreaker' => ['confidence' => $confidence, 'below_floor' => $confidenceBelowFloor, 'used_as_gate' => false],
```

- The floors are also echoed into the trace on the guardrail-fired and off-domain branches (lines 152-156, 189-195) and into `retrieveUnion()` (lines 505-506 read `retrieval_pool_k` / `retrieval_national_law_k`). Check B (citations) is computed in `hr-backend` from the synthesis envelope and has **no** config floor:

```400:409:hr-backend/app/Services/ChatService.php
        // Check B (load-bearing): citations present AND every cited chunk_id was in
        // the provided set (reject hallucinated citations).
        $validCitations = [];
        foreach (($synth['citations'] ?? []) as $cit) {
            $cid = isset($cit['chunk_id']) ? (int) $cit['chunk_id'] : null;
            if ($cid !== null && in_array($cid, $providedChunkIds, true)) {
                $validCitations[] = $cit;
            }
        }
        $checkB = count($validCitations) >= 1 && $answer !== '';
```

### 1.3 The hardcoded baseline — `GuardrailService`

Deterministic, fires as **step 2 of the pipeline, before the router and before any `hr-ai` call** (so a firing question never leaves our servers). Three pattern families, each a `private const` array of regexes; the class doc explicitly says *"Admins CANNOT weaken this baseline (the config UI is Sprint 6, additive-only)"* and *"Do not narrow these patterns."*

```30:37:hr-backend/app/Services/GuardrailService.php
    private const SENSITIVE_PATTERNS = [
        // Harassment / acoso
        '/\b(acoso|acosad|hostigamiento|intimidaci[oó]n|abuso|mobbing|denunci(a|ar|o))\b/iu' => 'sensitive_topic',
        // Mental health
        '/\b(salud mental|ansiedad|depresi[oó]n|burnout|estr[eé]s severo|baja psicol[oó]gica|psic[oó]log|psiquiatr)\b/iu' => 'sensitive_topic',
        // Disciplinary / termination
        '/\b(expediente disciplinario|sanci[oó]n disciplinaria|despido|despid(e|i|o)|cese|carta de despido|procedimiento disciplinario|finiquito)\b/iu' => 'sensitive_topic',
    ];
```

- `LEGAL_MEDICAL_PATTERNS` (lines 45-48) → escalates `off_domain` (rule `legal_medical`).
- `OTHER_EMPLOYEE_PATTERNS` (lines 57-65) → escalates `off_domain` (rule `other_employee_data`); the "¿cuánto gana Pedro?" privacy probe, case-sensitive on the trailing proper noun.

`check(string $question): array{fired, reason, rule}` runs the three families in order (lines 82-107). It is invoked once, unconditionally, at the top of the turn:

```148:160:hr-backend/app/Services/ChatService.php
        // --- Step 2: guardrail baseline (BEFORE the router and any hr-ai call) ---
        $guard = $this->guardrail->check($question);
        if ($guard['fired']) {
            $trace['guardrail_check'] = $guard;
            ...
            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', $guard['reason']);
        }
```

This call-site (line 149) is the **union point** for the admin blocked-topics knob (§3.2): the baseline runs first and unconditionally; the admin layer is a *second* deterministic check immediately after.

### 1.4 The off-domain path — `RouterService`

Off-domain is decided two ways, both already escalate (never answer):

1. The hardcoded `LEGAL_MEDICAL` / `OTHER_EMPLOYEE` baseline → `off_domain` *before* the router (above).
2. The LLM router classifies `salary | prose | off_domain`; a confident `off_domain` escalates, the uncertain middle fails safe to prose:

```187:198:hr-backend/app/Services/ChatService.php
        // --- Step 4c: off-domain → escalate -------------------------------------
        if ($decision['label'] === RouterService::OFF_DOMAIN) {
            unset($decryptedKey);
            $trace['floor_decision'] = [
                ...
                'escalation_reason' => 'off_domain',
                'note' => 'router classified off_domain',
            ];
            return $this->persistTurn($session, $employee, $question, self::ESCALATION_MESSAGE, [], $trace, 'escalate', 'off_domain');
        }
```

The deterministic salary pre-classifier lives in `RouterService` (`SALARY_PATTERNS`, lines 47-52) and **routes**, never escalates. The off-domain refusal text surfaced to the employee is the generic `ChatService::ESCALATION_MESSAGE` (lines 43-44) — there is **no** dedicated off-domain wording today (the admin "off-domain message" knob is new copy, §3.3).

### 1.5 Where `/synthesise` is assembled — the tone injection point

`hr-backend` does **not** assemble a synthesis prompt today; it sends `question` + `chunks` + key + provider config to hr-ai, which builds the prompt:

```376:376:hr-backend/app/Services/ChatService.php
        $synth = $this->ai->synthesise($question, $synthesisChunks, $decryptedKey, $providerConfig);
```

```166:178:hr-backend/app/Services/ExtractionClient.php
    public function synthesise(string $question, array $chunks, string $decryptedKey, array $providerConfig): array
    {
        $response = Http::withHeaders(['X-Internal-Token' => $this->token()])
            ->timeout(120)
            ->acceptJson()
            ->post("{$this->base()}/synthesise", [
                'question' => $question,
                'chunks' => $chunks,
                'provider_api_key' => $decryptedKey,
                'provider_config' => $providerConfig,
            ]);
```

The real prompt is a module constant in hr-ai (`SYSTEM_PROMPT`, `hr-ai/app/providers/claude.py:42-99`) plus `_build_user_prompt(question, chunks)` (lines 110-128). The `/synthesise` request body (`SynthesiseRequest`, `hr-ai/app/main.py:103-109`) has **no tone field**. Because the spec freezes hr-ai (no new endpoint, no migration, "unchanged"), the **only** zero-hr-ai-change injection point is the string `hr-backend` already controls: the `question` argument at `ChatService.php:376`. The recommended mechanism (a synthesis-only, style-delimited preamble) and why it cannot bypass a gate are in §3.4.

> Crucial detail for the wiring: the same `$question` is reused for `/ground` (`ChatService.php:441`) and for the router. Tone must therefore be injected into a **synthesis-only local variable**, never into `$question` itself, so the grounding gate and router still see the raw question.

### 1.6 Convert-by-reason today — `EscalationService::resolve`

The flywheel convert path is **unconditional on `reason`** today — any card may be converted (subject only to the scope-confirm 409 and the no-override fence):

```127:139:hr-backend/app/Services/EscalationService.php
    public function resolve(EscalationCard $card, Admin $actor, string $resolutionText, bool $convert, ?int $topicId): array
    {
        if (! $convert) {
            ...
        }
        // --- Convert → Save as knowledge ---------------------------------------
        $card->loadMissing('employee.convenio');
```

`architecture.md` §8.3 and `roadmap.md` already park this: *"Whether to restrict `convert` by escalation reason is a Sprint-6 guardrails-policy matter."* The card carries `reason` (the escalation reason enum, e.g. `sensitive_topic`, `low_confidence`, `salary_coverage_gap`, `off_domain`); the gate goes at the top of the convert branch (line ~141), enforced in `EscalationController::resolve` (§3.5).

### 1.7 The patterns we reuse

- **Ability gating** — `EnsureCan` middleware (`ability:<perm>`), spatie permissions seeded both in `RoleSeeder` and in a dated data-migration (`2026_06_25_100002_seed_sprint5_permissions.php`) so fresh + already-migrated DBs both get them. Route groups wrap writes (`routes/api.php:67,82,95,116,139`).
- **Append-only audit writers** — `tag_events` (KC), `escalation_events` (board, `EscalationService::log`), `employee_audit_log` (directory), `conversation_access_log` (history). All are insert-only, one row per change, written inside the same `DB::transaction`. Sprint 6 mirrors this with a `guardrail_config_events` writer.
- **Single-row typed config precedent** — `AnswerModelSetting` (`id=1`, `static current()`, `firstOrCreate`), surfaced by a super-admin-only `AnswerModelController` and the `AnswerModelPage` settings screen. This is the template for `guardrail_config`.
- **Reject-with-409/422 + clear message** — Sprint-3/4 `confirm_scope_change` (409) and `illegal_transition` (422) show the established "server rejects, returns a `code` + Spanish `message`, UI reads it" pattern.

---

## 2. The config store

### 2.1 Shape — recommendation: a typed single-row config + one child list table

**Recommended: a typed, single-row `guardrail_config` table (`id = 1`) for the scalar/typed knobs, plus a child `guardrail_blocked_topics` table for the add-only list, plus an append-only `guardrail_config_events` audit table.** Rationale:

- A **typed single row** (not a free-form key/value `settings` table) because every knob has a **specific type and a hardcoded floor it validates against**; a key/value bag would push types + per-field floor validation into stringly-typed glue and lose the compiler/cast guarantees. It directly mirrors the proven `AnswerModelSetting::current()` precedent (`id=1`, `firstOrCreate`), so the read/caching/test patterns are already established.
- **Global, no per-scope** (spec decision) — a single row *is* the global config. (Per-convenio would be a child-scoped table; explicitly out of scope.)
- The **blocked-topics list is a child table**, one row per admin-added pattern, because the list grows item-by-item and each addition wants its own audit row and its own enable/disable without rewriting a JSON blob; the union read is a trivial `where enabled` select. (Alternative: a `json` column on the single row — rejected: weaker per-item audit, and "add-only" is clearer as insert-only rows.)

`guardrail_config` columns (all admin-overridable knobs are **nullable**; `null` = "use the hardcoded floor", a non-null value is an admin *override* that must pass the floor check):

| column | type | meaning / floor |
|---|---|---|
| `id` | PK (always 1) | single row |
| `retrieval_score_floor` | `decimal(4,3)` null | admin Check-A floor; must be `>= config('hr.retrieval_score_floor')` |
| `answer_confidence_floor` | `decimal(4,3)` null | admin Check-C tiebreaker floor; `>= config('hr.answer_confidence_floor')` |
| `router_confidence_floor` | `decimal(4,3)` null | optional; `>= config('hr.router_confidence_floor')` |
| `off_domain_message` | `text` null | the refusal copy ("Solo puedo ayudarte con temas de RR. HH…"); null = default |
| `tone_constraints` | `text` null | style guidance injected into synthesis (length-capped, sanitized) |
| `convert_allowed_reasons` | `jsonb` null | allow-set for convert-by-reason; intersected with the hardcoded allow-set |
| `updated_by` | FK `admins` null | last writer |
| `updated_at` | timestamp | |

`guardrail_blocked_topics`: `id`, `pattern` (the admin keyword/phrase — matched as a normalized, escaped literal, **not** raw regex from the UI), `kind` (`blocked_topic` → escalate `sensitive_topic`, or `off_domain` → escalate `off_domain`, supporting both knob 2 and the "narrow off-domain" half of knob 3), `enabled` bool, `created_by`, `created_at`, `disabled_by`/`disabled_at` (disable = soft, never a hard delete, so history is intact and "can't subtract from baseline" is structurally true — there is nothing baseline in this table to subtract).

`guardrail_config_events` (append-only audit, mirrors `escalation_events`): `id`, `field` (e.g. `retrieval_score_floor`, `blocked_topic`, `tone_constraints`, `convert_allowed_reasons`, `off_domain_message`), `old_value` (text/json), `new_value`, `actor_id` FK `admins`, `note`, `created_at`. Never UPDATE/DELETE-d.

### 2.2 Reading it cheaply in the hot answer loop

The answer loop is hot; a per-turn DB query for config is unacceptable. Introduce a small **`GuardrailPolicy`** read-model (a service, resolved from the container) that loads the single row + enabled blocked-topics **once per process and caches it**, busting on write:

- `Cache::remember('guardrail_config', $ttl, fn () => GuardrailConfig::current()->load('blockedTopics'))` (short TTL, e.g. 60s, as a safety net) **and** an explicit `Cache::forget('guardrail_config')` in the audited write path so a change takes effect on the next turn. This matches the cheapness of the current `config('hr.…')` reads.
- `GuardrailPolicy` exposes the **already-combined** values so `ChatService` never sees a raw admin value: `retrievalFloor(): float` returns `max(config('hr.retrieval_score_floor'), $admin ?? -INF)`, `confidenceFloor(): float` likewise, `blockedTopicMatch(string $q): ?string`, `offDomainMatch(string $q): bool`, `offDomainMessage(): string`, `toneConstraints(): ?string`, `convertAllowedReasons(): array` (already intersected with the baseline allow-set). The `stricter_of` math lives **inside** the policy, so the call-sites can't accidentally use a weaker value.

### 2.3 The audited write path + the `guardrails.manage` ability

- A new spatie permission **`guardrails.manage`**, granted to **`super_admin` only** (consistent with `admin.manage` being super-admin-only for the most safety-sensitive surface; the answer-model key is also super-admin-only). `auditor` gets **read** access (no write) to match its oversight role; `hr_agent` / `knowledge_editor` get neither. Seeded in **both** `RoleSeeder` (fresh installs) and a dated data-migration (already-migrated DBs), idempotent, exactly like the Sprint-5 permission seeding.

  > Open question flagged in §7: spec text says "`super_admin` + the role you choose". Recommendation is **super_admin-only writes** (safest); if the reviewer wants `hr_agent` to tune caution, it is a one-line grant. `auditor` read-only is fixed.

- Every write goes through a `GuardrailConfigService` (hr-backend owns all writes, ADR-0007) that, in one `DB::transaction`: validates against the floor (reject, §4), writes the typed row / inserts the blocked-topic / updates the allow-set, appends a `guardrail_config_events` row (old→new, actor), and `Cache::forget`s the policy key. No write touches `GuardrailService` or `config/hr.php` — those stay code.

---

## 3. The five knobs + exactly where the engine applies `stricter_of(baseline, admin)`

For every knob the baseline is read first / runs first and the admin layer only ratchets up. The combination math lives in `GuardrailPolicy`; the `ChatService` / `EscalationService` edits are minimal retargets, not a decision rewrite (2b frozen).

### 3.1 Thresholds — raise-only (`max`)

- **Check A** at `ChatService.php:277` becomes `$retrievalFloor = $this->policy->retrievalFloor();` where `retrievalFloor()` returns `max(config('hr.retrieval_score_floor'), $admin)`. The comparison at line 328 (`$topScore < $retrievalFloor`) is unchanged — only the value rises. A raised floor → more turns fall below → more escalation (safer). Same retarget for the trace echoes (lines 153, 190).
- **Check C** at `ChatService.php:278` becomes `$this->policy->confidenceFloor()` = `max(config('hr.answer_confidence_floor'), $admin)`. It stays a **tiebreaker, not a gate** (line 462 `used_as_gate => false` is unchanged); the UI says so honestly. Raising it tightens the recorded signal; Check A/B remain the real gates.
- **`max` point:** inside `GuardrailPolicy::retrievalFloor()/confidenceFloor()`. The baseline `config('hr.…')` is the floor of the `max`, so the engine **can never read a value below it**. (Optional `router_confidence_floor` follows the identical pattern at `RouterService.php:135`.)

### 3.2 Blocked topics — add-only (`union`)

- **Union point:** immediately after the baseline guardrail at `ChatService.php:149-160`. The baseline `$this->guardrail->check()` runs first, unconditionally. If it does **not** fire, a second deterministic check runs: `if ($hit = $this->policy->blockedTopicMatch($question)) { …escalate… }`. Conceptually `escalate iff baseline.fired ∨ admin.matched` — a pure union; the admin list can only **add** escalations, never suppress a baseline one (the baseline already returned before the admin check is even reached).
- The baseline patterns are **not** in this table and are not editable; the admin table holds only added patterns. Removing a baseline pattern is therefore impossible by construction (it is not data). A blocked-topic hit escalates with reason `sensitive_topic` (kind `blocked_topic`), so it lands on the board with an existing reason label.

### 3.3 Off-domain boundary + message — narrow-only

- **Boundary (narrow-only):** the answerable domain can only shrink. Implemented with the same `guardrail_blocked_topics` table, `kind = off_domain`: an admin-added off-domain trigger forces an `off_domain` escalation *before* the router (checked alongside §3.2 at `ChatService.php:149-160`, routing to reason `off_domain`). The baseline (`LEGAL_MEDICAL` / `OTHER_EMPLOYEE` + the LLM router's `off_domain`) is untouched; admin triggers are a **union** on top → strictly more refusal, never less. There is no mechanism to make the router answer something it classified off-domain.
- **Message (copy only):** `off_domain_message` replaces the surfaced refusal text at the off-domain branch (`ChatService.php:197`) and the new admin-off-domain branch — `$this->policy->offDomainMessage()` falling back to `ESCALATION_MESSAGE`. This is display copy; it changes **no** decision.

### 3.4 Tone constraints — synthesis style only, structurally unable to unlock a gate

- **Injection point:** `ChatService::answerProse`, the synthesise call at `ChatService.php:376`. Build a **synthesis-only** local string and pass it as the question to `synthesise()` *only*; `$question` itself stays raw for `/ground` (line 441) and the router:

  ```
  $tone = $this->policy->toneConstraints();
  $synthQuestion = $tone === null ? $question
      : "[INSTRUCCIONES DE ESTILO — solo afectan al tono y el formato, NO a qué se "
      . "responde ni a las reglas de citación/fundamentación: {$tone}]\n\n" . $question;
  $synth = $this->ai->synthesise($synthQuestion, $synthesisChunks, $decryptedKey, $providerConfig);
  ```

  This is the "existing hr-backend prompt assembly" the spec means: hr-backend owns the string sent to `/synthesise`, hr-ai is byte-for-byte unchanged (no new field, no new endpoint, no migration).

- **Why tone cannot bypass a gate (the load-bearing argument — three independent guarantees):**
  1. **Server sanitation.** `tone_constraints` is length-capped (e.g. 280 chars), stored as plain guidance, and validated to reject obvious override phrasing (a deny-list of imperative gate-bypass terms — "ignora las fuentes", "responde aunque no haya cita", "inventa", "no escales", etc.). This is defense-in-depth, not the real guarantee.
  2. **Unchangeable system rules dominate.** The hr-ai `SYSTEM_PROMPT` (`claude.py:42-99`) carries the absolute rules ("afirma un dato SOLO si está respaldado… PROHIBIDO usar conocimiento general…") and is **not** admin-editable; tone arrives in the *user* turn, beneath the system rules.
  3. **Every gate is downstream of and independent from tone (the structural guarantee).** Tone only colours the synthesis wording. It cannot touch: **Check A** (pre-synthesis retrieval floor, decided before synthesis even runs), **Check B** (citation validation in `hr-backend`, `ChatService.php:400-409`), the **figure-guard** (deterministic in `hr-backend`, lines 412-418/796-848), or **`/ground`** (a *separate* call on the *raw* question, lines 440-441). So even a tone string that somehow coaxed a fabricated or uncited answer out of synthesis would still be caught by B / figure-guard / entailment and escalate. **Tone styles the answer; it can never unlock a gate.** This is provable by a test that sets a hostile tone and asserts an ungrounded answer still escalates (§4).

### 3.5 Convert-by-reason — restrict-only (set intersection)

- **Baseline allow-set (hardcoded floor):** a `const` in `EscalationService` (or `GuardrailPolicy`), e.g. `CONVERTIBLE_REASONS_BASELINE = ['low_confidence', 'salary_coverage_gap', 'off_domain', 'explicit_request']`, with **`sensitive_topic` never convertible** (the floor — admins cannot enable it).
- **Application point:** the top of the convert branch in `EscalationService::resolve` (`EscalationService.php:141`, just after the `if (! $convert)` early return): `if (! in_array($card->reason, $this->policy->convertAllowedReasons(), true)) { return ['outcome' => 'convert_blocked', …]; }`, surfaced as a 409 with a clear `code`/`message` in `EscalationController::resolve` (mirrors the publish-block 409 at `EscalationController.php:240-247`).
- **`stricter` point (intersection):** `convertAllowedReasons()` returns `array_intersect(CONVERTIBLE_REASONS_BASELINE, $admin_allow_set ?? CONVERTIBLE_REASONS_BASELINE)`. Admin can only **remove** reasons from the baseline allow-set; adding `sensitive_topic` is a no-op because it is not in the baseline set to begin with. So the engine never allows a conversion the baseline forbids.

---

## 4. The raise-only invariant — server enforcement

The server is the boundary; the UI only shows the floor. Enforcement is centralized so it cannot be bypassed by hitting the API directly.

### 4.1 Reject below-floor (never clamp), with a clear message

A `StoreGuardrailConfigRequest` (FormRequest) + the `GuardrailConfigService` validate **before** any write:

- For each threshold, if the submitted value `< config('hr.<floor>')` → **422** with a `code` (`threshold_below_floor`) and a clear Spanish `message` naming the field and the floor, e.g.: *"El umbral de recuperación no puede ser inferior al mínimo de seguridad (0.40). Solo puede subirse."* The value is **rejected, not clamped** — the admin sees they hit the floor. No write, no audit row (nothing changed).
- A `null` submission is accepted (it means "revert to the floor"), and a value `>= floor` is accepted and audited.
- Floor checks read `config('hr.…')` live, so they always track the true hardcoded floor (and any future raise of the default).

### 4.2 Impossibility of removing a baseline pattern

- The `GuardrailService` patterns are `private const` arrays in code — there is **no** endpoint, table, or field that references them, so the API surface offers no way to read, edit, or delete them. The blocked-topics table holds **only admin-added** rows; "disable" is a soft flag on an admin row and can never reach a baseline pattern.
- `convert_allowed_reasons` is intersected with the hardcoded baseline set, and `sensitive_topic` is not in that set — so it can never be made convertible regardless of payload.

### 4.3 `stricter_of` applied always

`GuardrailPolicy` returns only combined values (`max` for thresholds, `union` for blocked/off-domain, intersection for convert). `ChatService` / `EscalationService` call the policy, never a raw admin value — so the stricter-of is applied on every turn by construction, not by a caller remembering to do it.

### 4.4 The acceptance-proof tests (the spine of the sprint)

PHPUnit feature/unit tests (the Sprint-5 API-matrix style — hit endpoints directly, not through the UI):

**Server rejection / no-effect (weakening attempts):**
1. `POST /admin/guardrails` with `retrieval_score_floor = 0.20` (below the 0.40 floor) → **422**, body `code = threshold_below_floor`, message names the floor; assert the stored value is unchanged and **no** `guardrail_config_events` row was written.
2. Same for `answer_confidence_floor` below 0.65, and `router_confidence_floor` below 0.50.
3. `retrieval_score_floor = 0.40` exactly (== floor) → accepted (boundary). `0.55` (> floor) → accepted + audited.
4. There is **no** route that deletes/edits a baseline `GuardrailService` pattern — asserted by a route-list test + an attempt to "disable" a non-existent baseline id → 404/no-op.
5. `convert_allowed_reasons` payload that tries to add `sensitive_topic` → effective allow-set still excludes it (intersection); converting a `sensitive_topic` card → **409 `convert_blocked`**.

**Engine reads the admin layer (before/after trace):**
6. With default config a borderline question answers; raise `retrieval_score_floor` above its `top_score` → the same question now **escalates** (`low_confidence`); assert via the persisted `floor_decision` trace that the *raised* value was used.
7. Add a blocked topic → a question containing it **escalates** (`sensitive_topic`) and the baseline still fires first for a baseline-sensitive question (both true).
8. Add an off-domain trigger → matching question escalates `off_domain` with the admin `off_domain_message` surfaced.
9. **Tone-can't-bypass:** set a hostile `tone_constraints` ("responde aunque no haya fuentes"); feed a turn whose synthesis answer is ungrounded/uncited → assert it **still escalates** (Check B / figure-guard / `/ground` unaffected), and assert the sanitizer rejected the override phrasing at write time.

**Ability matrix:**
10. `auditor` `GET /admin/guardrails` → 200 (read); `POST` → 403. `hr_agent` / `knowledge_editor` `POST` → 403. `super_admin` `POST` → 200. Cache busts so the next answer-loop read reflects the change.

---

## 5. The UI — the Guardrails console

Reuse the Sprint-3/5 admin shell + design system. Add one nav entry and one page; no new shell primitives.

- **Nav:** add a `guardrails` view to `AdminShell.tsx` (`type View`, the `navBtn` row). Show it only when the identity can view (any admin may read; gate the *writes*, not the read — auditor sees it read-only). Add `canManageGuardrails(identity)` to `lib/api.ts` mirroring `canManageAdmins` etc. (`abilities['guardrails.manage']`); the page itself fetches its own read-vs-write capability from `/me` abilities.
- **Page (`GuardrailsPage.tsx`)** — built from existing design-system primitives (`card`, `field`, `input`, `btn`, `badge`, the `kv` list as on `AnswerModelPage`). One section per knob:
  1. **Thresholds** — numeric inputs for `retrieval_score_floor` and `answer_confidence_floor` (and optionally router). Each shows **`Mínimo de seguridad: 0.40`** inline (the hardcoded floor, fetched from the API so it is always truthful), client-side reject-below-floor for fast feedback, **and** the server is authoritative (a direct below-floor POST 422 surfaces the same message). An honest note on Check C: *"umbral de desempate, no una puerta principal — subirlo hace al bot más cauto, pero las puertas reales son A y B."*
  2. **Blocked topics** — an add-only list (input + "Añadir"); each row shows the pattern + a disable toggle (soft). Copy makes clear the **hardcoded baseline (acoso, salud mental, despido…) is always active and not shown/editable here** — this list only *adds*.
  3. **Off-domain** — the boundary triggers (shared list, off-domain kind) + a textarea for the refusal `off_domain_message` (with the default shown as placeholder).
  4. **Tone constraints** — a length-capped textarea with helper text: *"Solo estilo y formato (p. ej. «trato de usted, conciso»). No puede cambiar qué se responde ni saltarse las comprobaciones de fundamentación."* Server rejects override phrasing with a clear message.
  5. **Convert-by-reason** — checkboxes for the baseline-convertible reasons; `sensitive_topic` shown **disabled/locked** with a note that it can never be converted.
- **Change history** — a read panel listing `guardrail_config_events` (field, old→new, actor, when), exactly like the escalation activity log / KC timeline. Visible to auditor.
- **Gating** — writes call `guardrails.manage`-gated endpoints; a non-privileged admin sees the values **read-only** (inputs disabled, no save) and a 403 from the server if they force a write. `auditor` is read-only by design.

---

## 6. Migrations & build order

### 6.1 Additive migrations (hr-backend; ADR-0007; additive-only)

1. `…_create_guardrail_config_table` — single typed row (`id=1`), the nullable threshold/message/tone/convert columns, `updated_by`.
2. `…_create_guardrail_blocked_topics_table` — `pattern`, `kind` (`blocked_topic`|`off_domain`), `enabled`, `created_by`, `disabled_by`/`disabled_at`.
3. `…_create_guardrail_config_events_table` — append-only audit (mirror `escalation_events`).
4. `…_seed_guardrails_manage_permission` — dated data-migration: `Permission::findOrCreate('guardrails.manage')`, grant to `super_admin`; idempotent; **and** update `RoleSeeder` in lockstep (fresh installs).

No CHECK-constraint enum change is required (convert reasons live in JSON config, not the `escalation_cards.reason` enum). No `config/hr.php` change (the floors stay as the floor-of-the-floor). **No hr-ai migration, no new hr-ai endpoint** (tone rides the existing `/synthesise` question string).

### 6.2 Build order

**hr-backend first (the boundary):**
1. Migrations 1-4 above.
2. Models: `GuardrailConfig` (single-row, `static current()`), `GuardrailBlockedTopic`, `GuardrailConfigEvent`.
3. `GuardrailPolicy` read-model (cached, returns combined `stricter_of` values) + `GuardrailConfigService` (validated, audited, cache-busting writes).
4. Wire read-points: `ChatService.php:277-278` (thresholds via policy), `:149-160` (blocked-topic + off-domain union), `:197` (off-domain message), `:376` (synthesis-only tone preamble); `EscalationService.php:141` + `EscalationController::resolve` (convert-by-reason 409). `RouterService.php:135` optional router floor.
5. `GuardrailsController` (read open to admins, writes `ability:guardrails.manage`) + routes in the `admin` group (`routes/api.php`), + `StoreGuardrailConfigRequest` (floor validation).
6. The acceptance-proof tests (§4.4).

**hr-frontend after:**
7. `lib/api.ts` types + calls + `canManageGuardrails`; `AdminShell.tsx` nav; `GuardrailsPage.tsx`.

**docs (at DoD, not this turn):** `architecture.md` §7 (the now-built admin layer + stricter-of + reject-below-floor + tone-can't-bypass + `guardrails.manage`), `data-model.md` (the three tables + per-field floors), `roadmap.md` (Sprint 6 done), the new ADR (§6.3), `review.md` (with the invariant tests as proof).

### 6.3 New ADR — warranted (recommend **ADR-0019**)

Yes — a new ADR is warranted, analogous to ADR-0018 for access control. **ADR-0019: "Raise-only guardrail configuration (additive safety config)."** It records the load-bearing decisions: the hardcoded baseline stays the floor; the admin layer is data that can only tighten; the engine applies `stricter_of(baseline, admin)` always; below-floor values are **rejected, not clamped**; tone is synthesis-style-only and structurally cannot bypass a gate; convert-by-reason is restrict-only with `sensitive_topic` never convertible; global-only (no per-scope). Cross-references ADR-0002 (guardrail vocabulary), ADR-0007 (hr-backend owns writes), ADR-0015 (the answer model + key path), ADR-0018 (the server-is-the-boundary, ability-gated, audited pattern this reuses).

---

## 7. Assumptions & open questions

1. **Config-store shape (recommended, confirm).** Typed single-row `guardrail_config` + child `guardrail_blocked_topics` + append-only `guardrail_config_events`, rather than one key/value `settings` bag. Mirrors `AnswerModelSetting`; gives per-field types + per-field floor validation. Confirm before building.
2. **`guardrails.manage` grant (confirm role set).** Recommendation: **super_admin-only writes**, **auditor read-only**, others none — matching `admin.manage` / answer-model being super-admin-only for the most safety-sensitive surface. Spec says "super_admin + the role you choose"; if `hr_agent` should tune caution it's a one-line grant. Auditor read-only is fixed.
3. **Tone injection mechanism (the constraint tension — needs a ruling).** The spec freezes hr-ai ("unchanged, no new endpoint, no migration"), but the real `/synthesise` body has **no tone field** and the prompt is built inside hr-ai (`claude.py`). Two options:
   - **(Recommended, zero hr-ai change):** hr-backend prepends a clearly-delimited, sanitized, **style-only** preamble to the *synthesis-only* question string at `ChatService.php:376` (raw `$question` preserved for `/ground` + router). Honors "hr-ai unchanged" literally.
   - **(Cleaner separation, but breaks the constraint):** add an optional `tone_guidance` field to `SynthesiseRequest` + append it in `_build_user_prompt`. This is a small, additive, backward-compatible hr-ai code change — but it *is* an hr-ai change, which the spec forbids this sprint. Flagged for the reviewer to choose; the plan assumes option 1 unless told otherwise.
   - Either way, the **tone-can't-bypass guarantee is structural** (gates are downstream/independent, §3.4), not dependent on which option is picked.
4. **Convert-by-reason location.** Recommendation: the **allow-set lives in `guardrail_config` (JSON)**, read via `GuardrailPolicy`, enforced in `EscalationService::resolve` / `EscalationController::resolve` — *beside* the escalation model, not inside it. Rationale: it is a guardrails *policy*, co-located with the other knobs and audited the same way; the escalation flow only *reads* it. Alternative (a column on a board-config table) rejected as scattering the policy. The hardcoded baseline allow-set + `sensitive_topic`-never-convertible stays a code `const` (the floor).
5. **Off-domain "narrow-only" via the same table.** Assumption: the off-domain knob reuses `guardrail_blocked_topics` with `kind = off_domain` (a triggers list) + the `off_domain_message` copy field — rather than a separate table. Both are pure unions on top of the baseline. Confirm this consolidation is acceptable.
6. **Blocked-topic matching safety.** Admin input is matched as a **normalized, escaped literal / word-boundary** match (accent-insensitive, lowercased — reuse the `stripAccents` posture already in `ChatService`), **not** as raw admin-supplied regex (a raw-regex field would be an injection + ReDoS risk and could be authored to *under*-match). Confirm literal-match semantics.
7. **`stricter_of` wiring non-obvious spots in the real substrate:**
   - Check C is a *tiebreaker, not a gate* (`used_as_gate => false`), so "raising the confidence floor" tightens the recorded signal but **does not** by itself escalate more — the UI must say this honestly (it is in §5/§3.1). Raising Check A is the threshold that actually changes outcomes.
   - The baseline guardrail returns *before* the router; the admin blocked-topic/off-domain check must sit at the **same pre-router point** (`ChatService.php:149-160`) so an admin-blocked question also never reaches hr-ai — preserving the privacy guarantee. Putting it after the router would leak a blocked question to the provider.
   - Tone must **not** be injected into `$question` (shared with `/ground` + router) — only into a synthesis-local string (§1.5/§3.4). This is the single easiest wiring mistake and is called out explicitly.
   - Cache invalidation: a config change must `Cache::forget` so the next turn reads it; the before/after trace test (§4.4 #6) is what proves the bust works.
8. **`router_confidence_floor` as a knob — include?** It fits the additive pattern (raise = more fail-safe-to-prose). Recommendation: expose it as an optional fourth threshold but mark it secondary; defer if the reviewer wants the five named knobs only.
9. **No analytics / no per-scope / no LLM tagging** — explicitly out of scope (Sprints 7-9). Global config only.

---

## 8. Hard-constraint compliance check

- **Additive / raise-only, server-enforced** — `GuardrailPolicy` returns only `stricter_of` values; below-floor POSTs are **rejected (422), not clamped**; `GuardrailService` patterns stay code, not editable. ✓
- **No answer-loop rewrite** — Check A/B/C + guardrail/router decision logic is unchanged; Sprint 6 only retargets the *values* read and adds a pre-router union check + a synthesis-only tone string + a convert-reason gate. 2b frozen. ✓
- **hr-ai unchanged** — tone rides the existing `/synthesise` question string; no new endpoint, no hr-ai migration (option 1, §7.3). ✓
- **hr-backend owns writes + schema; additive migrations only; every change audited; writes `guardrails.manage`-gated; auditor read-only; server is the boundary** — §2.3, §4, §6.1. ✓
- **Reuse design system + admin shell + `EnsureCan` + audit-writer patterns** — §5, §1.7. ✓

---

**This plan is ready for review.** No guardrail-config code was written; the only file touched this turn is `hr-docs/sprints/sprint-06/plan.md`. On approval, build in the §6.2 order, starting with the hr-backend store + `GuardrailPolicy` + the §4.4 invariant tests.
