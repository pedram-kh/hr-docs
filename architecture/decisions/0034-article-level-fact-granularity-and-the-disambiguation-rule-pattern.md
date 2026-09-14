# ADR-0034 — Multi-motivo articles bundle into one article-level fact; topic-boundary traps close with an explicit negative-definition prompt rule, not a persistence-layer redesign

**Status:** accepted (Sprint 10c, CP-A — permisos; addenda through sprint close after CP-4/festivos)

## Context

Sprint 10c's new per-(convenio, topic) segmentation driver (`ReferenceFactProposalService::proposeForTopic()`) hit two real, load-bearing bugs on its first live topic (permisos), both confirmed against real staging data and recorded in full in `sprints/sprint-10c/review.md`:

**Bug A — the logical-key collision.** `ReferenceFactProposalService::persist()`'s upsert key is `(source, source_document_id, convenio_id, topic_id, job_category_id, group_label, validity_start, validity_end)` — built for 7b-2's single-valued topics (one canonical number per scope, e.g. "periodo de prueba"). Permisos is not single-valued: a real convenio's "Licencias retribuidas" article enumerates 5–8 independent motivos (matrimonio, fallecimiento, traslado de domicilio, exámenes…), none of which vary by group or job category, so every motivo the model proposed as a separate fact collapsed onto the *same* logical key. `persist()`'s upsert silently overwrote fact 1 with fact 2, fact 2 with fact 3, and so on — **4 of 5 real, correctly-extracted facts were destroyed before any human ever saw them**, with the review queue showing one surviving row and no indication four others had ever existed. This is data loss, not a flagged uncertainty — a strictly worse outcome than D4's flag-and-persist backstop, which exists precisely to keep information rather than discard it.

**Bug B — the topic-boundary trap, confirmed live for a topic the plan had predicted would be the easiest of the seven.** Maternidad/paternidad/lactancia content (statutory suspension-of-contract machinery, 16 semanas, permiso parental, adaptación de jornada por cuidado del lactante) sits inside or immediately beside the same "Permisos"/"Licencias retribuidas" article, anchored on the same word ("permiso"). Two of six eval convenios' run-1 facts were entirely mis-scoped lactancia content bundled under `permisos`. This is structurally identical to D2's preaviso-polysemy finding from the plan-gate — the same trap, in a topic nobody had flagged as risky.

Both bugs were fixed with one prompt iteration (documented in full in `review.md`); this ADR formalizes the two fixes as the tranche-wide pattern for every remaining multi-motivo topic (jornada, vacaciones, festivos, and whichever of descanso/horas extraordinarias proceed past their go/no-go gates), rather than leaving them as one-off permisos fixes, and records the alternative that was considered and rejected for Bug A.

## Decision

### 1. Article-level fact granularity: one fact per (document, topic, scope) for an enumerated multi-motivo article, full breakdown in `value`/`raw_values`

When a convenio article enumerates multiple motivos under one topic with no group/category variation, the segmentation prompt (`hr-ai/app/providers/claude.py`, rule 3) now mandates **one bundled fact**, not N independent facts — the complete per-motivo desglose lives inside that single fact's `value`/`raw_values`. This is not a new idea invented for permisos: it directly generalizes **D5's multi-year-schedule precedent** (already accepted for vacaciones/jornada — "breakdown in `value`/`raw_values`, validity = source document window, rule 8 untouched"), extended from "one scope's multi-value breakdown across time" to "one scope's multi-motivo breakdown across an enumerated list." The same reasoning applies: the model never invents structure that isn't in the source text, it only chooses how many `reference_facts` rows one real article's content becomes, and the identity discriminator problem this creates (below) is solved once, tranche-wide, rather than once per topic.

This closes Bug A **by construction**, not by making the upsert key smarter: there is now exactly one fact per (document, topic, scope) call for this article shape, so there is nothing left for the logical key to collide on.

### 2. The disambiguation-rule pattern: an explicit negative definition, added when live inspection finds a real leak — not speculatively, for every topic in advance

Bug B's fix (`PERMISOS_DISAMBIGUATION_ES`) follows the exact shape D2 already established for preaviso (`PREAVISO_DISAMBIGUATION_ES`): an explicit list of adjacent, keyword-overlapping concepts the topic must **never** include, even when they share a word or sit in the same article/chapter (for permisos: cuidado del lactante/lactancia, maternidad/paternidad suspension-of-contract machinery + permiso parental + adaptación de jornada por cuidado, permisos *sin sueldo* — the separate "permisos no retribuidos" topic — and a bare, undeveloped remission to an external statute with no convenio-specific figure).

