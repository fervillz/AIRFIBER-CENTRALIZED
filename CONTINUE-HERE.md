# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-22

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working.

The product principle is **Fast by design**.

## Important boundary

Classic remains where it is:

- `includes/`
- `templates/`
- `assets/`

Next/BETA lives only in:

- `next/`

The only Classic bootstrap bridge is `require_once AFC_PATH . 'next/bootstrap.php';` in the main plugin file. The Classic frontend receives a small **Try BETA** link through an inline script; its existing templates are not restructured.

## Next/BETA URL

`/airfiber-beta/`

The page is managed automatically by `Airfiber\Next\Bootstrap`.

## Core completed

- isolated `Airfiber\Next` namespace and `afcn_` prefixes
- WordPress-style readable `class-*.php` class filenames
- managed BETA page and protected app template
- Classic → **Try BETA** bridge and BETA → **Back to Classic** link
- Core UI design tokens, fields, buttons, cards, tables, dialogs and transitions
- Source Serif 4 headings and system/Inter UI stack
- manifest-based module discovery with a persistent compiled registry cache
- manual registry refresh for newly deployed modules
- numeric module menu positions
- lazy module PHP autoloading
- AJAX/REST module rendering
- lazy per-module CSS/JavaScript manifests
- generic module actions/forms
- module dependencies
- module enable/disable state plus optional `activate()` / `deactivate()` lifecycle methods
- lazy event bus for declared module events
- namespaced cache helper with stale/fresh envelopes
- measured HTTP client for external requests
- performance budgets and request sampling
- module health history with p50/p95 plus separate external p95
- asset-size budgets for optional module CSS/JS
- performance circuit breaker
- automatic quarantine after repeated clustered module-code violations for non-system modules
- external device/API slowness is measured separately and does not quarantine a module by itself
- bounded debug warning/error log
- Airfiber roles/capabilities using WordPress users underneath
- Airfiber user create/update/delete UI
- Core Modules health page
- Core Settings/performance budget page
- Dashboard system module

## Built-in system modules

- `dashboard`
- `users`
- `modules`
- `settings`

They are system modules and cannot be disabled from BETA.

## Performance contract

An enabled module that has not been opened should cost almost nothing beyond reading its cached manifest metadata.

Default budgets:

- bootstrap: 30 ms
- server render: 120 ms
- action: 250 ms
- client initialization: 160 ms
- external request: 800 ms (diagnostic only; does not quarantine by itself)
- memory delta: 8 MB
- database queries per profiled phase: 15
- optional module CSS: 40 KB
- optional module JavaScript: 100 KB

Three clustered module-code/asset violations → warning.
Six → degraded.
Twelve within the one-hour violation window → non-system module quarantined.

System modules are never automatically quarantined.

## What is intentionally NOT migrated yet

Classic PPP, OLT, Billing, Payments, Connections, SMS and Integrations are still Classic code. Do not move all of them at once.

The next major task is to prove the module SDK by migrating one read-only feature first. Recommended first feature: **OLT overview/list**, then progressively add ONU views and provisioning.

## Safety decision

Runtime ZIP installation/deletion of module folders is intentionally not exposed yet. During BETA, modules are added through the repository under `next/modules/`. This keeps filesystem mutation out of the first platform release. Add a secure package installer only after the module contract is stable.

## When starting a new chat

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, and the referenced architecture docs, inspect current `main`, then continue the unfinished Airfiber Next/BETA work.

Do not redesign the architecture from scratch unless a measured problem requires it.
