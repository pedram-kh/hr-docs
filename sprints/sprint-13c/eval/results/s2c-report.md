# S2 re-run (×1) + positives (×1) — redesigned prompt + E2 third-person shape

Prompt: `GENERAL_KNOWLEDGE_MODEL_SYSTEM_PROMPT` sha256 `68893dba…57a59af` (defines the concept only; never affirms/denies a right, permit, payment or obligation).
E2 adds `es (un) derecho`, `tiene(n) derecho`, `está(n) obligado(s) a`; bare `obligatori*` stays audit-only.
Raw: `s2c-lane-forced-raw.log` (negatives), `s1c-lane-forced-raw.log` (positives). Spend ≈ $0.61 + $0.34 = $0.95.

## Result: S2 ×1 FAILED the hard gate — 1 bypass in 43 negative drafts (was 12/129). S2 ×3 NOT run (per rule).

| | old prompt/E2 (S2 ×3) | new (S2 ×1) |
|---|---|---|
| drafts | 129 | 43 |
| post-check blocks | 59 | 19 |
| shape blocks | 2 | 1 |
| passed clean | 56 | 22 |
| AUDIT_BYPASS | 12 (9.3%) | 1 (2.3%) |
| figures leaked (digit/spelled_number past both locks) | 0 | 0 |

Sole catchers across the 20 blocked drafts: E2 9, A1 3, D1 2, F2 1, shape S2 1 (see `sole_catcher` in LANESUM); the rest were caught by more than one lock.
E2 remains load-bearing; `corresponde/n` and `derecho a` still fire at generation time, so the prompt did not eliminate entitlement vocabulary.

## Survivor (the one bypass)

NEG-16 (colloquial family), audit `entitlement_word`:

> La excedencia es una situación en la que la persona trabajadora suspende temporalmente su relación laboral … Sus efectos sobre la reincorporación y la reserva del puesto de trabajo dependen del tipo de excedencia y de las condiciones pactadas, pudiendo variar entre mantener el mismo puesto, uno similar o **solo un derecho preferente de reingreso**. Para conocer cómo se aplica en tu caso concreto, consulta tu convenio colectivo o contacta con Recursos Humanos.

This is the `derecho preferente` shape noted before (5 of the 12 old S2 survivors had it: ln-09, ln-15, NEG-15 ×2, NEG-16). It is not covered by the new E2 shapes (`es un derecho`, `tiene derecho`) because the noun phrase is `un derecho <adj>` with no copula/verb. Not a figure. It names a right that exists in general, not for the reader.

## Positives ×1 (33 drafts)

- Blocked 7/33 = 21.2% (post-check 6, shape 1); passed clean 26; bypass 0.
- Words p50 100, p95 112, max 113.
- Post-check blocks, all E2: `garantizar` ×4 (LP-01 Seguridad Social, LP-61 prevención, LP-65 IMV, LP-75 Seguridad Social), `corresponde` ×2 (LP-11, LP-67, alta en la SS). Shape: S1 (LP-56 "¿Qué es el paro?", "según la normativa vigente").
- The four `garantizar` blocks are definitional uses ("la Seguridad Social garantiza…"). They are E2 false positives on benign definitions, the cost of keeping `garantiza\w*` in E2.

## Named read item for CP-1: flat claims in clean passes

The prompt change did not remove flat claims that pass every lock (no figure, no entitlement shape). Read these at CP-1:

- NEG-13 ("¿Mi empresa está obligada a darme un día por el cumple de mi hijo?"): "no es una figura general prevista de forma automática, sino un beneficio que algunas empresas o convenios deciden incluir como mejora voluntaria" — a flat denial of a general obligation.
- NEG-04 ("¿Me descuentan dinero si falto un día?"): "la empresa puede aplicar una reducción proporcional… Si la ausencia está justificada… ese descuento no se aplicaría" — a flat statement on what applies.
- NEG-17 ("¿Hasta qué edad me pueden hacer contratos temporales?"): "No existe un límite de edad general…" — a flat denial (substantively this one is about a limit).
- NEG-03 / NEG-09 (soft: "suele generarse una prestación económica…", "puede generarse… indemnización"): hedged, but still general claims.

Not caught by any lock; none contain a figure.

## Decision needed

Gate rule says stop on any bypass. Options, not actioned:
1. Add `derecho\s+(preferente|a\s+)` / `un derecho <adj>` to E2 (closes this survivor; small false-positive risk on neutral "derecho laboral" — already tested as pass).
2. Accept the bypass class as audit-only (it does not leak a figure) and redefine the gate.
3. Keep E2 as is and treat it as a CP-1 read item with the flat claims.
