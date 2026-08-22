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

## Active does not mean loaded

Activating a module makes it available to Airfiber. It does **not** mean its PHP, CSS, JavaScript or data should run on every Airfiber page.

A normal page should load only the module the user opens. Shared pages can use lazy **slots** when they need small contributions from other modules.

Example:

```json
{
  "name": "OLT",
  "slots": {
    "dashboard.summary": "dashboard-summary"
  }
}
```

The OLT class can then optionally implement:

```php
public static function render_chunk( $chunk, $payload = array() ) {
    if ( 'dashboard-summary' === $chunk ) {
        return '<div class="afcn-card"><div class="afcn-card-body">OLT summary</div></div>';
    }

    return '';
}
```

Dashboard does not load OLT while rendering the page. Core reads the slot declaration from cached manifest metadata, creates a lightweight placeholder, and loads the OLT chunk only when that placeholder approaches the viewport.

See [Module Loading](MODULE-LOADING.md) for the full loading contract and [Module SDK](MODULE-SDK.md) for advanced module features.
