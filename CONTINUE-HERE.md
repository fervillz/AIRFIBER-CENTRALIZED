# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-23

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

## Boundary

Classic stays in `includes/`, `templates/`, and `assets/`. Next/BETA lives in `next/`. The only Classic bootstrap bridge is `require_once AFC_PATH . 'next/bootstrap.php';`; the Classic frontend gets a small **Try BETA** link without restructuring Classic templates.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.3.3**.

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
- performance budget lookup is cached for the request so the profiler does not repeatedly resolve the same Core option
- a single profile sample can report every exceeded budget (for example render time plus DB-query count), instead of hiding secondary causes
- warning records retain module, reason and sanitized sample context; Core Settings exposes the exact module and cause rather than only a generic warning message
- Airfiber users backed by WordPress users (`airfiber_admin`, `airfiber_operator`)
- user create/update/delete UI
- Dashboard, Users, Modules and Settings must-use Core components
- Modules health screen and Settings diagnostics/performance screen
- WordPress-like Modules browser with All, Active, Inactive, Update Available, Auto-updates Disabled, Trash and MU views
- reusable Core SVG icons and tooltip API
- Core icon library includes module identity icons for Dashboard, Users, Modules, Settings and Connections; module cards use manifest `icon` metadata instead of hard-coded feature knowledge
- shared card hover language: `#e7f0fb` hover background, -10px lift and `0 10px 40px 0 rgba(0,0,0,.1)` shadow
- shared card timing: fast hover-in (~160ms) and slow 5s hover-out; reduced-motion is respected
- Module Manager CSS is Core-owned to prevent a flash of unstyled controls
- shared `next/assets/css/browser.css` owns the common filter-tab/search/browser treatment used by Modules and Connections
- shared `next/assets/js/browser.js` owns reliable filter/search behavior for Modules and Connections; feature modules do not duplicate this browser logic
- native `[hidden]` state is enforced over card `display` rules, so All/Active/Inactive/MU filtering cannot be visually overridden by card CSS
- module cards use the same visual identity language as Connections: icon at upper-left, compact state/update pills at upper-right, title tooltip, health/version footer
- module actions remain icon-only with tooltip labels, live directly below the title like WordPress plugin actions, appear on card hover/focus, and receive a white rounded shadowed surface only when the individual action is hovered/focused
- module descriptions live in the shared black tooltip on the module title instead of occupying 150 × 150 card space
- cached module views now emit the same `afcn:module:loaded` lifecycle event as fresh views, so Core/feature wiring remains consistent when navigating back to a cached screen

## Modules browser performance rule

The Modules browser is intentionally hybrid rather than AJAX-only:

- **60 modules or fewer:** render the lightweight cards once and filter/search instantly in the browser with no extra network request. This is faster than REST/AJAX for normal Airfiber installations.
- **more than 60 modules:** the browser automatically switches to the existing Airfiber REST query path. Filters/search fetch only **30 cards per request**, with Load More for the next page.
- search is debounced; stale REST responses are ignored.
- AJAX-inserted module cards are passed back through the Core action/dialog wiring so Activate, Deactivate, Trash, Restore and Settings controls remain functional.

MU modules only belong to the MU group; they are not counted or displayed in All/Active/Inactive/update/trash views.

## Connector/Connections foundation completed

Core now owns generic connector primitives only:

- `Connector_Registry` — lightweight connector types compiled from module manifests
- `Connection_Store` — generic non-secret configured connection records
- `Secret_Store` — encrypted credentials, Sodium preferred / AES-256-GCM fallback, no plaintext fallback
- `Connection_Health` — cached status/latency/last-check records separate from configuration
- `afcn_manage_connections` capability for Airfiber administrators

Normal feature modules may advertise `connectors` metadata in `module.json`. Reading that metadata must not bootstrap the owning module.

The normal `next/modules/connections/` add-on is now the central Connections Hub UI. It is enabled by default and positioned after Dashboard. It provides:

- All / Online / Offline / Warning / Unconfigured filters
- search
- grouped cards: Network, Cloud & Integrations, Payments, Messaging, Storage, Other
- shared Core card/tooltip/hover language
- Core-owned browser/filter/search/card styling so Connections does not depend on a feature CSS file just to render correctly
- generic create/edit/delete UI when an active module advertises a connector type
- provider test action routed lazily to the owning module
- cache-first health presentation

While feature modules are still Classic-owned, the Hub shows existing Classic OLT, MikroTik and Google Sheets entries as read-only **CLASSIC** cards. No credentials are copied. Management links back to Classic.

See `docs/CONNECTORS.md` and the updated `docs/MODULE-SDK.md`.

## Module folders

Must-use Core components live in:

`next/modules/mu/<id>/`

They are always active and cannot be deactivated or trashed. Only settings may be exposed when declared.

Normal installable add-ons live in:

`next/modules/<id>/`

Regular modules can be activated/deactivated, moved to Trash, restored and can declare a settings target.

The registry determines MU status from the physical folder, not from a module-controlled manifest flag.

## Updates

Update UI is provider-ready through the `afcn_module_update_catalog` filter. No package/update service is connected yet, so Update Available and Auto-updates Disabled may show zero until a provider is built.

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device latency cannot quarantine a module. Non-MU modules can be quarantined after repeated code/performance failures. MU/Core components are protected from disable/trash actions.

Opening Connections must not fan out to every remote device. It renders stored/cached state first. Provider modules perform explicit tests/background refreshes through their own logic.

Secrets never belong in manifests, connection config, browser bootstrap data, audit/debug logs or task payloads.

Performance diagnostics must identify the module and the exact exceeded budget. Do not weaken a budget just to hide a warning; first determine whether the cost is code time, query count, memory, assets or external latency.

## Intentionally deferred

Runtime ZIP install and permanent filesystem delete are not exposed yet. Modules are deployed under `next/modules/` and then the registry is refreshed. Trash is currently safe soft-state. This avoids filesystem/package-execution risk until the SDK is proven with real feature modules.

Native BETA connector provider modules do not exist yet, so **Add Connection** will remain hidden until an active feature module advertises a connector type. Existing Classic connections still appear through the read-only bridge.

## Next work

Do NOT bulk-migrate Classic.

Build the first real provider module: read-only **OLT**. Its manifest should advertise the relevant OLT connector type(s), then use the Core connection APIs for native BETA ownership. Start with OLT overview/list and cached health; add per-OLT/PON/ONU lazy chunks next; provisioning writes come only after the read-only path proves the SDK.

During transition, keep the Classic OLT connection card read-only until its credentials/config are intentionally migrated. After OLT proves the provider contract, migrate MikroTik, PPP and other domains one bounded workflow at a time.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md` and `docs/MODULE-SDK.md`, inspect current `main`, then continue the unfinished Airfiber Next/BETA work.
