# Sprint 1 — Correction 02 (pre-commit): duplicate-numero handling

> Paste the block below into Cursor. One change: stop silently overwriting on a duplicate `numero` in the registry import; retain one convenio and fold the variant(s) into `convenios.aliases`, preferring non-null field values, and log every collapse. Record the change in `data-model.md`, append to `review.md`, and stop — still do not commit.

---

Sprint 1 has one more pre-commit correction. The registry `01_listado_convenios.xlsx` contains a real duplicate-`numero` case that the import currently collapses silently with `updateOrCreate`, losing data. Fix the import to make the collapse a deliberate, lossless, logged decision.

**The case:** numero `20000785011981` (Gipuzkoa cleaning, A3 `100008`) appears on two rows that differ:
- row A: `CONVENIO = "LIMPIEZA DE GIPUZKOA"`, `COMPLEMENTO IT = "Grupo Complementos IT 001"`
- row B: `CONVENIO = "LIMPIEZA EDIFICIOS Y LOCALES"`, `COMPLEMENTO IT = NULL`
Same numero, A3, province (`GUIPUZCOA`), and hours. This is almost certainly one convenio under two name spellings (a formal title + a colloquial one), not two distinct agreements — but the import must not decide that silently.

**Change 1 — `convenios.aliases`.** If `convenios` has no `aliases` column, add one (`jsonb NULL` / array of strings), consistent with `territories.aliases` and `sectors.aliases`. Migration in `hr-backend`.

**Change 2 — duplicate-numero handling in `registry:import`.** When two or more rows share a `numero`:
- retain a **single** convenio row keyed on `numero`;
- choose one `CONVENIO` value as the canonical `name` (prefer the more formal/longer title — here `LIMPIEZA EDIFICIOS Y LOCALES`) and **fold every other distinct name into `convenios.aliases`** (so a document or query referencing either name resolves to the same convenio — no name is lost);
- when collapsing each remaining field, **prefer the non-null / more-complete value** (so `COMPLEMENTO IT = "Grupo Complementos IT 001"` is retained, not overwritten by the NULL row);
- **log every collapse** in the import summary and via `Log::warning` (ADR-0011 managed-growth: an ambiguous merge is surfaced, not silent), e.g. `numero 20000785011981: merged 2 rows; canonical name "LIMPIEZA EDIFICIOS Y LOCALES"; aliases ["LIMPIEZA DE GIPUZKOA"]; retained COMPLEMENTO IT "Grupo Complementos IT 001"`.
- keep the whole import idempotent: re-running produces the same single convenio, same aliases, no duplicates, and the same logged summary.

**Change 3 — parser/resolver.** The convenio resolver should match a parsed/registry convenio name against both the canonical `name` and `aliases` (same alias-aware matching already used for territories/sectors), so either spelling resolves to the one convenio.

**Re-run and confirm:** after `migrate:fresh --seed` + `registry:import`, confirm the import now yields the same convenio count as before for distinct numeros, `20000785011981` exists once with `name = "LIMPIEZA EDIFICIOS Y LOCALES"`, `aliases` containing `"LIMPIEZA DE GIPUZKOA"`, and `COMPLEMENTO IT` retained; and that the collapse is reported in the summary/log. Re-running a second time changes nothing.

**Record in `data-model.md`:** under `convenios`, add the `aliases` column and a short note: duplicate `numero` rows are merged into one convenio (canonical name + folded name aliases, non-null field preference) and every merge is logged — the registry is treated as one-convenio-per-numero, with name variants carried as aliases.

Then append a brief note to `hr-docs/sprints/sprint-01/review.md` (the merge rule + the re-run result), and stop. Do not commit until I confirm.

---

Paste the result back here. Once this re-run looks right and you've done your browser eyes-on test, that's the commit (all four repos).
