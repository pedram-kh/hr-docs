# Sprint 11c — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-11c/kickoff-prompt.md`

---

You are planning **Sprint 11c** in the hr-platform workspace. Read `hr-docs/sprints/sprint-11c/spec.md` first. Write `hr-docs/sprints/sprint-11c/plan.md`, then **STOP — no code, no commits.**

Ground rules: inspect the real code and the live staging DB; cite `path:line` and real counts. Every edge must map to a stated DB relation (spec §2) — if you find yourself proposing an edge that needs an explanation longer than one factual sentence, it's out.

## A. The data, measured

1. Query staging: real counts of convenios, documents by `retrieval_status`, reference facts by status, topics, territories, sectors, approved group nodes. Compute the resulting node/edge counts under the spec's rules (hubs only at ≥2). Report the table.
2. List every edge type with its exact source relation (`table.column` → `table.column`). Flag anything ambiguous (e.g. facts from multi-convenio HR tables — how do they attach?). Propose how document #105/#106-style shared sources render (or don't).
3. Propose the node payload shape and the endpoint (route, controller, ability gate — which existing ability fits the Map's current gating; cite it).

## B. The library, verified

4. Confirm `3d-force-graph` + three.js versions, license, bundle sizes (real numbers, minified+gzip), and that both bundle cleanly with the existing Vite setup — no CDN, no eval. Report the lazy-chunk plan and the measured initial-bundle delta.
5. R1: compare the library's own 2D mode vs a hand-rolled 2D SVG (the ~80-line approach) for our node count — recommend one with reasons (bundle, look, code owned).
6. R2: label strategy at our scale (hover-only vs persistent hub labels vs distance-based) — propose with a mock/screenshot if cheap.
7. R4: WebGL detection + fallback path.

## C. Determinism + honesty guards

8. Show where the layout seed lives (the library accepts initial positions — golden-angle spiral keyed on sorted ids, per the field guide) and the test asserting no `Math.random` and stable seeds.
9. Propose the edge-builder as a pure function (backend or frontend — argue where it belongs) with fixture tests: correct edges built, forbidden edge types impossible, hub-sparsity rule enforced.

## D. Integration

10. Show the Map section's current structure (Hierarchy view) and propose the tab/toggle integration, the side card, deep links to Documents/Cobertura/Review (cite the existing deep-link params — Documents already supports `convenio_id`), and the filter chips.
11. Colour mapping: node state → token (verified/active → which brand token; unverified-AI → `--provenance-ai`; historical → which muted token), both themes.

## E. Plan output

12. Ordered build steps (endpoint + edge builder + tests first; rendering second; polish third), tests, open questions, and ⏸ checkpoints — at minimum: **CP-1** first working render on staging (both modes, both themes) for Pedram's look-and-feel approval before polish; **CP-2** final eyes-on (spec §6).

Then **STOP** and wait for review.
