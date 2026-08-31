# AIRFIBER-CENTRALIZED — Continue Here

Updated: 2026-08-31

## Current goal

Build Airfiber Next/BETA as an isolated, very fast application platform inside the existing WordPress plugin while keeping Airfiber Classic working. Product principle: **Fast by design**.

BETA URL: `/airfiber-beta/`.

Airfiber Next Core version: **0.4.34**.

## Boundary

Classic stays in `includes/`, `templates/`, and `assets/`. Next/BETA lives under `next/`. Do not bulk-restructure Classic. The Classic bridge only boots `next/bootstrap.php` and exposes the small **Try BETA** entry.

## Core platform completed

- isolated `Airfiber\Next` namespace and `afcn_` prefixes
- readable WordPress-style `class-*.php` files
- managed/protected BETA page
- shared UI system: Source Serif 4 headings, 9px controls, 14px dialogs, shared cards/tooltips/icons/motion
- shared compact Core drill-down header for primary-card → child-card views; dialogs remain separate
- shared Core `Data_Query` search/paging helper and modern bounded data-table browser; remote modules can page from short-lived safe caches
- shared Core Tabs component with accessible top/bottom/left/right layouts and keyboard navigation
- Core UI kit adds token-driven buttons/counters/status/alerts/lists/forms/menus/progress/empty states/dialog helpers; see `docs/UI-COMPONENTS.md`
- typography-first density rule: keep primary text readable; compress secondary data into pills/icons/tooltips or move it to dialogs/drill-downs instead of shrinking fonts; Core semantic type scale is 11/12/14/16/20px
- no-bold BETA rule: all UI text is normal 400 weight; emphasize with +1px size, darker color, spacing, pills/icons, or progressive disclosure; `.afcn-emphasis` is the shared Core utility
- Settings is now a lazy card launcher: budgets/platform/warnings/audit load into a Core dialog only on click; Developer Console keeps the existing lazy Tools drawer
- Core `UI::indicator_button()` supports superscript warning/error counts and severity dots; Tools FIX ALL sequentially retests/resolves fixable events and refreshes remaining counts live
- shared view-mode controller supports configurable defaults/labels; Router drill-down defaults to left tabs with cards as the alternate view
- one shared responsive 680 × 680 BETA dialog frame; long dialog bodies scroll while header/footer stay fixed
- one shared BETA dialog header close control using `.afcn-icon-button`, styled from the proven Classic Connections modal
- sitewide BETA button states with top-right loading/success/warning/error/disabled badges and shared tooltip messages
- modal alert + modal box-shadow state feedback synchronized with the active button action
- shared cards/list view controller with the Users-style list/grid title toggle
- shared Trello-like card arrangement runtime with long-press lift, pointer-follow, FLIP displacement, edge scroll, float-back and per-user persistence
- compiled cached module registry with 5-minute manifest self-refresh, numeric menu positions and lazy module PHP/assets/data
- generic REST render/query/chunk/action runtime
- browser SDK at `window.AirfiberNext`
- module activation/deactivation, dependencies, Trash and MU separation
- cache helpers, event bus/hooks, task queue, HTTP client, audit/debug logging
- WordPress-backed Airfiber users/roles/capabilities
- performance budgets, bounded samples, p50/p95 and circuit-breaker isolation
- Core/MU components: Dashboard, Users, Modules, Settings, Tools
- normal Connections add-on with Classic read-only connector bridge
- generic connector-field `show_when` metadata and unsaved form **Connect** probes
- first native read-only OLT provider using Connection_Store / Secret_Store / Connection_Health
- first BETA Payments module: zero-load focused customer search, 3-character debounced AJAX lookup, max 10 ranked PPP matches, 20-second search cache and narrow payment-only RouterOS write path
- generic nested navigation (`parent`) and utility presentation (`presentation: drawer`)
- Super-Admin-only Tools developer console and resilient performance FIX workflow
- resolved-warning lifecycle that keeps history but removes successfully remediated warnings from the active table
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

## Tools developer console — Core 0.4.4

