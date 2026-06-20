# ADR-0005 — Daily (~24h) sessions

**Status:** accepted

## Context
OTP on every visit would kill adoption; never expiring would weaken security. A balance is needed.

## Decision
OTP establishes a session; a Laravel Sanctum bearer token keeps it alive for **~24h** ("daily"). Re-OTP on expiry or logout. The window can be shortened if Sedena's security posture later requires it.

## Consequences
- Daily users authenticate once per day, not per visit.
- Token TTL is a single config value, easy to tighten.
