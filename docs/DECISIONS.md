# Airfiber Next Architecture Decisions

## 2026-08-22 — Build beside Classic

Decision: do not restructure the working Classic plugin while building Next. New architecture lives in `next/`. Classic gets only a tiny bootstrap bridge and Try BETA entry point.

Reason: rollback and day-to-day Classic work must remain safe.

## 2026-08-22 — Internal modules, not WordPress plugins

Decision: PPP, OLT, Billing, Payments, Connections, SMS and future features become Airfiber internal modules rather than separate entries on the WordPress Plugins screen.

Reason: one platform can enforce one UI system, permissions, routing and performance contract.

## 2026-08-22 — Manifest discovery

Decision: module navigation/dependencies are declared in `module.json`. Discovery must not execute module PHP.

Reason: many enabled modules should not make initial load progressively slower.

## 2026-08-22 — AJAX/REST by default

Decision: the app shell loads first and modules load on demand through the Airfiber Next REST router.

Reason: users normally need one area at a time.

## 2026-08-22 — WordPress users underneath

Decision: Airfiber accounts use WordPress authentication and users with Airfiber-specific roles/capabilities.

Reason: avoid a second credential/security system.

## 2026-08-22 — Strict performance circuit breaker

Decision: Core profiles modules and can quarantine a repeatedly slow non-system module.

Reason: one bad add-on must not make the entire Airfiber application slow.

## 2026-08-22 — Do not expose ZIP install/delete during first BETA

Decision: modules are added through the repository until the module contract is proven.

Reason: filesystem mutation and package validation add risk before the SDK stabilizes.

## 2026-08-23 — Modules and Connections are separate concepts

Decision: the Modules screen manages installed Airfiber software. The Connections Hub manages actual configured routers, OLTs, APIs, accounts and endpoints.

Reason: one add-on can own many configured devices, so treating each connection as a module would mix software lifecycle with infrastructure inventory.

## 2026-08-23 — Connector primitives are Core; provider logic is not

Decision: Core owns the generic `Connector_Registry`, `Connection_Store`, `Secret_Store` and `Connection_Health`. OLT/MikroTik/Google/provider-specific protocol logic remains inside feature modules.

Reason: all providers need consistent storage, credentials, security and health behavior, but Core must stay free of business/vendor knowledge.

## 2026-08-23 — Connector metadata is manifest-first

Decision: feature modules advertise connector types and field schemas in `module.json`. The Connections Hub can discover provider types without booting module PHP.

Reason: opening navigation/Connections should remain cheap even when many provider modules are installed.

## 2026-08-23 — Connections render cache-first

Decision: opening Connections displays stored configuration and cached health. It never fans out to every external device/API simply to render the page.

Reason: remote latency should not define Airfiber UI latency.

## 2026-08-23 — Classic connections stay read-only during migration

Decision: existing Classic OLT, MikroTik and Google Sheets configuration can appear as read-only CLASSIC cards in BETA. Credentials are not copied until the owning feature is intentionally migrated.

Reason: users keep infrastructure visibility in BETA without creating two writable sources of truth.
