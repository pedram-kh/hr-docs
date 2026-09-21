# Sprint 10c — Data-pass handoff

> Named deliverable per plan §D.11 step 11 / the build-authorization's
> file path. Written at sprint close (after topic 4/festivos, the
> tranche's authorized stopping point), for whoever runs the go-live data
> pass — HR time, not engineering, per `roadmap.md` §8's "Go-live data
> pass (parallel; HR time, not engineering)." Every number below is live,
> queried against staging on 2026-09-14, not carried forward from a plan
> estimate.

## 1. What this sprint added to the review queue

| Topic | `needs_review` facts | Eligible convenios | Convenios with a fact |
|---|---|---|---|
| permisos retribuidos | 16 | 15 | 15 |
| vacaciones | 16 | 15 | 15 |
| jornada | 30 | 15 | 15 |
| festivos | 3 | 15 | 3 |
| **Sprint 10c total** | **65** | | **15 of 15** (every eligible convenio has a fact from at least one of the 4 topics) |

(Pre-existing, unaffected by this sprint: 82 `needs_review` + 6 `verified`
periodo de prueba facts, reaching a further 7 convenios outside this
sprint's 15-convenio active-text pool — 22 distinct convenios carry a
reference fact platform-wide. Grand total across all topics: 153 facts,
147 `needs_review`, 6 `verified`.)

Nothing above is answerable yet (ADR-0020) — every one of these 65 facts
needs a human to open it, check it against the source excerpt, and either
verify or reject it before `ReferenceFactRouter` will ever route a
question to it.

## 2. Ranked verification ask — work topics in this order

The review queue itself now orders by risk (uncertainty first, then
lowest confidence) within a topic filter (D7, this sprint), but which
**topic** to open first is a demand question — answered directly by the
question-clustering pipeline's own topic demand scores (`topic_demand_scores`,
run 2026-09-14, `QuestionClusteringService::computeTopicDemandScores()`):

| Rank | Topic | Demand score | Volume (unanswered question count) | Escalation rate | This sprint's `needs_review` count |
|---|---|---|---|---|---|
| 1 | vacaciones | 272 | 50 | 68% | 16 |
| — | periodo de prueba (pre-existing) | 248 | 46 | 67% | 82 |
| 2 | permisos retribuidos | 176 | 50 | 88% | 16 |
| 3 | *preaviso* | 120 | 32 | 94% | **0 — not yet batched, see §5** |
| 4 | jornada | 56 | 16 | 88% | 30 |
| 5 | festivos | 52 | 15 | 87% | 3 |

**Recommendation: verify in this order — vacaciones, then permisos, then
jornada, then festivos.** This is the same order the review queue's own
demand-ranked sort now surfaces by default (D7); this table just makes
the "why" explicit for whoever is prioritizing HR time across topics, not
just within one. Note preaviso's demand score (120) already outranks both
jornada and festivos despite having zero facts to show for it yet — see
§5, this is the strongest evidence for making preaviso the very next
engineering slice, not a data-pass item.

**Within each topic**, verify the facts the review queue itself puts
first (uncertainty-flagged, lowest-confidence first) — no separate
within-topic ranking is needed; that ordering is already live.

## 3. Group-tree unlock quantification — which tree approvals unlock *this sprint's* own output

A fact with a `group_label` set is inert for group-scoped answering until
the convenio has an **approved** `convenio_groups` tree (7f) — the group
label is a string proposed by the segmentation agent, not yet bound to a
real node. Live count, cross-referencing this sprint's 65 new facts
against the 5 convenios with a **pending** (`needs_review`) tree:

| convenio_id | Convenio | Tree status | This sprint's group-labelled facts | Topics affected |
|---|---|---|---|---|
| **18** | ACCIÓN E INTERVENCIÓN SOCIAL | `needs_review` (4 nodes) | **4** | jornada (includes the convenio-18 near-duplicate example, §4) |
| **11** | OCIO EDUCATIVO Y ANIMACIÓN SOCIOCUL | `needs_review` (6 nodes) | **1** | jornada |
| 3 | OCIO EDUCATIVO Y ANIMACION SOCIOCUL (Álava) | `needs_review` (6 nodes) | 0 | — (this sprint's convenio-3 facts are all convenio-wide, no group split) |
| 19 | COEAS NAVARRA | `needs_review` (6 nodes) | 0 | — (same) |
| 4 | — (no active text; this sprint never touches convenio 4 at all — see §6) | `needs_review` (6 nodes) | 0 | — |
| 21 | Hostelería Navarra | **`approved`** (5 nodes) | 0 | — (21's only text document is `retrieval_status='historical'`; its approved tree currently unlocks nothing until 21 gets a current text document — a pre-existing gap, not created by this sprint) |

**Practical ask: approving convenio 18's or convenio 11's pending tree
directly unlocks group-scoped answering for facts this sprint already
proposed** (4 and 1 respectively) — the highest-leverage tree approvals
available right now. Approving 3's or 19's tree is still worth doing (it
unlocks any pre-existing periodo-de-prueba group-labelled facts for those
convenios, per the original 7f/7b-2 work) but has zero effect on this
sprint's own new output.

**Every OTHER convenio with group-labelled facts this sprint (25, 20, 8,
13, 12, 17, 22, 6, 15, 2, 10 — the majority) has no tree row at all yet,
pending or approved** — the 7f group-tree proposal step has simply never
been run for them. Approving a tree is not the blocker there; **proposing
one is** (a separate, cheaper AI step, same human-approval gate
afterward). Not quantified further here — a full "which convenios need a
tree proposed" sweep is its own small piece of work, not scoped to this
handoff.

## 4. Named example: convenio 18's near-duplicate group labels (a judgment call for HR, not a bug)

Two AI-proposed jornada facts sit side by side with near-identical but
not-identical group labels:
- id 135: *"Personal en régimen de guardia o expectativa (disponibilidad)"*
- id 147: *"Personal en régimen de guardia o expectativa"*

Each carries its own figures. Because the labels differ as strings, they
did not collide at the DB layer (no silent overwrite — both survive as
separate `needs_review` rows), but nothing in the system can tell whether
this is the *same* group described twice from two different passages
(the right move: merge, verify one, reject the other) or two genuinely
distinct sub-populations (the right move: verify both). This is exactly
the class of call the AI is deliberately never allowed to make (ADR-0020)
— it is named here as a concrete instance, not left for whoever opens the
queue to puzzle out cold. Recorded permanently in
`HR-PLATFORM-HANDOFF-2026-09-11.md` §7 as well, as a named example of the
judgment calls the wider data pass will face repeatedly (this is very
unlikely to be the only such pair once verification starts in earnest).

## 5. Festivos coverage note — a corpus property, not a system gap

**Only 3 of the 15 eligible convenios (20%) have any genuine
day-count/calendar festivos content at all** — verified directly, page by
page, before and during both the eval and the batch, not inferred from a
low yield. The other 12 convenios' `festivos`-anchored text is entirely
**retribución** clauses (a pay premium — "Plus de festivos" — for
*working* on a festivo), never a grant of extra días de cierre beyond the
official calendar.

**Consequence for HR: festivos employee questions will largely continue
to escalate even after all 3 of this topic's facts are verified.** This
is not a shortfall to chase with a bigger prompt or a wider anchor list —
the gold-fixture eval (6/6 correct, both directions — 2 genuine
extractions, 4 correct zero-yields) and the batch (an independent,
larger sample: 1 fact from 9 convenios, the same ~20% ratio) agree the
model's restraint is *correct*, not a miss. There is no missing fact to
extract for most convenios because most convenios genuinely do not grant
one. If festivos escalations remain high after this data pass, the
right response is a product one (e.g. surfacing the *national/regional*
official festivo calendar as its own answerable source, a different
knowledge type than a convenio-specific reference fact) — not another
engineering pass at this topic's extraction.

## 6. Convenios 4 and 21 — confirmed correctly excluded from every batch this sprint

Per plan §B.6, convenios 4 and 21's only ingested `convenio_text`
documents are both `retrieval_status = 'historical'` (doc 89 and doc 51
respectively) — neither has any `active` text. The new per-(convenio,
topic) driver reads `document_pages` joined to `documents.convenio_id`
filtered to `retrieval_status = 'active'`, the same filter every other
retrieval path in the system uses — so 4 and 21 were **automatically**
excluded from all four topics' dry-runs and batches this sprint, confirmed
live every time (`facts:segment-topic <topic> --dry-run` never listed
either). This is the natural behavior of the correct implementation, not
a hardcoded skip list — restated here as the handoff's own closing
confirmation that the exclusion held for the whole tranche, not just the
one topic that first predicted it.

**If either convenio 4 or 21 gets a current (non-historical) text
document supplied during the data pass**, it becomes automatically
eligible for all 4 already-built topics the next time their batch command
runs — no code change needed, per the filter above.

## 7. What this sprint deliberately did NOT start — and why

Per this sprint's own checkpoint discipline (plan §2.1's "gate fails or
yields near-zero is a legitimate outcome, to be reported, not forced"),
three of the originally-scoped seven topics remain untouched:

- **preaviso** — its topic row **exists and is approved**, created
  through the new topic-vocabulary lane (this sprint's own first real use
  of that lane). It has **no gold-fixture eval and no batch run yet**.
  Given §2's demand-ranking table shows preaviso's demand score (120)
  already outranks both jornada (56) and festivos (52) — topics this
  sprint DID complete — **preaviso is the strongest candidate for the
  very next engineering slice**, not a data-pass item. Flagged here as a
  recommendation for whoever authorizes the next piece of this sprint,
  not started without that authorization.
- **descanso** — no approved topic row, no go/no-go sample. Needs a
  decision before any work starts (plan §B.4 predicted a
  narrative/low-yield shape, similar to festivos' actual finding — worth
  a deliberate go/no-go sample before committing a full eval+batch, per
  the original plan's own framing).
- **horas extraordinarias** — same status as descanso: no approved topic
  row, no go/no-go sample, needs its own decision.

None of the three needs anything from the data pass to get started —
they need an engineering decision (build the gold-fixture harness, same
pattern as the 4 completed topics) and, for descanso/horas
extraordinarias, a topic-vocabulary approval through the same lane
preaviso just proved out.

## 8. Everything else this sprint changed that HR should know about

- **Review queue now has a topic filter and column** (D7) — verification
  work can be scoped to one topic at a time using the queue's own UI,
  matching §2's ranked ask directly.
- **`excedencia`→`excedencias` name fix** — a pre-existing topic-name
  mismatch is corrected; no data-pass action needed, just noted so a
  reviewer doesn't wonder why the name changed mid-sprint.
- **Worker-restart-on-deploy** is now a documented runbook step
  (`deploy.md`) — an engineering/ops note, not a data-pass action, listed
  here only for completeness of "everything this sprint touched."

## 9. Convenio 4 joins the ultraactividad-status list — its salary is now current while its prose is still expired (added 2026-09-16, Correction-salary-02)

Convenio **4** (Ocio Educativo y Animación Andalucía) now has two
different temporal states live at once, and an employee on it will see
both in the same conversation without any indication they're
inconsistent:

- **Salary: current.** Correction-salary-02 bound and imported COEAS
  Andalucía's real table (doc #11), which covers **2025 and 2026** —
  i.e. a year *past* the convenio text's nominal end. A salary question
  now answers cleanly from the 2026 table.
- **Prose: expired, no successor.** Convenio 4's `convenio_text` ended
  **2025-12-31** with nothing to replace it in the corpus (`deploy.md`'s
  existing go-live item). A prose question on the same convenio either
  escalates `estatuto_fallback_gap` or answers from the Estatuto baseline
  — by design, because the Estatuto deliberately does not stand in for a
  convenio that may still govern under ultraactividad (ET 86.4,
  ADR-0032).

**Why this is worth flagging rather than leaving as two separate,
already-documented facts.** The 2026 salary table is itself indirect
evidence *for* ultraactividad — someone kept revising this convenio's pay
scale for a year after its nominal expiry, which is exactly what
continuing-in-force behaviour looks like. But nothing in the corpus
*states* that the text remains in force; the salary table's existence is
suggestive, not confirmed. Until the client confirms, an employee on
convenio 4 gets a **live, specific salary figure** and a **historical-or-
escalated prose answer** from what is presented to them as one and the
same convenio — a split that reads as inconsistent unless someone knows
why.

**Convenio 4 now joins convenios 10, 18 and 21 on the "unresolved
ultraactividad status" list** that has been accumulating across this
sprint's own work rather than living in one place before now:

| convenio | what's unresolved | where it surfaced |
|---|---|---|
| **4** | Ocio Educativo Andalucía — text expired 2025-12-31, no successor; salary table now runs through 2026 (new, this correction) | `deploy.md` (expired/no-successor item) + this note |
| **10** | Agencias de Viajes — vigencia backfill was withheld during the triage batch specifically because of ultraactividad risk (treated like c18 rather than the instructed backfill) | `sprints/sprint-10c/triage/triage.md` §0 |
| **18** | Acción e Intervención Social (Navarra) — text states its own ultraactividad schedule through 2028, but validity is null pending the client's decision; facts left unverified | `sprints/sprint-10c/triage/triage.md` fact #134; `data-pass-handoff.md` §4 |
| **21** | Hostelería Navarra — text expired 2025-12-31, no successor | `deploy.md` (same item as c4) |

**The ask is the same for all four, and is genuinely one client
decision each, not a data-pass judgment call:** for each convenio, either
(a) confirm the text remains in force under ultraactividad and
re-activate it (`retrieval_status = active`) so prose answers resume, or
(b) supply the successor text if one exists, or (c) confirm it has
genuinely lapsed with no continuing effect. Whichever answer, the
resolution is a `retrieval_status`/validity write a human makes
deliberately — not something inferred from a salary table still being
updated.

## 10. Two genuine registry gaps for the client to confirm (added 2026-09-16)

Found while reviewing the documents Cobertura counts as "sin ámbito"
(`convenio_id IS NULL AND authority_level != 'national_law'`, 44 documents
on staging). Most of that pile is a *tagging* backlog — 31 of the 44 name
a convenio the registry already holds, and several state the official
14-digit code in their own text. **Three documents are different: the
convenio they belong to is not in the registry at all.** No amount of
tagging work will bind them, so they need a client decision, not a
reviewer's judgment:

| doc | title | what its text states | registry status |
|---|---|---|---|
| 20 | `TABLAS SALARIALES HOSTELERIA` | Gipuzkoa, *Hostelería y turismo*, convenio code **2000705** (salary revision for 2010) | **no Gipuzkoa hostelería row exists** — the registry's hostelería rows are Huesca (c16) and Navarra (c21) |
| 21 | `HOSTELERIA  2008 2010` | Gipuzkoa, *Hostelería y turismo*, convenio code **2000705** (the 2008–2010 text) | same gap as doc 20 — these two are the text and its salary revision, one convenio |
| 61 | `INTERVENCIÓN SOCIAL ALAVA` | Intervención social, **Álava** | the registry has intervención social for Gipuzkoa (c14), Vizcaya (c26), Navarra (c18) and Estatal (c7) — **but not Álava** |

**Why this is worth an explicit ask rather than a silent skip.** These are
true `NO_CONVENIO_MATCH` cases in the ledger's own vocabulary — the code
`CorpusCoverageService` documents as describing *a document with no
convenio tag*, not a convenio's own gap. Both gaps are also
**employee-relevant in principle**: the registry is the arbiter of which
scopes we serve, so a scope that has documents but no registry row is
invisible to Cobertura's convenio-level grid entirely (the grid iterates
registry convenios, so a missing row cannot show up as a gap in it — it
can only show up here). That is the blind spot this note exists to cover.

**The ask, per gap:** (a) is this a scope the client actually employs
people in? If yes, the registry needs the row added (and then docs 20/21
or 61 bind normally and become answerable). (b) If no — if these
documents were supplied as reference material for a scope with no
employees — say so, and they can be marked as out-of-scope corpus rather
than sitting in the unbound pile looking like unfinished tagging work.

Note both gaps are **historical** material (all three documents are
`retrieval_status = 'historical'`), so neither is urgent for answering
today; the value of resolving them is closing the "why is this
unbindable?" question permanently instead of re-litigating it at every
corpus review.

## 11. One document needs a year the source never states — doc #7, Deporte Estatal (added 2026-09-16)

Doc **#7** (`TABLAS SALARIALES Deporte Estatal.xlsx`, `salary_tables`,
`active`) belongs to convenio **9** (Instalaciones Deportivas y Gimnasios
Estatal) — its own title cell reads `DEPORTE ESTATAL`, and doc #73
(`99015105012005 Deporte Estatal 2023 2025`) already binds to that
convenio, so the scope is not in question. **It was deliberately left
unbound for one reason: the file states no year, and a salary table with
no year is unreachable.**

**Why the year is not a detail.** Every consumer of `salary_tables`
selects by year — `SalaryAnswerService::resolveTable()` matches the exact
year and then falls back through two `whereNotNull('year')` branches, and
Cobertura's salary cell applies the same filter. A table imported with
`year = NULL` would write real rows, print an ordinary success line, and
still answer nobody, while the convenio kept reporting
`NO_SALARY_SOURCE`. So the year has to come from somewhere before the
import is worth running.

**What was checked, exhaustively.** Every non-empty cell of the file's
only sheet is: the title `DEPORTE ESTATAL`, one header row (`Grupo`,
`Bruto año`, `Salario Base`, `Comp. SMI`, `Salario Hora`, `Plus
Tpte/día`, `Plus hora nocturna`) and **eight** data rows for groups 1
through 5. No year, no BOE/BON reference, no validity dates, no second
sheet, no defined names. The file's own document properties carry only
authoring timestamps (created 2026-02-25, modified 2026-03-26 by named
individuals) — when someone edited the spreadsheet, not which year the
table governs.

**The inference we deliberately did NOT act on.** `Bruto año` floors at
exactly **17.094 €** for groups 3.1–5 alongside a `Comp. SMI` top-up
column, and 17.094 € is the SMI-compensated annual floor that COEAS
Estatal's *explicitly dated* 2026 sheet also uses (its 2024–2025 sheet
floors at 16.576 € instead). That points hard at **2026**. But it is an
inference from an SMI floor, not a statement by the source, and the year
decides which questions this table answers — the same reason a salary
figure here is a source cell or it is not stored (ADR-0027) applies to
the year that scopes it.

**The ask:** confirm the year these tables apply to (from the convenio's
own gazette publication, or from whoever supplied the file). With that
one fact, the document binds and imports normally — the parse is
otherwise clean (8 rows, a legitimately labelled `Salario Base` monthly
column, `Comp. SMI`, hourly and two pluses all typed). Until then
convenio 9's salary coverage stays a gap, which is the honest state
rather than a table nobody can reach. A parser-side ticket to make
`salary:import` **refuse** a year-less table (instead of writing an
unreachable one) is recorded in `roadmap.md`.

One secondary note for whoever works this document: its categories are
bare group codes (`1`, `2.1`, `2.2`, `3.1`, `3.2`, `4.1`, `4.2`, `5`)
with no role names, so employees on convenio 9 will only match a salary
row if their profile's job category is recorded in that same coded form.
