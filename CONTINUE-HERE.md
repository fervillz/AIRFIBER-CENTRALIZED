# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-23

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.3.10**.

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

## User access model

Authority and visibility are separate.

- **Super Admin** is explicit owner/developer authority and is not automatically granted to the WordPress `administrator` role.
- WordPress Administrators remain normal Airfiber Administrators by default.
- Super Admin sees all enabled modules and the Modules-screen MU inventory and bypasses per-user module visibility.
- Normal Administrators do not see the MU inventory in the Modules browser.
- `User_Access` stores optional per-user allow lists for normal feature modules. The module runtime enforces those restrictions in addition to hiding navigation.
- no saved user visibility policy means all enabled normal modules are visible; if every module is checked Core clears the restrictive policy so future modules remain visible automatically.
- MU/Core pages remain capability-driven; the visibility UI does not let normal Admins manipulate Core/MU access.
- the policy reserves an `areas` key for future nested/submenu visibility, but module-level visibility is the only stable contract now.

The Users MU screen is card-first using the same 150×150 visual language as Modules, with icon role pills and icon-only hover actions. A list icon beside the title toggles between cards and a File-Explorer-style list; the choice is stored locally in the browser.

### Lazy module asset portability

Core 0.3.10 fixes the actual cause of the repeated unstyled Users screen on Windows-based development hosts. PHP `realpath()` returns Windows paths with backslashes, while WordPress/Airfiber paths commonly use forward slashes. The old containment check compared those raw strings and rejected valid module CSS/JS as if they were outside the module directory.

`Assets::module_manifest()` now normalizes both the module base path and resolved asset path with `wp_normalize_path()` before doing the security containment check. The traversal/readability checks remain intact. This keeps Users and every future module's CSS/JS lazy while making the loader portable across Windows and Unix hosts.

Core also supports conventional lazy assets:

```text
assets/<module-id>.css
assets/<module-id>.js
```

Explicit manifest asset declarations remain supported for additional/non-conventional files.

Super Admin is never created as a hidden account/backdoor. A local deployment explicitly designates it, for example:

```php
define( 'AFCN_SUPER_ADMIN_USER_ID', 123 );
```

Use the WordPress user ID of the intended owner/developer account. Public/customer installs that do not designate this remain normal Admin installations.

See `docs/USER-ACCESS.md` and the matching entries in `docs/ARCHITECTURE.md`, `docs/DECISIONS.md` and `AGENTS.md`.

A future Developer/Debug/Security nav is intentionally **not** implemented yet. If added, it should be Super-Admin-only, explicit and audited; no hidden SSH/backdoor behavior.

## Module developer experience

GitHub Markdown is the documentation source of truth. Start at `docs/README.md` and `docs/MODULE-BASICS.md`.

`module.json` is the Airfiber equivalent of a WordPress plugin header. A convention-following module can use only a `name`; Core infers folder ID, namespace, class and class filename without executing module PHP.

## Active does not mean loaded

> Installed does not mean loaded. Active does not mean loaded.

Feature module PHP/assets/data are loaded only when a direct page, query, action, lazy chunk, declared event, background task or shared-page slot explicitly needs them.

`Module_Slots` resolves shared-page contributors from cached manifest metadata. Dashboard exposes `dashboard.summary`; the browser requests each contribution only as it approaches the viewport. Lazy chunk assets are deduplicated and loaded only when that chunk is requested.

See `docs/MODULE-LOADING.md`.

## Modules browser

Normal add-ons live in `next/modules/<id>/`. MU components live in `next/modules/mu/<id>/`.

Normal Admins see only normal add-on filters/inventory. Super Admin additionally sees the MU tab/inventory. The browser remains hybrid: local filtering up to 60 modules; above that it switches to REST/AJAX pages of 30.

## Performance telemetry

Core 0.3.4 corrected the old client-timing bug. Browser client apply is separated from diagnostic transport, asset-load and full-navigation timing. External provider latency is separate. Only actionable code/server/client violations feed the circuit breaker.

See `docs/PERFORMANCE-CONTRACT.md`.

## Connections architecture

Core owns generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. The normal `connections` add-on provides the grouped Hub UI. Existing Classic OLT, MikroTik and Google Sheets entries appear as read-only **CLASSIC** cards without copying credentials.

See `docs/CONNECTORS.md`.

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device/network latency cannot quarantine a module. Secrets never belong in manifests, browser bootstrap data, logs or task payloads. Do not weaken a performance budget just to hide a warning.

Do not introduce broad feature-module bootstrap hooks that run on every Airfiber request. Prefer explicit Core loading triggers and manifest metadata.

Feature CSS/JS should remain lazy rather than being moved into global Core merely to solve a deployment, path or cache issue. Shared BETA design is consumed through Core variables/components; module CSS should add only feature-specific layout.

## Intentionally deferred

Runtime ZIP installation and permanent filesystem deletion are not exposed yet. Trash is safe soft-state while the SDK is proven with real modules. The update UI is provider-ready but no package/update provider exists yet.

Nested/submenu permission checkboxes and the proposed Developer/Debug/Security area are deferred until a real use case defines their contract.

## Next work

Do NOT bulk-migrate Classic.

Build the first real provider module: read-only **OLT**. Use the convention-based module skeleton, advertise OLT connector types, contribute a small cache-first `dashboard.summary` slot, then build cached overview/list and lazy per-OLT/PON/ONU chunks. Provisioning writes come only after the read-only path proves the SDK.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/README.md`, `docs/MODULE-BASICS.md`, `docs/MODULE-LOADING.md`, `docs/USER-ACCESS.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md`, `docs/MODULE-SDK.md` and `docs/PERFORMANCE-CONTRACT.md`, inspect current `main`, then continue Airfiber Next/BETA work.
