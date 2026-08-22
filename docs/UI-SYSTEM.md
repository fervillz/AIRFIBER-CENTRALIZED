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
- Icon-only circular controls may remain circular.

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

`Airfiber\Next\Tooltip` is the single tooltip API. It supports plain text, up/down animation, alternate backgrounds and an optional tooltip action. Default background is black.

`Airfiber\Next\Icon` supplies small dependency-free SVG icons for shared controls.

## Default hover language

Cards and normal Airfiber buttons use the same motion language unless a component has a strong reason not to:

- `transform: translateY(-10px)`
- soft background/glow
- `box-shadow: 0 10px 40px 0 rgba(0,0,0,.1)`

The global interaction stylesheet respects `prefers-reduced-motion` and disables the lift when reduced motion is requested.

## Module Manager cards

The Modules browser intentionally uses compact 150 × 150 px cards. This fixed size is specific to the module browser and is not a global card dimension.

Module descriptions do not consume card space. Hovering/focusing the module name opens the shared Core tooltip containing the description. The health dot has its own performance tooltip.

Card hover/focus reveals icon actions. MU/Core cards expose only Settings when a settings target exists. Normal module cards may expose Activate, Deactivate, Settings, Trash or Restore depending on state.

The Module Manager visual rules live in Core at `next/assets/css/module-manager.css`. This prevents a Core management screen from flashing as unstyled content while its own lazy module assets are still loading. The Module Manager JavaScript remains module-owned and lazy.

## Rule for modules

A module inherits Core CSS automatically. Optional module CSS is lazy and should add feature-specific layout only. Do not redefine global colors, typography, radii, hover behavior, tooltips or generic button/dialog behavior inside a module.

## Performance note

Only Source Serif 4 is fetched externally. Body typography intentionally uses the local/system stack to avoid a second webfont payload. Normal add-on CSS/JavaScript remains lazy and is loaded only when that add-on is opened. Small styling needed to render must-use Core management surfaces without a flash of unstyled content may live in Core.
