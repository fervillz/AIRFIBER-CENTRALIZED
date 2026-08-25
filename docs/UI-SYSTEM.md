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
- shared button/action status feedback
- shared dialog alert and state shadow feedback
- shared dialog dirty-state / conditional Cancel behavior
- shared card arrangement / drag ordering
- shared hover/lift behavior
- shared tooltip styling and motion
- shared SVG icon sizing
- Module Manager tabs/search/card layout

`Airfiber\Next\UI` provides basic PHP helpers for buttons, fields, selects, badges and notices.

`Airfiber\Next\Tooltip` is the single tooltip API. It supports plain text, up/down motion, alternate backgrounds and an optional tooltip action. Default background is black. Tooltip enter/exit uses opacity and vertical movement instead of abruptly appearing/disappearing.

`Airfiber\Next\Icon` supplies small dependency-free SVG icons for shared controls.

## Shared action status feedback

Core owns action feedback for BETA buttons through the shared browser status manager. Modules should report the result of an action rather than inventing separate button-state CSS.

Supported states are:

- loading: blue status indicator/spinner
- success: green check/status and green button border
- warning: blue attention state
- error: red `!` status and red button border
- disabled: grey status

The small status indicator is positioned at the top-right of the button and uses the same BETA tooltip language for the current message. Async module forms, connector probes, navigation actions and developer Tools can all use the same manager.

When the action happens inside an `.afcn-dialog`, the same state is mirrored into the dialog alert and the modal shadow:

- loading: blue shadow
- success: green shadow
- warning: blue shadow
- error: red shadow
- disabled: grey shadow

The dialog shadow is state feedback, not a module-owned decoration. It transitions with the action and returns to the normal dialog shadow when the status is cleared, the form is edited after a completed action, or the dialog closes. `prefers-reduced-motion` removes the shadow transition.

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

Footer **Cancel** controls follow two shared rules:

- **Create/Add dialogs:** Cancel is visible from the moment the dialog opens because there is no existing record to return to.
- **Edit dialogs:** Cancel is hidden when the dialog opens and appears only when an editable `input`, `select` or `textarea` differs from the baseline captured after the existing record is populated. Returning every edited value to its original value hides Cancel again.

Hidden/internal fields are not part of the dirty comparison. A successful submit establishes the current values as the new baseline, while non-persistent actions such as an OLT Connect probe do not clear unsaved-change state.

Core recognizes create/add dialogs from the established `create-*`, `*-create`, `add-*` and `*-add` action naming convention. New dialog implementations may also declare `data-afcn-dialog-mode="create"` explicitly. Modules should keep using the normal `.afcn-dialog-footer [data-afcn-dialog-close]` Cancel markup and must not implement separate dirty trackers merely to show or hide Cancel.

This keeps Connections, Users and future OLT/PPP/Billing forms visually stable even when one form contains many more fields than another.

## Site-wide card arrangement

Core 0.4.13 adds an Android-style arrangement mode for BETA cards. It applies to normal `.afcn-card` components and Module Manager `.afcn-module-card` cards.

Interaction contract:

- long press any card in a group containing at least two visible sibling cards to enter arrangement mode;
- while arrangement mode is active, card controls are temporarily inert and eligible cards use a grab cursor;
- drag a card to reorder it only among cards with the same direct parent; a card never crosses into another card group;
- long press any eligible card again to save the current order and exit arrangement mode;
- Escape is a desktop convenience that also saves and exits;
- navigating away while arrangement mode is active saves the current order first.

Saved order is a UI preference stored locally per Airfiber user/browser. It does not modify module data, database records, connection records or business ordering. Core restores the order when the same module/card group is rendered again. New cards that were not part of the saved order remain available and are appended after known saved cards.

Cards should expose stable visible labels. Core already derives stable keys from common Airfiber identifiers such as module IDs, connection IDs, user IDs, browser search metadata and card headings. An unusual custom card may explicitly provide `data-afcn-card-key="..."` when it needs a stronger stable identity.

## Default card hover language

All normal Airfiber cards use the same motion language unless a component has a strong reason to opt out:

- `transform: translateY(-10px)`
- hover background `#e7f0fb`
- `box-shadow: 0 10px 40px 0 rgba(0,0,0,.1)`
- fast hover-in response (about 160 ms)
- very slow hover-out return (5 seconds)

The asymmetric timing is deliberate: cards respond immediately when hovered, then gently settle back after the pointer leaves. The global interaction stylesheet respects `prefers-reduced-motion` and disables the lift/transition when reduced motion is requested.

Normal Airfiber buttons use a softer shared hover than cards: `translateY(-5px)` with a 500 ms transition for transform, shadow, background, border and color. Cards retain their existing -10 px / asymmetric timing.

## Module Manager cards

The Modules browser intentionally uses compact 150 × 150 px cards. This fixed size is specific to the module browser and is not a global card dimension.

Module descriptions do not consume card space. Hovering/focusing the module name opens the shared Core tooltip containing the description. The health dot has its own performance tooltip.

Card hover/focus reveals icon-only actions. The `.afcn-module-card-actions` wrapper is layout-only: no border, panel background, padding or shadow. MU/Core cards expose only Settings when a settings target exists. Normal module cards may expose Activate, Deactivate, Settings, Trash or Restore depending on state. Tooltip text supplies the action label so the controls can remain visually icon-only.

The Module Manager visual rules live in Core at `next/assets/css/module-manager.css`. This prevents a Core management screen from flashing as unstyled content while its own lazy module assets are still loading. The Module Manager JavaScript remains module-owned and lazy.

## Rule for modules

A module inherits Core CSS automatically. Optional module CSS is lazy and should add feature-specific layout only. Do not redefine global colors, typography, radii, hover behavior, tooltips or generic button/dialog behavior inside a module.

## Performance note

Only Source Serif 4 is fetched externally. Body typography intentionally uses the local/system stack to avoid a second webfont payload. Normal add-on CSS/JavaScript remains lazy and is loaded only when that add-on is opened. Small styling needed to render must-use Core management surfaces without a flash of unstyled content may live in Core.
