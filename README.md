# Airfiber - Centralized

WordPress-based ISP customer, billing, payment, installation, notification, and MikroTik management system.

## Current foundation

- Plugin bootstrap and lifecycle hooks
- Customer and payment post types
- Airfiber admin menu
- Responsive Tabler/Bootstrap dashboard
- Extension hook prefix: `afc_`
- Preserves operational data during uninstall

## UI framework

The admin application uses the free, MIT-licensed [Tabler](https://tabler.io/) dashboard kit, based on Bootstrap 5. Assets are pinned to a specific version instead of using an unversioned CDN URL.

## Installation

1. Download or clone the repository.
2. Place `airfiber-centralized` inside `wp-content/plugins/`.
3. Activate **Airfiber - Centralized**.
4. Open **Airfiber** in the WordPress administration menu.

## Development rules

- WordPress is the billing source of truth.
- MikroTik enforces connection state; it does not own billing dates.
- No individual RouterOS scheduler per customer.
- Actions describe events; filters modify values.
- Add-ons must use public functions and hooks rather than directly changing protected post meta.
- Operational records are never deleted automatically during plugin uninstall.

## Status

Version `0.7.0` turns the PPP browser into a Billing & PPP Operations page. It groups secondary details into hover information, adds service summaries and filters, records payments for today in both RouterOS comments and WordPress, automatically imports or updates customers, creates payment records, assigns the Expired profile without forcing an immediate disconnect, and restores/reconnects expired accounts to their saved plan.
