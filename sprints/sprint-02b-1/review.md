# Sprint 2b-1 — Review (the narrow answer vertical)

> Status: **built; pending the provider-dependent eyes-on (real EU Claude key) + commit.**
> Read with `plan.md` (this sprint), `architecture.md` §2/§5/§7, `data-model.md` §8/§9,
> ADR-0007/0010/0012/0013/0015.

This slice is the single prose answer path — scope resolve → guardrail baseline →
`/retrieve` → pre-synthesis floor → `/synthesise` → answer-or-escalate → persist.
No router, no salary-in-chat, no full grounding model, no escalation board, no
guardrails-config UI, no history-search/role-scope enforcement (all 2b-2 / later).

---

## 1. What was built (Phase 1 → 2 → 3)

**Phase 1 — `hr-ai` synthesis (ADR-0015).**
- `app/providers/` — a pluggable `AnswerProvider` port (`base.py`) + `ClaudeProvider`
  (`claude.py`, default) + `get_provider()` registry. The API key is a **per-call
  argument**, never an instance field — it cannot outlive the one call.
- `POST /synthesise` (`main.py`) — composes a cited answer grounded only in the
  passed chunks. The **precedence rule is encoded in the prompt** (`SYSTEM_PROMPT`):
  the convenio governs the topics it addresses; `national_law` (the Estatuto) is the
  gap-filling baseline; on genuine conflict surface the convenio as governing or
  escalate — never blend, never silently present the baseline.
- **Citations are mapped from the provided set only** — a cited index outside the set
  is dropped, so a hallucinated citation can't reach `hr-backend`. **`authority_used`
  is computed deterministically from the cited chunks** (not trusted from the model).
- Non-secret config (`config.py`): `ANSWER_PROVIDER` / `ANSWER_MODEL` /
  `ANSWER_ENDPOINT` (EU-target). `anthropic` added to `requirements.txt` + `pyproject`.

**Phase 2 — `hr-backend` pipeline, decision, writes.**
- `answer_model_settings` migration + `AnswerModelSetting` model: `Crypt`-encrypted
  key at rest, `key_last_four` for masking (reconstructed **without** decrypting),
  `$hidden` ciphertext, `setKey`/`decryptKey`/`clearKey`/`maskedKey`.
- `GuardrailService` — the **hardcoded baseline**, deterministic, fires as step 2
  **before any `hr-ai` call**; conservative sensitive / legal-medical / other-employee
  patterns.
- `ChatService` — the orchestrator: scope resolve → guardrail → `/retrieve` → Check A →
  authority-ordered `/synthesise` (key decrypted just-in-time, passed in body) → A∧B
  decision (C tiebreaker only) → one-transaction persist of session/messages/citations/
  trace (incl. `authority_used`)/escalation card.
- `config/hr.php` — named, conservative, additive-only floor knobs
  (`RETRIEVAL_SCORE_FLOOR = 0.40`, `ANSWER_CONFIDENCE_FLOOR = 0.65`, `SESSION_WINDOW_HOURS = 24`).
- `ExtractionClient::synthesise()`, `ChatController` (`POST /chat/message`, employee-only),
  `Admin\AnswerModelController` (`status` / `store` / `destroy`, super_admin), routes.
- `ChatTestUserSeeder` — 3+1 dev employees bound to real convenios.

**Phase 3 — `hr-frontend` (ADR-0012, tokens only, no new colour literals).**
- `pages/chat/` — `ChatScreen` (non-streaming, "Pensando…" loading state), `CitationList`
  (authority badges + page), `TracePanel` (the expandable "how I got here" as a
  provenance timeline). `EmployeeShell` now renders the chat.
- `pages/admin/AnswerModelPage` — set / mask / rotate / clear; the raw key lives only
  in the controlled input and is cleared on submit. `AdminShell` gains a Settings nav.
- `lib/api.ts` — `sendChatMessage`, `getAnswerModelStatus`, `setAnswerModelKey`,
  `clearAnswerModelKey` + types. Chat/answer-model CSS appended to `index.css`,
  composing existing tokens.