`next/modules/mu/tools/` is a **must-use lazy module**. It is physically MU because developer diagnostics are part of the Airfiber control plane and must not be activatable, deactivatable, trashed or deleted like customer feature modules.

It declares:

```json
{
  "parent": "settings",
  "presentation": "drawer",
  "capability": "afcn_super_admin"
}
```

Core only owns the generic navigation hierarchy and fixed right-side utility drawer. The Tools PHP/CSS/JS remains module-owned and lazy despite MU status.

For the explicit Super Admin:

- Settings gets a nested **Tools** submenu.
- Tools opens in a fixed right-side drawer with slide-in/slide-out animation, without replacing the main page.
- Settings → Recent performance warnings shows **FIX** for module warnings.
- Clicking FIX automatically opens Tools and runs an AJAX diagnostic session in the console.
- The built-in console sends diagnostic commands through the required module action endpoint instead of depending on optional query support.
- If one diagnostic stage fails, the console logs the problem and continues with safe warm-up / REST retest when possible instead of immediately stopping the entire FIX run.
- The session inspects health/budgets/assets, warms the compiled registry, runs a controlled module render, retests the module REST request, then prints recommendations.
- When the retest succeeds within budget, the original warning is marked resolved, removed from the Settings table immediately, and retained only in bounded debug history.
- If the same issue happens again, Performance Monitor writes a fresh warning and it becomes active again automatically.
- Modules → MU includes Tools with the other protected components.

Normal customer/Admin sessions do not receive the Tools submenu, MU inventory, FIX buttons, utility drawer runtime, or Tools assets.

Automatic FIX is intentionally conservative: it does not rewrite live PHP/JS/CSS, database schema, customer data, SSH settings or firewall configuration. Structural changes are recommendations for the normal Git/development workflow.

See `docs/TOOLS-CONSOLE.md` and `docs/DECISIONS.md`.

## Native OLT — Core 0.4.7

`next/modules/olt/` is the first real native provider module and remains a normal lazy add-on, not MU. It starts read-only to prove the connector/runtime architecture against real infrastructure before any provisioning code is migrated.

The OLT manifest advertises `olt-snmp` without loading OLT PHP during discovery. Connections can therefore offer **OLT (SNMP)** from metadata alone.

Current native OLT support:

- GPON and EPON
- SNMPv3 `authPriv` with SHA/DES, matching Classic
- SNMPv2c read-only community
- SNMP-version-aware fields: v2c shows Community only; v3 shows Username/Auth/Privacy
- encrypted SNMP secrets through `Secret_Store`
- system name/description identity read
- GPON/EPON default RX OIDs when the custom OID is blank
- explicit **Connect** probe in the Add/Edit Connection dialog without saving the form
- successful probe changes the button to **Connected**; editing the form resets it to **Connect**
- cached health/details in `Connection_Health` for saved connection tests
- external SNMP latency recorded as diagnostic performance data
- cache-first OLT page and `dashboard.summary` contribution

### GPON SNMPv2c compatibility

Classic already contains an important firmware workaround: V1600G-family GPON agents can answer SNMP GET/GETNEXT while stalling on GETBULK/`snmp2_real_walk()`. Core 0.4.7 ports that proven behavior into the native OLT provider rather than inventing a different transport path.

For GPON + SNMPv2c, BETA now tries a bounded numeric-OID GETNEXT walk first, then `real_walk()` as fallback. Other SNMP modes retain normal real-walk behavior with the bounded GETNEXT path available when the normal walk fails.

This difference was the main code-level mismatch between the working Classic GPON path and the first BETA OLT implementation.

Opening **Connections**, **OLT**, or Dashboard still never polls an OLT. Live SNMP happens only from an explicit saved test, form Connect probe, or future bounded background work.

Classic is still the migration safety net. Creating or probing an unsaved native OLT does not hide the matching Classic card. After the **saved** native connection for the same host passes an explicit test and is cached as online, Connections prefers the verified BETA card and suppresses only that duplicate Classic OLT card. No Classic credentials are copied automatically.

