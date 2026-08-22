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
- Unopened modules must have near-zero runtime cost.
- Module PHP, optional CSS/JS, data and external network work are lazy/on-demand.
- Module manifests are metadata only and must not execute application logic.
- Expensive remote calls should use `Airfiber\Next\HTTP_Client` so external latency is measured separately.
- Repeated performance budget violations can quarantine a non-system module.

## Safe Git workflow

Do not discard local work. Prefer normal fast-forward commits. Never force-update `main`.