**Build health:** `hr-ai` `py_compile` clean; `hr-backend` `php -l` + `pint` clean;
`hr-frontend` `npm run build` + `eslint` clean (zero new lint errors — the
`AnswerModelPage` fetch-on-mount uses the promise-chain form to avoid the
`set-state-in-effect` finding the two Sprint-1 pages carry).

---

## 2. Eyes-on / stress-test gates

Two gates require the external provider (a real **EU** Claude key + the `hr_ai`
container restarted so `/synthesise` is live and `anthropic` is installed). The
running `hr_ai` container (up 16h, pre-this-slice) still serves the old code —
`POST /synthesise` returns 404 — so **the provider-dependent gates are staged for
Pedram, not yet run.** Everything that does **not** need the provider was run on the
live stack (`hrdev_pg` with the 2a substrate: 3 509 chunks, real convenios) and is
recorded below. Probe rows were cleaned from the dev DB afterwards.

### Gate 1 — Precedence (the gold test) · ⏳ PENDING (needs real key)
**Test:** `test-gipuzkoa@example.com` asks *"¿cuántos días de vacaciones tengo?"* →
expect the convenio's **31 días (Art. 7)**, cited to the convenio, **not** the
Estatuto's 30; trace `authority_used = [official_convenio]`.
**Staged / verified so far:** the question **passes the guardrail** (unit-checked:
`fired=n`), so it reaches retrieval + synthesis; `ChatService::orderByAuthority()`
puts convenio chunks before `national_law` and the payload carries `authority_level`
per chunk; the prompt encodes precedence; `authority_used` is derived from the cited
chunks. **To run:** restart `hr_ai`, set an EU key on the admin screen, ask on the
chat UI, confirm the answer + the trace's `synthesis.authority_used`.

### Gate 2 — Silent-topic fallback · ⏳ PENDING (needs real key)
**Test:** `test-navarra@example.com` (Limpieza Navarra, convenio resolved at seed)
asks about *periodo de prueba* (convenio defers to the Estatuto) → expect a correct
**Estatuto-based** answer, cited to `national_law`, `authority_used = [national_law]`.
**Staged:** Navarra employee seeded (convenio id 22); the precedence prompt explicitly
uses the Estatuto for gaps. **To run:** as Gate 1, on the Navarra profile.

### Gate 3 — Sensitive topic · ✅ VERIFIED (provider never hit)
Run via `ChatService` on the live stack:
| question | escalated | reason | guardrail | `/retrieve` reached? |
|---|---|---|---|---|
| "Estoy sufriendo **acoso** laboral…" | yes | `sensitive_topic` | fired | **no — short-circuited before hr-ai** |
| "¿Cuánto gana **Pedro García**?" (other-employee) | yes | `off_domain` | fired (`other_employee_data`) | no |

The harassment/mental-health/disciplinary message **never reaches `/retrieve` or the
provider** (no `retrieval`/`synthesis` block in the trace) — verified by the absence
of those trace blocks and the short-circuit in `ChatService` before the `hr-ai` calls.
No legal/medical advice path likewise escalates. The explicit *"¿cuánto gana
[Nombre]?"* probe is caught (see §3 for the regex fix).

### Gate 4 — Floor (out-of-scope / salary escalate, no router) · ✅ VERIFIED
| question | check A (top score) | outcome | trace note |
|---|---|---|---|
| "¿Cuál es la **capital de Francia**?" | **false** (0.3615 < 0.40) | escalate `low_confidence` | "eligible chunks but all below retrieval floor" |
| "¿Cuál es el **salario** bruto anual de un técnico?" | true (0.5599) | escalate `low_confidence` | (no key set → "answer model not configured"); with a key, salary figures are not in prose chunks → Check B (citations) carries the escalation |

