# Payments Module

The BETA Payments module intentionally starts with the proven Classic Basic workflow:

1. open Payments;
2. the customer search is already focused;
3. type at least 3 characters;
4. receive a small AJAX result set;
5. select the customer;
6. record the payment in the shared Core dialog.

## Fast-by-design difference from Classic

Classic Basic waits for the main PPP screen to load the full PPP secret inventory, active sessions, imported-customer mapping and cached optical data. The Basic search then filters that already-loaded browser array.

BETA does no customer or RouterOS work during module render.

Search begins only after 3 characters and is debounced in the browser. The first search builds a bounded, safe PPP search index from the router's PPP secrets, then all matching is performed server-side against customer name, PPP account, phone and address. The raw structured comment is used only while building the index and is not cached or sent to the browser. The safe index is reused for 90 seconds and the browser receives at most 10 ranked matches. It does not fetch active sessions or OLT information.

The browser ignores stale responses when the user keeps typing. Payment verification re-reads the exact PPP secret with the Payments transport so a long structured comment is preserved rather than passing through the generic 500-character Router display sanitizer.

## Router boundary

Payments depends on the Routers module for configured MikroTik connections, but Routers remains read-only.

The Payments module owns a narrow RouterOS transport:

- search PPP secrets;
- re-read one exact PPP account before saving;
- replace only that selected PPP secret comment during payment recording.

There is no browser-supplied RouterOS command endpoint.

A router is eligible for payment search when its Router connector has either:

- **PPP** read scope enabled; or
- **All available read-only data** enabled.

## Search fields

The first implementation searches:

- customer name stored in the structured PPP comment;
- PPP account name;
- phone/contact number;
- address.

Results may display the customer, PPP account, plan, last payment, usual amount, service state and source router. The raw MikroTik comment is never sent to the browser.

## Payment recording

The first BETA payment form supports:

- amount;
- Cash;
- GCash;
- current server date.

Before writing, the server re-reads the selected PPP account and verifies that its RouterOS secret id still matches the selected search result.

The payment updates the structured PPP comment fields:

- `paymentDate`;
- `paymentAmount`;
- `paymentMethod`.

The module also writes the existing Classic-compatible `afc_payment` record and updates/creates the `afc_customer` record. This keeps payment history compatible while Subscribers/Billing are migrated into BETA.

Expired service is deliberately separate: recording a payment does not reconnect an expired PPP account. The UI tells the operator when the account remains expired.

## Future direction

When the Subscribers module becomes the customer source of truth, Payments should search the local subscriber index first and use RouterOS only for service verification/write operations. The current interface and query contract are designed so that backend source can change without changing the operator workflow.
