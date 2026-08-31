# Airfiber Next UI System

Core owns the visual language. Modules should not invent a separate design system.

## Typography

- Headings: `Source Serif 4`, weight 400.
- UI/body: Inter when available, then native system fonts.
- Avoid bold display headings unless the design specifically requires emphasis.
- Prefer the semantic Core scale: `--afcn-type-xs` 11px, `--afcn-type-sm` 12px, `--afcn-type-md` 14px, `--afcn-type-lg` 16px and `--afcn-type-xl` 20px. Feature modules should use the scale rather than inventing smaller text to make crowded layouts fit.
- **Readable type wins over density.** Do not solve an overloaded card, list row, tab or toolbar by shrinking important text. Preserve primary text at a comfortable size, then reduce information density instead.
- **No-bold rule:** BETA UI text uses normal `400` weight site-wide. Do not use `600`, `700`, `800`, `bold`, or `bolder` for emphasis.
- Emphasize text with hierarchy instead of weight: make it about **1px larger**, use a darker text tone, give it more whitespace, or move secondary information into a pill/icon/dialog.
- Use `.afcn-emphasis` when a reusable inline emphasis treatment is appropriate; it keeps weight at 400, adds 1px, and uses the darker emphasis color.

## Progressive disclosure and density

Airfiber BETA uses a **typography-first density rule**.

When a component contains too much information, use this order:

1. keep the primary identity/action readable;
2. turn short state/category data into a Core pill, status dot, counter or recognizable icon;
3. remove low-priority data from the summary when it is not needed for the current decision;
4. move secondary technical/detail fields into the existing dialog, drill-down or detail view;
5. use a tooltip for brief explanation of an icon or compact state;
6. only after those choices should spacing be tightened.

Do **not** respond to crowded content by creating 9px/10px paragraphs or adding more table-style columns. Summary surfaces should help the operator decide what to click; the detail surface can hold the full record.

As a default hierarchy for new list/card UI:

- primary name/value: `--afcn-type-lg` or larger;
- important secondary value/date: `--afcn-type-md`;
- normal metadata: `--afcn-type-sm`;
- pills/compact labels only: `--afcn-type-xs`.

This rule applies site-wide to future Subscribers, Billing, Payments, Routers and other modules.

## Shape tokens

- Buttons: 9px radius.
- Fields/selects: 9px radius.
- Cards: 10px radius.
- Dialogs/popups: 14px radius.
- Dialog height is content-fit by default. Core caps tall dialogs at the shared maximum/viewport height and scrolls only `.afcn-dialog-body`; do not set feature-specific fixed dialog heights.
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
- shared cards/list view controller and title toggle
- shared compact drill-down header for in-page child-card views
- shared bounded data-table browser styling with search and pagination
- shared accessible tabs with top, bottom, left and right placement
- shared hover/lift behavior
- shared tooltip styling and motion
- shared SVG icon sizing
- Module Manager tabs/search/card layout

`Airfiber\Next\UI` provides basic PHP helpers for buttons, fields, selects, badges, notices and shared tabs.

`Airfiber\Next\Tooltip` is the single tooltip API. It supports plain text, up/down motion, alternate backgrounds and an optional tooltip action. Default background is black. Tooltip enter/exit uses opacity and vertical movement instead of abruptly appearing/disappearing.

`Airfiber\Next\Icon` supplies small dependency-free SVG icons for shared controls.

`Airfiber\Next\Data_Query` is the reusable server-side search/paging helper for bounded row sets. Modules keep ownership of retrieval and permissions, then pass safe rows into Core. By default search matches all scalar values in each row, so future modules can add fields without rewriting the search engine; an optional `search_fields` list can narrow matching when needed.

## Core UI kit — Core 0.4.30

Core owns the small reusable application controls that feature modules need repeatedly. The kit is intentionally dependency-free and token-driven so Subscribers, Billing and future modules can stay mostly business logic plus layout.

The shared PHP API now includes:

- enhanced buttons with icon, size, loading/disabled states and raised notification counters;
- pills, numeric counters and status dots;
- structured info/success/warning/danger/neutral alerts;
- compact/actionable lists with leading icons, secondary copy, values, counters and pills;
- read-only detail lists;
- text/select/textarea plus checkbox and switch helpers with help/error states;
- progress, empty-state and skeleton helpers;
- native lightweight action menus;
- reusable dialog markup;
- existing tabs, notices and drill-down headers.

Colors, soft backgrounds, radii, control heights and motion remain CSS variables in Core. Modules should change feature layout only and should not fork button/status/list/menu styling.

Most components are HTML/CSS only. Dismissible alerts and menu closing add only a small event layer to the existing Core runtime. No UI framework or component dependency was introduced.

Tabs may now include an optional `count` and `count_variant`, and buttons accept the same notification-counter idea through `count` / `count_variant`. This keeps quantities visible without increasing the control height.

See `docs/UI-COMPONENTS.md` for the call reference and examples.

## Lazy settings cards — Core 0.4.34

Settings summary pages should render card launchers only. Expensive forms, audit history and diagnostic detail belong behind an on-demand query/dialog or an existing lazy utility drawer. Settings cards follow the same compact 230px-minimum / 168px-height rhythm used by the connection-card system.

Core provides `UI::indicator_button()` for icon actions with superscript indicators. Multiple indicators can show warning/error counts such as yellow `7` | red `8`; an empty indicator value renders as a severity dot for controls such as Developer Console status.

## Shared tabs — Core 0.4.28

Core owns one tab component for pages, cards and dialogs. It follows the normal BETA surface, radius, border, muted-text, blue-active-state and motion language rather than introducing module-specific tab bars.

Use the PHP helper:

```php
echo \Airfiber\Next\UI::tabs(
    'device-tabs',
    array(
        'basic' => array(
            'label'   => 'Basic',
            'content' => '<div>...</div>',
        ),
        'status' => array(
            'label'   => 'Status',
            'content' => '<div>...</div>',
        ),
    ),
    array(
        'position' => 'top', // top, bottom, left, right
        'active'   => 'basic',
        'label'    => 'Device sections',
    )
);
```

The browser runtime is available as `window.AirfiberTabs` and as `window.AirfiberNext.tabs`:

```js
AirfiberTabs.activate(document.querySelector('[data-afcn-tabs]'), 'status');
```

Core handles click switching, `aria-selected`, panel visibility, roving `tabindex`, Home/End navigation, horizontal Left/Right keys, vertical Up/Down keys, disabled tabs, and the bubbling `afcn:tab:change` event. Left/right tabs collapse to the horizontal top layout on narrow screens so dialog/page content stays usable.

Modules may hand-write the same `data-afcn-tabs`, `data-afcn-tab`, and `data-afcn-tab-panel` contract when content must be assembled incrementally, but they should not recreate tab styling or a separate tab runtime.

## Shared bounded data tables

Core 0.4.25 adds the reusable `.afcn-data-*` table browser language for large-but-bounded module results. The standard pattern is a compact result summary, one search field, a horizontally safe modern table, and Previous/Next pagination. Only the active page should be rendered into the DOM. Feature modules still define their business columns and data retrieval.

For remote systems, prefer cache-first paging: the first explicit or deferred request may refresh a bounded safe dataset, then search/page requests should reuse that short-lived dataset rather than fan out repeatedly to the remote device. A manual Refresh/Load action may bypass that cache when freshness is requested.

## Connection submenu labels

Modules using the Core `connection_submenu` contract receive the normal nested-navigation component. The primary line is the saved connection name; an optional muted `<small>` line shows its endpoint. Core owns truncation, spacing, hover and active states so provider modules do not add menu-specific CSS.

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

## In-page drill-down views

Core 0.4.23 owns the compact header used when a primary card opens an in-page child view, such as a router opening its read-only scope cards. This is deliberately separate from dialogs: gear/settings actions still use normal Core dialogs and are not converted into drill-down pages.

Use `Airfiber\\Next\\UI::drilldown_head( $context, $title, $meta, $actions )` instead of repeating a second full module heading. The shared header renders:

- the selected item title as the dominant heading;
- a small muted uppercase context label above the title, left-aligned with it (for example `ROUTER`);
- one optional muted metadata line such as an endpoint;
- optional compact actions/health indicators aligned to the right.

