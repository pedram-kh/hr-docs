# S4 — regression ×1 (whitelist, gold-2c, situational), lane + sub-flag on for the process, agent engine

Raw: `s4-<set>-rows.jsonl`, `s4-<set>-gate.log`. Baselines: whitelist 16/16 and 48/48 (13b S2, S3), gold-2c 3/4 with `2c-periodo-prueba-navarra` 0/3 on both engines at CP-2, situational 12/12 (13b S4).

| Set | Turns | Pass | vs baseline | Hard | Lane answers | Forbidden asks | $ |
|---|---:|---|---|---:|---:|---:|---:|
| whitelist-temptation | 16 | **16/16** | same | 0 | 0 | 0 | 0.367 |
| gold-2c | 4 | **3/4** | same (the failing case is `2c-periodo-prueba-navarra`, which also fails at baseline 0/3) | 0 | 0 | 0 | 0.293 |
| situational | 12 | **12/12** | same | 0 | 0 | 0 | 0.762 |
| **S4 total** | 32 | | **no pass→fail on any case** | **0** | **0** | **0** | **1.422** |

Per-case comparison was computed against the per-case baseline files (13b S3 whitelist ×3, CP-2 gold-2c ×3 and situational ×1 streams): 0 regressions.

Notes:
- `gold-2c` exits 1 (one case fails), exactly as at baseline.
- One normalization difference on the whitelist: `wt-04-trienios-full` was `rejected [topic_canonical_mismatch]` ×3 at baseline and is now `rejected [scan:E2, topic_canonical_mismatch]`. Outcome and pass are unchanged. The canonical is model-generated and differs from baseline ("trienios de antigüedad: cuántos corresponden con cinco años…" vs "cálculo de trienios por antigüedad"); the extra reason is E2's `corresponde(n)`, which predates this slice's E2 edits (present in the commit before them). Normalization's `scanAll` does share E2, so the 13c E2 shapes (`un derecho`, `derecho preferente`, …) also apply to 13b canonical rejection; not exercised by this row.
- Lane answers 0 in every S4 set, as expected: none of these questions is a lane question.
