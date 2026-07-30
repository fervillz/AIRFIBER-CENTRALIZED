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

Version `0.8.1` adds typo-tolerant collection-area grouping. Zone/Zon/Zine/Z/Purok spellings, common barangay spelling variations, corrupted Sto. Nino text, and municipality suffixes such as MF/Manolo Fortich/Bukidnon are normalized before the summary and print list are built. Distinct barangays and meaningful sub-areas remain separate. Staff can choose a cutoff date, print every account whose `paymentDate` is on or before that date, or click an area summary to print only that area. Printouts contain only Zone/Area, Customer Name, Plan, and Due Date.
