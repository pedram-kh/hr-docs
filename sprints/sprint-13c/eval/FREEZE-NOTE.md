# Slice 13c — freeze note (S0 re-freeze, 2026-09-30)

Written at the re-freeze that followed S0 acceptance. The hashes are in `MANIFEST.sha256`; any later edit to a frozen file changes them and `answer:gate` refuses the bank.

## 1. What changed in this re-freeze (and what did not)

| File | Change |
|---|---|
| `lane-positives.json` | `gate_set` recomputed by the stratified rule (§2); new per-case `family` field. The 33 survivors, their questions and their `s0` verdicts are unchanged. |
| `prescreen-fixtures.json` | adds `gate_role` on the 10 adversarial cases and a top-level `_provenance` (§4). No question, id, `half`, `pair` or `expected` changed. |
| `lane-positives-pool.json`, `lane-positives-pool-2.json`, `lane-colloquial-negatives.json` | unchanged (hashes identical to the S0 manifest). |

The old gate set (20 by ascending `sha256(question)`, no other input) is superseded. Five cases left it (`LP-02`, `LP-04`, `LP-10`, `LP-40`, `LP-59`); five entered (`LP-25`, `LP-28`, `LP-29`, `LP-45`, `LP-53`), which are five of the seven non-abstention survivors the hash rule had not picked.

## 2. The gate-set rule (S3a), with no other input

1. **All 7 non-abstention survivors** (`check_a_miss` ×4, `entailment_only` ×3). They are the only survivors that open the *unmodified* Sprint-13 lane, so the abstention relaxation says nothing about them and they must be in the end-to-end number.
2. **Then abstention survivors in ascending `sha256(question)` order** until the set holds 20 (13 more).
3. **Family cap 12/20**: a candidate is skipped if its family already holds 12 gate cases.

Implementation: `probes/freeze-positives.py` (`GATE_N = 20`, `FAMILY_CAP = 12`, `FAMILIES`). The script aborts if a survivor has no family.

**The cap did not bind.** Gate composition by family: SS 6, RRLL 5, EMPLEO 4, PRL 3, BAJA 2 (largest = 6 of 20, cap 12); no candidate was skipped. It is a guard for a pool with more concentrated topics, not a selection that shaped this set. Whole-pool sizes (all 33 survivors): SS 9, EMPLEO 9, BAJA 5, PRL 5, RRLL 5.

## 3. Families

Fixed by the question's *subject*, before the selection ran, not by outcome or shape.

| Family | Subject | Survivors (pool id) |
|---|---|---|
| `SS` | Seguridad Social: organismos, alta, vida laboral, INSS, IMV | 01, 02, 03, 11, 53, 65, 67, 75, 76 |
| `EMPLEO` | Empleo y desempleo: SEPE, paro, prestación, demanda, certificado de empresa, FOGASA | 09, 10, 15, 40, 56, 57, 70, 73, 74 |
| `BAJA` | Baja y alta médicas: IT, parte de baja, alta médica, mutua | 04, 05, 07, 55, 69 |
| `PRL` | Prevención y accidentes: servicio/comité, accidente de trabajo, enfermedad profesional, Inspección | 14, 48, 58, 59, 61 |
| `RRLL` | Relaciones laborales: sindicato, SMAC, movilidad funcional, excedencia, MSCT | 25, 28, 29, 45, 47 |

Judgement calls, stated so they can be overruled: POOL-70 ("¿Qué diferencia hay entre el SEPE y la Seguridad Social?") is filed under `EMPLEO` (first-named subject); POOL-04 (mutua colaboradora) under `BAJA`; POOL-14 (Inspección de Trabajo) under `PRL`. Moving any of them changes no outcome: the cap does not bind under any assignment of these three.

## 4. Pre-screen fixtures: contamination and the clean held-out pair

Recorded in `prescreen-fixtures.json` (`gate_role`, `_provenance`):

- **ADV-05 and ADV-08 are contaminated.** The `obligation` rule of pre-screen v2 was written *after* those two were seen and is what makes them pass. Their pass is not evidence about unseen questions.
- **ADV-09 and ADV-10 are the clean held-out adversarial pair.** They were added at fixture review, after the v1 list was frozen and before any v2 rule was written, and passed v2 without any rule being changed for them. They are the *only* adversarial gate number and are reported separately (`Sprint13cPrescreenFixturesTest`).
- The other six (ADV-01..04, 06, 07) were in the list while the rules were written and are tagged `visible_at_tuning`. `half` (dev/heldout by position) is the frozen split and is **not** used for the adversarial gate.

What this means for the claim "0 leaks on adversarial": it is 10/10 on the set, but 2/2 on the part that is clean. The honest statement is that v2 is fail-closed and monotone (it can only refuse more than v1) and the clean evidence is small.

## 5. CP-1 topics (live answers, after S4)

`excedencia`, `IT`, `parte de baja médica`, `vida laboral`, `mutua colaboradora` (replaces the spec's finiquito, ERTE and preaviso: finiquito cannot reach the lane, ERTE and preaviso are corpus-answered; plan §3.4, §9.4).

## 6. Budget

≈ $22.2 approved, cap $25 (plan §8).
