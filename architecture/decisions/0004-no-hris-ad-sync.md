# ADR-0004 — No HRIS / Active Directory sync

**Status:** accepted

## Context
No HRIS or AD integration is feasible for this project. Profiles (province, convenio, job category, location) determine which legal answers an employee receives, so they must be kept current somehow.

## Decision
The user directory is a **first-class part of this platform**, not a mirror of an external system. Full manual CRUD + CSV bulk upload + search/filter. Every profile change is written to `employee_audit_log`. A `profile_last_reviewed_at` field signals staleness.

## Consequences
- Simpler architecture: no integration layer, sync reconciliation, or dependency on Sedena IT.
- Profile accuracy becomes a human process — supported by the audit log, the staleness signal, and (optionally, later) letting employees see and flag their own profile.
- CSV upload is the bootstrap; manual edit is the day-to-day correction path (e.g. an employee transferring province).
