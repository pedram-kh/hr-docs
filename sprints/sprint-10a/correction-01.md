# Sprint 10a — Correction-01 (paste into the Cursor thread)

> Save as: `hr-docs/sprints/sprint-10a/correction-01.md`
> Source: CP-4 eyes-on, step 1 (employee view, `test-fullgap@`). Three findings, all employee-presentation. **Branch `sprint-10a`, before merge. Still no commit until this is re-reviewed.**

## Findings

- **E1** — `[Fuente 1]` markers render literally in the employee answer text.
- **E2** — the fallback caveat exposes internal system state ("todavía no está cargado en el sistema") and its `**`/`---` markdown renders raw.
- **E3** — the employee chat shows the FUENTES excerpt block and the full "Cómo llegué a esto" trace (router confidence, model name, chunk counts). That is admin material.

First, report for E3: is trace/FUENTES visibility in the employee chat **pre-existing** (since 2b) or introduced this sprint? State it in the correction record either way — it determines nothing about the fix but everything about what else may need checking.

## Authorized changes

1. **E1 (frontend, display-only):** strip `[Fuente N]` markers when rendering employee chat messages. Stored `chat_messages.content` is untouched — Check B parses those markers. Admin conversation views unchanged.
2. **E2 (backend, `decorate()` constant only):** replace the caveat with plain text, no markdown, no separator line, no system-state language:
   > `Esta respuesta se basa en el Estatuto de los Trabajadores, que establece los mínimos legales para cualquier persona trabajadora. Tu convenio colectivo puede mejorar estas condiciones (nunca empeorarlas). Para confirmar lo que se aplica en tu caso concreto, consulta con Recursos Humanos.`
   Update the caveat-presence assertions (T5/T5b and the eval harness) to the new constant. Deterministic append point (`persistTurn`) unchanged.
3. **E3 (server is the boundary, ADR-0018 spirit):** the employee-facing chat endpoints stop sending the `trace` object and citation excerpts. The employee view renders instead **one** source line per answered turn — `Basado en: <document display name>` — derived from the citations server-side (e.g. a minimal `source_labels` field). Admin/HR endpoints and views (TracePanel, FUENTES, access-logged conversation views) completely unchanged. No CSS-hiding of data still in the payload.
4. The small grey "Fundamentado en la ley nacional (Estatuto)." line is superseded by the source line — remove from the employee view, keep any admin equivalent.

## Constraints

- No answer-loop change of any kind. `floor_decision`, stored content (except the caveat constant), citations, Check A/B, `/ground`: untouched. `Sprint7cAdditivityRegressionTest` — note: if its golden traces pin the old caveat string, updating the pinned string for fallback turns is authorized; **any other byte difference is a stop**. Non-fallback golden traces must not change at all.
- Tests: employee endpoint returns no `trace` key (API test, not a frontend assertion); marker-stripping unit test; caveat constant test updated.
- Record in `review.md` as Correction-01 with the E3 pre-existence answer.

**Then STOP** — Pedram re-runs eyes-on steps 1–2 plus one admin check (trace still fully visible at `admin@`), and continues CP-4 from step 3.

---

## Resolution (full record in `review.md` §13)

**E3 pre-existence:** the trace/citation-excerpt channel to the employee is pre-existing since Sprint 2b-1 (`ChatController::message()`'s original commit already returns `handleMessage()`'s full result; `ChatScreen.tsx`'s original commit already renders `CitationList`/`TracePanel` unconditionally; `ConversationPresenter` inherited the same shape for session hydration in Sprint 4, with no audience branching on trace/citations before this correction). Not introduced this sprint — but this sprint's own `prose_gap`/`floor_decision.fallback` additions put more sensitive content through that pre-existing channel (on the `expired_only` path, the trace literally named the internal classification and cited ADR-0032), which `Sprint10aInvariantTest::test_t9b_*` could not have caught because it only checks `result['answer']`, never `result['trace']`. E3's fix below closes that as a direct side effect.

**Implemented:** E1 — `stripSourceMarkers()` (frontend, display-only; `hr-frontend/src/lib/citationMarkers.ts` + unit tests, new `vitest` setup). E2 — `ChatService::FALLBACK_CAVEAT` replaced verbatim; T5/T5b and the eval harness needed no edit (both reference the constant or a substring that survives in the new wording). E3 — `ConversationPresenter::present()` branches on audience (employee: no `trace` key, `citations: []`, new `source_labels`; admin: unchanged); `ChatController::message()` applies the same reshaping to the live turn via a shared `ConversationPresenter::sourceLabels()` helper; `ChatScreen.tsx` drops `CitationList`/`TracePanel` and the superseded "Fundamentado en…" caption, renders one `Basado en: …` line instead.

**Tests:** new `Sprint10aCorrection01Test.php` (5 HTTP-level tests: no `trace` key on the live turn and on session hydration, stored rows untouched, admin presenter unaffected, caveat wording exact-match); new `citationMarkers.test.ts` (6 cases); one pre-existing test fixed (`Sprint6GuardrailInvariantTest` read `trace` off the live endpoint — repointed at the persisted `MessageTrace` row, same guarantee).

**Regression:** `Sprint7cAdditivityRegressionTest` green, and confirmed to exercise no fallback turn at all — zero byte difference, not just an authorized one. Full backend suite 624/624. Frontend `tsc`/`vitest`/`eslint` clean on every touched file.

Still uncommitted, still on `sprint-10a`. **Stopping here per the authorization.**

**Staging deployment (follow-up, same day):** eyes-on step 1 found the old caveat still live on a fresh turn — the code above had never reached staging. Backend: `ChatService.php`/`ConversationPresenter.php`/`ChatController.php` `docker compose cp`'d straight into the running `hr-backend` container (the same mechanism the rest of Sprint 10a's backend code was already injected with); confirmed live via `php artisan tinker` reading `ChatService::FALLBACK_CAVEAT` and `method_exists(ConversationPresenter::class, 'sourceLabels')` back from the running process, not just off disk. Frontend: turned out staging's bundle had never been rebuilt past Sprint 8 at all (`prose_gap` was absent from the deployed `TracePanel.tsx` — this sprint's whole frontend diff, not just Correction-01's, had never shipped) — `rsync`'d the current working tree over the box's `hr-frontend` checkout, rebuilt `frontend-dist`, force-recreated it to sync the shared volume Caddy serves from. Confirmed by fetching the live bundle and grepping it: `"Basado en"` present, `"Fundamentado en"` absent. Full detail, including the revert path for both, is in `review.md` §11's updated table.
