# Airfiber Next Module Basics

Airfiber modules are internal add-ons for Airfiber Next/BETA. They are intentionally similar to WordPress plugins: drop a correctly structured module into the modules folder, refresh the registry, and Airfiber discovers it automatically.

The important difference is that Airfiber discovers modules from a small `module.json` manifest instead of scanning/executing PHP for plugin headers. This keeps discovery cheap even when many modules are installed.

## Minimum module

A working module can start with only two files:

```text
next/modules/hello-world/
├── module.json
└── includes/
    └── class-hello-world-module.php
```

### 1. `module.json`

Only the module name is required when you follow Airfiber's naming convention:

```json
{
  "name": "Hello World"
}
```

The folder name becomes the module ID automatically.

For `hello-world`, Airfiber infers:

| Item | Inferred value |
| --- | --- |
| Module ID | `hello-world` |
| Namespace | `Airfiber\Next\Modules\HelloWorld` |
| Main class | `Hello_World_Module` |
| Main class file | `includes/class-hello-world-module.php` |
| Version | `0.0.0` |
| Menu position | `100` |
| Icon | `box` |
| Capability | `afcn_access` |
| Enabled by default | `true` |

You may explicitly provide `id` or `class`, but normally there is no reason to. If an explicit `id` is supplied, it must match the folder name. A custom main class must remain inside the module's Airfiber namespace.

### 2. Main module class

```php
<?php

namespace Airfiber\Next\Modules\HelloWorld;

use Airfiber\Next\Module_Contract;

defined( 'ABSPATH' ) || exit;

class Hello_World_Module implements Module_Contract {

    public static function render( $context = array() ) {
        return '<div class="afcn-card"><div class="afcn-card-body">Hello Airfiber.</div></div>';
    }

    public static function handle_action( $action, $payload = array() ) {
        return new \WP_Error(
            'hello_world_unknown_action',
            __( 'Unknown action.', 'airfiber-centralized' ),
            array( 'status' => 400 )
        );
    }
}
```

`render()` and `handle_action()` are the only methods required by the base module contract.

## How Airfiber discovers a module

Airfiber scans these locations for `module.json`:

```text
next/modules/*/module.json
next/modules/mu/*/module.json
```

Normal add-ons belong in `next/modules/<module-id>/`.

`next/modules/mu/` is reserved for Airfiber Core must-use components. A third-party or feature module must not place itself there.

Discovery reads the JSON manifest only. The module PHP class is not loaded just because Airfiber found the module. PHP is loaded later when the module actually needs to render, answer a query, perform an action, run a background task, or handle another supported runtime operation.

That rule is central to Airfiber's fast-by-design architecture.

## A practical manifest

A real module will usually add a few optional fields:

```json
{
  "name": "OLT",
  "description": "Manage optical line terminals and ONUs.",
  "version": "1.0.0",
  "position": 30,
  "icon": "server",
  "capability": "afcn_access",
  "default_enabled": true,
  "settings": "olt",
  "updates": true,
  "assets": {
    "css": ["assets/olt.css"],
    "js": ["assets/olt.js"]
  },
  "slots": {
    "dashboard.summary": "dashboard-summary"
  },
  "requires": {
    "core": ">=0.3.6"
  }
}
```

The `id` and `class` are still inferred from the folder when omitted.

## Naming convention

Use lowercase kebab-case folder IDs:

```text
olt
mikrotik
speed-test
customer-import
```

Airfiber converts them by convention:

```text
speed-test
    ↓
Airfiber\Next\Modules\SpeedTest
    ↓
Speed_Test_Module
    ↓
class-speed-test-module.php
```

Additional classes follow the same readable WordPress-style filename rule:

```php
class Speed_Test_Repository {}
```

lives in:

```text
includes/class-speed-test-repository.php
```

Avoid vague filenames such as `helper.php`, `functions2.php`, or `misc.php` when a descriptive class name is possible.

## Registering the module during development

