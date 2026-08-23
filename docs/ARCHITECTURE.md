# Airfiber Next Architecture

## Purpose

Airfiber Next is a platform inside the existing Airfiber Centralized WordPress plugin. WordPress sees one plugin; Airfiber Next discovers and runs internal modules.

Classic continues to use the existing files. Next uses the `Airfiber\Next` namespace, `afcn_` options/handles, `/airfiber-beta/`, and files under `next/`.

## Core runtime

`next/bootstrap.php` loads only the Airfiber Next bootstrap/autoload foundation. Bootstrap registers the protected BETA page, REST router and background queue hook. It does not load feature modules.

Module manifests compile into a persistent registry option, so normal requests do not reopen dozens of JSON files. The Modules page can rebuild the registry after a deployment.

## Request flow

```text
Open /airfiber-beta/
  -> Core shell + Core CSS + Core JS
  -> cached module registry builds navigation
  -> browser asks REST for current module
  -> Core loads only that module PHP
  -> optional module CSS/JS loads
  -> deeper queries/chunks load only when requested
```

Airfiber's runtime rule is stricter than the normal WordPress active-plugin model:

> Installed does not mean loaded. Active does not mean loaded.

A feature module should load only when a direct page, query, action, lazy chunk, shared slot, event or background task explicitly needs it.

MU status changes lifecycle protection, not the lazy-loading rule. An MU component may still keep its PHP/assets dormant until Core explicitly needs that component.

## Shared-page slots

Core provides `Module_Slots` for lightweight contributions to shared pages such as Dashboard.

A feature module advertises slot metadata in `module.json`:

```json
"slots": {
  "dashboard.summary": {
    "chunk": "dashboard-summary",
    "priority": 20,
    "span": 4
  }
}
```

A shared page exposes the slot:

```php
echo Module_Slots::render( 'dashboard.summary', array( 'grid' => true ) );
```

Core resolves eligible contributors from the compiled manifest registry without loading their PHP. The browser then uses visibility-based lazy loading to request each declared chunk only as it approaches the viewport.

Chunk responses include the owning module's optional asset manifest. Core deduplicates and loads those assets before inserting the chunk, then emits `afcn:chunk:loaded`.

The Dashboard exposes `dashboard.summary` as the first shared slot. This allows OLT, PPP, Billing and future modules to contribute small cached summary cards without making Dashboard depend on those modules.

See `docs/MODULE-LOADING.md`.

## Module lifecycle and extension

Modules implement `Module_Contract` and may optionally expose lazy query/chunk/background/lifecycle/event methods. Core provides generic routes, so a new module does not require edits to the REST router.

Cross-module behavior can use `Event_Bus` or normal `afcn_*` WordPress hooks. Module state changes publish both.

## Modules vs MU components

Must-use Core/platform components live in:

```text
next/modules/mu/<id>/
```

Current MU components include Dashboard, Users, Modules, Settings and the Super-Admin-only Tools console.

Normal installable feature add-ons live in:

```text
next/modules/<id>/
```

MU status is determined by physical location. Normal modules cannot promote themselves to Core through manifest metadata.

MU components are always lifecycle-enabled and cannot be activated/deactivated/trashed/deleted through the normal Modules lifecycle. They can still be lazy at runtime. Tools is the clearest example: it is MU but its PHP/CSS/JS loads only when the Super Admin opens Settings → Tools or starts a FIX workflow.

The Modules browser exposes the MU inventory only to Airfiber Super Admin. Normal Airfiber Administrators still use capability-appropriate Core pages such as Users, Modules and Settings, but they do not see the internal MU component inventory.

## Connections architecture

Connections are intentionally separate from Modules.

- **Modules** answers: what Airfiber software is installed/active?
- **Connections** answers: what real devices/services/accounts/endpoints are configured?

Generic connector infrastructure belongs to Core:

```text
Connector_Registry
Connection_Store
Secret_Store
Connection_Health
HTTP_Client
Cache
Task_Queue
```

Vendor/business knowledge belongs to feature modules. Core must not contain VSOL commands, RouterOS behavior, ONU provisioning, Google API behavior or similar provider-specific logic.

Feature modules advertise lightweight connector types in `module.json`. Because that metadata is compiled into the module registry, the Connections Hub can discover provider types without booting the provider PHP.

The normal `connections` add-on supplies the central grouped card UI. It does not own provider logic and is not required merely to store/use a connection; `Connection_Store` and related services are Core APIs.

Connection credentials are stored separately through `Secret_Store`. Connection configuration and cached health are separate records so status refreshes never rewrite credentials/settings.

While Classic remains active, the BETA Connections Hub exposes existing Classic OLT, MikroTik and Google Sheets entries as read-only CLASSIC cards. No credentials are copied and management links back to Classic until the relevant feature has a native BETA module.

See `docs/CONNECTORS.md` for the provider contract and security rules.

## Background work

`Task_Queue` stores a small bounded queue and wakes through WP-Cron. Only the module attached to a due task is loaded. Jobs retry with bounded exponential backoff. Payloads are size-limited and should contain identifiers, never large datasets/secrets.

The queue contract is intentionally runner-agnostic so a dedicated worker can replace WP-Cron later without changing module APIs.

## Data and external devices

Use `Cache` for cache-first/stale-while-refresh behavior, `Module_Options` for per-module settings, and `HTTP_Client` so remote latency is measured separately from PHP performance.

Connection pages should render from stored/cached state first. Opening a page must not fan out to every OLT, MikroTik or cloud API.

## Users and visibility

Airfiber uses WordPress users/authentication underneath with Airfiber-specific authority and visibility rules.

Normal WordPress Administrators receive the standard Airfiber administration capabilities. They do **not** automatically become Airfiber Super Admin.

Super Admin is explicit and is intended for the owner/developer authority. It sees all enabled modules and the MU inventory and bypasses per-user module visibility. Core ships with no hidden account or remote backdoor; the site must explicitly designate the Super Admin, for example with the one-time owner setup or `AFCN_SUPER_ADMIN_USER_ID`.

`User_Access` stores optional per-user allow lists for normal feature modules. No saved policy means all enabled normal modules are visible, so newly installed modules remain visible by default. Saving a restricted policy hides and blocks direct access to unchecked normal modules.

MU/Core pages stay capability-driven rather than becoming ordinary visibility checkboxes. Super Admin Core access is always enabled. The `areas` policy key is reserved for future nested/submenu visibility after a real module requires it.

See `docs/USER-ACCESS.md`.

## Fault isolation

Core catches module `Throwable` failures around bootstrap/render/query/action/chunk/background/event execution. Runtime failures are tracked separately from performance violations. Three clustered runtime failures can quarantine a non-system module; Core and unrelated modules remain available.

External device/API latency is diagnostic and never quarantines a module by itself.

## Security boundaries

Only physical MU modules are Core/must-use components. Module class namespaces and lazy asset paths are validated before loading.

Secrets do not belong in module manifests, connection config, browser bootstrap data, logs or background task payloads. `Secret_Store` uses site-derived encryption and has no plaintext fallback.
