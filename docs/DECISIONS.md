# Airfiber Next Architecture Decisions

## 2026-08-22 — Build beside Classic

Decision: do not restructure the working Classic plugin while building Next. New architecture lives in `next/`. Classic gets only a tiny bootstrap bridge and Try BETA entry point.

Reason: rollback and day-to-day Classic work must remain safe.

## 2026-08-22 — Internal modules, not WordPress plugins

Decision: PPP, OLT, Billing, Payments, Connections, SMS and future features become Airfiber internal modules rather than separate entries on the WordPress Plugins screen.

Reason: one platform can enforce one UI system, permissions, routing and performance contract.

## 2026-08-22 — Manifest discovery

Decision: module navigation/dependencies are declared in `module.json`. Discovery must not execute module PHP.

Reason: many enabled modules should not make initial load progressively slower.

## 2026-08-22 — AJAX/REST by default

Decision: the app shell loads first and modules load on demand through the Airfiber Next REST router.

Reason: users normally need one area at a time.

## 2026-08-22 — WordPress users underneath

Decision: Airfiber accounts use WordPress authentication and users with Airfiber-specific roles/capabilities.

Reason: avoid a second credential/security system.

## 2026-08-22 — Strict performance circuit breaker

Decision: Core profiles modules and can quarantine a repeatedly slow non-system module.

Reason: one bad add-on must not make the entire Airfiber application slow.

## 2026-08-22 — Do not expose ZIP install/delete during first BETA

Decision: modules are added through the repository until the module contract is proven.

Reason: filesystem mutation and package validation add risk before the SDK stabilizes.

## 2026-08-23 — Modules and Connections are separate concepts

Decision: the Modules screen manages installed Airfiber software. The Connections Hub manages actual configured routers, OLTs, APIs, accounts and endpoints.

Reason: one add-on can own many configured devices, so treating each connection as a module would mix software lifecycle with infrastructure inventory.

## 2026-08-23 — Connector primitives are Core; provider logic is not

Decision: Core owns the generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. OLT/MikroTik/Google/provider-specific protocol logic remains inside feature modules.

Reason: all providers need consistent storage, credentials, security and health behavior, but Core must stay free of business/vendor knowledge.

## 2026-08-23 — Connector metadata is manifest-first

Decision: feature modules advertise connector types and field schemas in `module.json`. The Connections Hub can discover provider types without booting module PHP.

Reason: opening navigation/Connections should remain cheap even when many provider modules are installed.

## 2026-08-23 — Connections render cache-first

Decision: opening Connections displays stored configuration and cached health. It never fans out to every external device/API simply to render the page.

Reason: remote latency should not define Airfiber UI latency.

## 2026-08-23 — Classic connections stay read-only during migration

Decision: existing Classic OLT, MikroTik and Google Sheets configuration can appear as read-only CLASSIC cards in BETA. Credentials are not copied until the owning feature is intentionally migrated.

Reason: users keep infrastructure visibility in BETA without creating two writable sources of truth.

## 2026-08-31 — Connection-backed submenus stay generic and cache-only

Decision: a module may declare `connection_submenu: true`. Core then builds bounded child navigation from that module's saved `Connection_Store` records, using the record ID as route context and the endpoint as secondary text. Core does not know the provider protocol and does not load feature PHP or contact endpoints to build the menu.

Reason: Routers needs one submenu entry per configured device, but MikroTik behavior does not belong in Core and navigation must remain fast.

## 2026-08-31 — Native RouterOS begins with explicit bounded reads

Decision: the first native Routers module supports connection management, cached health and individually requested read-only scopes. Browser input chooses only an allow-listed scope; the server owns every RouterOS sentence and property list. Configuration writes and arbitrary command consoles are excluded.

Reason: operators gain useful PPP/interface/firewall/log visibility without turning the BETA web application into a general router shell or making page navigation wait on network devices.

## 2026-08-23 — `module.json` is the Airfiber plugin header

Decision: keep `module.json` as the lightweight registration marker, but infer the module ID and main class from the folder convention. A convention-following module therefore needs only a `name` in its manifest plus its main class file.

Reason: this gives Airfiber the drop-in simplicity of WordPress plugin discovery without scanning or executing module PHP. Metadata remains cheap to compile, and multi-word folders map predictably to namespaces/classes.

Example: `speed-test/` maps to `Airfiber\Next\Modules\SpeedTest\Speed_Test_Module` in `includes/class-speed-test-module.php`.

## 2026-08-23 — Active does not mean loaded