After adding or changing a manifest, open **Airfiber BETA → Modules** and use **Refresh Registry**.

The registry is compiled and cached so Airfiber does not scan every module folder on every request. Core version/schema changes also invalidate the compiled registry automatically.

Once discovered, a normal module appears in the Airfiber Modules screen where it can be activated, deactivated, placed in Trash, restored, and inspected for health.

## Icons and menu position

`icon` uses the shared Airfiber Core icon library. Do not ship an icon library just for one module unless the Core icon set truly cannot express the feature.

`position` works like WordPress admin menu positions: lower values appear earlier.

Example:

```json
{
  "name": "Billing",
  "position": 50,
  "icon": "billing"
}
```

If an icon name is unknown, Core falls back safely rather than breaking module discovery.

## Assets are optional and lazy

Do not add CSS or JavaScript unless the feature needs it.

If declared in `module.json`, module assets are loaded when that module page or one of its lazy chunks is requested; they are not sent on the initial Airfiber app request simply because the module is enabled.

Prefer Core UI first:

```text
.afcn-card
.afcn-button
.afcn-input
.afcn-select
.afcn-table
.afcn-dialog
Airfiber\Next\UI
Airfiber\Next\Tooltip
Airfiber\Next\Icon
```

## Active does not mean loaded

Activating a module makes it available to Airfiber. It does **not** mean its PHP, CSS, JavaScript, data, or remote connections should run on every Airfiber page.

A normal page loads only the module the user opens. Shared pages can use lazy **slots** when they need small contributions from other modules.

Example:

```json
{
  "name": "OLT",
  "slots": {
    "dashboard.summary": "dashboard-summary"
  }
}
```

The OLT class can implement:

```php
public static function render_chunk( $chunk, $payload = array() ) {
    if ( 'dashboard-summary' === $chunk ) {
        return '<div class="afcn-card"><div class="afcn-card-body">OLT summary</div></div>';
    }

    return '';
}
```

Dashboard does not load OLT while rendering the page. Core reads the slot declaration from cached manifest metadata, creates a lightweight placeholder, and loads the OLT chunk only when that placeholder approaches the viewport.

See [Module Loading](MODULE-LOADING.md) for the full loading contract.

## Actions, queries and lazy chunks

The two required methods are enough for a basic page. More capable modules can optionally implement:

- `handle_query()` for lazy/read-only data.
- `render_chunk()` for a lazy HTML section or shared-page slot contribution.
- `handle_background()` for queued work.
- `activate()` / `deactivate()` for lifecycle operations.

Use the Core browser API and REST runtime instead of creating a second AJAX system.

See [Module SDK](MODULE-SDK.md) for the full contracts.

## Connectors

A module that owns a device or external service can also declare lightweight connector metadata in `module.json`.

That lets the Connections Hub discover available connector types without loading the provider module or contacting the remote device.

See [Connectors](CONNECTORS.md).

## Performance rule

A module being installed or enabled must not make unrelated Airfiber screens slower.

Do not perform these during discovery/bootstrap:

- remote OLT/MikroTik/API calls
- large database scans
- large object construction
- broad user/customer queries
- unnecessary CSS/JavaScript loading

Load real work when the user opens the feature, requests a lazy chunk/query, when a declared slot becomes visible, or when a background task intentionally runs.

See [Performance Contract](PERFORMANCE-CONTRACT.md).

## Why `module.json` instead of a PHP plugin header?

WordPress plugin headers are elegant because one PHP file can identify a plugin. For Airfiber, `module.json` gives us the same drop-in discovery experience while keeping metadata separate from executable code.

This gives Airfiber three useful properties:

1. Core can discover many modules without booting their PHP.
2. Connector/menu/dependency/slot metadata can be compiled into a cheap registry.
3. A broken or expensive module class does not need to run just to appear on the Modules screen.

So the Airfiber equivalent of a WordPress plugin header is intentionally the tiny `module.json` file.
