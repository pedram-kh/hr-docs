# Slice 13c — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-13c/kickoff-prompt.md`

---

You are planning **Slice 13c** in the hr-platform workspace. Read `hr-docs/sprints/sprint-13c/spec.md`, then Sprint 13's `plan.md` §B.6 and `review.md` (the lane, the F.8 stance, the forced-lane harness results, the catalogue findings), ADR-0035, and `GeneralLanePostCheck` with its tests. Write `hr-docs/sprints/sprint-13c/plan.md`, then **STOP — no code, no commits, no spend.**

Cite `path:line`. Lane off must stay byte-identical (goldens); everything here is behind `HR_GENERAL_LANE_ENABLED`.

1. **Today's lane, exactly.** Trace the lane's pre-condition rule, the pre-screen, the provider call, the source order, the post-check, the badge and the toggle. State why 0/28 positives reached it (which gate stopped each).
2. **Model knowledge on.** Propose the source order and the provider prompt for a model-knowledge draft: ≤ 120 words, explanatory register, no figures/durations/amounts/entitlement language, no fabricated citations, ends by pointing to the employee's convenio/HR. Show how `authority: general_knowledge` is recorded and rendered (R4).
3. **Positive set.** Propose ≥ 20 explanatory questions; for each, a read-only Check A run on staging proving no corpus material, and a pre-screen check proving it's explanatory. Freeze + hash. Include R3's edge (questions whose honest explanation wants a figure) and propose the lane's behaviour.
4. **Pre-screen fixtures.** ≥ 30 entitlement / ≥ 30 explanatory, with minimal pairs (R2). Report the pre-screen's current accuracy on them before any change.
5. **Forced-lane harness as the gate.** Extend `lane-forced.php` to run positives and negatives, ×N, with the post-check and `audit()` verdicts per draft, and a summary. New negatives: ≥ 15 entitlement-shaped questions phrased colloquially (13b style).
6. **Catalogue in Guardarraíles.** Editable list (super_admin, `guardrails.manage`), same allowlist enforcement, audit on write; the existing 5 rows seeded.
7. **Trace + UI.** Lane trace step (source used, post-check verdict, word count), badge text both dictionaries, escalate button already present.
8. **Gate plan** per spec §5 with spend estimate (≤ $25), staged: S1 forced-lane on positives ×1 (block rate — R1), S2 forced-lane negatives ×3, S3 end-to-end positives ×2 + negatives ×2, S4 regression ×1. Stop after each.
9. Ordered build steps, tests, open questions, the single **CP-1** (five live lane answers).

Then **STOP** and wait for review.