The pattern, formalized: **a disambiguation rule is written when live inspection of real convenio text demonstrates an actual leak for that specific topic** (D2's plan-gate sampling for preaviso; this sprint's run-1 for permisos), not preemptively for all seven tranche topics on the theory that any of them *might* have one. Two topics have needed one so far, for different adjacent-concept reasons; the tranche's remaining topics are checked for this the same way — a gold-fixture eval built from real passages, scored for mis-scope as the hard gate — at their own checkpoint, not guessed at in advance.

### 3. Rejected alternative: widen the upsert key with a per-motivo discriminator

The alternative to bundling was adding a per-motivo field (e.g. a model-generated `motivo_label`) to `persist()`'s logical key, so each motivo would upsert onto its own row instead of colliding.

**Rejected.** The model's motivo labels are not stable across calls: the same real "matrimonio" clause can be labeled `"matrimonio"`, `"boda"`, or `"matrimonio o pareja de hecho"` depending on run-to-run paraphrasing, and the *count* of motivos the model splits a given article into is not guaranteed stable either (an article with 5 real motivos could be split into 5, 4, or 6 facts across two runs, e.g. if two closely related motivos are merged or split differently). Widening the key on an unstable field would not fix the collision problem — it would trade "silent overwrite" for "silent duplication": every re-run (a prompt iteration, a re-segmentation after a source document is amended) would mint *new* rows alongside the old ones instead of upserting onto them, because the label the new row hashes against rarely matches the old row's label byte-for-byte. That directly breaks **Sprint 7d's supersession machinery (ADR-0024)**, which depends on the logical key remaining a stable, scope-only identity so that "is this the same fact, updated" and "is this a new, independent fact" stays a question a human answers once per real-world change — not once per run, for every article, forever. A discriminator that is itself model output is exactly the kind of unstable ground ADR-0024's semantic-comparison design was built to avoid depending on.

