# Airfiber Next Tools Console

The **Tools** module is a must-use Airfiber module stored under `next/modules/mu/tools/`. It is presented as a fixed right-side utility drawer and is available only to the explicit Airfiber Super Admin.

Tools is MU because it is part of the platform's developer/diagnostic control plane. It cannot be activated, deactivated, trashed or deleted from the normal Modules lifecycle. MU does **not** mean eagerly loaded: its PHP/CSS/JS remains lazy and is loaded only when the Super Admin opens Tools or starts a FIX workflow.

## Purpose

Tools provides developer diagnostics without turning developer tooling into a normal customer/admin workflow.

The first workflow is performance remediation from **Settings → Recent performance warnings**:

1. Super Admin clicks **FIX** on a warning.
2. Core opens the Tools drawer from right to left.
3. The Tools MU module loads lazily.
4. The console inspects module health, budgets and lazy assets.
5. It performs safe runtime warm-up/verification.
6. It retests the module REST request through AJAX.
7. It prints recommendations for any remaining structural work.
8. If the retest completes within the applicable budget, the original warning is marked **resolved** and disappears from the active warning table.

Normal Administrators do not receive the Tools navigation item, utility runtime, FIX buttons or MU inventory.

## FIX request flow

The browser console runs its own diagnostic commands through the module **action** endpoint. `handle_action()` is part of the required module contract, so this avoids making the FIX workflow depend on optional `handle_query()` support or a stale runtime that has not recognized optional query methods yet.

Tools still exposes the read-only `diagnose-performance` query for SDK consumers, but the built-in console does not require it.

A single failed diagnostic stage should not immediately stop the whole FIX session. The console logs the failure and, where safe, continues to the warm-up and REST retest so the Super Admin still gets useful evidence. The final result clearly reports whether the REST retest succeeded or failed.

A successful FIX no longer deletes the original debug event. `Debug_Logger` marks it resolved and keeps it in the bounded debug history for troubleshooting/audit purposes. **Recent performance warnings** shows unresolved events only. If the same performance problem happens again, the monitor writes a new warning and it appears again automatically.

The Settings MU module listens for the Tools resolution event and removes the resolved row immediately, so the Super Admin does not need to reload Settings after a successful FIX.

## Safe automation boundary

Tools is deliberately conservative. Automatic remediation may:

- inspect module health and performance samples
- inspect lazy asset sizes
- warm the compiled module registry
- run one controlled module render
- retest the module REST endpoint
- mark the original warning resolved after a successful in-budget retest
- recommend caching, pagination, lazy chunks, DOM reduction or asset splitting

Tools does **not** automatically rewrite PHP, JavaScript or CSS, alter database schema, modify customer data, change SSH/firewall settings, or create a remote developer backdoor.

Code restructuring remains a developer action because self-modifying production code would be difficult to review and unsafe to roll back.

## Module metadata

Tools uses the same lightweight manifest contract as other Airfiber modules:

```json
{
  "name": "Tools",
  "parent": "settings",
  "presentation": "drawer",
  "capability": "afcn_super_admin"
}
```

Its **physical location** under `next/modules/mu/` makes it MU. A manifest cannot promote a normal module to MU.

`parent` nests a module under another visible navigation module.

`presentation` supports:

- `page` — normal main-stage module page (default)
- `drawer` — fixed right-side utility drawer

These are generic Core navigation/presentation primitives; Core does not contain Tools-specific diagnostic logic.

## Performance

The drawer shell and its interaction CSS are part of the small Core UI system, but the Tools MU module PHP/CSS/JS remains lazy.

The additional Core `utility.js` runtime is enqueued only for an explicit Super Admin session. Normal customer/admin sessions do not download it.

Opening Settings does not load Tools. Tools loads only when the Super Admin opens **Settings → Tools** or clicks a **FIX** action.
