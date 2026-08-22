# Airfiber Next Performance Contract

Performance is an architectural requirement, not a later optimization pass.

**An unopened module must have near-zero runtime cost.** Core may read cached manifest metadata; it must not load the module class, optional assets, feature data or external connections merely because the module is enabled.

## Default budgets

- bootstrap: 30 ms
- render: 120 ms
- lazy query: 180 ms
- action: 250 ms
- background task: 1000 ms
- client initialization: 160 ms
- external request: 800 ms (diagnostic only)
- memory delta: 8 MB
- database queries: 15 per profiled phase
- optional CSS: 40 KB
- optional JavaScript: 100 KB

Normal samples are retained at a low sampling rate; violations are always retained. Health exposes bounded p50/p95 and separate external p95.

## Circuit breaker

Performance/code/query/memory/asset violations cluster over one hour: 3 warning, 6 degraded, 12 quarantine for non-system modules.

Runtime failures are stricter: first failure warns, second degrades, third clustered failure can quarantine a non-system module.

System modules are never automatically quarantined. External OLT/MikroTik/API latency is also excluded from quarantine.

## Fast patterns

- cache-first responses
- stale-while-refresh network data
- server-side pagination
- lazy HTML chunks
- lazy read-only queries
- background queue for work not required for first paint
- small module manifests and optional assets
- measured external HTTP via `HTTP_Client`

The profiler itself must stay cheap; do not add verbose production tracing to every request.