The trace **distinguishes no-retrieval from weak-retrieval** via `retrieval.eligible_total`
(431 here) + `retrieval.top_score`. Off-domain lands in escalation **without a router**
because Check A fails. (Honest note: salary's safe escalation rides Check A *or* Check B —
its figures live in `salary_tables`, not `document_chunks`, so synthesis grounded in
prose can't cite a figure; salary-in-chat is 2b-2.)

### Gate 5 — Key screen (set → mask → rotate → no leak) · ✅ VERIFIED
`AnswerModelSetting` round-trip on the live app:
- set `sk-ant-TESTKEY-abcd1234` → `configured = Y`, `masked = ••••1234`.
- `decryptKey()` round-trips to the original (used only server-side, just-in-time).
- JSON serialization contains **no** `api_key_encrypted` (the `$hidden` guard holds).
- `clearKey()` → `configured = n`.
- **`grep` of `storage/logs/*.log` for the raw key → 0 matches.** The set/rotate audit
  log records the actor + `key_last_four` only, never the key.

The controller never returns the raw key on any route (status/store/destroy return the
masked value + flags). The browser never holds a stored key; the frontend input clears
on submit.

### Gate 6 — History + trace (every turn auditable) · ✅ VERIFIED
The 5-question probe produced (before cleanup): **1** `chat_session` (all turns
continued the one session — the 24h window works), **10** `chat_messages` (user +
assistant per turn), **5** `message_traces`, **5** `escalation_cards` (2
`sensitive_topic`, 3 `low_confidence`, all `status = new`, `assigned_to = null` —
decide-and-queue). The `trace` JSONB is the **2a superset**: `profile`, `scope_filters`,
`router_decision = null`, `guardrail_check`, `retrieval`, `synthesis`, and
`floor_decision` (with `authority_used` and `check_c_confidence_tiebreaker.used_as_gate = false`).
Design-system adherence: chat + answer-model styles compose existing tokens; the
no-hex-leak property of `index.css` is preserved (new rules use `var(--…)` only).

---

## 3. Borderline-sensitive escalation report (the requested rate check)

The sensitive-topic regex is intentionally **broad**, so it escalates some
legitimately-answerable termination/disciplinary questions. Observed:

| question | fired? | rule |
|---|---|---|
| "¿Cuál es el **preaviso por despido** en mi convenio?" | **yes** | `sensitive_topic` (matched `despido`) |
| "¿Cuántos días de vacaciones tengo?" | no | — |
| "jornada de trabajo en mi convenio" | no | — |
| "¿Cuánto gana un técnico?" (own category) | no | — |

The *preaviso por despido* case is a **deliberate false-escalation**: a real,
answerable convenio question is escalated because it names `despido`. Per the sprint
note this is the **correct conservative default** for a legal-weight tool (a human
should handle anything touching a live termination), and the pattern was **not
narrowed**. Rate impression: the broad net fires on the disciplinary/harassment/
mental-health vocabulary only; ordinary jornada/vacaciones/permiso/own-category
questions pass cleanly. If the eyes-on judges the false-escalation rate too high, the
fix is a Sprint-6 *additive* refinement, not a baseline weakening.

> **Regex fix during the gate run:** the first probe showed *"¿Cuánto gana Pedro
> García?"* slipping past the other-employee rule (the patterns lacked a
> sentence-initial-capital allowance, so `Cuánto` didn't match `cu…`). Fixed in
> `GuardrailService` (allow `[Cc]`, keep the trailing proper-noun token
> case-sensitive so "un técnico" doesn't trip it) and re-verified: `Pedro García`,
> `Salario de Pedro`, `Nómina de María` all fire; "un técnico" / "jornada de
> trabajo" / "vacaciones" do not.

---

## 4. Forced / notable doc changes

- `architecture.md` §5 — added the Sprint-2b-1 block: the `/synthesise` contract, the
  **authority-precedence** rule, and the answer-or-escalate decision (A∧B load-bearing,
  C tiebreaker-only). §7 — documented the hardcoded baseline as `GuardrailService`
  firing before any `hr-ai` call, fires-regardless-of-confidence, conservative-by-design.
- `data-model.md` §8 — population note (one-transaction writes, 24h session window,
  role-scoping-ready shape), the full **`trace` shape** (the 2a superset incl.
  `authority_used`), and the new **`answer_model_settings`** table. §9 — the
  decide-and-queue escalation-card note.
- `deploy.md` §1 — tied `HR_AI_ANSWER_MODEL` / `HR_AI_ANSWER_ENDPOINT` to the EU-endpoint
  requirement; refreshed the key-handling design-fact note with the 2b-1 mechanics.
- `roadmap.md` — recorded 2a as built and the **2b → 2b-1 / 2b-2 split** (status table +
  the Sprint 2b section).
- `hr-ai/README.md`, `hr-backend/README.md` — `/synthesise`, the pipeline, the floor,
  the key handling, and the dev test profiles.

---

## 5. To complete the eyes-on (Pedram)

1. **Make `/synthesise` live:** `docker restart hr_ai` (the entrypoint reinstalls
   `requirements.txt`, so `anthropic` installs; the bind-mounted new code loads).
   Re-warm: the BGE-M3 weights reload on first `/retrieve`. (2a Correction-01 note:
   confirm `host.docker.internal` routing after the restart.)
2. **Set the key:** log in as `admin@…` (super_admin), Settings → Answer model, paste an
   **EU-endpoint** Claude key, confirm it shows `••••<last4>`.
3. **Run Gates 1 + 2** on the chat UI as `test-gipuzkoa@…` and `test-navarra@…`; confirm
   the answers, the citations' authority, and `synthesis.authority_used` in the trace.
4. Re-confirm Gates 3–6 through the UI (they pass at the service layer above).

---

## 6. Out-of-scope confirmed not built
No router, no salary-in-chat, no full per-claim grounding model, no escalation board,
no guardrails-config UI, no history-search UI, no role-scope enforcement, no lens UI,
no analytics. GDPR (EU endpoint / DPA / zero-retention) is referenced (deploy.md §1),
not built.

---

## Correction-01 — anti-fabrication + figure-grounding backstop

**Trigger.** Pre-commit review flagged the silent-convenio case (`test-navarra@…`,
*"¿cuánto dura el periodo de prueba?"*) as producing a *confidently fabricated legal
answer* — the exact failure the floor exists to prevent.

### Step 0 — diagnostic (8 retrieved chunks, convenio 22, `include_national_law:true`)

| # | score | authority | page | document | first line |
|---|-------|-----------|------|----------|------------|
| 1 | 0.609 | national_law | 36 | Estatuto | Art. 13 — Trabajo a distancia |
| 2 | 0.560 | official_convenio | 25 | Limpieza Navarra | Art. 36 Euskera … **(chunk body also holds Art. 37 Período de prueba)** |
| 3 | 0.544 | official_convenio | 9 | Limpieza Navarra | art. 37.3 b) permisos |
| 4 | 0.521 | national_law | 187 | Estatuto | Art. 48.5 — permiso nacimiento |
| 5 | 0.518 | national_law | 186 | Estatuto | Art. 48.4 c) |
| 6 | 0.516 | national_law | 187 | Estatuto | Art. 48.4 e) |
| 7 | 0.510 | national_law | 109 | Estatuto | Art. 26 LPRL |
| 8 | 0.509 | national_law | 26 | Estatuto | "…no inferior a seis meses ni exceder de un año" (contrato formativo) |