Provisioning, ONU writes and deep PON/ONU inventory are intentionally deferred until this read-only connection path is verified against real devices.

See `docs/CONNECTORS.md`, `docs/ARCHITECTURE.md` and `docs/DECISIONS.md`.

## Uniform BETA dialogs — Core 0.4.5 / 0.4.9

All normal `<dialog class="afcn-dialog">` modals use one Core-owned responsive frame rather than module-specific dimensions.

Desktop target is 680 × 680 px, constrained by the current viewport. Dialog header and footer stay fixed; only `.afcn-dialog-body` scrolls when a form is taller than the shared frame. Mobile uses the same component with a small viewport gutter.

Core 0.4.6 standardized the dialog header close control. Add/Edit User and Add/Edit Connection use the same existing `.afcn-icon-button` with `data-afcn-dialog-close`. Its 32 × 32 px outlined white rounded-square styling is based on the proven Classic Connections close button. Do not introduce `.afcn-dialog-close` or another module-specific close-button class.

Core 0.4.8 introduced shared sitewide button states and modal alert messages. Core 0.4.9 extends the same state to the dialog shadow so the modal itself communicates the current action: blue while loading/warning, green on success, red on error, and grey when explicitly disabled. Editing after a completed action clears stale modal feedback; closing a dialog also clears its transient visual state.

Modules should not set their own modal width/height, close-button styling, status badges, alert styling or state shadows. Connections, Users and future OLT/PPP/Billing forms inherit the shared Core rules automatically.

See `docs/UI-SYSTEM.md`.

## Connector form contract — Core 0.4.7

Connector manifests may add a simple equality condition to a field:

```json
"show_when": {"field":"version","value":"2c"}
```

Connections renders and wires this generically. Hidden fields are disabled so irrelevant credentials are not submitted. During edit, an omitted conditional field keeps its existing saved value instead of being erased. Provider-specific field names remain outside Core/Connections logic.

A connector with `test_action` can expose the shared **Connect** dialog action. The probe sends sanitized current form configuration to the provider and only the secret values actually typed in the form. Blank edit secrets can fall back to `Secret_Store`. The probe never persists configuration and never changes the health of the saved connection.

See `docs/CONNECTORS.md`.

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

Core recognizes generic optional navigation/presentation metadata:

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

MU also does not mean eagerly loaded. MU modules are lifecycle-protected, but their PHP/assets can still remain lazy when the module contract allows it.

`Module_Slots` resolves shared-page contributors from cached manifest metadata. Dashboard exposes `dashboard.summary`; the browser requests each contribution only as it approaches the viewport. OLT is the first real contributor and its chunk reads only cached native connection state.

See `docs/MODULE-LOADING.md`.

## Shared card UX — Core 0.4.14 / 0.4.15

`window.AirfiberViewMode` owns the common cards/list toggle. Users and Connections reuse the same list/grid icons, tooltip and local view preference rather than implementing separate controls.

`window.AirfiberCardOrder` owns the site-wide arrangement runtime. Core 0.4.15 keeps the existing long-press-to-enter / long-press-to-save-and-exit contract but makes the actual drag Trello-like:

- about 420 ms long press immediately lifts the same card under the held pointer;
- the floating card follows pointer movement with `requestAnimationFrame` + `translate3d()`;
- neighboring cards move using FLIP/Web Animations instead of snapping;
- top/bottom insertion and bounded edge scrolling are supported;
- invalid drops animate back to the original index;
- valid drops settle into the placeholder before normal card CSS is restored;
- existing v1 saved card order migrates into the v2 preference key.

Cross-list dragging is supported only for explicitly compatible containers. Board-like modules may put the same `data-afcn-card-drop-group="..."` on multiple direct card parents. Default containers remain isolated so user layout customization cannot visually move a semantic Connection category or similar business grouping by accident.

See `docs/UI-SYSTEM.md`.

## Modules browser

Normal add-ons live in `next/modules/<id>/`. MU components live in `next/modules/mu/<id>/`.

