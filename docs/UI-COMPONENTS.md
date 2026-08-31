# Airfiber Core UI Components

Core UI is intentionally small and dependency-free. Feature modules should provide data and business logic, then reuse these components instead of creating module-specific buttons, statuses, alerts, lists, menus or form controls.

Namespace:

```php
use Airfiber\Next\UI;
```

## Theme tokens

Shared colors, radius, sizing and motion live in `next/assets/css/core.css` under `:root`.

Important tokens include:

- `--afcn-primary`
- `--afcn-info`
- `--afcn-success`
- `--afcn-warning`
- `--afcn-danger`
- `--afcn-muted`
- `--afcn-border`
- `--afcn-surface`
- `--afcn-radius-button`
- `--afcn-radius-card`
- `--afcn-transition`

Prefer changing these tokens over overriding components inside a feature module.

## Buttons

```php
echo UI::button( 'Add subscriber', array(
    'variant' => 'primary',
    'icon'    => 'plus',
) );

echo UI::button( 'Invoices', array(
    'variant'       => 'secondary',
    'count'         => 4,
    'count_variant' => 'danger',
) );
```

Variants:

`primary`, `secondary`, `success`, `warning`, `danger`, `danger-solid`, `info`, `ghost`, `link`

Options:

- `size`: `small`, `default`, `large`
- `icon`
- `icon_position`: `before` or `after`
- `count` / `count_variant`
- `loading`
- `disabled`
- `block`
- `attrs`

The count is rendered as a small raised notification counter so button height does not change.

## Pills, counters and status

```php
echo UI::pill( 'Paid', 'success', array( 'dot' => true ) );
echo UI::counter( 12, 'warning' );
echo UI::status( 'Overdue', 'danger', array( 'count' => 3 ) );
```

Supported tones:

`primary`, `info`, `success`, `warning`, `danger`, `neutral`

Use:

- **pill** for compact labels/categories;
- **counter** for a number/notification;
- **status** for a state that benefits from a colored dot.

`UI::badge()` remains supported for compatibility and uses the same pill language.

## Alerts

```php
echo UI::alert(
    'This account has an unpaid balance of ₱1,200.',
    array(
        'variant'     => 'warning',
        'title'       => 'Payment overdue',
        'dismissible' => true,
    )
);
```

Alerts support `info`, `success`, `warning`, `danger` and `neutral`.

Optional `actions` accepts trusted internal action markup. Dismissible alerts use the shared Core runtime and emit `afcn:alert:dismissed`.

`UI::notice()` remains the lightweight compatibility wrapper.

## Structured lists

```php
echo UI::list_items(
    array(
        array(
            'tag'   => 'button',
            'icon'  => 'receipt',
            'label' => 'August invoice',
            'meta'  => 'Due 5 Sep 2026',
            'value' => '₱1,500',
            'pill'  => array( 'label' => 'Unpaid', 'variant' => 'warning' ),
        ),
        array(
            'icon'  => 'credit-card',
            'label' => 'Last payment',
            'meta'  => 'GCash · 29 Aug 2026',
            'value' => '₱1,500',
        ),
    ),
    array( 'compact' => true )
);
```

List items support leading icons, primary/secondary copy, values, counters, pills, active/disabled state and `div`, `button` or `a` rows.

## Detail lists

```php
echo UI::detail_list( array(
    'Account' => 'AF-10021',
    'Plan'    => 'Plan 1500',
    'Router'  => 'DESKTOP-P',
    'Status'  => 'Active',
) );
```

Use detail lists for read-only label/value information rather than creating a two-column table.

## Form controls

```php
echo UI::field( 'account_no', 'Account number', array(
    'value' => 'AF-10021',
    'help'  => 'Unique subscriber reference.',
) );

echo UI::textarea( 'notes', 'Notes' );
echo UI::checkbox( 'send_receipt', 'Email receipt', array( 'checked' => true ) );
echo UI::toggle( 'auto_suspend', 'Automatic suspension', array(
    'description' => 'Suspend service after the configured grace period.',
) );
```

Fields/selects/textareas support help text, validation errors, required/disabled state and custom HTML attributes.

## Tabs

```php
echo UI::tabs(
    'subscriber-tabs',
    array(
        'overview' => array( 'label' => 'Overview', 'content' => $overview ),
        'billing'  => array(
            'label'         => 'Billing',
            'content'       => $billing,
            'count'         => 2,
            'count_variant' => 'warning',
        ),
    ),
    array(
        'position' => 'left',
        'active'   => 'overview',
    )
);
```

Tabs support top, bottom, left and right positions and can display notification counters without changing the tab height.

## Progress

```php
echo UI::progress( 72, array(
    'label'   => 'Import progress',
    'variant' => 'info',
) );
```

Use progress only for measurable work. For unknown-duration loading use a spinner or skeleton.

## Empty and loading states

```php
echo UI::empty_state(
    'No invoices yet',
    'Invoices will appear here after the first billing cycle.',
    array(
        'icon'    => 'receipt',
        'actions' => UI::button( 'Create invoice', array( 'variant' => 'primary' ) ),
    )
);

echo UI::skeleton( array( 'lines' => 4 ) );
```

Skeletons are CSS-only and should be used for short deferred content loads.

## Action menus

```php
echo UI::menu(
    'subscriber-actions',
    array(
        array( 'label' => 'View', 'icon' => 'user' ),
        array( 'label' => 'Edit', 'icon' => 'edit' ),
        array( 'separator' => true ),
        array( 'label' => 'Suspend', 'icon' => 'alert', 'variant' => 'danger' ),
    )
);
```

Menus use native `<details>` plus a tiny Core enhancement for outside-click/Escape closing. No dropdown framework is loaded.

## Dialog helper

```php
echo UI::dialog(
    'payment-dialog',
    'Record payment',
    $body,
    array(
        'subtitle' => 'Manual payment entry',
        'size'     => 'default',
        'footer'   => $actions,
    )
);
```

Sizes: `small`, `default`, `large`.

The helper uses the existing Core dialog open/close runtime. Modules still own the actual form/action behavior.

## Rules

1. Prefer a Core component before adding module CSS.
2. Feature CSS should describe feature layout, not redefine shared controls.
3. Counters are for small quantities/notifications, not long labels.
4. Pills are for short categories/states.
5. Status dots are for operational/account state.
6. Alerts are for information requiring attention.
7. Dangerous actions must use the danger language consistently.
8. Keep remote/AJAX behavior separate from presentation components.
9. Do not add a UI dependency just to gain a component Core can render with HTML/CSS.
