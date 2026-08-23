# AIRFIBER-CENTRALIZED AI / Developer Instructions

Before changing this repository:

1. Read `CONTINUE-HERE.md`.
2. Read the relevant files in `docs/`.
3. Inspect the latest `main` branch before writing.
4. Preserve the Classic application unless the task explicitly requires a Classic bug fix.
5. New platform work belongs in `next/`.
6. Update `CONTINUE-HERE.md` after substantial work.

## File naming

All PHP files whose primary purpose is to define a class or interface use the WordPress-style readable prefix `class-`.

Examples:

- `class-module-manager.php`
- `class-performance-monitor.php`
- `class-users-module.php`

A developer should be able to understand the file's purpose from its filename.

## Architecture rules

- Airfiber Classic remains in the existing `includes/`, `templates/`, and `assets/` structure.
- Airfiber Next/BETA lives under `next/`.
- Do not migrate Classic files merely to make folders look cleaner.
- Core owns the visual system and shared runtime services.
- Modules use Core components first; module-specific CSS/JS should be the exception.
- BETA dialogs use the shared Core `.afcn-dialog` frame. Do not introduce module-specific dialog width/height; put overflow content in `.afcn-dialog-body` so the shared body scrolling works.
- **Installed does not mean loaded. Active does not mean loaded.**
- Unopened modules must have near-zero runtime cost.
- Module PHP, optional CSS/JS, data and external network work are lazy/on-demand.
- Module manifests are metadata only and must not execute application logic.
- Do not add broad feature-module hooks that make every active module participate in every Airfiber request.
- Use explicit loading triggers: direct module page, query, action, lazy chunk, manifest slot, declared event, or background task.
- Shared-page contributions should use `Airfiber\Next\Module_Slots`; the host page must not hard-code knowledge of contributing feature modules.
- Slot chunks should stay small/cache-first. A Dashboard slot must not secretly bootstrap a full OLT/Billing/PPP workflow or fan out to external devices.
- HTTP/API remote calls should use `Airfiber\Next\HTTP_Client`. Non-HTTP transports such as SNMP must still record remote duration through `Performance_Monitor::record_external()` so external latency remains diagnostic rather than module-code time.
- Repeated performance budget violations can quarantine a non-system module. External device/network latency must not quarantine a module by itself.
- Generic module presentation may use manifest `parent` and `presentation` metadata. Keep feature-specific behavior in the module; Core only owns generic navigation/presentation primitives.

## OLT migration rules

- `next/modules/olt/` is the first native provider module and starts read-only.
- Native OLT configuration belongs in `Connection_Store`; credentials belong in `Secret_Store`; cached status/details belong in `Connection_Health`.
- Do not copy or decrypt Classic OLT credentials into BETA automatically.
- Opening Connections, OLT, Dashboard or a shared slot must not trigger SNMP/network fan-out.
- Live OLT reads must be explicit or bounded background work. The first slice permits live SNMP only for explicit connection testing.
- Keep the Classic OLT bridge visible until a matching native endpoint has passed an explicit BETA health test. Only then may the duplicate Classic card be suppressed.
- Do not add ONU provisioning/mutation until the native read-only connection and inventory path is proven against real devices.
- Keep OLT/SNMP/vendor knowledge in the OLT module, not Core or the Connections UI.

## User access rules

- WordPress authentication remains underneath Airfiber users.
- Airfiber Super Admin is explicit and is **not** equivalent to the WordPress `administrator` role.
- Never add a hidden developer account, secret bypass, or remote backdoor to create Super Admin access.
- Public/customer WordPress Administrators receive normal Airfiber administration capabilities only.
- `User_Access` may restrict normal feature-module visibility per user; the module runtime must enforce that policy, not only hide navigation markup.
- MU/Core pages remain capability-driven. The Modules-screen MU inventory is Super-Admin-only.
- Super Admin always sees all enabled modules and cannot have Core access disabled from the Users UI.
- Modules requiring `afcn_super_admin` are developer-authority modules. Normal Admins must not see them in navigation, module inventory, or user-visibility checkboxes.
- Keep the reserved nested `areas` visibility model unused until a real business module requires submenu/sub-feature visibility; do not invent broad permission complexity early.

## Developer Tools rules

- The developer console is the lazy **MU** `tools` module under `next/modules/mu/tools/`, nested under Settings and presented in the generic right-side Core drawer.
- MU protects Tools from normal activate/deactivate/trash/delete lifecycle actions; it does **not** make Tools eager. Its PHP/CSS/JS remains lazy.
- Tools/FIX controls are Super-Admin-only. Normal customer/admin sessions must not download the utility runtime or Tools assets merely because the component exists.
- Performance FIX may inspect metrics/assets, warm safe runtime state, run controlled read-only render checks, retest REST/AJAX delivery, and recommend changes.
- **Never make the live Tools module automatically rewrite PHP, JavaScript, CSS, database schema, SSH/firewall configuration, or customer data as an optimization.** Structural source changes belong in the normal Git/development workflow where they can be reviewed and rolled back.
- Any future security operations such as user/IP blocking must be explicit, auditable, reversible where practical, and separately capability-checked.
- Never implement SSH or remote shell as a hidden backdoor.

## Safe Git workflow

Do not discard local work. Prefer normal fast-forward commits. Never force-update `main`.
