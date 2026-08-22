# Airfiber Next Module Manager UI

Updated: 2026-08-23

## Module locations

Airfiber Next deliberately separates must-use Core components from installable feature modules.

```text
next/modules/
├── mu/
│   ├── dashboard/
│   ├── users/
│   ├── modules/
│   └── settings/
└── <installable-module-id>/
```

Anything under `next/modules/mu/<id>/` is a must-use Core component. It is always enabled and cannot be deactivated or moved to Trash. Regular add-ons live directly under `next/modules/<id>/`.

The registry decides whether a module is MU from its physical source folder. A normal module cannot make itself must-use by placing `"system": true` in its manifest.

## Module browser

The Modules screen follows the WordPress Plugins mental model with these views:

- All
- Active
- Inactive
- Update Available
- Auto-updates Disabled
- Trash
- MU

Search filters the current view without another server request.

Regular module cards are compact 150 × 150 px cards. Hovering or focusing a card reveals icon actions:

- check = Activate an inactive module
- X = Deactivate an active module
- gear = Open settings when the manifest declares a `settings` target
- trash = Move an inactive module to Trash
- restore = Restore a trashed module

MU/Core cards never expose activate, deactivate or trash actions. They may expose Settings when appropriate.

## Update providers

Core does not hard-code an update server. A future update provider supplies metadata through:

```php
add_filter( 'afcn_module_update_catalog', function ( $catalog ) {
    $catalog['example'] = array(
        'version' => '1.2.0',
        'url'     => 'https://example.test/changelog',
        'notes'   => 'Optional short note.',
    );

    return $catalog;
} );
```

Until an update provider is connected, Update Available and Auto-updates Disabled can legitimately show zero.

## Tooltips

Use the shared `Airfiber\Next\Tooltip` class instead of creating per-module tooltip CSS.

Basic text:

```php
echo Tooltip::render( $button_html, 'Activate' );
```

Direction/background:

```php
echo Tooltip::render(
    $button_html,
    'More information',
    array(
        'direction' => 'down',
        'variant'   => 'info',
    )
);
```

An optional action can be supplied with `action => array(...)`. Default variant is black/dark.

## Shared hover language

Core provides the default hover motion for Airfiber Next cards and buttons:

- translate Y by -10px
- subtle background/glow
- `box-shadow: 0 10px 40px 0 rgba(0,0,0,.1)`

Reduced-motion preferences disable the lift animation.
