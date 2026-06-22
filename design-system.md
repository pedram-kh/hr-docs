# HR Platform — Design System

> Canonical location: `hr-docs/design-system.md`
> Implemented in: `hr-frontend/src/index.css` (tokens + component classes + light/dark blocks)
> Decision record: **ADR-0012** (vanilla CSS + tokens, no Tailwind)

This is the visual constitution for `hr-frontend`. Every screen — the employee chat and the admin console — is built against these tokens and component classes. It is **vanilla CSS**: tokens are CSS custom properties; components are plain classes; theming is a `data-theme` attribute on `<html>`. No Tailwind, no component library.

---

## 1. Design intent

This is a **legal-weight HR tool**. Two audiences: a handful of HR power-users (admin console) and ~1,500 regular staff (employee chat) reading answers that carry real consequences. The design earns trust through **readability, restraint, and honesty about scope** — not through flourish.

The **signature** of this product is not a hero graphic; it is that **every answer and every document wears its scope and provenance openly**. The status badges (conflict / under-review / verified / historical / national) and the document card's facet tags + provenance timeline are the personality of the UI, because they are what the whole system is about: the right answer for the right territory, sector, convenio, and date — auditable. We spend our design attention there and keep everything around it quiet.

Defaults: **light theme**, with an optional dark theme. Light is the default because the content is dense legal prose and data tables read by a broad, occasional audience, often in office light or printed.

---

## 2. Color tokens

Defined as CSS custom properties in `:root` (light) and overridden under `[data-theme="dark"]`. **Components never use raw hex** — only `var(--…)`. The accent is a neutral, trustworthy blue (placeholder until a Sedena brand color is chosen; it lives in one token, so swapping it is a one-line change).

### Brand / accent (shared by both themes)
| token | hex | role |
|---|---|---|
| `--accent` | `#2563eb` | primary actions, active state, links |
| `--accent-hover` | `#1d4ed8` | hover for primary |
| `--accent-weak` | `#eff4ff` | tinted backgrounds (selected row, info) |
| `--accent-contrast` | `#ffffff` | text on accent |

### Semantic state (map directly onto the existing badges)
| token | light hex | role |
|---|---|---|
| `--danger` / `--danger-bg` | `#b42318` / `#fef3f2` | **conflict** badge, destructive |
| `--warning` / `--warning-bg` | `#b54708` / `#fffaeb` | **under-review** / **empty-text** |
| `--success` / `--success-bg` | `#067647` / `#ecfdf3` | **verified**, healthy |
| `--info` / `--info-bg` | `#175cd3` / `#eff8ff` | **national** scope marker |
| `--neutral` / `--neutral-bg` | `#475467` / `#f2f4f7` | **historical**, inactive |

Each state has a **foreground** (text/icon) and a **-bg** (tint behind it) so badges read as colored text on a soft tint — calm, not loud. Dark theme overrides supply the same roles on dark surfaces (brighter foregrounds, low-alpha tints).

### Surfaces & text — LIGHT (default)
| token | hex | role |
|---|---|---|
| `--canvas` | `#f8fafc` | page background |
| `--surface` | `#ffffff` | cards, panels, table |
| `--surface-raised` | `#f2f4f7` | secondary buttons, hover rows |
| `--surface-inset` | `#f8fafc` | inputs, code/source-text wells |
| `--text` | `#101828` | primary text |
| `--text-muted` | `#475467` | secondary / labels |
| `--text-faint` | `#98a2b3` | captions, placeholders |
| `--border` | `#e4e7ec` | default hairline |
| `--border-strong` | `#d0d5dd` | inputs, emphasis |

### Surfaces & text — DARK (`[data-theme="dark"]`)
| token | hex | role |
|---|---|---|
| `--canvas` | `#0b1220` | page background |
| `--surface` | `#161b26` | cards, panels, table |
| `--surface-raised` | `#222936` | secondary buttons, hover rows |
| `--surface-inset` | `#0e131c` | inputs, source-text wells |
| `--text` | `#f4f6fa` | primary text |
| `--text-muted` | `#9aa4b2` | secondary / labels |
| `--text-faint` | `#667085` | captions, placeholders |
| `--border` | `#222936` | default hairline |
| `--border-strong` | `#333c4a` | inputs, emphasis |

> The accent and the *roles* of the state colors stay constant across themes; only their literal values shift. A component written against `var(--surface)` / `var(--text)` / `var(--danger)` is automatically correct in both themes.

---

## 3. Typography

**Inter**, loaded via `@fontsource/inter` (bundled, no external request, Vite-friendly). One family, used across both UIs; weight and size carry hierarchy, not multiple typefaces — appropriate for a utility tool where clarity beats flourish.

| role | size / line-height | weight | use |
|---|---|---|---|
| `--text-xs` | 12px / 1.4 | 600 | labels, badges, captions (often uppercase, `letter-spacing: .04em`) |
| `--text-sm` | 13px / 1.5 | 400–500 | table cells, metadata, dense UI |
| `--text-base` | 15px / 1.6 | 400 | body, answer prose, document text |
| `--text-lg` | 18px / 1.4 | 600 | panel titles |
| `--text-xl` | 22px / 1.3 | 700 | page headings |

Numerals in data tables (hours, salary figures, dates) use `font-variant-numeric: tabular-nums` so columns align. Legal/source prose uses `--text-base` with generous line-height for sustained reading.

---

## 4. Spacing, radius, shadow