**Was the Estatuto's periodo-de-prueba article (Art. 14 ET) in the retrieved set? → NO.**
national_law chunks are present (5 of 8) but none is Art. 14; they cover trabajo a
distancia, permisos, prevención, and a fixed-term-contract clause.

**But the premise was wrong about the corpus.** Reading the full text of the cited
convenio chunk (p25) shows this convenio is **not silent** on the topic:

> *Art. 37.º Período de prueba. … no podrá exceder de **15 días** para el personal
> obrero y subalterno, ni de **30 días** para el personal técnico y administrativo, y
> en el contrato de emprendedores, de **6 meses**.*

So "15/30 días, 6 meses (emprendedores)" — including the "6 meses" the report believed
was hallucinated from training knowledge — is **literally in the cited convenio chunk**.
This is a **precedence case (convenio governs), not a silent-fallback case.** The Art. 14
ET miss is a genuine **doc-level retrieval-recall gap** (an on-topic national-law article
ranked below off-topic ones) but is **moot here** because the convenio governs; the recall
question is flagged for **2b-2**.

### Fix 1 — synthesis must not fabricate (`hr-ai`, `claude.py` `SYSTEM_PROMPT`)
Hardened the prompt: a claim may appear **only** if a supplied chunk states it directly
(no general labour-law knowledge to fill gaps); a citation must point to the chunk that
*actually* states the claim (never the nearest lookalike — e.g. a contrato-formativo
"seis meses" clause cannot answer a periodo-de-prueba question); when chunks don't answer,
either answer from a `national_law` chunk that does (cited to the Estatuto) **or** abstain
— empty `cited_sources` + confidence ≤ 0.2 → `hr-backend` escalates. Never assert a
convenio rule the convenio chunks don't contain.

