# Airfiber Next Architecture

## Purpose

Airfiber Next is a platform inside the existing Airfiber Centralized WordPress plugin. WordPress sees one plugin; Airfiber Next discovers and runs internal modules.

```text
WordPress
└── Airfiber Centralized
    ├── Classic (existing system)
    └── Next/BETA
        ├── Core
        └── Modules
```

## Isolation

Classic and Next share WordPress, authentication and existing application data, but they do not share frontend architecture.

Classic continues to use the existing files. Next uses the `Airfiber\Next` namespace, `afcn_` options/handles, `/airfiber-beta/`, and files under `next/`.

## Bootstrap

`next/bootstrap.php` loads only `next/core/class-bootstrap.php`. The bootstrap registers a lightweight autoloader and the few WordPress hooks required to recognize the BETA page and REST routes.

It does not load feature modules.

## Module discovery

Each module has a tiny `module.json`. Core compiles discovered manifests into a persistent registry option. Normal requests read that compiled registry instead of reopening every manifest file.

The Modules system screen has **Refresh Registry** for newly deployed module folders. A future secure package installer must invalidate the registry automatically.

Opening a module causes Core to autoload that module class and call its render method through the shared REST router.

## Module lifecycle

Non-system modules can optionally expose static `activate()` and `deactivate()` methods. They run only when the module state is intentionally changed, not during normal app boot.

Module state changes also fire `afcn_module_state_changed` and the lazy `module_state_changed` Event Bus event.

## Request flow

```text
Open /airfiber-beta/
  -> Core shell + Core CSS + Core JS
  -> cached module registry builds navigation
  -> browser asks REST for current module
  -> Core loads only that module PHP
  -> optional module CSS/JS is returned as a lazy manifest
  -> browser injects module markup
```

## Data flow

Modules should return cached/current UI quickly. Expensive device/network refreshes belong in separate on-demand/background requests.

Use `Airfiber\Next\Cache` for namespaced caches and stale/fresh envelopes. Use `Airfiber\Next\HTTP_Client` for remote calls so external latency is profiled separately from module PHP.

## Users

Airfiber does not maintain a second password database. It uses WordPress users underneath with Airfiber-specific roles and capabilities:

- `airfiber_admin`
- `airfiber_operator`

WordPress administrators automatically have Airfiber Next capabilities.

## Extension points

The platform uses three forms of extension:

1. Manifest metadata for navigation, assets, dependencies and lazy event subscriptions.
2. `Airfiber\Next\Event_Bus` for events that should lazy-load only subscribed modules.
3. Normal WordPress actions/filters inside modules that are already loaded.

## Fault isolation

Each module is profiled independently. Non-system modules with repeated clustered code/query/memory/asset budget violations can be quarantined without bringing down Core or unrelated modules. External device/API latency is reported separately and cannot quarantine a module by itself.
