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

Class filenames use the readable WordPress-style `class-` prefix.

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

`position` works like WordPress menu numbering: lower values appear earlier.

## Module class

```php
namespace Airfiber\Next\Modules\Example;

use Airfiber\Next\Module_Contract;

class Example_Module implements Module_Contract {
    public static function render( $context = array() ) {
        return '<div class="afcn-card">...</div>';
    }

    public static function handle_action( $action, $payload = array() ) {
        // Validate capability and input, then perform the requested action.
    }
}
```

## Shared UI first

Use Core classes and markup:

- `.afcn-button`
- `.afcn-input`
- `.afcn-select`
- `.afcn-card`
- `.afcn-table`
- `.afcn-dialog`
- `Airfiber\Next\UI`

Only add module CSS when Core cannot express the feature.

## Lazy assets

Declare optional assets in the manifest:

```json
"assets": {
  "css": ["assets/example.css"],
  "js": ["assets/example.js"]
}
```

Core does not send them on the initial app request. They load only when the module opens.

## Actions

Forms can use the generic Core action transport:

```html
<form data-afcn-module="example" data-afcn-action="save-item">
    ...
</form>
```

The browser posts to the module action endpoint. The module validates capability/input in `handle_action()`.

## Dependencies

```json
"requires": {
  "core": ">=0.1.0",
  "modules": ["ppp"]
}
```

A module with missing dependencies is not exposed as available.

## Lazy events

Declare event names in `events` and implement `on_event()` or `filter_event()` on the module class. `Event_Bus` loads only modules that declared that event.

## Performance rules

Module bootstrap must be tiny. No broad database scans, remote OLT/MikroTik calls, or large data construction should happen simply because the module class was loaded.

If a feature becomes large, keep one logical module but split it into on-demand feature chunks rather than creating many tightly coupled modules.