Decision: activating an Airfiber module only makes it eligible for use. Core must not load its PHP, optional assets, data or external connections on unrelated Airfiber pages.

Reason: Airfiber should decide what a request needs before loading module code. This avoids the normal WordPress pattern where every active plugin participates in every request.

Direct pages, queries, actions, lazy chunks, declared events and background tasks are explicit loading triggers. Shared-page contributions use manifest-declared slots. Core resolves slot contributors from cached metadata, then the browser requests each chunk only when its placeholder approaches the viewport.

## 2026-08-23 — Super Admin is explicit, not equal to WordPress Administrator

Decision: Airfiber Super Admin is a separate owner/developer authority. WordPress Administrators receive normal Airfiber administration capabilities but do not automatically receive `afcn_super_admin`.

Reason: public/customer administrators should be able to operate Airfiber without automatically seeing owner/developer-only Core internals. It also avoids treating every WordPress Administrator as the product owner.

Core does not ship a hidden Super Admin account. A deployment must explicitly designate its Super Admin, for example with `AFCN_SUPER_ADMIN_USER_ID`, a controlled filter, an explicit user-level capability, or the one-time owner setup flow.

## 2026-08-23 — First-run owner setup never ships a default password

Decision: if no Airfiber Super Admin exists, a WordPress Administrator may explicitly promote the current account or create a separate owner from the Users screen. The separate-owner form suggests `bordocs` as a convenience only; the username is editable and no password is stored in source code. A blank password generates a strong random value that is displayed once.

Reason: this gives local/development and public installations a convenient bootstrap path without creating a universal credential or hidden backdoor. Once an owner is claimed, the first-run setup disappears. The owner claim is stored separately and guarded against concurrent first-run setup attempts.

## 2026-08-23 — Authority and menu visibility are separate

Decision: roles/capabilities define authority while `User_Access` may further narrow the normal feature modules visible to a user. MU/Core pages remain capability-driven and the MU inventory is visible only to Super Admin.

Reason: an Administrator can have administrative authority while still receiving a simplified operational menu. Hiding a module must also block direct interactive loading; visibility is not implemented as CSS/menu hiding only.

Selecting every available normal module stores no restrictive policy, so newly installed modules remain visible by default. Nested/submenu visibility is reserved in the policy model but deferred until a real module needs it.

## 2026-08-23 — Developer Tools is an MU module in a Core utility drawer

Decision: the developer console lives physically in `next/modules/mu/tools/`, while Core only provides generic `parent` navigation metadata and a `drawer` presentation primitive. Tools is nested under Settings and is visible/loadable only to the explicit Airfiber Super Admin.

Reason: Tools is part of Airfiber's developer/diagnostic control plane and should not be activatable, deactivatable, trashed or deleted like a normal customer feature module. Physical MU placement gives it that lifecycle protection while the existing lazy runtime still prevents its PHP/CSS/JS from loading until the Super Admin actually opens it.

Normal Administrators do not receive the Tools navigation item, Tools MU inventory, utility runtime, or performance FIX action.

## 2026-08-23 — Performance FIX does not self-modify production source code

Decision: the Tools performance FIX workflow may inspect health/budgets/assets, warm safe runtime state, run a controlled render, retest the REST endpoint with AJAX and produce recommendations. It does not automatically rewrite PHP, JavaScript, CSS or database schema.

Reason: automated source restructuring on a live WordPress installation is difficult to review, version, test and roll back safely. Airfiber may automate reversible runtime optimizations, but structural code changes remain explicit developer work.

## 2026-08-23 — OLT migration starts native and read-only

Decision: the first real provider module is `next/modules/olt/`. It owns native SNMP connection testing and cache-first OLT presentation using `Connection_Store`, `Secret_Store` and `Connection_Health`; it does not call Classic OLT classes for native operation and does not provision ONUs yet.

Reason: a read-only OLT slice proves the module/connector/lazy-slot contracts against real infrastructure without putting provisioning at risk. Classic remains a migration fallback until the matching native endpoint has passed an explicit BETA connection test.

A verified native OLT suppresses only the duplicate Classic card with the same host. Untested or failing native configuration does not hide Classic.

## 2026-08-23 — BETA dialogs use one shared frame

Decision: all normal `.afcn-dialog` modals use the same responsive 680 × 680 px target frame. Header/footer remain fixed and `.afcn-dialog-body` scrolls when content exceeds the available body height.

Reason: Users, Connections and future feature forms should feel like one product. Module-specific modal dimensions create visual jumps and make larger forms inconsistent; scrolling the body preserves a stable frame while still supporting long forms.
