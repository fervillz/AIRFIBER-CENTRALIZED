# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-23

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.4.1**.

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
- generic nested navigation (`parent`) and utility presentation (`presentation: drawer`)
- Super-Admin-only Tools developer console and performance FIX workflow
- safe one-time Airfiber Owner/Super Admin setup in Users

## User access model

Authority and visibility are separate.

- **Super Admin** is explicit owner/developer authority and is not automatically granted to the WordPress `administrator` role.
- WordPress Administrators remain normal Airfiber Administrators by default.
- Super Admin sees all enabled modules and the Modules-screen MU inventory and bypasses per-user module visibility.
- Normal Administrators do not see the MU inventory in the Modules browser.
- `User_Access` stores optional per-user allow lists for normal feature modules. The module runtime enforces those restrictions in addition to hiding navigation.
- no saved user visibility policy means all enabled normal modules are visible; if every module is checked Core clears the restrictive policy so future modules remain visible automatically.
- MU/Core pages remain capability-driven; the visibility UI does not let normal Admins manipulate Core/MU access.
- modules requiring `afcn_super_admin` are authority-only developer modules and never appear in normal Admin navigation/module inventory or per-user visibility checkboxes.

The Users MU screen is card-first using the same 150×150 visual language as Modules, with icon role pills and icon-only hover actions. A list icon beside the title toggles between cards and a File-Explorer-style list; the choice is stored locally in the browser.

### First-run Airfiber Owner — Core 0.4.1

Airfiber never ships a hard-coded Super Admin credential.

If no Super Admin exists, a logged-in WordPress Administrator sees **Set up the Airfiber Owner** at the top of Users. The Administrator can:

- promote the current WordPress account to Airfiber Super Admin; or
- create a separate WordPress Administrator and designate it as Super Admin.

The separate-owner dialog suggests `bordocs` as the username only for convenience. It is editable and no password is embedded in source. Password may be entered manually or left blank so WordPress generates a strong random password and Airfiber displays it once.

Successful in-app setup stores the chosen user ID in `afcn_super_admin_user_id` and grants the direct `afcn_super_admin` capability. `AFCN_SUPER_ADMIN_USER_ID` in `wp-config.php` remains a supported deployment-level override.

The first-run setup disappears after an owner exists and the owner claim uses an atomic option write so two Administrators cannot independently complete setup at the same time.

See `docs/USER-ACCESS.md` and `docs/DECISIONS.md`.

## Tools developer console — Core 0.4.0

`next/modules/tools/` is a **normal lazy module**, not MU/Core. It declares:

```json
{
  "parent": "settings",
  "presentation": "drawer",
  "capability": "afcn_super_admin"
}
```

Core only owns the generic navigation hierarchy and fixed right-side utility drawer. The Tools PHP/CSS/JS remains module-owned and lazy.

For the explicit Super Admin:

- Settings gets a nested **Tools** submenu.
- Tools opens in a fixed right-side drawer with slide-in/slide-out animation, without replacing the main page.
- Settings → Recent performance warnings shows **FIX** for module warnings.
- Clicking FIX automatically opens Tools and runs an AJAX diagnostic session in the console.
- The session inspects health/budgets/assets, warms the compiled registry, runs a controlled module render, retests the module REST request, then prints recommendations.

Normal customer/Admin sessions do not receive the Tools submenu, Tools inventory entry, FIX buttons, utility drawer runtime, or Tools assets.

Automatic FIX is intentionally conservative: it does not rewrite live PHP/JS/CSS, database schema, customer data, SSH settings or firewall configuration. Structural changes are recommendations for the normal Git/development workflow.

See `docs/TOOLS-CONSOLE.md` and `docs/DECISIONS.md`.

## Lazy module asset portability

Core 0.3.10 fixed the repeated unstyled module screen on Windows-based development hosts. `Assets::module_manifest()` normalizes both module base paths and resolved asset paths with `wp_normalize_path()` before the security containment check.

Core also supports conventional lazy assets:

```text
assets/<module-id>.css
assets/<module-id>.js
```

Explicit manifest asset declarations remain supported for additional/non-conventional files.

## Module developer experience

