# ADR-0003 — Email OTP authentication (not SSO)

**Status:** accepted

## Context
There is no identity provider to federate with, and no HRIS/AD (ADR-0004). Email is already a mandatory, unique field on every employee. Managing 1,500 passwords (resets, leaks) is avoidable burden.

## Decision
Passwordless **email OTP**: a one-time 6-digit code emailed on login. Email is both the auth key and the profile-lookup key. Codes are short-TTL, single-use, hashed at rest, rate-limited per email and per verification attempt; requesting a new code invalidates outstanding ones. Admins use the same flow. This is explicitly **not** SSO (no external IdP) — call it email OTP to set correct expectations.

## Consequences
- No passwords to store or reset; the email inbox is the security factor.
- Login depends on email deliverability — use a transactional provider (Postmark) with SPF/DKIM in production. For local dev, use **MailHog** (SMTP catcher, UI at `localhost:8025`) so OTP codes are viewable without sending real mail; transport is selected by environment.
- Editing an employee's email changes how they log in — the admin create/edit flow is also account recovery.
- Identity maps to exactly one profile, which strengthens the scoping guarantee.