- **Spacing scale (4px base):** `--space-1:4px` `--space-2:8px` `--space-3:12px` `--space-4:16px` `--space-5:24px` `--space-6:32px` `--space-8:48px`. Use the scale, not arbitrary values.
- **Radius:** `--radius-sm:6px` (inputs, badges-as-pills are `9999px`), `--radius-md:8px` (buttons), `--radius-lg:12px` (cards, panels).
- **Shadow:** `--shadow-sm` (subtle card lift), `--shadow-panel` (the detail drawer). Light theme uses soft neutral shadows; dark theme uses near-black, lower-spread shadows. Keep shadows restrained — this is a flat, calm UI, not a glossy one.

---

## 5. Component classes

Named classes in `index.css`, all referencing tokens. These extend the project's existing vocabulary (`card`, `badge-*`, `docs-table`) rather than replacing it.

### Buttons
- `.btn` — base (inline-flex, gap, `--radius-md`, `--text-sm`, weight 600, `transition`, visible disabled state).
- `.btn-primary` — `--accent` bg, `--accent-contrast` text, `--accent-hover` on hover.
- `.btn-secondary` — `--surface-raised` bg, `--text` , `--border` ring.
- `.btn-ghost` — transparent, `--text-muted`, `--surface-raised` on hover.
- `.btn-danger` — `--danger` (used only for destructive confirmation, never as a default).

### Cards & panels
- `.card` — `--surface`, `1px --border`, `--radius-lg`, `--space-4` padding, `--shadow-sm`.
- `.panel` — the detail drawer surface: `--surface`, left `1px --border`, `--shadow-panel`.
- `.well` — `--surface-inset` block for source/extracted text and code-like content.

### Inputs
- `.input`, `.select`, `.textarea` — `--surface-inset`, `1px --border-strong`, `--radius-sm`, focus ring (see §6). Placeholder is `--text-faint`.
- `.field` — label + control + help-text wrapper; label is `--text-xs` uppercase `--text-muted`.

### Status badges (the signature element)
- `.badge` — base pill: `--text-xs`, `--radius` full, colored **foreground on -bg tint**.
- `.badge-conflict` → danger · `.badge-review` → warning · `.badge-empty` → warning (distinct icon) · `.badge-verified` → success · `.badge-national` → info · `.badge-historical` → neutral.
- Badges read as quiet colored text on a soft tint, with an optional 12px leading icon. Never full-saturation fills — these appear in dense tables and must not shout.

### Data table
- `.docs-table` — `--surface`; header row `--text-xs` uppercase `--text-muted` on `--surface-raised`, sticky; cells `--text-sm`, `--space-3` padding, `1px --border` row separators; row hover `--surface-raised`; selected row `--accent-weak` with a 2px `--accent` left border. Numeric columns `tabular-nums`, right-aligned. This is the densest surface in the app and the spec is tuned for scanning.

### Provenance timeline (the second signature element)
- `.timeline` — vertical list; each `.timeline-item` has a small source dot colored by origin (`filename_parse` neutral · `system` warning · `admin_manual` accent), the action text (`--text-sm`), actor + relative time (`--text-xs --text-faint`), and old→new value where present. Reads top-to-bottom as the document's history: "parsed → flagged → confirmed by admin."

### Tags / facets
- `.facet` — a labeled scope chip (territory / sector / convenio / validity) used on the document card: `--text-xs` label in `--text-faint` + value in `--text`, on `--surface-raised`, `--radius-sm`. These are not interactive badges; they are the document's identity, shown plainly.

---

## 6. Accessibility & quality floor

Non-negotiable for a legal-weight tool used by 1,500 people:
- **Visible keyboard focus** on every interactive element: a 2px `--accent` outline with 2px offset (`:focus-visible`), never `outline: none` without a replacement. This is the most common omission and it matters here.
- **Contrast:** body text meets WCAG AA on its surface in both themes (the token values above are chosen for this). Badge foregrounds meet AA on their `-bg` tints.
- **Hit targets** ≥ 36px for buttons and row actions.
- **Reduced motion:** wrap transitions/animation in `@media (prefers-reduced-motion: no-preference)`; the UI is fully usable with motion off.
- **Theme toggle:** sets `data-theme` on `<html>`; respects `prefers-color-scheme` for the initial value, then remembers the user's explicit choice (in memory/state — no browser storage constraints apply in the real app; persist via the app's own settings later).
- **Don't rely on color alone:** every status badge pairs its color with text (and an icon), so conflict-vs-verified is never a color-only distinction.

---

## 7. Voice (UI copy)

Copy is design material. From the end-user's side of the screen, plain and active:
- Name things by what people control: "Confirm tags," "Re-assign convenio," not "Submit" / "Mutate facet."
- An action keeps its name through the flow: the **Confirm tags** button produces a **"Tags confirmed"** result.
- Errors explain what happened and how to fix it, in the interface's voice, never apologizing or vague: "This file isn't a PDF — only PDFs are ingested this sprint," not "Oops, something went wrong."
- Empty states invite action: an empty review queue says "No documents need review" — a statement of health, not a blank panel.
- Sentence case throughout; labels label, help-text explains, nothing does double duty.

---

## 8. What this is not (scope guardrails)

- No Tailwind, no CSS-in-JS, no component library (ADR-0012). Plain CSS + tokens + the classes above.
- No gradients-as-decoration, no glassmorphism, no heavy shadows — the reference kit's `backdrop-blur`/3D-canvas idioms are dropped; they belonged to a 3D planner, not a legal HR tool.
- No per-screen bespoke styling: screens compose the classes above. New visual needs are met by adding a token or a class here first, then using it — so the system stays the single source of truth.