GitHub Markdown is the documentation source of truth. Start at `docs/README.md` and `docs/MODULE-BASICS.md`.

`module.json` is the Airfiber equivalent of a WordPress plugin header. A convention-following module can use only a `name`; Core infers folder ID, namespace, class and class filename without executing module PHP.

Core 0.4.0 also recognizes generic optional navigation/presentation metadata:

```json
{
  "parent": "settings",
  "presentation": "drawer"
}
```

Default presentation remains `page`.

## Active does not mean loaded

> Installed does not mean loaded. Active does not mean loaded.

Feature module PHP/assets/data are loaded only when a direct page, query, action, lazy chunk, declared event, background task or shared-page slot explicitly needs them.

`Module_Slots` resolves shared-page contributors from cached manifest metadata. Dashboard exposes `dashboard.summary`; the browser requests each contribution only as it approaches the viewport. Lazy chunk assets are deduplicated and loaded only when that chunk is requested.

See `docs/MODULE-LOADING.md`.

## Modules browser

Normal add-ons live in `next/modules/<id>/`. MU components live in `next/modules/mu/<id>/`.

Normal Admins see only normal add-on inventory they are authorized to know about. Super Admin additionally sees the MU inventory and developer-authority modules such as Tools. The browser remains hybrid: local filtering up to 60 modules; above that it switches to REST/AJAX pages of 30.

## Performance telemetry

Browser client apply is separated from diagnostic transport, asset-load and full-navigation timing. External provider latency is separate. Only actionable code/server/client violations feed the circuit breaker.

Diagnostic transport/navigation warnings can now be handed to the Super Admin Tools console for safe runtime analysis rather than blindly increasing the budget.

See `docs/PERFORMANCE-CONTRACT.md` and `docs/TOOLS-CONSOLE.md`.

## Connections architecture

Core owns generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. The normal `connections` add-on provides the grouped Hub UI. Existing Classic OLT, MikroTik and Google Sheets entries appear as read-only **CLASSIC** cards without copying credentials.

See `docs/CONNECTORS.md`.

## Core safety rules

An unopened module costs almost nothing beyond cached manifest metadata. External device/network latency cannot quarantine a module. Secrets never belong in manifests, browser bootstrap data, logs or task payloads. Do not weaken a performance budget just to hide a warning.

Do not introduce broad feature-module bootstrap hooks that run on every Airfiber request. Prefer explicit Core loading triggers and manifest metadata.

Feature CSS/JS should remain lazy rather than being moved into global Core merely to solve a deployment, path or cache issue. Shared BETA design is consumed through Core variables/components; module CSS should add only feature-specific layout.

Developer Tools must never become a hidden backdoor or live self-modifying source-code system. Safe diagnostics can be automated; source restructuring stays in Git.

Do not add a known/default owner password to source. First-run owner setup must remain explicit and local.

## Intentionally deferred

Runtime ZIP installation and permanent filesystem deletion are not exposed yet. Trash is safe soft-state while the SDK is proven with real modules. The update UI is provider-ready but no package/update provider exists yet.

Nested business-module/sub-feature permission checkboxes remain deferred until a real use case defines their contract. Future Tools security features such as IP/user blocking require explicit auditing and separate design.

## Next work

Do NOT bulk-migrate Classic.

First verify Core 0.4.1 owner setup by opening Users as the current WordPress Administrator and promoting that account. After reload it should show **Super Admin**, Modules should expose MU, Settings should expose Tools, and performance warnings should expose FIX.

After that verification, build the first real provider module: read-only **OLT**. Use the convention-based module skeleton, advertise OLT connector types, contribute a small cache-first `dashboard.summary` slot, then build cached overview/list and lazy per-OLT/PON/ONU chunks. Provisioning writes come only after the read-only path proves the SDK.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/README.md`, `docs/MODULE-BASICS.md`, `docs/MODULE-LOADING.md`, `docs/USER-ACCESS.md`, `docs/TOOLS-CONSOLE.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md`, `docs/MODULE-SDK.md` and `docs/PERFORMANCE-CONTRACT.md`, inspect current `main`, then continue Airfiber Next/BETA work.
