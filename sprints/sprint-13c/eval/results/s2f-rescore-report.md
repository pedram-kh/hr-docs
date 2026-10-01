# Audit aligned to the decision; all logs re-scored offline (no spend)

Change (`GeneralLanePostCheck::audit()`): `obligatori*` is no longer an entitlement word as a bare adjective. It counts only in `es obligatori*`, `obligatori* que`, `obligado/a(s) a`. `scan()`/E2 are unchanged by this step. Tests: bare `obligatorio/obligatoria` neither scanned nor audited; the three shapes audited. Script: `probes/rescore-audit.php` (re-runs today's `scan()` and `audit()` on every stored draft; a previously clean/bypass draft that `scan()` now blocks counts as blocked).

| Log | Drafts | Bypasses now | Figures leaked | Cleared by the audit alignment | Cleared by E2 shapes |
|---|---:|---:|---:|---|---|
| **S2 ×3, new prompt** (s2d + s2e) | 129 | **0** | 0 | ln-20-cov, NEG-08 | — |
| **Held-out ×1** | 10 | **0** | 0 | — | — |
| S2c ×1 (before the E2 shape) | 43 | 0 | 0 | — | NEG-16 |
| S1c positives | 33 | 0 | 0 | — | — |
| S1b positives (old prompt) | 33 | 0 | 0 | LP-11, LP-45, LP-75 | — |
| old S2 ×3 (old prompt) | 129 | 1 | 0 | ln-20, NEG-08 ×2 | ln-09, ln-15, ln-17 ×3, NEG-14, NEG-15, NEG-16 |

The two S2 ×3 survivors were cleared only by the audit alignment (your decision), not by a new block. Disclosed: the audit got narrower, so the 0 on S2 ×3 is partly definitional.

Remaining in the old-prompt run: NEG-15, "…derecho de reingreso" (E2 has `un derecho` / `derecho preferente` but not `derecho de <noun>`). It does not occur in the new-prompt runs (0 of 172 drafts); listed as a residual shape.

## CP-1 read list carried forward

Flat claims in clean drafts: NEG-13, NEG-17, NEG-12, NEG-04, ln-12, ln-06.
Existence affirmations: HO-02, HO-08, HO-10, HO-07.