Normal Admins see only normal add-on inventory they are authorized to know about. Super Admin additionally sees the MU inventory, including Tools. MU components have no activate/deactivate/delete lifecycle actions. The browser remains hybrid: local filtering up to 60 modules; above that it switches to REST/AJAX pages of 30.

## Performance telemetry

Browser client apply is separated from diagnostic transport, asset-load and full-navigation timing. External provider latency is separate. Only actionable code/server/client violations feed the circuit breaker.

Diagnostic transport/navigation warnings can be handed to the Super Admin Tools console for safe runtime analysis rather than blindly increasing the budget.

Active Settings warnings are unresolved events only. A successful in-budget FIX marks the old event resolved instead of deleting it. The original event remains available in the bounded debug history, while any recurrence creates a fresh active warning.

OLT SNMP timing uses the existing external-latency channel so a slow/unreachable OLT does not quarantine otherwise healthy OLT PHP.

See `docs/PERFORMANCE-CONTRACT.md` and `docs/TOOLS-CONSOLE.md`.

## Connections architecture

Core owns generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. The normal `connections` add-on provides the grouped Hub UI and generic connector-form behavior.

OLT is the first native BETA provider. Existing Classic OLT, MikroTik and Google Sheets entries still appear as read-only **CLASSIC** cards without copying credentials. For OLT, a verified saved native endpoint suppresses the duplicate Classic card with the same host; untested/failing native setup leaves Classic visible.

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

OLT provisioning writes, ONU mutations and full PON/ONU inventory remain deferred until native BETA connection tests are verified on real OLTs.

## Next work

Do NOT bulk-migrate Classic.

Verify Core 0.4.15 on the development installation:

1. Open the BETA **GPON - LINGION** connection for `10.13.88.7`.
2. With **SNMPv2c** selected, only the Community credential should be shown; SNMPv3 Username/Auth/Privacy must be hidden. Switching to SNMPv3 should reverse that visibility.
3. Re-enter the same SNMPv2c community used by Classic if BETA does not already have it saved. Classic credentials are intentionally not copied.
4. Press **Connect** before Save Changes. The current form values should be tested without saving. While connecting, the button badge, modal alert and modal shadow should be blue; success changes all three to green and the button to **Connected**; changing any field resets it to **Connect** and clears the modal state.
5. Trigger one intentional validation/error case in a BETA modal and confirm the button badge, alert and dialog shadow become red while the modal remains open.
6. The GPON v2c test should use the Classic-compatible bounded GETNEXT path before falling back to `real_walk()`.
7. After saving, run the normal card **Test connection** once. A successful saved test marks the native card online and allows Connections to suppress the matching Classic OLT card.
8. Open **OLT** and Dashboard; both must remain cache-only and cause no SNMP poll.
9. Check Add/Edit User, Owner and Connection dialogs: all should use the same 680 × 680 frame and shared `.afcn-icon-button` close control.
10. Long-press a card and verify it lifts automatically under the same pointer, neighboring cards displace smoothly, an outside drop floats back, edge scrolling remains responsive, and a second long press saves/exits arrangement mode.

After the real GPON connection is verified, extend OLT read-only functionality with cached overview/inventory and lazy per-OLT/PON/ONU details. Keep remote reads explicit/background and bounded. Provisioning writes come only after the read-only path proves stable.

## New-chat handoff

Tell ChatGPT:

> Open `fervillz/AIRFIBER-CENTRALIZED`, read `CONTINUE-HERE.md`, `AGENTS.md`, `docs/README.md`, `docs/MODULE-BASICS.md`, `docs/MODULE-LOADING.md`, `docs/USER-ACCESS.md`, `docs/TOOLS-CONSOLE.md`, `docs/ARCHITECTURE.md`, `docs/CONNECTORS.md`, `docs/MODULE-SDK.md`, `docs/UI-SYSTEM.md` and `docs/PERFORMANCE-CONTRACT.md`, inspect current `main`, then continue Airfiber Next/BETA work.
