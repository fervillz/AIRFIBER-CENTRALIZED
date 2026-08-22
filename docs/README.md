# Airfiber Next Developer Documentation

Airfiber Next/BETA is an internal application platform inside Airfiber Centralized. These docs are the source of truth for building Core and feature modules.

## Start here

- [Module Basics](MODULE-BASICS.md) — build your first Airfiber module with the minimum required files.
- [Module Loading](MODULE-LOADING.md) — how Airfiber keeps active modules dormant until a route, slot, query, action, event or task needs them.
- [Module SDK](MODULE-SDK.md) — actions, queries, lazy chunks, settings, assets, connectors and shared Core services.
- [Architecture](ARCHITECTURE.md) — Core/module boundaries and runtime design.
- [UI System](UI-SYSTEM.md) — shared visual components, cards, dialogs, tooltips and interaction rules.
- [Performance Contract](PERFORMANCE-CONTRACT.md) — budgets, profiling and fast-by-design rules.
- [Connectors](CONNECTORS.md) — connection types, credential storage and provider integration.
- [Architecture Decisions](DECISIONS.md) — important decisions and why they were made.
- [Migration Plan](MIGRATION-PLAN.md) — moving Classic features into Next safely.

## Documentation rule

Document stable contracts while they are built. Do not wait until the product is finished, but also do not duplicate every implementation detail from the PHP source.

GitHub Markdown in this repository remains the canonical documentation. A separate documentation service may be added later as a presentation layer, but it should not become a second source of truth.
