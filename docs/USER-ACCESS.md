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
- is the intended authority for future developer/security tooling

Core ships with no hidden account or remote backdoor. A site explicitly designates its Super Admin, for example in `wp-config.php`:

```php
define( 'AFCN_SUPER_ADMIN_USER_ID', 123 );
```

The `afcn_super_admin_user_ids` filter and explicit user-level `afcn_super_admin` capability are also supported for controlled deployments.

A public/customer installation that does not explicitly designate a Super Admin treats WordPress Administrators as normal Airfiber Administrators.

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

Editing a user exposes normal module visibility checkboxes. Super Admin always shows all normal modules checked and locked. Core/MU visibility is only shown for the Super Admin account and is read-only.

Normal Administrators do not see MU visibility controls.

The data model reserves an `areas` map for future nested/submenu visibility, but Core does not invent submenu permission semantics before a real module needs them. Module-level visibility is the stable contract today.

## Future developer/security area

A Developer/Debug/Security navigation area is intentionally deferred. If added, it should be Super-Admin-only and should contain developer diagnostics/security tools rather than normal buyer workflows.

Potential examples include advanced diagnostics, security event review, IP/user blocking controls and infrastructure settings. Remote shell/SSH access must never be implemented as a hidden backdoor; any such feature would require explicit local configuration, auditing and a separate security review.
