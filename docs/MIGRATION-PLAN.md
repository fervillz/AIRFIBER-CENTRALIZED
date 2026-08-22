# Classic → Airfiber Next Migration Plan

Migration is incremental. Classic remains usable throughout.

## Phase 0 — Core platform

Status: implemented.

- isolated Next folder/namespace
- BETA page and Classic bridge
- shared UI system
- module manifests/loader
- REST lazy loading
- optional lazy assets
- permissions/users
- cache/HTTP/event services
- profiler, health, circuit breaker, debug
- Dashboard, Users, Modules and Settings system modules

## Phase 1 — OLT proof of concept

Start read-only.

1. Add `next/modules/olt/` manifest/class.
2. Render cached OLT overview/list using existing data sources without changing Classic OLT code.
3. Measure load/queries/memory.
4. Add per-OLT lazy detail request.
5. Add PON/ONU list as deeper on-demand chunks.
6. Only after read-only behavior is stable, add Add ONU/provisioning writes.

## Phase 2 — PPP

Migrate PPP list/search and creation into a Next module. Reuse existing data/services through adapters where safe, but do not make Next depend on Classic presentation CSS/JS.

## Phase 3 — Payments/Billing/Connections

Migrate one bounded workflow at a time. Use server-side pagination and cache-first summaries.

## Phase 4 — SMS/Integrations/background work

Separate interactive UI from scheduled/background runtime. Background jobs should not require interactive module UI boot.

## Phase 5 — Default switch

Only after feature parity and measured performance stability:

- make `/airfiber/` point to Next;
- keep Classic temporarily at a rollback URL;
- remove Classic only after a separate cleanup decision.