When a module switches from its primary card browser into a drill-down, hide the primary browser heading and browser content. Do not repeat the module description, add another explanatory section title, or create a highlighted summary card merely to restate context already visible from the selected item. Child cards should begin immediately after the compact drill-down header unless the feature genuinely needs another semantic section.

The module still owns selection/history and its business-specific child cards. Core owns the visual header language so future card-to-child-card views stay consistent without adding a generic routing framework.

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

Core 0.4.15 refines the BETA card arrangement runtime into a Trello-like pointer interaction while keeping the Android-style long-press entry and explicit save/exit behavior.

Interaction contract:

- long press an arrangeable card for about 420 ms to enter arrangement mode;
- that same long press immediately lifts the held card and attaches it to the current pointer position — there is no second click or second drag gesture;
- while arrangement mode is active, card controls are temporarily inert and eligible cards use a grab cursor;
- moving another card in active arrange mode begins dragging after only a tiny movement threshold;
- the floating card tracks the pointer with `requestAnimationFrame` and GPU-friendly `translate3d()` transforms;
- neighboring cards use FLIP displacement animations with a short cubic-bezier easing curve so they move out of the way instead of jumping;
- dropping at the beginning/end of a list or grid is supported;
- dragging near the viewport top/bottom performs bounded continuous edge scrolling;
- dropping outside a compatible destination restores the placeholder to its original index and animates the floating card back into that position;
- a valid drop animates the floating card into the placeholder before normal card styles/interactions are restored;
- long press any card again while arrange mode is active to save the current order and exit;
- Escape is a desktop convenience that also saves and exits;
- navigating away while arrangement mode is active saves the current order first.

Saved order remains a UI preference stored locally per Airfiber user/browser. Core 0.4.15 migrates the original v1 browser preference into the v2 storage format rather than discarding an existing arrangement.

### Compatible cross-list dragging

Card containers are isolated by default. This is intentional: moving an OLT card into a visually different Connections category must not silently change the meaning of business data.

A board-style module can explicitly allow cards to move between multiple direct parent lists by giving those parents the same compatibility key:

```html
<div data-afcn-card-drop-group="work-board">...</div>
<div data-afcn-card-drop-group="work-board">...</div>
```

Only containers in the same current Airfiber module/scope and with the same `data-afcn-card-drop-group` can exchange cards. Cross-list membership and order are persisted with the same per-user layout preference. Empty compatible lists are valid drop zones because the marker is discovered even when they contain no cards.

Cards should expose stable visible labels. Core already derives stable keys from common Airfiber identifiers such as module IDs, connection IDs, user IDs, browser search metadata and card headings. An unusual custom card may explicitly provide `data-afcn-card-key="..."` when it needs a stronger stable identity.

The runtime is dependency-free vanilla Pointer Events + `requestAnimationFrame` + Web Animations/FLIP. No drag library or AJAX request is required for purely personal layout changes, which keeps site-wide overhead small. `prefers-reduced-motion` removes the settle/displacement animation while preserving ordering behavior.

## Shared cards / list view

Core 0.4.14 owns the cards/list switch through `window.AirfiberViewMode`. Core 0.4.29 extends the same controller with a configurable default view and labels, so a feature can reuse the list/grid toggle for equivalent presentations such as **tabs/cards** without creating another switch. The Router drill-down uses this with left tabs as the default and cards as the alternate view. The control keeps the same list and grid/thumbnail icons, tooltip behavior and 32 px title control. Feature modules should not create another view-toggle component.

The shared controller:

- defaults to cards;
- remembers the selected view in local browser storage;
- changes the list icon to the existing grid/thumbnail icon while list mode is active;
- keeps the toggle next to the page title;
- accepts a module-owned cards container and list/table container so business-specific columns stay inside the feature module while switching behavior stays in Core.

Users now delegates its existing cards/list switching to this Core controller. Connections uses the same controller and produces a table view from the same currently rendered connection cards, so filters, search, status, Classic/BETA source and actions remain consistent. Switching Connections to list rebuilds the table from the current card DOM order, including any saved card arrangement inside each group.

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
