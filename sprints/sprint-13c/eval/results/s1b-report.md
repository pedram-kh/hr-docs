# S1b — prompt iteration for length + the two fixes (2026-09-30)

## Fixes made first
1. **Web → model-knowledge fallback** (`GeneralKnowledgeTool`, sub-flag on only). A web attempt that leaves nothing usable (empty draft, or a draft that fails `/ground` against its own page) now asks hr-ai once more with `skip_web` (no catalogue fetch, model-knowledge prompt); that draft then meets the same post-check and shape check as any model-basis answer. If the second draft is also unusable the web outcome stands. The trace records `general_lane.fallback {from, reason, web_cost_usd, web_draft_words}`. Sub-flag off: no second call (`Sprint13cLaneGoldenTraceTest::test_the_fallback_is_model_knowledge_subflag_only`).
2. **`general_lane_blocked.sub` is written** (`trace.agent.general_lane_blocked.sub`): post-check E1/E2 → `entitlement_language`, every other post-check id → `figure`, shape check → `shape` (new), availability pre-screen → `question_prescreen`. New MATRIX key `general_lane_blocked.shape` (backend explainer + frontend label; MATRIX 70 → 71).
   **Goldens re-recorded, disclosed:** `28_lane_postcheck_block` and `29_lane_shape_block` (trace gains `agent.general_lane_blocked.sub`; the card facts now name the real block: 29 was "question_prescreen / pide una cantidad…", now "shape / cita una ley, un artículo…"). New goldens `32_lane_web_empty_fallback`, `33_lane_web_ungrounded_fallback`. The 25 Sprint-13 goldens and 26/27/30/31 are unchanged.

## Prompt iteration (one)
`GENERAL_KNOWLEDGE_MODEL_SYSTEM_PROMPT`: "de 60 a 110 palabras … uno o dos párrafos" → "unas 90 palabras (entre 70 y 100; NUNCA más de 120), en UN solo párrafo. Sé breve: una definición y para qué sirve, sin enumerar supuestos, casos ni ejemplos." Nothing else changed; the S2 cap stays 120; E2 untouched.

**Frozen prompt sha256: `131f6a8f1ae93a1215287df1931847032bc8af91290e1faa21080279dd28fb05`** (pinned by `hr-ai/scripts/general_lane_fetch_test.py` check 17; staging confirmed identical). Web prompt unchanged (`e9e2c6a1…`).

## Result: positives ×1, all 33 (raw: `s1b-lane-forced-raw.log`)
| | S1 (before) | S1b (after) |
|---|---|---|
| model-basis drafts | 28 (+5 lost to web/unavailable) | **33** (5 via the fallback: 2 `web_empty_draft`, 3 `web_ungrounded`; all 5 passed clean) |
| words p50 / p95 / max | 114 / 129 / 134 | **102 / 110 / 118** (target ≈ 90: **not reached**, p50 is 12 over) |
| same 28 drafts: p50 / p95 | 114 / 129 | 101 / 108 (max 118) |
| blocked by shape (S2) | 4 | **0** |
| blocked by post-check | 6 | 4 (all E2) |
| passed clean | 17 | 26 |
| **block rate (R1)** | 10/28 = 35.7% | **4/33 = 12.1%** |
| AUDIT_BYPASS | 1 | 3 (all `obligatorio`/"es obligatoria" in generic institutional statements: alta en la SS, SMAC, cotización) |
| draft cost p50 / total | $0.0062 / $0.22 | $0.0060 / $0.26 (incl. the 5 abandoned web attempts) |
| latency p50 / p95 | 4.0 s / 5.0 s | 3.7 s / 4.4 s |

Spend this stage ≈ $0.30 (+ S1's $0.30 earlier). The prompt moved length by about 12 words, not the ≈ 25 asked; it is frozen as is (one iteration, per instruction).
