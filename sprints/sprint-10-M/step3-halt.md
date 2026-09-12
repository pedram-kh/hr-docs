# Sprint 10-M — Step 3 halted: hard-requirement miss, injection reverted

**Status:** stopped per the sprint's own gate ("any miss = stop, revert the injection, report"). Staging is back on `claude-sonnet-4-5`, verified. Awaiting direction before any further attempt.

## What was injected

Same mechanism as Sprint 10a (uncommitted code on `sprint-10-M` reaching staging without a real deploy), with the env-export lesson from 10a's close applied:

| change | where | mechanism |
|---|---|---|
| `HR_AI_ANSWER_MODEL: claude-sonnet-4-5` → `claude-sonnet-5` | `docker-compose.staging.yml`, `hr-backend` + `hr-backend-worker` blocks | on-box compose file replaced, then `docker compose up -d --force-recreate hr-backend hr-backend-worker hr-ai` with `vars.sh`'s exports (`AWS_REGION`, `RDS_ENDPOINT`, `STAGING_EIP`, `S3_DOCUMENTS_BUCKET`, `S3_BACKUPS_BUCKET`) sourced first |
| `OCR_PRICING_PER_MTOK["claude-sonnet-5"]` `(2.00, 10.00)` → `(3.00, 15.00)` | `hr-ai/app/providers/claude.py` | `docker compose cp` into the running `hr-ai` container (same idiom as 10a's chunker injection) |
| `config/services.php` + `hr-ai/app/config.py` defaults | local repo only | not yet live-relevant — these are fallback defaults; staging always pins the env var explicitly |

Verified live before running anything: `config('services.hr_ai.answer_model')` → `claude-sonnet-5`; `APP_URL`/`DB_HOST` resolved correctly (no repeat of 10a's `Invalid URI` gap); `hr-ai` container healthy.

## What broke

Ran the four gold cases (`gold-answer-run.php`, real loop, real turns). Two of four answered correctly and matched baseline in substance:

| case | result |
|---|---|
| Navarra, periodo de prueba | answer, `official_convenio`, doc 36 only, 15/30 — **matches baseline** |
| Navarra, trabajo a distancia | answer, `national_law`, doc 75, Ley 10/2021 — **matches baseline** |

Two did not:

| case | run 1 | run 2 (repeat, per "flap once" instruction) | run 3 (diagnostic repeat) |
|---|---|---|---|
| Gipuzkoa, vacaciones | **escalate**, `low_confidence`, `parse_error: true` in `trace_fragment` | **escalate**, `low_confidence`, `parse_error: true` again | **answer**, but `authority_used: ["official_convenio", "national_law"]` — gold expects convenio-only; `completion_tokens: 991` against a `max_tokens: 1024` ceiling |
| Gipuzkoa, trabajo a distancia | **escalate**, `low_confidence` | **answer**, `national_law`, Ley 10/2021 — matches baseline | not re-run (resolved) |

**Trabajo a distancia's single flip is read as an ordinary flap** (same class of margin non-determinism the 10a review already disclosed for Q4 — three LLM calls in the loop, occasional flap expected, and the second run matches baseline exactly).

**Vacaciones is not a flap — it is a repeatable miss (2 of 3 runs), and the one run that did parse still failed the hard requirement** (citation/authority set gained `national_law` alongside `official_convenio`, contradicting the gold claim's "convenio only"). This hits Step 3's named hard requirement directly: *"all four gold figures byte-identical in substance... with same authority and citation docs."*

## Root-cause hypothesis (evidenced, not certain)

`/synthesise` calls `client.messages.create(model=config.model, max_tokens=1024, ...)` — unchanged by this sprint (scope is model-string only). `/synthesise` has **no retry-on-truncation path** (unlike `/ground`, which retries once at a larger budget on `stop_reason == "max_tokens"`); an unparseable/truncated response there is a silent, terminal escalation.

Sonnet 5 produced measurably longer answers on every real call observed in this session (visible qualitatively in the trabajo-a-distancia answer text, which runs noticeably longer/more elaborated than the pre-swap baseline; visible quantitatively in the one successful vacaciones run using 991 of the 1024-token budget). A small isolated diagnostic call (short system+user prompt, no real chunk context) came back with `thinking_tokens: 0` and only 106 output tokens — so the pressure is not literal "thinking" tokens eating the budget on trivial calls, it is that Sonnet 5's real-task completions for this endpoint's actual prompt (chunk context + citation contract) are longer than Sonnet 4.5's were, and 1024 was tuned against the old model's terser style. When a completion runs long enough to be cut mid-JSON, `_extract_json()` throws and the turn escalates with no visibility into why (`parse_error: true` is all the trace records).

This is exactly the risk flagged before Step 3 started (CP-A follow-up, item 2): *"Sonnet 5's default adaptive thinking consumes tokens from that same budget... expected count is zero. If any occur, stop and report before continuing."* It occurred on the very first real check, at a 2-of-3 repeat rate on the one question that exercises the longest/most source-dense answer in the gold set.

## What was NOT run

Per the gate, the positive set (13q) and both negative sets (15q each) were **not** run against Sonnet 5 — stopping at the first hard-requirement miss, before spending further real API cost/traffic against a configuration that already failed the gate. `max_tokens` for both endpoints, for the record: `/synthesise` = 1024 (no retry); `/ground` = 4096, retried once at 8192 on `stop_reason == "max_tokens"` (Correction-04's existing truncation-retry logic — `/ground` was not observed to fail in the three real calls made this session, only `/synthesise`).

## Revert, verified

- `docker-compose.staging.yml` restored from the pre-injection on-box backup (`md5 82326665cfd369f22acd6742b13122a3`, matches the pre-Sprint-10-M file byte-for-byte).
- `hr-backend`, `hr-backend-worker`, `hr-ai` force-recreated from the clean deployed images (drops the `docker compose cp`'d `claude.py` too — hr-ai's pricing table is back to its pre-sprint stale entry, not the corrected one, since a full revert should not leave a partial injection behind).
- Verified live post-revert: `answer_model` → `claude-sonnet-4-5`, `router_model` → `claude-haiku-4-5` (unaffected throughout), `hr-ai`'s `claude.py` back to the original `OCR_PRICING_PER_MTOK` table.
- The local `sprint-10-M` branch (all three repos) still carries the attempted diff, uncommitted — kept as the record of what was tried, not discarded, pending direction below.

## Open question for Pedram

The scope fence says "no prompt-text changes to any endpoint — model swap only, so the measurement isolates the model variable," and the CP-A follow-up separately said "no effort-control changes in this sprint regardless of the result." Both of those pre-emptively rule out the two most direct fixes (raise `max_tokens`, or set `effort` lower to shorten completions) as in-scope for this sprint's own definition. That leaves three paths, and the choice is yours, not mine to make:

1. **Treat this as a genuine model-behavior regression and stop the sprint here** — Sonnet 5 does not swap in cleanly at the current `max_tokens` ceiling for at least one real question shape (long, multi-source, mixed-authority answers), report as-is, no merge.
2. **Authorize `max_tokens` as an explicitly separate, named exception** to the "model swap only" fence — it is a budget parameter, not prompt text or a sampling param, and Sonnet 5's own release notes are what make it newly load-bearing; if authorized, the fix is narrow (raise `/synthesise`'s 1024, matching it to a real headroom figure, e.g. 8x smallest observed overflow) and re-run only the gold set to confirm.
3. **Descope to `/ground` only** — `/ground` has its own truncation-retry logic already and was not observed to fail in three real calls; `/synthesise` could stay on `claude-sonnet-4-5` if that split is acceptable (though the prompt's own scope explicitly names both together, so this would need your explicit re-authorization too).
