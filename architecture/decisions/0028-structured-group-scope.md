# ADR-0028 — A group is an approved node, not a digit in a label (Sprint 7f)

**Status:** accepted — supersedes ADR-0023's Tier 2, which matched a fact's free-text `group_label` against `convenio_job_categories.group_code` by regex.

## Context

ADR-0023 gave the reference-fact answer path a four-tier resolution ladder: exact job category, else the employee's group, else the convenio-wide fact, else escalate. Tier 2 was implemented as a digit search — take the employee's `job_category.group_code`, look for it in the fact's printed `group_label` as a standalone token:

```php
preg_match('/(?<!\d)'.preg_quote($groupCode, '/').'(?!\d)/u', $fact->group_label)
```

The negative lookarounds were there because "Grupo 10" must not match code `1`. That much worked. Two things it could not survive were both already true in the corpus.

**The label side.** Convenio 21 (Hostelería Navarra) states its probation periods as two facts, whose labels are `Grupo 1 (todas las áreas) y Grupo 2 (área 5)` — 90 days — and `Grupo 2 (resto áreas)` — 60 days. An employee in *resto áreas* has group code `2`. The digit `2` appears in **both** labels. The first one is longer and sorts first, so the employee was told **90 días** where their convenio says 60 — from a fact whose own text says it covers *área 5*, which is exactly the area they are not in. The answer was cited, structured, `authority_used = structured_reference`, and wrong. A confidently wrong answer with a source attached is worse than an escalation and worse than silence: it is the one failure mode a reviewer has no reason to look at twice.

**The employee side.** `convenio_job_categories.group_code` was never a group code. Of 22 non-empty values in the corpus, nine are something else: `4.1`, `2.1` and similar section numbers (convenio 6), and free text. 72 of 94 categories have no value at all — including *every* category of the convenios this feature demonstrates on. And the field is populated by the same ingest that parses salary sheets, so the day someone imports a clean `group_code` column for convenio 21, a latent wrong answer becomes a live one. That risk was real enough that `roadmap.md` carried a hard precondition against seeding clean digit codes before this sprint landed, and Sprint 7f gave it an executable form (§2.7 of the plan): a SQL query that must return zero rows.

The deeper problem is that both sides were **strings written for humans**, compared by a machine at answer time. "Grupo Prof. 1.º", "GRUPO PROFESIONAL PRIMERO" and "grupo 1" are the same group in one convenio's own pages. No regex reconciles that, and the ones that come close do so by guessing.

## Decision

**A convenio's group structure is explicit, reviewed data. At answer time the matcher compares integers.**

### 1. Granularity follows value differences, not the convenio's prose

A group is split into sub-areas **only where the text assigns different values**. Convenio 21 names six functional areas inside Grupo 2 but pays área 5 differently from the other five, so Grupo 2 gets exactly two children — `área 5` and `resto áreas` — not six. A tree that mirrored every heading would be more faithful to the document and less useful for answering, because five of those nodes would be indistinguishable in every fact that exists.

### 2. A depth-2 tree in its own table, not a column on categories

`convenio_groups` (`convenio_id`, `parent_id`, `label`, `code_normalized`, `status`, provenance). Two levels, enforced in Postgres by a trigger rather than by convention — a third level is a modelling error, and the place to refuse it is the place that cannot be bypassed. Membership of job categories is a **many-to-many** (`convenio_group_categories`), because the group vocabulary has to exist for convenios that have no categories at all, which is most of them.

`code_normalized` is derived once, at write time, by `GroupCodeNormalizer`, and every spelling of one group in one convenio must normalize to one key. That normalization happens during review, where a human can see the result — never during a chat turn.

### 3. Facts bind through a join table; a compound fact is bound, never split

`reference_fact_group_scopes` (`reference_fact_id`, `convenio_group_id`, provenance). The headline fact is *one* sentence covering *two* groups — Grupo 1 entirely, plus área 5 of Grupo 2 — and it holds **two rows**. A single `group_id` column on `reference_facts` could not express it, and the alternative of splitting it into two facts would create two rows that can drift apart, each citing the same sentence. The fact stays one fact; its scope is a set.

### 4. The AI proposes; a human approves; nothing exists until then

`POST /propose-groups` (hr-ai) reads the convenio's pages, its categories as *evidence only*, and its facts' labels, and returns a proposed tree — every node with the excerpt that justifies it. It writes nothing and never migrates. Proposals land `ai_agent` / `needs_review` and are inert: the matcher only ever compares against `approved` nodes. Categories are validated against a closed set, so the proposer can never mint one (ADR-0011).

Approval shows the **fact-binding diff** first: these are the facts whose labels resolve to this node, and no `reference_fact_group_scopes` row is written for any fact the reviewer does not tick. Approving a node binds nothing by itself.

