# Sprint 1 — Correction 01 (pre-commit)

> Paste the block below into Cursor. Two changes: tighten the territory-level classifier (code), and record the convenio-derived-scope decision (doc). Each change is recorded in the named file. After this, write a short addendum to `review.md` and stop — still do not commit until I confirm.

---

Sprint 1 is approved pending two pre-commit corrections. Apply both, record each in the named file, append a short note to `hr-docs/sprints/sprint-01/review.md`, and stop. Do not commit yet.

**Correction 1 — robust territory-level classifier (code + data-model).**
The registry import currently classifies a territory's `level` by matching the `PROVINCIA` string (`ESTATAL → national`, `ANDALUCIA → regional`, else `provincial`). This hardcodes the two known cases by name: a second region appearing in the registry (another autonomous-community convenio) would fall into "else" and be silently mislabeled `provincial`. Replace the name-match with the numero-prefix range rule, using the `PROVINCIA` string only as a cross-check:

- prefix `99` → `national`
- prefix in `01`–`52` → `provincial`
- any other prefix (the autonomic range, e.g. Andalucía's `71`) → `regional`

If the prefix-derived level and the `PROVINCIA` string disagree, or the prefix is outside all known ranges, **do not silently default** — log the row and surface it (import summary + a flagged territory) for human confirmation, consistent with ADR-0011's managed-growth principle (the import may create vocabulary, but an ambiguous classification should be visible, not guessed). Re-run the import and confirm the 12 existing territories classify identically to before (Andalucía still `regional` code `71`, Estatal `national` `99`, the rest `provincial`). Record the rule change in `data-model.md` (the `territories` / `convenios.territory_id` notes that currently describe the `PROVINCIA`-string classification).

**Correction 2 — record the convenio-derived-scope decision (data-model only).**
Cursor's design (review §5) makes territory + sector **derived via the convenio**, not columns on `documents`; the only re-assignable document facets are `convenio` and `document_type`. This is a sound, deliberate normalization (it makes a document-vs-convenio territory contradiction structurally impossible) but it is a deviation from ADR-0001's independent-facet framing and has one latent gap. Add a short note to `data-model.md` near the `documents` table recording:

- territory + sector are **derived from the document's `convenio`** (not stored on `documents`); re-assignable document facets are `convenio` and `document_type`; the parser's territory/sector resolution is used only for conflict detection + provenance, with the convenio authoritative;
- national-law docs carry universal scope via `authority_level = national_law` (convenio + territory NULL);
- **known limitation:** a document that is **territory-scoped but has no convenio** (a regional/provincial non-convenio policy doc) cannot currently carry its scope — the corpus has no such case today (the only no-convenio docs are national), and this is revisited if one appears.

Do not change any document/convenio schema for Correction 2 — it is a documentation note recording an accepted decision and its known limitation.

Then append a brief addendum to `review.md` describing both corrections (the new classifier rule + the re-run result, and the data-model note), and stop. Do not commit until I confirm.

---

Paste the addendum back here when done. After I confirm the classifier re-run looks right, you do your browser eyes-on test, then commit all four repos.
