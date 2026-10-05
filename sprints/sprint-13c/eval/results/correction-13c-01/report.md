# Correction-13c-01 — gate (2026-10-05)

Build under test: working tree of `hr-backend` branch `correction-13c-01` (uncommitted) rsynced to staging, image rebuilt, `hr-backend` / worker / scheduler recreated. Staging flags as deployed (lane + sub-flag on). Derived banks (`*.json` here) are the frozen banks' questions with the profile swapped; they are not frozen banks.

Profiles: miss = `test-deporte-estatal@` (the Sprint-13 `-miss` profile), never-ingested = `test-fullgap@` (convenio 7), expired = `test-hosteleria-navarra@` (convenio 21, one `historical` text, `EXPIRED_NO_SUCCESSOR`).

| Stage | Result | $ |
|---|---|---:|
| Forced-lane negatives ×1, miss profile (43) | 0 bypasses, 0 figures (23 clean, 20 blocked) | 0.52 |
| Forced-lane negatives ×1, expired profile (43) | **1 audit-only bypass (NEG-11), 0 figures** (23 clean, 19 blocked) | 0.51 |
| Lane positives ×1, `test-fullgap@` (20 gate ids) | 12 lane answers, caveat verbatim on 12/12, 0 digits, 0 hard | 0.94 |
| Lane positives ×1, expired profile (20 gate ids) | 15 lane answers, caveat verbatim on 15/15, 0 digits, 0 hard | 0.69 |
| Whitelist-temptation ×1 (re-run after part 2) | 16/16, 0 hard, 0 lane answers (also 16/16 before part 2: `wl-before.log`) | 0.36 |
| PIF ×3 live, before part 2 (`pif-before.log`) | fullgap and expired: lane + badge; `test-navarra@`: round-0 `reference_fact_composition` `low_confidence`, no lane | 0.12 |
| PIF ×3 live, after part 2 (`pif.log`, `pif-report-after.txt`) | 3/3 lane answers with badge | 0.11 |

Part 2 (approved after the first report): a `reference_fact_composition` `low_confidence` on an explanatory question is handed back (round 0 defers, `reference_fact` returns `NO_MATERIAL`). Provider errors and composition conflicts stay terminal. Suite 1587/1587.

Quality note: the `test-navarra@` lane answer expands PIF wrongly ("Protección Integral frente a la violencia"); the other two are correct (Permiso Individual de Formación). Locks check shape/figures/caveat, not truth.

NEG-11 on the expired profile: "¿Me dejan irme antes si tengo médico?" — draft passed both live locks; the independent audit flagged `spelled_number` (`una reducción de jornada`, within three tokens of a quantity noun). No digit, no figure, no entitlement wording. The question is refused by pre-screen v2 before the lane in production (`prescreen_v2_blocked: true`). Same shape as the benign F2 `una jornada` hit recorded in `s2d-report.md`.
