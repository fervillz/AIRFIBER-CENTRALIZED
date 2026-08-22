# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-22

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

## Boundary

Classic stays in `includes/`, `templates/`, and `assets/`. Next/BETA lives in `next/`. The only Classic bootstrap bridge is `require_once AFC_PATH . 'next/bootstrap.php';`; the Classic frontend gets a small **Try BETA** link without restructuring Classic templates.

BETA URL: `/airfiber-beta/`.

## Core platform completed

- isolated `Airfiber\Next` namespace and `afcn_` prefixes
- readable WordPress-style `class-*.php` class/interface files
- protected managed BETA page; Try BETA / Back to Classic navigation
- shared UI design system (Source Serif 4 headings, 9px buttons/fields, 14px dialogs)
- compiled persistent module manifest registry + manual refresh
- numeric menu positions
- lazy PHP module autoloading
- generic REST rendering, read-only queries, lazy HTML chunks, and actions
- lazy per-module CSS/JS + asset path validation and size budgets
- browser SDK at `window.AirfiberNext`
- module dependencies, state, optional activate/deactivate lifecycle
- lazy Event Bus + normal WordPress `afcn_*` hooks
- `Module_Options` namespaced per-module settings
- cache/stale-cache helpers
- measured HTTP client
- durable bounded background Task Queue with retry/backoff
- performance budgets, sampling, p50/p95, separate external p95
- performance circuit breaker + runtime-failure isolation/quarantine
- bounded debug diagnostics and bounded administrative Audit Log
- Airfiber users backed by WordPress users (`airfiber_admin`, `airfiber_operator`)
- user create/update/delete UI
- Dashboard, Users, Modules and Settings Core modules
- Modules health screen and Settings diagnostics/performance screen

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device latency cannot quarantine a module. Non-system modules can be quarantined after repeated code/performance failures. Only built-in Core IDs may declare themselves system modules.

## Intentionally deferred from Core 0.1

Runtime ZIP install/delete is not exposed yet. Modules are deployed under `next/modules/` and then the registry is refreshed. This avoids filesystem/package-execution risk until the SDK is proven with real feature modules.

## Next work after Core

Do NOT bulk-migrate Classic. Build the first real non-system module as a proof: read-only **OLT overview/list**, then per-OLT/PON/ONU lazy chunks, and only later provisioning writes. After OLT proves the SDK, migrate PPP and other domains one bounded workflow at a time.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md` and the architecture docs, inspect current `main`, then continue the unfinished Airfiber Next/BETA work.
