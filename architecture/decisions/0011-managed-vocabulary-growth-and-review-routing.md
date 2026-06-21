# ADR-0011 — Managed growth of scoping vocabulary & two-reason review routing

**Status:** accepted (decision made now; implementation deferred to the LLM-tagging-tier sprint)

## Context
ADR-0002 made the scoping vocabulary (territories, sectors, convenios) **closed** so the parser/AI can never mint values — this prevents drift (e.g. `Bizkaia` / `Vizcaya` / `Vizcaya (Bizkaia)` becoming three territories). But "closed" must not mean "frozen": real corpora grow (new convenios, new sectors, a new province). If the only outcomes are "match an existing value" or "conflict forever," legitimately-new things get stuck. We need a safe path for the vocabulary to grow, and a review queue that routes its two failure reasons differently.

## Decision

### 1. Managed growth — agent proposes, human approves
Scoping vocabulary grows the same way topics already do (managed): an agent may **propose** that a new value should exist; a **human approves** it into the vocabulary (or rejects it). The AI never creates a scoping value autonomously.
- **Strong default toward "variant, not new."** When a parsed value doesn't match, the assumption is that it is a spelling/language variant of an existing value (to be folded into that value's `aliases`), **not** a new value. Only when nothing plausibly matches does the agent propose "this may be new." A human decides variant-vs-new.
- This is the same propose-not-create principle as tagging, applied one level up: for *tags* the agent proposes a value from the list; for *vocabulary* the agent proposes that a value should join the list.

### 2. Two-reason review routing
A document reaches review for one of two distinct reasons, and they route differently:
- **`unresolved`** — the parser had nothing to work with (messy/missing filename, scan). This is **LLM-eligible**: in the LLM-tier sprint, these route through the LLM (which reads *content*) before a human; a human sees only what the LLM is low-confidence on or that conflicts.
- **`conflict`** — the parser resolved values, but they contradict the registry (territory disagreement, unknown convenio). This is **human-adjudicated**: the LLM may *suggest* but must **never auto-apply** to a conflict. A conflict is a disagreement between two sources, not a knowledge gap an LLM should paper over; confidence never beats a conflict (ADR-0002).

## Consequences
- The vocabulary can grow without losing the anti-drift guarantee — growth is gated by human approval, biased toward absorbing variants into aliases.
- The review queue must, **from Sprint 1**, record (a) the **reason** (`unresolved` vs `conflict`) and (b) the **raw unmatched value** the parser couldn't resolve. These two small fields are the seed the later LLM-rescue and vocabulary-suggestion flows grow from; capturing them now avoids a migration later.
- Implementation of the LLM rescue path and the propose-new-value UI is deferred to the LLM-tagging-tier sprint; Sprint 1 only lays the data foundations above.
