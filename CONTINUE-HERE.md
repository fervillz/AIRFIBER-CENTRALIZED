# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-23

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.3.4**.

## Boundary

Classic stays in `includes/`, `templates/`, and `assets/`. Next/BETA lives in `next/`. Do not bulk-restructure Classic. The Classic bridge only boots `next/bootstrap.php` and exposes the small **Try BETA** entry.

## Core platform completed

- isolated `Airfiber\Next` namespace and `afcn_` prefixes
- readable WordPress-style `class-*.php` files
- managed/protected BETA page
- shared UI system: Source Serif 4 headings, 9px controls, 14px dialogs, shared cards/tooltips/icons/motion
- compiled cached module registry, numeric menu positions and lazy module PHP/assets/data
- generic REST render/query/chunk/action runtime
- browser SDK at `window.AirfiberNext`
- module activation/deactivation, dependencies, Trash and MU separation
- cache helpers, event bus/hooks, task queue, HTTP client, audit/debug logging
- WordPress-backed Airfiber users/roles/capabilities
- performance budgets, bounded samples, p50/p95 and circuit-breaker isolation
- Core MU components: Dashboard, Users, Modules, Settings
- normal Connections add-on with Classic read-only connector bridge

## Modules browser

MU components live in `next/modules/mu/<id>/` and only appear in the MU tab. Normal add-ons live in `next/modules/<id>/`.

The Modules browser uses shared Core CSS/JS and WordPress-like filters: All, Active, Inactive, Update Available, Auto-updates Disabled, Trash and MU. Module cards are 150×150 with manifest-driven icons, pills, description tooltip, health/version footer and icon-only hover actions.

Performance rule:

- **60 modules or fewer:** render lightweight card metadata once and filter/search locally with no AJAX request.
- **more than 60 modules:** switch automatically to REST/AJAX, 30 cards per page, debounced search and Load More only when another page exists.

## Performance telemetry — important

Core 0.3.4 corrected a measurement bug from metric schema v1.

Previously `client` timing started **before the module REST request**, so WordPress/network/server time was incorrectly reported as browser/client work. Values such as 170–400 ms therefore did not prove that module JavaScript was slow.

Browser timing is now separated:

- `client` = DOM insertion + Core/module event wiring through the next paint; actionable and allowed to affect module health
- `transport` = REST/WordPress/network delivery; diagnostic only
- `asset_load` = optional module CSS/JS delivery; diagnostic only
- `navigation` = complete uncached module transition; diagnostic only
- `external` = OLT/MikroTik/API latency; diagnostic only

Default budgets now include client 160 ms, transport 500 ms, asset load 250 ms, navigation 650 ms and external 800 ms. Server budgets remain bootstrap 30 ms, render 120 ms, query 180 ms, action 250 ms, background 1000 ms, 8 MB memory delta and 15 DB queries.

Only actionable code/server/client violations feed the circuit breaker. Transport/navigation/asset-delivery/external latency can be logged as diagnostics but cannot quarantine a module.

A one-time metric migration runs only on the BETA frontend and removes the invalid legacy `client` warning state, samples and matching debug rows while preserving unrelated failures. Runtime health p50/p95 now excludes transport/navigation/network timing and exposes those as separate p95 values.

See `docs/PERFORMANCE-CONTRACT.md`.

## Connections architecture

Core owns generic connector primitives only:

- `Connector_Registry`
- `Connection_Store`
- `Secret_Store`
- `Connection_Health`

The normal `next/modules/connections/` add-on provides the central grouped Connections Hub. Existing Classic OLT, MikroTik and Google Sheets entries appear as read-only **CLASSIC** cards without copying credentials. Native provider modules will advertise connector metadata in `module.json` and perform their own explicit/background tests; opening Connections must never fan out to every device.

See `docs/CONNECTORS.md` and `docs/MODULE-SDK.md`.

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device/network latency cannot quarantine a module. Secrets never belong in manifests, browser bootstrap data, logs or task payloads. Do not weaken a budget just to hide a warning; first identify whether the cost is server code, DB queries, memory, client apply, transport, assets or external latency.

## Intentionally deferred

Runtime ZIP installation and permanent filesystem deletion are not exposed yet. Trash is safe soft-state while the SDK is proven with real modules. The update UI is provider-ready but no package/update provider exists yet.

## Next work

Do NOT bulk-migrate Classic.

Build the first real provider module: read-only **OLT**. Advertise OLT connector types through its manifest, use Core connection APIs, start with cached overview/list, then lazy per-OLT/PON/ONU chunks. Provisioning writes come only after the read-only path proves the SDK.

Before optimizing a module because of a performance warning, use the new separated telemetry to identify the actual phase first.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md`, `docs/MODULE-SDK.md` and `docs/PERFORMANCE-CONTRACT.md`, inspect current `main`, then continue Airfiber Next/BETA work.
