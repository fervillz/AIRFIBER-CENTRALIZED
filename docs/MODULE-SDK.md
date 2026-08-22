# Airfiber Next Module SDK

## Folder structure

Regular installable add-on:

```text
next/modules/example/
├── module.json
├── includes/
│   └── class-example-module.php
└── assets/
    ├── example.css   (optional)
    └── example.js    (optional)
```

Must-use Core components live separately:

```text
next/modules/mu/<core-id>/
```

Only Core-owned components belong under `mu/`. The registry determines must-use status from the physical folder, so a normal add-on cannot promote itself by setting a manifest flag.

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
  "default_enabled": true,
  "settings": "example",
  "updates": true,
  "assets": {"css": [], "js": []},
  "connectors": [],
  "requires": {"core": ">=0.3.0"},
  "events": []
}
```

`position` works like WordPress menu numbering: lower values appear earlier.

`settings` is optional. When present, the Modules card can expose the shared gear action and open that module target.

`updates` declares that an update provider may manage the add-on. Actual available versions are supplied through the `afcn_module_update_catalog` filter; Core does not hard-code an update server.

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

## Connector types

A module that owns an external device/service can advertise connector types directly in `module.json`. This metadata is compiled into the module registry, so the Connections Hub can discover it without loading the module PHP.

Example:

```json
"connectors": [
  {
    "id": "example-api",
    "name": "Example API",
    "description": "Connect Airfiber to the Example service.",
    "group": "cloud",
    "icon": "cloud",
    "test_action": "test-connection",
    "fields": [
      {
        "key": "endpoint",
        "label": "Endpoint",
        "type": "url",
        "required": true,
        "display": "endpoint"
      },
      {
        "key": "account",
        "label": "Account",
        "type": "text",
        "display": "meta"
      },
      {
        "key": "api_key",
        "label": "API key",
        "type": "password",
        "required": true,
        "secret": true
      }
    ]
  }
]
```

Supported field types: `text`, `password`, `number`, `email`, `url`, `select`, `checkbox`.

Supported card display hints for non-secret fields: `endpoint`, `meta`.

A provider test action receives:

```php
array( 'connection_id' => $connection_id )
```

The provider can then read:

```php
$connection = \Airfiber\Next\Connection_Store::get( $connection_id );
$api_key    = \Airfiber\Next\Secret_Store::get( $connection_id, 'api_key' );
```

and may return:

```php
array(
    'state'      => 'online',
    'message'    => 'Connected.',
    'latency_ms' => 35.4,
    'details'    => array( 'version' => '1.2' ),
);
```

Do not put provider credentials in the manifest, normal connection config, debug logs or task payloads. See `docs/CONNECTORS.md`.

## Shared Core services

- `Airfiber\Next\UI` — shared fields/buttons/badges/notices.
- `Airfiber\Next\Tooltip` — shared text/rich tooltips; default black, optional direction/background/action.
- `Airfiber\Next\Icon` — dependency-free shared SVG icons.
- `Airfiber\Next\Cache` — namespaced transient and stale/fresh caching.
- `Airfiber\Next\Module_Options` — one namespaced settings store per module.
- `Airfiber\Next\HTTP_Client` — measured remote HTTP requests.
- `Airfiber\Next\Task_Queue` — background work; queue IDs/small payloads, not large datasets or secrets.
- `Airfiber\Next\Event_Bus` — lazy cross-module events/filters.
- `Airfiber\Next\Audit_Log` — bounded administrative audit events.
- `Airfiber\Next\Connector_Registry` — lightweight connector-type metadata compiled from manifests.
- `Airfiber\Next\Connection_Store` — generic non-secret configured endpoint records.
- `Airfiber\Next\Secret_Store` — encrypted connection credentials; no plaintext fallback.
- `Airfiber\Next\Connection_Health` — cached status/latency/last-check state separate from configuration.

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

Cards inherit the Core hover behavior: `#e7f0fb`, `translateY(-10px)`, shared shadow, fast hover-in and slow hover-out. Tooltips must use the shared Tooltip class instead of module-specific tooltip CSS.

Only add module CSS when Core cannot express the feature.

## Lazy assets

Declare optional assets in the manifest. Core does not send them on the initial app request; they load only when the module opens.

MU/Core components follow the same lazy-asset rule. Being must-use does not mean their feature-specific CSS/JavaScript is sent on every Airfiber page.

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
  "core": ">=0.3.0",
  "modules": ["ppp"]
}
```

Do not depend on the `connections` UI module merely to store/use a connection. Generic connection storage is a Core service. The Connections add-on is the central management/overview UI.

## Performance rules

Module bootstrap must be tiny. No broad database scans, remote OLT/MikroTik calls, or large data construction may happen because the module class was merely discovered or loaded.

Connector metadata must be lightweight manifest metadata. Do not contact the remote provider to populate navigation, module discovery or the Connections page shell.

If a logical module becomes large, split it into lazy queries/chunks/background tasks rather than many tightly coupled modules.
