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
- **Installed does not mean loaded. Active does not mean loaded.**
- Unopened modules must have near-zero runtime cost.
- Module PHP, optional CSS/JS, data and external network work are lazy/on-demand.
- Module manifests are metadata only and must not execute application logic.
- Do not add broad feature-module hooks that make every active module participate in every Airfiber request.
- Use explicit loading triggers: direct module page, query, action, lazy chunk, manifest slot, declared event, or background task.
- Shared-page contributions should use `Airfiber\Next\Module_Slots`; the host page must not hard-code knowledge of contributing feature modules.
- Slot chunks should stay small/cache-first. A Dashboard slot must not secretly bootstrap a full OLT/Billing/PPP workflow or fan out to external devices.
- Expensive remote calls should use `Airfiber\Next\HTTP_Client` so external latency is measured separately.
- Repeated performance budget violations can quarantine a non-system module.

## User access rules

- WordPress authentication remains underneath Airfiber users.
- Airfiber Super Admin is explicit and is **not** equivalent to the WordPress `administrator` role.
- Never add a hidden developer account, secret bypass, or remote backdoor to create Super Admin access.
- Public/customer WordPress Administrators receive normal Airfiber administration capabilities only.
- `User_Access` may restrict normal feature-module visibility per user; the module runtime must enforce that policy, not only hide navigation markup.
- MU/Core pages remain capability-driven. The Modules-screen MU inventory is Super-Admin-only.
- Super Admin always sees all enabled modules and cannot have Core access disabled from the Users UI.
- Keep the reserved nested `areas` visibility model unused until a real module requires submenu/sub-feature visibility; do not invent broad permission complexity early.
- A future Developer/Debug/Security area, if built, must be Super-Admin-only and explicitly configured/audited.

## Safe Git workflow

Do not discard local work. Prefer normal fast-forward commits. Never force-update `main`.
