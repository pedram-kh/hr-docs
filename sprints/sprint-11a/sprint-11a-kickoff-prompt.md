# Sprint 11a — Plan-gate kickoff prompt (paste into a fresh Cursor thread)

> Save as: `hr-docs/sprints/sprint-11a/kickoff-prompt.md`

---

You are planning **Sprint 11a** in the hr-platform workspace (`hr-backend`, `hr-ai`, `hr-frontend`, `hr-docs`). Read `hr-docs/sprints/sprint-11a/spec.md` first. Write `hr-docs/sprints/sprint-11a/plan.md`, then **STOP — no code, no commits.**

Ground rules: inspect the real code and the deployed staging host; cite `path:line`. This sprint is presentation only (plus the staging fixed-OTP flag) — the answer loop, answer text, permissions and data must not change. AI provenance stays fuchsia. No client name hardcoded in components.

## A. Brand theme
1. Locate the token system (ADR-0012/0013): list every token, where it's defined, light/dark. Propose the full mapping from the five brand colours (spec §2.1) onto the tokens, plus designed **status colours** (error/warning/success/info) and **dark-mode variants**.
2. Produce a **contrast table** (WCAG AA) for every text/background pair you propose, light and dark. State where Arena (#D9B56D) and Verde Niebla (#DCE9E6) may and may not be used as text.
3. Grep for **hardcoded colours** in components (hex, rgb, named) and list them — each must move to a token.
4. Fonts: brand assets are in `hr-docs/sedena/` (logo `LOGO-SEDENA-01.svg`, palette `sedena_cromatic_range.png`, Montserrat, Playfair Display; ignore `__MACOSX`). List the font files present; propose self-hosting (formats, weights actually needed, subsetting). Read the production CSP from the Caddy config and confirm self-hosted fonts load under it unchanged.
5. Propose the **theme config + asset folder** (logo, product name, fonts, colours) and show that no component references the client name directly.
6. Confirm `--provenance-ai` stays untouched and list every place it renders.

## B. Navigation
7. Show the current nav: where items come from, how role/ability visibility works, and the identity-payload nav keys. Propose the grouped structure (spec §2.2), the **lucide icon per item** with a one-line reason, and the per-role snapshot test.

## C. Filter toolbar
8. For History, Escalations, Review (all tabs), Documents: list today's search/filter/date controls and their query params. Propose one shared toolbar component that preserves every param and behaviour.

## D. Human statuses
9. **Grep every render site** for raw internal keys (escalation reasons, sub-outcomes, fact/document statuses, trace outcome values) — don't rely on the known list. Table: where it renders, current text, proposed human label, whether the technical key stays as secondary text. Show how the existing enum guard test extends to cover them.

## E. Employee chat
10. Show the employee chat components; propose the branded layout and the welcome screen with its static suggested-question config (5–6 questions, in Spanish, grounded in topics the corpus actually answers today).

## F. Staging fixed OTP
11. Trace the OTP flow end to end. Propose the flag, the allowlist check, and the **boot-time refusal in production**, with the invariant tests.

## G. Plan output
12. End with: ordered build steps (theme tokens first — everything else sits on them), tests, open questions, and ⏸ checkpoints — at minimum:
   - **CP-1:** a static **brand preview page** on staging (all tokens, status colours, buttons, inputs, badges, cards, fuchsia AI badge, light + dark) — Pedram approves the visual system before it's applied across screens.
   - **CP-2:** Pedram approves the nav grouping + icons.
   - **CP-3:** final eyes-on (spec §5).

Then **STOP** and wait for review.
