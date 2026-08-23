# Airfiber Next Tools Console

The **Tools** module is a normal Airfiber module presented as a fixed right-side utility drawer. It is available only to the explicit Airfiber Super Admin.

## Purpose

Tools provides developer diagnostics without turning developer tooling into a normal customer/admin workflow.

The first workflow is performance remediation from **Settings → Recent performance warnings**:

1. Super Admin clicks **FIX** on a warning.
2. Core opens the Tools drawer from right to left.
3. The Tools module loads lazily.
4. The console inspects module health, budgets and lazy assets.
5. It performs safe runtime warm-up/verification.
6. It retests the module REST request through AJAX.
7. It prints recommendations for any remaining structural work.

Normal Administrators do not receive the Tools navigation item, utility runtime, or FIX buttons.

## Safe automation boundary

Tools is deliberately conservative. Automatic remediation may:

- inspect module health and performance samples
- inspect lazy asset sizes
- warm the compiled module registry
- run one controlled module render
- retest the module REST endpoint
- recommend caching, pagination, lazy chunks, DOM reduction or asset splitting

Tools does **not** automatically rewrite PHP, JavaScript or CSS, alter database schema, modify customer data, change SSH/firewall settings, or create a remote developer backdoor.

Code restructuring remains a developer action because self-modifying production code would be difficult to review and unsafe to roll back.

## Module metadata

Tools demonstrates two generic manifest fields introduced in Core 0.4.0:

```json
{
  "name": "Tools",
  "parent": "settings",
  "presentation": "drawer"
}
```

`parent` nests a module under another visible navigation module.

`presentation` supports:

- `page` — normal main-stage module page (default)
- `drawer` — fixed right-side utility drawer

These are generic Core navigation/presentation primitives; Core does not contain Tools-specific module logic.

## Performance

The drawer shell and its interaction CSS are part of the small Core UI system, but the Tools module PHP/CSS/JS remains lazy.

The additional Core `utility.js` runtime is enqueued only for an explicit Super Admin session. Normal customer/admin sessions do not download it.

Opening Settings does not load Tools. Tools loads only when the Super Admin opens **Settings → Tools** or clicks a **FIX** action.
