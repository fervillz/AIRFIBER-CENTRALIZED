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

`Airfiber\Next\UI` provides basic PHP helpers for buttons, fields, selects, badges and notices.

## Rule for modules

A module inherits Core CSS automatically. Optional module CSS is lazy and should add feature-specific layout only. Do not redefine global colors, typography, radii or generic button/dialog behavior inside a module.

## Performance note

Only Source Serif 4 is fetched externally. Body typography intentionally uses the local/system stack to avoid a second webfont payload.
