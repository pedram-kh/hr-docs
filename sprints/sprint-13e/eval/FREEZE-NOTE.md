# Slice 13e — fixture freeze note (PROPOSED at the plan gate; becomes binding when you approve the plan)

| File | n | Role | sha256 |
|---|---:|---|---|
| `off-domain-24.json` | 24 | gate set, spec §5.1: ≥ 18 `decline`, 0 escalate-with-card, 0 answers | `f58b30e5…5f880e` (full value in `MANIFEST.sha256`; re-hashed after the per-case `expect` objects were added, questions unchanged) |
| `borderline-10.json` | 10 | reported, not gated (spec §5.3) | `64db4d2b…b4fc` |

Written **before** any model saw them, except two disclosed exceptions:

* **OD-01** (`what is the definition of job?`) is the real staging trigger. It was in the plan-gate probe as anchor A1.
* **OD-02** (`¿Cuál es la capital de Australia?`) was probe anchor A2.

Nothing was tuned on either: the probe is planner-only and changes no code. Neither is withheld from the gate. Treat them as "seen once" in the review.

`BL-01` and `BL-02` come from real staging traces (they reach `planner_escalated` / `off_domain` today). `BL-03` is the system prompt's own example (`hr-ai/app/planner/tools.py:188`). `BL-04…10` are authored.

**What the borderline set cannot show.** The proposed workplace-vocabulary veto (plan §4, P8) was drafted after the borderline set was written, so its catches there (7 of 10) are *by construction* and are not evidence of generalisation. The generalisation evidence is the other direction: the veto fires on **0 of 24** off-domain questions (replayed offline, no model, no spend; plan §3.4).

`MANIFEST.sha256` is verified by `answer:gate` / the probe the same way as 13b/13c (`--allow-unfrozen` only for dev iteration; never for these two files). If you edit either file during review, re-hash both before build and update the table above.

Nothing here was run through `answer:gate`. The only model spend so far is the 12-question planner-only probe: **$0.187** (`results/plan-gate-probe-raw.log`).
