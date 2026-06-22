# ADR-0012 — Vanilla CSS with design tokens (no Tailwind)

**Status:** accepted

## Context
`hr-frontend` needs a real design system: it currently has hand-written global CSS in a single `index.css`, semantic classNames (`card`, `badge-conflict`, `docs-table`), system fonts, hardcoded hex literals, no token layer, and no theming. A reference kit was provided (the "Volu3D" dark UI kit) built on **Tailwind** and **dark-only**. We want a trustworthy, readable design system with a **light default and an optional dark theme**, and we must decide whether to adopt Tailwind to get there.

`hr-frontend` is React 19 + Vite 8, with three runtime deps (`react`, `react-dom`, `react-router-dom`). It is a focused admin/chat tool, not a large multi-team UI.

## Decision
**Keep vanilla CSS; do not introduce Tailwind.** Use the reference kit as a source of *design vocabulary* (its accent + semantic state palette, radii, shadows, component recipes), not as a tooling mandate. Build a proper **token layer as CSS custom properties** in `:root`, express the existing component classes against those tokens, and add theming via a `[data-theme="dark"]` override block.

Reasons:
- The valuable part of the reference kit is its *structure* (centralized tokens, semantic state colors, named component recipes), all of which is achievable in plain CSS — and the project already uses the component-class pattern, so the markup barely changes.
- The reference kit is **dark-only**; we want **light-default + dark-optional**, so the surface scale is being rebuilt regardless of paradigm. There is no real "drop-in" to forgo.
- **Light/dark theming is cleaner in CSS variables** than in Tailwind for a two-theme system: define light values in `:root`, override the same variable names under `[data-theme="dark"]`, toggle one attribute on `<html>` — zero per-component dark variants.
- It honors the project's established discipline (minimal dependencies, clean separation). Tailwind earns its keep on large/sprawling UIs; a focused tool with semantic CSS does not need the added build tooling.

## Consequences
- The design system ships as `hr-docs/design-system.md` (the constitution doc) + an upgraded `hr-frontend/src/index.css` (tokens + component classes + light/dark blocks) + Inter loaded via `@fontsource/inter` (Vite-friendly, no external request).
- A runtime theme toggle flips `data-theme` on `<html>`; the default is light.
- Branding is deferred: a neutral, trustworthy blue is the placeholder accent, defined as a single token so a real Sedena brand color is a one-line swap later.
- **Reversibility:** if `hr-frontend` ever grows large enough to want Tailwind, adopting it later is straightforward; the inverse (removing Tailwind) would be the painful direction, which this decision avoids.
