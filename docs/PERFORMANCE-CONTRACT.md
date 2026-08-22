# Airfiber Next Performance Contract

Performance is an architectural requirement, not a later optimization pass.

**An unopened module must have near-zero runtime cost.** Core may read cached manifest metadata; it must not load the module class, optional assets, feature data or external connections merely because the module is enabled.

## Default budgets

Actionable module/code budgets:

- bootstrap: 30 ms
- render: 120 ms
- lazy query: 180 ms
- action: 250 ms
- background task: 1000 ms
- client apply/paint: 160 ms
- memory delta: 8 MB
- database queries: 15 per profiled server phase
- optional CSS: 40 KB
- optional JavaScript: 100 KB

Delivery/diagnostic budgets:

- module REST request / transport: 500 ms
- optional asset delivery: 250 ms
- complete uncached module navigation: 650 ms
- external device/API request: 800 ms

Normal samples are retained at a low sampling rate; violations are always retained. Health exposes bounded server-runtime p50/p95 plus separate client, transport, navigation, asset-load and external p95 values.

## Browser timing semantics

Browser metrics must not mix unrelated costs:

- `client` measures only synchronous DOM insertion/wiring through the next browser paint. This is actionable module/client code and can affect module health.
- `transport` measures the REST request, WordPress request stack and network delivery. It is diagnostic only and must not quarantine a module.
- `asset_load` measures optional module CSS/JavaScript delivery. It is diagnostic only and must not quarantine a module.
- `navigation` measures the complete uncached module transition. It is diagnostic only and must not quarantine a module.
- `external` remains device/API latency and is diagnostic only.

Metric schema v1 incorrectly used `client` for the complete REST round trip. Core 0.3.4 performs a one-time migration that removes those invalid client-based warning states/samples/log rows without disturbing unrelated runtime failures.

## Circuit breaker

Actionable performance/code/query/memory/asset violations cluster over one hour: 3 warning, 6 degraded, 12 quarantine for non-system modules.

Runtime failures are stricter: first failure warns, second degrades, third clustered failure can quarantine a non-system module.

System modules are never automatically quarantined. Transport, navigation, asset-delivery and external OLT/MikroTik/API latency are also excluded from quarantine.

## Fast patterns

- cache-first responses
- stale-while-refresh network data
- server-side pagination
- lazy HTML chunks
- lazy read-only queries
- background queue for work not required for first paint
- small module manifests and optional assets
- measured external HTTP via `HTTP_Client`
- separate server, client and delivery timings so optimization targets the actual bottleneck

The profiler itself must stay cheap; do not add verbose production tracing to every request.
