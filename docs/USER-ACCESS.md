# Airfiber Next User Access

Airfiber Next uses WordPress users and authentication underneath, but Airfiber controls its own roles, module visibility and application authority.

The access model deliberately separates **authority** from **visibility**:

- a role/capability answers **what may this user do?**
- a visibility policy answers **which normal Airfiber modules should this user see and be able to open?**

A hidden module is also blocked by the module runtime; hiding a menu item is not the security boundary by itself.

## Roles

### Super Admin

Super Admin is the Airfiber owner/developer authority. It is intentionally **not** granted automatically to the WordPress `administrator` role.

Super Admin:

- sees every enabled Airfiber module
- sees Core/MU internals in the Modules screen
- bypasses per-user module visibility restrictions
- cannot have its Core access unchecked in the Users screen
- can access the developer Tools drawer and performance FIX workflow

Core ships with no hidden account or remote backdoor. A deployment may explicitly designate its Super Admin in `wp-config.php`:

```php
define( 'AFCN_SUPER_ADMIN_USER_ID', 123 );
```

The `afcn_super_admin_user_ids` filter and explicit user-level `afcn_super_admin` capability are also supported for controlled deployments.

A public/customer installation that does not explicitly designate a Super Admin treats WordPress Administrators as normal Airfiber Administrators until the one-time owner setup is completed.

## One-time owner setup

Core 0.4.1 adds a safe first-run owner bootstrap to **Users**.

When all of the following are true:

- no Airfiber Super Admin currently exists
- the current user is logged in
- the current user is a WordPress Administrator with `manage_options`

Users shows **Set up the Airfiber Owner**.

The Administrator can either:

1. promote the current WordPress account to Airfiber Super Admin; or
2. create a separate WordPress Administrator and designate it as the Airfiber Super Admin.

The separate-owner form suggests the username `bordocs` for convenience, but this is not a built-in account and no default password exists in source code. The username may be changed. The password must be entered by the Administrator or left blank so WordPress generates a strong random password that is displayed once.

Successful in-app setup stores the chosen user ID in `afcn_super_admin_user_id` and also grants the user-level `afcn_super_admin` capability. `AFCN_SUPER_ADMIN_USER_ID` in `wp-config.php` remains the strongest deployment-level override.

The setup UI disappears once an owner exists. Setup is intentionally guarded so two Administrators cannot independently create two first-run owners at the same time.

### Administrator

Airfiber Administrator is the normal buyer/site-manager role.

Administrators can manage normal Airfiber users, modules, settings and connections according to their capabilities. Core/MU implementation details are hidden from the Modules browser.

WordPress Administrators receive normal Airfiber administration capabilities but do **not** receive Airfiber Super Admin automatically.

### Operator

Operators receive normal Airfiber access only by default. Their visible feature modules can be narrowed per user.

## Module visibility

Per-user visibility is stored in user meta through `Airfiber\Next\User_Access`.

The default policy is intentionally simple:

- no saved policy = all enabled normal modules are visible
- saved policy = only the listed normal modules are visible
- Super Admin = all enabled modules are visible regardless of saved policy
- MU/Core pages remain capability-driven rather than being normal visibility checkboxes
- modules requiring `afcn_super_admin` are authority-only and never appear as user visibility checkboxes

This keeps activation, authority and visibility separate:

```text
installed != active
active != permitted
permitted != visible
visible/needed != loaded
```

The existing lazy-loading contract still applies after visibility is resolved. A visible active module is not loaded until a route, slot, query, action, event or background task actually needs it.

## Users screen

The Users MU component is card-first by default and can be toggled to a Windows/File-Explorer-style list view. The preference is local to the browser.

Editing a user exposes normal module visibility checkboxes. Super Admin always shows all normal assignable modules checked and locked. Core/MU visibility is only shown for the Super Admin account and is read-only.

Normal Administrators do not see MU visibility controls or developer-only modules.

The data model reserves an `areas` map for future nested/submenu visibility, but Core does not invent submenu permission semantics before a real module needs it. Module-level visibility is the stable contract today.

## Developer Tools

Core 0.4.2 stores Tools as a **must-use module** under `next/modules/mu/tools/`. It is available only to the explicit Super Admin, is nested under **Settings → Tools**, and opens as a fixed right-side utility drawer rather than replacing the main Airfiber page.

Because Tools is MU, it cannot be activated, deactivated, trashed or deleted through the normal Modules lifecycle. It still follows the lazy runtime contract: its PHP/CSS/JS does not load until the Super Admin opens Tools or starts a FIX workflow.

Normal Administrators do not receive:

- the Tools submenu
- the Tools MU inventory
- the utility drawer JavaScript runtime
- performance warning FIX buttons

The Tools module currently focuses on diagnostics and safe performance remediation. Security administration such as IP/user blocking may be added later, but remote shell/SSH access must never become a hidden backdoor; any such feature requires explicit local configuration, auditing and a separate security review.

See `docs/TOOLS-CONSOLE.md`.