### Fix 2 — deterministic figure-grounding guard (`hr-backend`, `ChatService`)
After synthesis, before surfacing: each **load-bearing figure** (number + unit — *N días/
meses/horas/años/semanas*, currency) must appear in at least one **cited** chunk's text,
in **digit or spelled-out Spanish form** (so "treinta días" grounds "30 días"). A figure
absent from all cited chunks in both forms → escalate (`low_confidence`, trace note
*"answer figure not grounded in cited chunk"*). Conservative: fires only on total absence;
the action is always escalate, never a silent edit. Recorded in the
`floor_decision.figure_grounding` trace block. **Cheap backstop, not the 2b-2 entailment
model** (roadmap note added).

Guard validated on synthetic cases: a fabricated "6 meses" cited to a chunk with no
6/seis → `grounded:false` (escalates); "30 días" vs a chunk saying "treinta" →
`grounded:true`; "31 días" vs digit "31" → `grounded:true`; a unit-less number → no-op.

### Re-run — both gold tests (end-to-end through `ChatService`, real provider key)

**Gipuzkoa vacaciones (must still PASS — regression guard).**
- Answer: *"…un período de vacaciones anuales de **31 días** naturales, de los cuales **26**
  laborables [Fuente 1]."* — `authority_used = official_convenio`, cited to the convenio
  (Limpieza Gipuzkoa, p3). **A=✓ B=✓**, `figure_grounding.grounded = true`. **Outcome: answer.**
- (During development an interim model phrasing introduced the Estatuto baseline "30 días",
  whose source chunk spells it "treinta" — this surfaced a digit-only false positive in the
  guard; adding spelled-out-Spanish normalization fixed it. The passing case is not made to
  abstain.)

**Navarra periodo de prueba (must NOT fabricate).**
- Outcome is the legitimate **convenio-grounded answer** the corpus actually supports:
  *"15 días … 30 días … 6 meses en el contrato de emprendedores [Fuente 1]"* —
  `authority_used = official_convenio`, cited to the convenio (Limpieza Navarra, p25),
  **A=✓ B=✓**, `figure_grounding.grounded = true` (15/30/6 all verbatim in the cited chunk).
  **No fabrication.** This is neither of the report's two predicted outcomes (Estatuto-grounded
  answer / clean escalation) because its premise — that this convenio is silent on periodo de
  prueba — does not hold for the seeded corpus. The convenio governs and is correctly cited.

**Net:** the fabrication risk is closed (synthesis abstains; the figure guard escalates any
load-bearing figure not present in a cited chunk), the precedence pass is preserved, and the
Art. 14 ET recall gap is logged for 2b-2.

---

## Correction-02 — deterministic salary-topic guard

**Trigger.** An adversarial behavioral probe of the prose answer path (11 questions across
the two seeded profiles) returned 9 PASS and surfaced two issues. The load-bearing one
(Q5) is a live safety bug fixed here; the other (Q10, a compound-query recall gap causing
baseline-over-convenio on one sub-topic) is the same recall class already parked for 2b-2.

