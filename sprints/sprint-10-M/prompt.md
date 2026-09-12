# Sprint 10-M — Model upgrade: `/synthesise` + `/ground` → Sonnet 5 (paste into Cursor)

> Save as: `hr-docs/sprints/sprint-10-M/prompt.md` (review goes to `sprint-10-M/review.md`)
> Shape: config change + measurement, on branch **`sprint-10-M`**. Same gate: **no commit/merge until reviewed.** No ADR needed unless a decision emerges; record model versions in `architecture.md`'s tooling section.

## Scope

- **In:** the model used by `/synthesise` and the model used by `/ground`, upgraded to Anthropic's Sonnet 5. Nothing else.
- **Out (do not touch):** `/route` and `/explain` stay on Haiku (decided: cheap classifier at 0.95 confidence with deterministic fail-safe; guarded explainer). Embeddings stay BGE-M3. No prompt-text changes to any endpoint — model swap only, so the measurement isolates the model variable.

## Step 1 — Report, then ⏸ CP-A

1. Report where each endpoint's model is configured and the **current** model string per endpoint (`/route`, `/synthesise`, `/ground`, `/explain`) — from the real config, not memory.
2. Resolve the exact Sonnet 5 model string against the Anthropic API's models list (do not guess it; verify it resolves).
3. Note per-token pricing difference if documented, and the expected latency direction.
4. **STOP at CP-A** — Pedram confirms the model string and cost before anything changes.

## Step 2 — Baseline (before touching config)

The post-merge state IS the baseline; capture it explicitly so "before" is a file, not a memory:
- The four 2c gold answer cases (just re-run at 10a close — reuse those results, note their date).
- Fallback positive set (13 q, `test-fullgap@`) and both negative sets (15 q each) — latest 10a results serve as baseline; re-run only if anything material changed since.
- Record Q5/Q13's current outcomes (`low_confidence` escalations) — these are the questions most likely to move.

## Step 3 — Swap and measure

1. Change the two model strings on the branch; inject to staging as in 10a (same mechanism, record in the injection table).
2. Re-run, on the real loop: the four gold cases, the positive set, both negative sets. One run each; if any single result flaps against baseline, re-run that question once and report both.
3. Compare:
   - **Hard requirements (any miss = stop, revert the injection, report):** all four gold figures byte-identical in substance (15/30, 31/26, Ley 10/2021 ×2) with same authority and citation docs; **both negative sets stay 0 answered** with no fallback key; every positive answer grounded and carrying the caveat; salary questions still escalate.
   - **Observations (report, don't gate):** positive answer rate vs 9–10/13 — did Q5/Q13/Q4 improve, i.e. does the grounding gate now entail what it declined before; answer length/latency; Check-A top-score distribution unchanged (retrieval untouched — if this moves, something is wrong beyond the model).

## Step 4 — Golden traces, the careful part

`Sprint7cAdditivityRegressionTest` pins byte-for-byte outputs; a new synthesis model changes bytes by definition. Regeneration is authorized, **with a reviewed diff**:
- Regenerate the golden baselines on the new model.
- Produce a structural diff of old vs new traces: `router_decision`, `floor_decision.{path,outcome,escalation_reason,authority_used}`, citation source-sets, and every deterministic field must be **identical**; only synthesis text and grounding verdict details may differ. Any structural field that moved = stop and report.
- The regenerated baselines commit with a note naming the model change as the cause, so the next reader knows why the bytes moved.

## Step 5 — Close

- Full backend suite + chunker guards green.
- Docs: `architecture.md` model versions; `deploy.md` gains the ops note from 10a close — *container recreation must use the same env exports as `deploy.sh` (`STAGING_EIP`, `RDS_ENDPOINT`); `/up` returning 200 does not prove artisan health — verify with an artisan command after any recreate.*
- `review.md`: before/after tables, the structural-diff verdict, cost/latency note.
- **STOP** for review. Then eyes-on is light: Pedram asks two questions in the browser (one convenio-covered, one fallback) and spot-reads tone/length. Then merge `--no-ff`, push, `deploy.sh`, recreate containers **with the deploy env exports**, verify running config shows the new model strings, snapshot `hr-staging-post-10M`, and delete `hr-staging-pre-10a-rechunk` once settled (keep `post-10a`).
