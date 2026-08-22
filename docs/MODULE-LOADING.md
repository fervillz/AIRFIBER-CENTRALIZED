# Airfiber Next Module Loading

Airfiber Next does **not** use the normal WordPress active-plugin runtime model.

The key rule is:

> Installed does not mean loaded. Active does not mean loaded. A module is loaded only when a route, slot, query, action, event, background task, or another explicit Core runtime operation needs it.

This is a Core performance contract, not an optional optimization.

## Normal request flow

```text
Open /airfiber-beta/
  -> Airfiber Core boots
  -> cached module manifest registry is read
  -> no feature module PHP is loaded
  -> browser requests the selected module
  -> Core loads only that module PHP
  -> optional module CSS/JS loads only for that module
  -> deeper data/chunks load only when explicitly requested
```

Module discovery reads `module.json`; it must never execute module PHP merely to discover menus, slots, connectors, dependencies or event metadata.

## What can cause a module to load?

### Direct module page

When the user opens a module from Airfiber navigation, Core requests that module through the REST runtime and loads only that module.

### Query or action

Explicit calls such as these load only the named module:

```js
AirfiberNext.query('olt', 'onus', { page: 2 });
AirfiberNext.action('billing', 'save-invoice', { id: 42 });
```

### Lazy chunk

A feature can request a small HTML chunk without loading a full module page:

```js
AirfiberNext.loadChunk('olt', 'pon-summary', '#target');
```

Chunk responses include that module's optional asset manifest. Core loads those assets only when the chunk is actually requested.

### Shared-page slot

A module can contribute a small feature to another page without forcing that page to know the module exists.

Example `module.json`:

```json
{
  "name": "OLT",
  "slots": {
    "dashboard.summary": "dashboard-summary"
  }
}
```

This shorthand means: when a page renders the `dashboard.summary` slot and the contribution approaches the viewport, request the OLT module's `dashboard-summary` chunk.

The full form supports ordering and grid width:

```json
{
  "name": "OLT",
  "slots": {
    "dashboard.summary": {
      "chunk": "dashboard-summary",
      "priority": 20,
      "span": 4
    }
  }
}
```

`priority` defaults to `50`. Lower values render first. `span` defaults to `4` and is clamped to the Core 12-column grid.

A page exposes a slot with Core PHP:

```php
use Airfiber\Next\Module_Slots;

echo Module_Slots::render(
    'dashboard.summary',
    array( 'grid' => true )
);
```

Core resolves eligible contributors from cached manifest metadata only. At this point **no contributing module PHP has been loaded**.

The browser uses `IntersectionObserver` with a small prefetch margin. A contribution is requested only when it approaches the viewport. If `IntersectionObserver` is unavailable, Core falls back to immediate chunk loading.

The Dashboard currently exposes `dashboard.summary` for future OLT, PPP, Billing and similar lightweight summary cards.

### Background task or event

Background work must identify its owning module. Only that module should be loaded when the task becomes due.

Event subscriptions should be declared as lightweight manifest metadata where possible. Core should resolve the interested modules first, then load only those subscribers.

## Slots are not cross-module includes

A slot contribution should be small and independently useful. It should not turn Dashboard into a hidden full OLT/Billing/PPP bootstrap.

Good slot contributions:

- OLT health summary
- offline ONU count
- PPP online count
- unpaid invoice count
- payment health

Bad slot contributions:

- loading an entire OLT inventory
- contacting every remote device during Dashboard render
- building thousands of rows before the slot becomes visible
- using a slot simply to run module initialization code

Use cache-first data and lazy follow-up queries inside the chunk if the feature needs more detail.

## Shared-page chunks and assets

`render_chunk()` may return markup that uses Core UI classes. This is preferred for small slot cards.

If the owning module declares optional CSS or JavaScript in `module.json`, Core returns and loads those assets before inserting the chunk. The assets are deduplicated, so requesting another chunk from the same module does not load them again.

After insertion Core emits:

```text
afcn:chunk:loaded
```

with the module, chunk, target element and response data in `event.detail`.

Module JavaScript can listen for that event when a lazy chunk needs custom behavior.

## Global bootstrap is intentionally discouraged

Airfiber modules should not recreate the WordPress pattern where every active plugin attaches broad `init`, `wp_loaded`, or frontend enqueue behavior on every request.

Do not perform expensive work just because the module class exists.

Prefer:

- a direct page request
- a named query/action
- a lazy chunk
- a manifest slot
- a manifest event subscription
- a background task

A future global-runtime escape hatch should only be added if a real feature proves it is necessary. It is deliberately not part of the normal module contract today.

## Mental model

```text
ACTIVE MODULES
OLT  PPP  Billing  SMS  Connections
        |
        v
cached metadata only

User opens Dashboard
        |
        v
Dashboard PHP only
        |
        +-- dashboard.summary metadata
                |
                +-- OLT chunk loads when visible
                +-- PPP chunk loads when visible
                +-- Billing chunk loads when visible

SMS remains completely dormant.
```

The Core decision should always be made **before module code is loaded**.
