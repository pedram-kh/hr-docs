# ADR-0001 — Faceted model, not a fixed tree

**Status:** accepted

## Context
The correct HR answer varies by province, sector, convenio, validity date, and job category. The instinct is a hierarchy (province → sector → convenio → document). But documents cross-cut: the Estatuto de los Trabajadores applies to everyone (no single province); a state-wide convenio (COEAS Estatal) spans all provinces while provincial COEAS versions also exist. A strict tree forces such documents into either a wrong single parent or duplication across branches that drifts out of sync — and wrong scoping produces wrong legal answers.

## Decision
Store scope as independent **facet** values on each document (province, sector, convenio, validity window, document type, topic). Render the hierarchy UI as **lenses** — `GROUP BY` orderings of the facets — not as stored parent/child structure. A document is a leaf in every lens, reached by different paths, never duplicated.

## Consequences
- Cross-cutting documents are represented once and appear correctly under every relevant lens.
- The hierarchy UI is one reusable component driven by a lens definition; new lenses are config, not new code.
- The tree/graph/list visual is a rendering concern with no effect on the schema.
- Requires discipline: scoping facets must be controlled vocabulary (ADR-0002) or the lenses fracture on spelling variants.
