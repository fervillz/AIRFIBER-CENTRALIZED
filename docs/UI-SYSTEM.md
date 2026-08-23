# Airfiber Next UI System

Core owns the visual language. Modules should not invent a separate design system.

## Typography

- Headings: `Source Serif 4`, weight 400.
- UI/body: Inter when available, then native system fonts.
- Avoid bold display headings unless the design specifically requires emphasis.

## Shape tokens

- Buttons: 9px radius.
- Fields/selects: 9px radius.
- Cards: 10px radius.
- Dialogs/popups: 14px radius.
- Dialog header close controls: 10px radius, outlined white square.
- Icon-only circular controls may remain circular only when the control is intentionally circular and is not the standard dialog close control.

## Core components

Core CSS currently includes:

- app shell/header/navigation
- page headings
- responsive grid
- cards/stat cards
- buttons
- fields/selects/textareas
- tables
- badges/notices
- loading states/spinner
- module errors
- toasts
- dialogs
- shared hover/lift behavior
- shared tooltip styling and motion
- shared SVG icon sizing
- Module Manager tabs/search/card layout

`Airfiber\Next\UI` provides basic PHP helpers for buttons, fields, selects, badges and notices.

`Airfiber\Next\Tooltip` is the single tooltip API. It supports plain text, up/down motion, alternate backgrounds and an optional tooltip action. Default background is black. Tooltip enter/exit uses opacity and vertical movement instead of abruptly appearing/disappearing.

`Airfiber\Next\Icon` supplies small dependency-free SVG icons for shared controls.

## Uniform BETA dialogs

All normal BETA `<dialog class="afcn-dialog">` modals use one Core-owned frame instead of module-specific dimensions.

Desktop target:

- width: 680 px
- height: 680 px
- still constrained to the current viewport
- shared 14 px radius
- header and footer stay fixed
- `.afcn-dialog-body` is the only scrolling region when content is taller than the frame

On small/mobile viewports the same component fills the available viewport with a small outer gutter. Modules should not set their own dialog width/height or make the complete dialog scroll; add content inside `.afcn-dialog-body` and let Core handle overflow.

Every dialog header close button uses the existing shared markup:

```html
<button type="button" class="afcn-icon-button" data-afcn-dialog-close aria-label="Close">×</button>
```

Do not create `afcn-dialog-close` or another module-specific close-button class. The shared `.afcn-icon-button` deliberately copies the proven Classic Connections modal close control: 32 × 32 px, 1 px `#dce4ec` border, 10 px radius, white background and muted blue-grey icon/text. Core owns its hover/focus state.

This keeps Connections, Users and future OLT/PPP/Billing forms visually stable even when one form contains many more fields than another.

## Default card hover language

All normal Airfiber cards use the same motion language unless a component has a strong reason to opt out:

- `transform: translateY(-10px)`
- hover background `#e7f0fb`
- `box-shadow: 0 10px 40px 0 rgba(0,0,0,.1)`
- fast hover-in response (about 160 ms)
- very slow hover-out return (5 seconds)

The asymmetric timing is deliberate: cards respond immediately when hovered, then gently settle back after the pointer leaves. The global interaction stylesheet respects `prefers-reduced-motion` and disables the lift/transition when reduced motion is requested.

Normal Airfiber buttons retain the shared lift/shadow behavior but do not use the five-second card return unless specifically designed as a card-like control.

## Module Manager cards

The Modules browser intentionally uses compact 150 × 150 px cards. This fixed size is specific to the module browser and is not a global card dimension.

Module descriptions do not consume card space. Hovering/focusing the module name opens the shared Core tooltip containing the description. The health dot has its own performance tooltip.

Card hover/focus reveals icon-only actions. The `.afcn-module-card-actions` wrapper is layout-only: no border, panel background, padding or shadow. MU/Core cards expose only Settings when a settings target exists. Normal module cards may expose Activate, Deactivate, Settings, Trash or Restore depending on state. Tooltip text supplies the action label so the controls can remain visually icon-only.

The Module Manager visual rules live in Core at `next/assets/css/module-manager.css`. This prevents a Core management screen from flashing as unstyled content while its own lazy module assets are still loading. The Module Manager JavaScript remains module-owned and lazy.

## Rule for modules

A module inherits Core CSS automatically. Optional module CSS is lazy and should add feature-specific layout only. Do not redefine global colors, typography, radii, hover behavior, tooltips or generic button/dialog behavior inside a module.

## Performance note

Only Source Serif 4 is fetched externally. Body typography intentionally uses the local/system stack to avoid a second webfont payload. Normal add-on CSS/JavaScript remains lazy and is loaded only when that add-on is opened. Small styling needed to render must-use Core management surfaces without a flash of unstyled content may live in Core.
