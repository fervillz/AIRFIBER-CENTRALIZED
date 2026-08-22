# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-23

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.3.6**.

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

## Module developer experience

GitHub Markdown is the documentation source of truth. Start at `docs/README.md` and `docs/MODULE-BASICS.md`.

`module.json` is intentionally the Airfiber equivalent of a WordPress plugin header. Core detects `next/modules/*/module.json` without executing module PHP.

A convention-following manifest can be only:

```json
{
  "name": "Hello World"
}
```

For folder `hello-world/`, Core infers `Airfiber\Next\Modules\HelloWorld\Hello_World_Module` in `includes/class-hello-world-module.php`.

`Module_Naming` is the single source of truth for folder/namespace/class conventions.

## Active does not mean loaded

Core 0.3.6 formalizes the rule:

> Installed does not mean loaded. Active does not mean loaded.

Feature module PHP/assets/data are loaded only when a direct page, query, action, lazy chunk, declared event, background task or shared-page slot explicitly needs them.

`module.json` now supports lightweight `slots` metadata. Example:

```json
"slots": {
  "dashboard.summary": {
    "chunk": "dashboard-summary",
    "priority": 20,
    "span": 4
  }
}
```

Core `Module_Slots` resolves eligible contributors from the cached manifest registry without loading their PHP. The browser uses `IntersectionObserver` with a small prefetch margin and requests each contribution only as its placeholder approaches the viewport. Disabled, trashed, dependency-blocked, unauthorized or quarantined modules are excluded before rendering placeholders.

Lazy chunk responses now include the owning module's optional assets. Core loads/deduplicates those assets before inserting the chunk and emits `afcn:chunk:loaded` afterward.

Dashboard exposes the first shared slot: `dashboard.summary`. It currently renders nothing because no feature module contributes to it yet. The first real use should be the read-only OLT module.

See `docs/MODULE-LOADING.md`, `docs/MODULE-BASICS.md`, `docs/MODULE-SDK.md`, and `docs/ARCHITECTURE.md`.

## Modules browser

MU components live in `next/modules/mu/<id>/` and only appear in the MU tab. Normal add-ons live in `next/modules/<id>/`.

The Modules browser uses shared Core CSS/JS and WordPress-like filters: All, Active, Inactive, Update Available, Auto-updates Disabled, Trash and MU. Module cards are 150×150 with manifest-driven icons, pills, description tooltip, health/version footer and icon-only hover actions.

Performance rule:

- **60 modules or fewer:** render lightweight card metadata once and filter/search locally with no AJAX request.
- **more than 60 modules:** switch automatically to REST/AJAX, 30 cards per page, debounced search and Load More only when another page exists.

## Performance telemetry — important

Core 0.3.4 corrected the old client-timing bug. Browser timing is separated into actionable client apply versus diagnostic transport, asset-load and full-navigation timing. External provider latency is also separate. Only actionable code/server/client violations feed the circuit breaker.

See `docs/PERFORMANCE-CONTRACT.md`.

## Connections architecture

Core owns generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. The normal `connections` add-on provides the grouped Hub UI. Existing Classic OLT, MikroTik and Google Sheets entries appear as read-only **CLASSIC** cards without copying credentials.

See `docs/CONNECTORS.md`.

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device/network latency cannot quarantine a module. Secrets never belong in manifests, browser bootstrap data, logs or task payloads. Do not weaken a performance budget just to hide a warning.

Do not introduce broad feature-module bootstrap hooks that run on every Airfiber request. Prefer explicit Core loading triggers and manifest metadata.

## Intentionally deferred

Runtime ZIP installation and permanent filesystem deletion are not exposed yet. Trash is safe soft-state while the SDK is proven with real modules. The update UI is provider-ready but no package/update provider exists yet.

## Next work

Do NOT bulk-migrate Classic.

Build the first real provider module: read-only **OLT**. Use the convention-based module skeleton, advertise OLT connector types, contribute a small cache-first `dashboard.summary` slot, then build cached overview/list and lazy per-OLT/PON/ONU chunks. Provisioning writes come only after the read-only path proves the SDK.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/README.md`, `docs/MODULE-BASICS.md`, `docs/MODULE-LOADING.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md`, `docs/MODULE-SDK.md` and `docs/PERFORMANCE-CONTRACT.md`, inspect current `main`, then continue Airfiber Next/BETA work.
