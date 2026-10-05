# Correction-13c-01 — the lane is a property of the question, not of the account

Status: **accepted 2026-10-05**. Decision record: ADR-0038, "Amendment 2026-10-05". Evidence: `sprints/sprint-13c/eval/results/correction-13c-01/` (`report.md` has the gate table; raw logs and rows beside it).

## What changed

The general-lane hand-over depends on two things only: the question passes the explanatory pre-screen, and the corpus did not answer. Nine account-state exclusions were removed (never ingested, expired, scope under review, no chunks, no group, and a verified-fact composition that did not answer); the ADR table lists each with `path:line` at `54fea62`. Entitlement questions in those states keep today's escalation reason, and with the lane off the output is byte-identical. The sensitive-topic guard, the off-domain decline and "convenio answers first" are untouched. Provider errors, `same_validity_conflict` and composition `conflict` stay terminal.

Two things the request did not name, found by the gate: `ReferenceFactTool` and round 0 (`AgentChatService`) also settled the turn on a gap, and a first-turn composition `low_confidence` on `test-navarra@` ended at round 0 with no lane. Both are rows 7–9 in the ADR table.

## Gate (list-price spend ≈ $3.6)

| Stage | Result |
|---|---|
| Suite | 1588/1588 (26 new tests: one group per removed state, lane-off byte-identity, and the Calidad sampling pin); goldens unchanged |
| Forced-lane negatives, miss profile | 0 bypasses, 0 figures (43) |
| Forced-lane negatives, expired profile | 1 audit-only bypass (NEG-11), 0 figures (43) |
| Lane positives, `test-fullgap@` / expired | 12 / 15 lane answers, caveat on all |
| Whitelist | 16/16, 0 hard, 0 lane answers (before and after the composition change) |
| PIF live, 3 accounts | 3/3 lane answer with badge (after the composition change) |

## Recorded, not fixed

- **Known limit: model-knowledge answers are shape-checked, not truth-checked.** `test-navarra@` expanded PIF wrongly ("Protección Integral frente a la violencia"); the other two accounts were right. Mitigation ticketed: **Slice 13f** (HR-curated glossary in Guardarraíles, injected into the lane prompt as given facts), `roadmap.md` §7.
- **NEG-11 is audit-only:** `spelled_number` on "una reducción de jornada", no digit or figure; pre-screen v2 refuses the question in production before the lane.
- **Calidad samples lane answers.** They are answered turns with `path = general_knowledge`, their own stratum, never sampled to zero. Pinned by a test; no pre-pilot ticket needed.
- **Cost of the composition hand-over:** a first-turn explanatory question whose composition abstains pays the composition's LLM calls twice. Accepted.
- **Behaviour change to know about:** with an unknown group and a topic that has a verified fact, an explanatory question now continues to `convenio_search`, so convenio prose may answer it. Entitlement questions still end at the gap.
- Gate-run side effects: the three PIF accounts have extra turns in Historial from the gate runs.

## Close

Merge SHAs, deploy, health, verification from the live site and the snapshot are in the close-out record below (filled in after the deploy).
