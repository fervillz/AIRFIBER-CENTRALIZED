# Airfiber Next Performance Contract

Performance is an architectural requirement, not a later optimization pass.

## Primary rule

**An unopened module must have near-zero runtime cost.**

Core may read its small manifest. It must not load the module class, optional assets, feature data or external device connections merely because the module is enabled.

## Default budgets

- module bootstrap: 30 ms
- module render: 120 ms
- module action: 250 ms
- client module initialization: 160 ms
- measured external request: 800 ms
- memory delta per profiled phase: 8 MB
- database queries per profiled phase: 15

Budgets are configurable from the BETA Settings module.

## Sampling

Normal samples are kept at a low sampling rate to stop the profiler from becoming the performance problem. Violations are always retained.

Metrics include duration, memory delta and query count. Module health stores bounded recent history and exposes p50/p95.

## Circuit breaker

Violations are counted inside a rolling one-hour cluster window.

- 3: warning
- 6: degraded
- 12: quarantine for non-system modules

A quarantine blocks module loading while Core and other modules continue running. System modules are never automatically quarantined.

An administrator can inspect the recommendation and reset module health from Modules.

## Recommendations

Core attempts to distinguish common causes:

- too many queries → cache/paginate
- high memory → reduce dataset/split lazy feature chunks
- slow bootstrap → move work out of module class loading
- slow render/action → cache-first UI and smaller on-demand chunks

## External latency

Use `Airfiber\Next\HTTP_Client` instead of direct `wp_remote_*()` for device/API work where possible. This records external latency as `external` rather than incorrectly treating a slow OLT or API as PHP bootstrap cost.

## Cache-first behavior

For network equipment and large ISP data, prefer:

1. show cached/last-known values immediately;
2. mark their age;
3. refresh asynchronously;
4. update only changed UI.

Fast software should not make the user wait for work that can happen after first paint.
