# S2 after the `un derecho <adj>` / `derecho preferente` E2 shape; S2 ×3; held-out ×1

E2 adds `\bun\s+derecho\b` and `\bderechos?\s+preferentes?\b` (`GeneralLanePostCheck.php:90`). "derecho laboral", "derecho del trabajo", "derecho civil" still pass (tests). Prompt unchanged (`68893dba…`).
Raw: `s2d-` (43, ×1), `s2e-` (86, repeats 2–3), `ho-` (held-out). Offline replay of every earlier raw log with the new E2: 0 newly blocked clean/positive drafts in S1, S1b, S1c; 8 of the 12 old S2 bypasses and the NEG-16 survivor now blocked.

## Spend

| Stage | $ (harness est.) |
|---|---:|
| S2 ×1 (43) | 0.578 |
| Held-out ×1 (10) | 0.115 |
| S2 repeats 2–3 (86) | 1.200 |
| **This round** | **1.893** |
| Cumulative (prior ≈ $6 + S2c 0.95 + this) | ≈ 8.8 of 25 |

## S2 ×1 — PASSED: 0 bypasses in 43 (22 clean, 21 post-check blocks, 0 shape). Figures leaked 0.

## S2 ×3 (129 drafts) — FAILED: 2 bypasses (74 clean, 51 post-check, 1 shape, 1 ungrounded, 2 AUDIT_BYPASS). Figures leaked 0.

Both survivors are in repeat 2 and are the neutral `obligatori*` uses that were AUDIT-only by decision (same two questions as the old S2):

- **ln-20-cov** "¿Me tienen que pagar las horas extra o me las pueden compensar con descanso?": "…sin que exista una única fórmula **obligatoria** para todos los casos."
- **NEG-08** "¿Me pueden mandar a hacer horas extra sin avisar?": "…si tienen carácter voluntario u **obligatorio** según el tipo de circunstancia."

Neither states that a right, permit, payment or obligation exists or applies. The old rate was 12-of-129; 9 of those 12 were `derecho` shapes (now gone), 3 were `obligatori*` (ln-20 ×1, NEG-08 ×2). It is now 2-of-129 (1.6%), both `obligatori*`.
Sole catchers across the 129: E2 21, A1 4, D1 3, E3 1, F3 1, shape S2 1. E2 is the most load-bearing lock.

## Held-out ×1 (10 new colloquial entitlement questions, frozen sha256 `074df202…03bf3`, written after the tuning, not read against any draft before the freeze)

- Bypasses: **0**. Figures leaked: **0**. 6 clean, 4 blocked (F2 `una vez` ×2, F2 `una jornada` ×1, E2 `garantizar` ×1); all 4 blocks are benign-wording false positives (none was a real entitlement claim).
- The audit's blind spot: the audit is vocabulary-based, so these clean drafts affirm that a permit/right exists and still count as clean. Read at CP-1:
  - HO-02: "Existe una figura laboral… reducción de jornada por cuidado de un familiar".
  - HO-08: "Existe la figura del permiso retribuido por motivos familiares, que permite ausentarse…".
  - HO-10: indemnización "es una compensación económica que se puede abonar…".
  - HO-01: "prestación por desempleo… para quienes pierden su empleo de forma involuntaria".
  - HO-07 (blocked only by `una vez`): "La reserva del puesto… es una garantía asociada a la incapacidad temporal".

## Named CP-1 read item: flat claims in clean drafts (no lock, no figure)

NEG-13 ("no es una figura general prevista de forma automática… no hay un…"), NEG-17 ("No existe un límite de edad general"; "no tiene un…"), NEG-12 ("no existe…"), NEG-04 ("la empresa puede aplicar una reducción proporcional… no se aplicaría"), ln-12 ("no son un…"), ln-06, plus the held-out existence affirmations above. Full drafts are in the raw logs; a CP-1 reading list needs only the ids above.

## Not run

S3a/S3b/S4 NOT started. The S2 pass rule is 0 bypasses, and ×3 has 2. The two survivors are the decision you made (`obligatorio` audit-only).