### The Q5 finding (the bug)
`test-gipuzkoa`, *"¿Cuál es el salario base de un limpiador según mi convenio?"* was
**answered** (not escalated). The figures came from cited convenio **p32 chunks that are
the embedded wage tables** (`SOLDATA TAULAK / TABLAS SALARIALES`) — i.e. salary read from
prose. Worse, the year→figure mapping was **misattributed**: the table rows sit at chunk
starts while each year header lands at the chunk end, so the model reported **2024 =
1.330,80 €** when the real 2024 Limpiador/a row is **1.244,74 €** (1.330,80 is the 2025
row). The `figure_grounding` guard (Correction-01) passed it `grounded:true` because the
digits exist *somewhere* in the cited text — it validates digit presence, **not** row/
column/year alignment, so it gives false confidence on tabular data. This violates ADR-0006
(salary lives in `salary_tables`, never a vector chunk; a figure must be exact) and must
never reach an employee.

### The fix
A deterministic **salary-topic guard** in `hr-backend` `GuardrailService`, in the same
spirit as the hardcoded sensitive-topic baseline:
- Detects salary/wage/retribution questions (`salario`, `sueldo`, `nómina`, `retribución`,
  `remuneración`, `tablas salariales`, `pagas extra`, `cuánto gano/gana/cobra/me pagan…`)
  **before** synthesis.
- On match → escalate with the **new, distinct reason `salary_not_in_chat`** (separate from
  `low_confidence` / `sensitive_topic` for the trace + future analytics), a clear
  employee-facing message that a person will handle salary questions, and **no `/synthesise`
  call** (no synthesis block in the trace).
- Conservative-by-design and additive — like the sensitive baseline it can only cause
  escalation, never a weaker answer. The bare verb "paga" is intentionally **not** matched,
  so "¿la empresa me paga el gimnasio?" is not treated as a salary question.
- The `salary_not_in_chat` value ships as a **standalone additive migration**
  (`…_add_salary_not_in_chat_to_escalation_cards_reason`) — the historical
  `create_escalation_cards_table` migration is left untouched (committed migrations are
  immutable). The new migration introspects the actual `reason` CHECK constraint name (not
  guessed), `DROP … IF EXISTS` then re-adds it with the complete value set, with a `down()`
  that restores the prior 5-value set. It is idempotent and applies cleanly on fresh
  installs, already-migrated environments, and this dev DB (replacing the interim manual
  ALTER). Verified: post-migrate the constraint accepts `salary_not_in_chat` and rejects an
  unknown value; `git diff` of `database/migrations/` shows only the new file added.

### Explicit deferral (accepted, not fixed here)
The 2a wage-table pages embedded as prose chunks are **left in the index**. This guard stops
salary questions from reaching them, but a non-salary question could still retrieve a table
chunk. We knowingly accept this residual; the deeper 2a cleanup (excluding wage-table pages
from prose chunking) is parked — see roadmap 2b-2.

### Re-verify
- **Guard unit-scan over all 11 probes + 3 gold questions + extras:** only the salary
  question and salary variants (`¿cuánto gano?`, `nómina`) fire `salary_not_in_chat`;
  `despido` still fires `sensitive_topic`; `¿cuánto gana Pedro García?` still fires
  `other_employee_data` (privacy takes precedence); **no other probe trips it** — including
  Q9 *"¿la empresa me paga el gimnasio?"* and Q4 *"…seguro médico privado pagado…"*.
- **Q5 end-to-end:** `outcome = escalate`, `reason = salary_not_in_chat`, **synthesis block
  ABSENT** (provider never called), 0 citations, salary-deferral message surfaced — no
  salary figure reaches the employee.
- **Regression — gold tests intact:** Gipuzkoa vacaciones → answer, `official_convenio`,
  31/26 días; Navarra periodo de prueba → answer, `official_convenio`, 15/30 días; Navarra
  trabajo a distancia → answer, `national_law`. None trips the salary guard.