A narrower version of this idea — index the motivos by their position/order within one call instead of a label — was also considered and rejected on the same grounds: ordering within an enumerated list is not guaranteed stable either (the model is not asked to preserve source order, and D5-style bundling doesn't need it to).

### 4. Edit-before-verify: confirmed to already exist — not a queue-capability gap

Checked directly against both layers before writing this ADR:

- **Backend:** `ReferenceFactController::update()` (`hr-backend/app/Http/Controllers/Admin/ReferenceFactController.php:146-206`) applies to a fact of *any* `status` or `source` — there is no guard restricting it to `verified` facts, and no separate code path for `ai_agent`/`needs_review` rows. Every edit appends an `admin_manual` `tag_events` row (append-only provenance, unchanged); a scope-affecting change (convenio/job_category/validity) still requires the Sprint-3 `confirm_scope_change` gate (409 without it).
- **Frontend:** `ReferenceFactPanel.tsx:267-276` renders an edit control for every fact a `knowledge.edit` holder can see, and **labels it explicitly** for this exact case — `isAi && fact.status === 'needs_review'` renders the button as **"Fix then verify"** rather than plain "Edit", i.e. the UI already names the workflow this ADR's Context needed: correct a bundled fact's `value`/`raw_values` (or any other field) before verifying it, in one action, without rejecting and manually recreating anything.

**Conclusion: the edit-before-verify path exists end-to-end and predates this sprint** (it is the same bounded-edit machinery from 7b-1, already wired to 7b-2's AI-proposed facts). There is no reject+manual-create workaround needed and no queue-capability gap to record. A reviewer who disagrees with one motivo inside a bundled fact edits `value`/`raw_values` directly, then verifies — they cannot yet verify *one motivo* independently of the other seven in the same row (see Consequences), but that is a granularity trade-off, not a missing capability.

## Consequences

**What gets better.** Multi-motivo articles are captured completely and losslessly — Bug A's 4-of-5-facts-destroyed failure mode cannot recur for any topic that adopts this pattern, by construction rather than by a smarter collision check. Topic-boundary leaks (Bug B's class) get a repeatable, auditable fix pattern: found by a real-passage gold-fixture eval, closed by one explicit negative-definition prompt rule, verified by re-running the same eval — the same shape twice now, for two different topics.

**What we accept — the granularity trade-off.** A reviewer verifying, rejecting, or fixing a bundled fact acts on *all* its motivos in one row, not each independently: disagreeing with just one figure inside an 8-motivo bundle means editing that one value via "Fix then verify," not partially verifying seven and flagging one. This is the same shape as D5's already-accepted vacaciones/jornada trade-off, now extended tranche-wide to every multi-motivo topic rather than staying scoped to multi-year schedules. **This is a real precedent, not a settled-forever design decision** — recorded here as accepted for this tranche, flagged for eyes-on rather than treated as invisible.

**What this depends on.** Each remaining tranche topic gets its own real-passage gold-fixture eval at its own checkpoint (CP-B onward) before batching, specifically checking for its own topic-boundary adjacent concepts the same way permisos's was found — this ADR does not claim the pattern generalizes for free without that check being repeated per topic.

## Alternatives considered

**Widen the upsert key with a model-generated per-motivo label.** Rejected — §3 above: unstable across runs, breaks ADR-0024 supersession, trades silent overwrite for silent duplication.

**Widen the upsert key with a positional/ordinal discriminator instead of a label.** Rejected — same instability: the model's split count and ordering are not guaranteed stable across runs either.

**Redesign `persist()`'s logical key and the review queue to support per-motivo sub-rows under one parent fact.** Not attempted this sprint (noted in `review.md`'s "Open decision" section as a real possibility worth a future sprint's dedicated design, not a change to make inside a topic-eval checkpoint). Bundling was chosen because it closes the sprint's actual blocking bug (data loss) without a schema/queue redesign; a sub-row model would recover per-motivo independent verification but is a materially larger change than this checkpoint's authorized scope.

**Preemptively write a disambiguation rule for every tranche topic now, before any topic-specific leak is confirmed.** Rejected — a rule written from guessed adjacent concepts rather than a demonstrated live leak risks both false restraint (excluding real content the topic should include) and false confidence (missing the actual leak because it wasn't the one guessed). The pattern's own discipline — find it live, fix it, prove the fix against the same real passages — is what makes each rule trustworthy; front-loading it would trade that for speed without evidence.

## Addendum (CP-B, vacaciones) — Bug A recurred in a NEW shape; §1's "by construction" claim was too strong, now fixed at the rule level

This ADR's §1 claimed bundling closes Bug A "by construction... there is
nothing left for the logical key to collide on." That claim covered the
shape Bug A was originally found in (one enumerated multi-motivo article,
letters/numbers, not bundled). It did **not** cover a second, structurally
different collision shape, found live three more times after this ADR was
first accepted — once in permisos' own batch run (topic 1, convenio 11,
below), and twice in the vacaciones CP-B gold-fixture eval (convenios 3 and
25, `sprints/sprint-10c/eval/vacaciones/`):

**Cross-passage collision (Bug A, variant 2):** the filtered passages fed to
one call sometimes contain the topic's real, dedicated article **plus** a
second, unrelated or tangential passage elsewhere in the document that also
mentions the topic's anchor word — not multiple motivos *within* one
article, but content from **two separate articles**. Rule 3 (as originally
written) only forced bundling *within* an already-identified enumerated
list; it said nothing about passages the model treats as structurally
separate. The model proposed 2 independent facts in each case, both landing
on the identical logical key (same document, convenio, topic, no group, same
validity), and `persist()`'s upsert silently overwrote the first with the
second — the exact Bug A failure mode, just triggered by a different input
shape:

- **Permisos batch, convenio 11** (`documents.id=76`, IV Convenio Ocio
  Educativo estatal): Article 64 "Permisos retribuidos" (the real,
  comprehensive, correctly-bundled multi-motivo article, a-f) was silently
  destroyed by a second fact proposed from Article 90 "Hijos/as con
  discapacidad" (a much later, unrelated article in a different chapter that
  happens to also grant a "permiso de ausencia"). Found post-hoc during the
  batch run's yield audit (fact id=109 inspected, traced back to the source
  pages, found wrong); **deleted** (not superseded by a re-run — see
  `review.md`'s permisos-batch section for the full trace and the decision
  to leave convenio 11 at 0 facts pending this prompt fix, rather than ship
  a fact silently missing 5 of 6 real motivos into the review queue).
- **Vacaciones eval, convenio 3**: Article 25's real 4-year multi-year
  schedule (35/36/37/37 días) was destroyed by a second fact from an
  unrelated, group-conditional accrual clause elsewhere in the document
  (personal en régimen de estancias vacacionales/educativas).
- **Vacaciones eval, convenio 25**: Article 36's real vacaciones entitlement
  (30 días, 21 consecutive) was destroyed by a second fact extracted from a
  vacation-interruption-by-birth-leave narrative embedded in the nacimiento
  article — itself also a restraint failure (no day-count figure, wrong
  topic), compounding into data loss instead of just an over-extraction.

**Fix, generalized at the rule level (not per-topic):** rule 3 in
`hr-ai/app/providers/claude.py` gained an explicit cross-passage-merge
clause: if the same scope/topic content appears in more than one filtered
passage, merge into the same fact — never propose a second independent fact
for a secondary passage, because a same-scope collision silently destroys
the first. This is deliberately NOT a per-topic disambiguation rule (unlike
§2's pattern): it is a correction to §1's own bundling mechanism, since the
failure is in *how many facts get proposed per call*, not in *which topic's
content is in scope*. Verified fixed live: vacaciones run2 (post-fix)
produced exactly 1 fact per convenio, 0 collisions, across all 6 eval
convenios, including convenio 3 (`review.md`'s CP-B section has the full
run1→run2 diff and `score_eval.py` output).

**Revised claim:** bundling closes Bug A's *original* shape (multiple
motivos within one already-identified enumerated article) by construction.
It does **not**, on its own, prevent a *second* independent fact being
proposed from a structurally separate passage — that required the explicit
cross-passage-merge instruction added here. Both together are what actually
closes Bug A for a given topic; a future topic's gold-fixture eval should
still treat this as a real risk to check for (multiple non-adjacent passages
matching the same topic anchor), not assume the rule now covers every case
by construction either.