### 5. Binding is a decision separate from approval, and a human may decide what the parser refuses

`FactGroupBindingPlanner` reads each fact's `group_label` and resolves it to nodes *conservatively*: enumerations and parenthesized qualifiers yes, and an explicit refusal — with a reason — for anything it cannot prove. `Grupo 2 excepto área cinco` is refused as a **complement**: resolving it means deciding what the *other* facts cover, which is a judgement.

A reviewer may bind it anyway. That is not a hole in the validation; it is the authority the validation defers to. What makes it safe is the filing: an overridden binding is recorded under `facet = group_scope_manual` with the planner's refusal reason kept beside the human's note, so a later reader can distinguish a scope that was **asserted** from one that was **read**. Without that distinction an override would just be the digit matcher wearing a badge.

Override is refused where it would be a contradiction rather than a judgement — a label that resolves to a *different* node (correct the label instead), and a **convenio-wide** fact (binding it would narrow a rule that applies to everyone, the inverse of this sprint's bug and just as wrong).

### 6. Tier 2 compares node ids, and an indeterminate scope escalates

`factMatchesGroup()` and `resolveEmployeeGroupCode()` are **deleted**. `job_category.group_code` is no longer read by the answer path at all. Against the employee's node E, per fact:

| | | |
|---|---|---|
| (1) | a bound node **is** E | match |
| (2) | a bound node is a **child** of E | **escalate** — the fact is sub-area-specific and the employee's sub-area is unknown |
| (3) | a bound node is E's **parent**, and that parent has approved children | **escalate** — the *fact* claims a group the convenio splits, so the fact's own scope is the ambiguous one |
| (4) | otherwise | no match; fall through to Tier 3/4 |

Rule (1) is evaluated across all of a fact's nodes before (2)/(3), so the compound fact answers for an employee at Grupo 1 on the strength of its Grupo 1 binding.

An escalate from (2)/(3) is a **hard stop for the tier**, not a skip. This is the one place the ladder's "else continue" shape changes, and it changes toward refusal: falling through to Tier 3 would answer convenio-wide, which is less specific than the evidence in front of it and stated with identical confidence. A fact with **zero** bound nodes is never group-matchable, and cannot satisfy Tier 3 either (that requires a null `group_label`), so it escalates — an unbound label is a claim nobody has vouched for.

No AI participates in any of this (ADR-0015/0016). The comparison is integer equality and two parent-child lookups.

### 7. An employee's group is set by a human

A picker on the employee form, pre-filled *as a suggestion only* from the job category's approved memberships, plus two CSV columns. An ambiguous CSV row **fails** rather than resolving to a best guess. No import path ever writes `employees.convenio_group_id` without a person confirming it.

## Consequences

**The bug is fixed, and the fix is visible.** On staging, one employee and one question across three nodes: *resto áreas* → 60/45/30; Grupo 1 → 90/75/60 (from the compound fact's other binding); Grupo 2, the split parent → escalates. The trace records `match_kind = group` with `group_node_id` and `group_node_label` alongside the fact's printed `group_label`, because those two can legitimately disagree — `Grupo 2 excepto área cinco` bound to `resto áreas` — and a reviewer needs to see both.

**Nothing changes for anyone until an admin acts.** Every real profile has `convenio_group_id = null`, which skips Tier 2 exactly as before; every proposed node arrives `needs_review`, which the matcher ignores. Baseline check A (a convenio-wide Tier 3 answer) is byte-for-byte identical after the change, and the `group_node_*` keys are written **only** on a group match so no other tier's trace shape moves.

**More escalations than the old matcher, deliberately.** Rules (2) and (3) refuse cases the digit search would have answered. Every one of those answers would have been a guess about which slice of a split group an employee sits in. Escalating is visible and recoverable; a wrong cited answer is neither. The follow-on that makes those escalations *explicable* to the employee and fixable by HR is Sprint 7g, not this one.

**The §2.7 gate retires.** It existed to keep a dangerous combination — clean digit `group_code` plus verified group-scoped facts — out of the corpus while the regex was live. With the regex deleted the query is vacuous: run 3 returns zero rows, and would be harmless if it did not.

**The cost is a review step per convenio.** Six convenios were proposed and scored at **31/31 exact, zero under-splits, zero over-splits**, for $0.95 and 122 seconds total. A convenio whose structure nobody has approved answers exactly as it does today — which is to say Tier 2 is inert for it, not broken.

**A known limit, carried:** the proposer truncates at 180k characters, which affected four of the six convenios. All four still scored exact because classification articles sit early in a convenio, but a document that classifies late would silently lose its structure. Tracked as O-3 in `sprints/sprint-07f/review.md`.
