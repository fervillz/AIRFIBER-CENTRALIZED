# Airfiber Next Architecture

## Purpose

Airfiber Next is a platform inside the existing Airfiber Centralized WordPress plugin. WordPress sees one plugin; Airfiber Next discovers and runs internal modules.

Classic continues to use the existing files. Next uses the `Airfiber\Next` namespace, `afcn_` options/handles, `/airfiber-beta/`, and files under `next/`.

## Core runtime

`next/bootstrap.php` loads only `next/core/class-bootstrap.php`. Bootstrap registers the autoloader, protected BETA page, REST router and background queue hook. It does not load feature modules.

Module manifests compile into a persistent registry option, so normal requests do not reopen dozens of JSON files. The Modules page can rebuild the registry after a deployment.

## Request flow

```text
Open /airfiber-beta/
  -> Core shell + Core CSS + Core JS
  -> cached module registry builds navigation
  -> browser asks REST for current module
  -> Core loads only that module PHP
  -> optional module CSS/JS loads
  -> deeper queries/chunks load only when requested
```

## Module lifecycle and extension

Modules implement `Module_Contract` and may optionally expose lazy query/chunk/background/lifecycle/event methods. Core provides generic routes, so a new module does not require edits to the REST router.

Cross-module behavior can use `Event_Bus` or normal `afcn_*` WordPress hooks. Module state changes publish both.

## Background work

`Task_Queue` stores a small bounded queue and wakes through WP-Cron. Only the module attached to a due task is loaded. Jobs retry with bounded exponential backoff. Payloads are size-limited and should contain identifiers, never large datasets/secrets.

The queue contract is intentionally runner-agnostic so a dedicated worker can replace WP-Cron later without changing module APIs.

## Data and external devices

Use `Cache` for cache-first/stale-while-refresh behavior, `Module_Options` for per-module settings, and `HTTP_Client` so remote latency is measured separately from PHP performance.

## Users

Airfiber uses WordPress authentication underneath with `airfiber_admin` and `airfiber_operator` roles. WordPress administrators automatically receive Airfiber capabilities.

## Fault isolation

Core catches module `Throwable` failures around bootstrap/render/query/action/chunk/background/event execution. Runtime failures are tracked separately from performance violations. Three clustered runtime failures can quarantine a non-system module; Core and unrelated modules remain available.

External device/API latency is diagnostic and never quarantines a module by itself.

## Security boundaries

Only the built-in Dashboard, Users, Modules and Settings IDs can be marked as Core system modules. Module class namespaces and lazy asset paths are validated before loading.