## Addendum 2 (CP-3/jornada, CP-4/festivos, sprint close) — the pattern generalizes to a RECALL fix, and once, deliberately, to a pre-emptive one

Two more rules were added this sprint, both worth reconciling explicitly
against §2's stated discipline ("a disambiguation rule is written when
live inspection... demonstrates an actual leak," and the Alternatives
section's rejection of writing rules preemptively "before any
topic-specific leak is confirmed").

**Rule 16 (jornada, `JORNADA_DISAMBIGUATION_ES`) — the pattern's first
   140|RECALL fix, not a restraint one.** Every rule before it (13/14/15,
permisos/vacaciones) fixed an OVER-extraction: the model pulling in
adjacent content it shouldn't. Convenio 25's jornada run-1 was the
opposite — a FALSE NEGATIVE, the model concluding "no dedicated article"
when one genuinely existed, buried under ~30 incidental mentions of the
anchor word. (This was *initially* misdiagnosed as the true root cause;
the real bug turned out to be the silent input-truncation cap — see
`review.md`'s CP-3 section — but rule 16 was still added as legitimate
defense-in-depth once written, since a model faced with heavy anchor
noise plausibly could give up even with the full text present.) The
pattern generalizes cleanly to this shape: found live, fixed with an
explicit instruction ("search exhaustively by heading before concluding
absence"), verified against the same real passages — same discipline,
opposite failure direction.

**Rule 17 (festivos, `FESTIVOS_DISAMBIGUATION_ES`) — the pattern's only
PRE-EMPTIVE rule this sprint, and why that does not contradict this ADR's
own rejected alternative.** The Alternatives section rejected writing
rules "before any topic-specific leak is confirmed... from guessed
adjacent concepts." Festivos' rule was written before run 1, but not from
a guess: every one of the 6 eval convenios' anchored pages was read in
full first, and that direct reading *confirmed* (not guessed) that 4 of 6
convenios' only anchored content was retribución, including one hard case
(convenio 18) with real dates attached. The distinction the Alternatives
section actually cares about is evidence vs. speculation, not
before-first-run vs. after — reading real passages before writing a rule
is exactly what every other rule in this ADR is built on, just moved one
step earlier because this pre-read is what built the gold set itself.
Confirmed proportionate by the result: 6/6 clean on run 1 (the only topic
this sprint needing no correction) and the batch's independent sample
(1/9 convenios, matching the eval's ~20% ratio) agreeing rule 17 did not
over-suppress genuine content.

**Running tally of the disambiguation-rule pattern, this tranche:** 13
(preaviso, D2, plan-gate), 14 (permisos, Bug B), 15 (vacaciones), 16
(jornada, recall), 17 (festivos, restraint, pre-emptive-but-evidenced) —
five rules across four topics that needed one; two topics (jornada, per
its own rule 16, and every topic's rule 3 cross-passage-merge from
Addendum 1) needed a mechanism-level fix instead of a purely topic-level
one. No topic has needed more than one topic-specific rule.

## References

- ADR-0020 (inert until verified — the spine this ADR's granularity trade-off does not weaken: a bundled fact is still fully inert, still a single human decision, just a coarser-grained one)
- ADR-0011 (managed vocabulary growth — the closed-set/bind-only discipline this sprint's topic-mapping fixes and the still-open `preaviso` topic-creation question both sit under)
- ADR-0022 (reference-fact segmentation agent — the 7b-2 substrate `persist()` and the logical key belong to)
- ADR-0024 (semantic comparison, human-adjudicated, fail-toward-caution — the supersession machinery a per-motivo label discriminator would have broken)
- `sprints/sprint-10c/plan.md` §D5 (the multi-year-schedule precedent this ADR generalizes), §D2 (the preaviso disambiguation-rule precedent this ADR names as the pattern's first instance)
- `sprints/sprint-10c/review.md` (the run-1/run-2 measurements, the two bugs' full evidence, the cost, and the open granularity-trade-off flag this ADR formalizes)
- `sprints/sprint-10c/data-pass-handoff.md` (the sprint-close deliverable — ranked verification ask, group-tree unlock quantification, the convenio-18 near-duplicate example, the festivos coverage note)
