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

Must-use Core management components live in:

```text
next/modules/mu/<id>/
```

Normal installable feature add-ons live in:

```text
next/modules/<id>/
```

MU status is determined by physical location. Normal modules cannot promote themselves to Core through manifest metadata.

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

## Users

Airfiber uses WordPress authentication underneath with `airfiber_admin` and `airfiber_operator` roles. WordPress administrators automatically receive Airfiber capabilities.

Airfiber administrators can manage Connections through `afcn_manage_connections`; operators remain view/access oriented unless a later role policy grants more.

## Fault isolation

Core catches module `Throwable` failures around bootstrap/render/query/action/chunk/background/event execution. Runtime failures are tracked separately from performance violations. Three clustered runtime failures can quarantine a non-system module; Core and unrelated modules remain available.

External device/API latency is diagnostic and never quarantines a module by itself.

## Security boundaries

Only physical MU modules are Core/must-use components. Module class namespaces and lazy asset paths are validated before loading.

Secrets do not belong in module manifests, connection config, browser bootstrap data, logs or background task payloads. `Secret_Store` uses site-derived encryption and has no plaintext fallback.
