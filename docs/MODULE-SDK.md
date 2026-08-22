# Airfiber Next Module SDK

## Folder structure

```text
next/modules/example/
├── module.json
├── includes/
│   └── class-example-module.php
└── assets/
    ├── example.css   (optional)
    └── example.js    (optional)
```

Every PHP class/interface file uses the readable WordPress-style `class-` prefix. A filename must tell a developer what is inside it.

## Minimal manifest

```json
{
  "id": "example",
  "name": "Example",
  "description": "Example module.",
  "version": "1.0.0",
  "class": "Airfiber\\Next\\Modules\\Example\\Example_Module",
  "position": 40,
  "icon": "box",
  "capability": "afcn_access",
  "system": false,
  "default_enabled": true,
  "assets": {"css": [], "js": []},
  "requires": {"core": ">=0.1.0"},
  "events": []
}
```

`position` works like WordPress menu numbering: lower values appear earlier. Third-party/future modules cannot mark themselves as Core system modules.

## Required module methods

```php
namespace Airfiber\Next\Modules\Example;

use Airfiber\Next\Module_Contract;

class Example_Module implements Module_Contract {
    public static function render( $context = array() ) {
        return '<div class="afcn-card">...</div>';
    }

    public static function handle_action( $action, $payload = array() ) {
        // Validate capability and input, then perform writes.
    }
}
```

## Optional module methods

A module may add these without changing Core:

- `handle_query( $query, $payload )` — lazy GET/read-only data.
- `render_chunk( $chunk, $payload )` — lazy HTML sub-view.
- `handle_background( $action, $payload )` — durable background queue work.
- `activate()` / `deactivate()` — lifecycle when module state changes.
- `on_event()` / `filter_event()` — lazy event subscriptions declared by the manifest.

## Shared Core services

- `Airfiber\Next\UI` — shared fields/buttons/badges/notices.
- `Airfiber\Next\Cache` — namespaced transient and stale/fresh caching.
- `Airfiber\Next\Module_Options` — one namespaced settings store per module.
- `Airfiber\Next\HTTP_Client` — measured remote HTTP requests.
- `Airfiber\Next\Task_Queue` — background work; queue IDs/small payloads, not large datasets or secrets.
- `Airfiber\Next\Event_Bus` — lazy cross-module events/filters.
- `Airfiber\Next\Audit_Log` — bounded administrative audit events.

## Browser API

Core exposes `window.AirfiberNext` after startup:

```js
AirfiberNext.query('example', 'items', {page: 2});
AirfiberNext.action('example', 'save-item', {id: 14});
AirfiberNext.loadChunk('example', 'details', '#target', {id: 14});
AirfiberNext.toast('Saved');
```

Module JavaScript should use this API rather than inventing another AJAX layer.

## Shared UI first

Use Core classes and markup: `.afcn-button`, `.afcn-input`, `.afcn-select`, `.afcn-card`, `.afcn-table`, `.afcn-dialog`, and `Airfiber\Next\UI`.

Only add module CSS when Core cannot express the feature.

## Lazy assets

Declare optional assets in the manifest. Core does not send them on the initial app request; they load only when the module opens.

## Generic forms/actions

```html
<form data-afcn-module="example" data-afcn-action="save-item">
    ...
</form>
```

Core posts the form to the module action route, shows the result, and reloads only that module unless the response contains `reload: false`.

## Dependencies

```json
"requires": {
  "core": ">=0.1.0",
  "modules": ["ppp"]
}
```

## Performance rules

Module bootstrap must be tiny. No broad database scans, remote OLT/MikroTik calls, or large data construction may happen because the module class was merely discovered or loaded.

If a logical module becomes large, split it into lazy queries/chunks/background tasks rather than many tightly coupled modules.
