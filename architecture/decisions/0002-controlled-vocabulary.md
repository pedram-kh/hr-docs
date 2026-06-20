# ADR-0002 — Controlled vocabulary for scoping facets

**Status:** accepted

## Context
If the AI or an admin can invent tag values freely, vocabulary drifts ("Álava" / "Alava" / "Araba"), and because a tag *is* the legal scope boundary, drift silently produces wrong or missing answers. The convenio registry already gives a closed, authoritative set of provinces, sectors, and convenio numbers.

## Decision
- **Scoping facets** (province, sector, convenio, job category, validity) are a **closed controlled vocabulary**: stored in vocabulary tables, referenced by foreign key, so invalid values are structurally impossible. New values only via deliberate, logged admin action — never by the AI, never as a side effect of tagging.
- **Descriptive facets** (topic) are a **managed vocabulary**: seeded from the FAQ categories; the AI may *propose* a new value (`status = proposed`); only an admin approves it.
- The tagging agent **proposes**, a human **confirms**. Low-confidence or conflicting tags go to a review queue.
- Conflict detection (parsed tags cross-checked against the registry) outranks confidence — a confidently-wrong tag is the dangerous kind.

## Consequences
- Clean, queryable scope data; lenses and filters stay coherent.
- The AI cannot corrupt the legal vocabulary.
- Slightly more process to add a genuinely new convenio/sector (an admin action), which is correct for legally load-bearing data.
