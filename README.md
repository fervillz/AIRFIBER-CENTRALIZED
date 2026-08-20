# Airfiber - Centralized

WordPress-based ISP customer, billing, payment, installation, notification, and MikroTik management system.

## Current foundation

- Plugin bootstrap and lifecycle hooks
- Customer and payment post types
- Airfiber admin menu
- Responsive Tabler/Bootstrap dashboard
- Read-only OLT optical monitoring with cached ONU RX-power snapshots
- Extension hook prefix: `afc_`
- Preserves operational data during uninstall

## UI framework

The admin application uses the free, MIT-licensed [Tabler](https://tabler.io/) dashboard kit, based on Bootstrap 5. Assets are pinned to a specific version instead of using an unversioned CDN URL.

## Installation

1. Download or clone the repository.
2. Place `airfiber-centralized` inside `wp-content/plugins/`.
3. Activate **Airfiber - Centralized**.
4. Open **Airfiber** in the WordPress administration menu.

## OLT optical monitoring

Airfiber supports separate EPON and GPON OLT connection cards. The known Airfiber devices are `10.13.88.5` (EPON) and `10.13.88.7` (GPON); each record keeps its own technology, RX OID, test result, and connection state.

1. Install and enable the PHP SNMP extension on the WordPress server.
2. On the OLT, create a dedicated read-only SNMPv3 `authPriv` identity using SHA authentication and DES privacy.
3. Restrict UDP/161 so only the WordPress server or its private VPN address can reach the OLT.
4. Open **Advanced → Connections**, select an OLT card, save the connection, and run the connection test.
5. Open `/airfiber/#operations`, import the customer if necessary, then use **Map ONU** to assign its PON and ONU ID.

The WordPress administration pages remain available as a recovery path, but normal OLT monitoring and configuration can be performed from the private Airfiber frontend app.

The default VSOL RX-power column OIDs are:

```text
EPON: 1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7
GPON: 1.3.6.1.4.1.37950.1.1.6.1.1.3.1.7
```

The plugin performs one bulk SNMP walk per active OLT, combines the results into an OLT-aware snapshot, caches it for five minutes, and reuses the last successful snapshot if monitoring is temporarily unavailable. It never performs a separate SNMP request for every customer. OLT secrets are encrypted at rest with AES-256-GCM using the WordPress authentication key and are deleted when the plugin is uninstalled.

Customer-to-ONU bindings store the OLT identity, PON number, ONU ID, and optional ONU MAC. Duplicate locations are rejected within the same OLT, while the same PON/ONU numbers remain valid on a different chassis.
When an OLT exposes ONU or learned-MAC data, the plugin compares each online PPP caller-ID with the subscriber MAC addresses. Unique exact MAC matches can be linked automatically; description-based suggestions still require administrator review.

## Development rules

- WordPress is the billing source of truth.
- MikroTik enforces connection state; it does not own billing dates.
- No individual RouterOS scheduler per customer.
- Actions describe events; filters modify values.
- Add-ons must use public functions and hooks rather than directly changing protected post meta.
- Operational records are never deleted automatically during plugin uninstall.

## Status

Current plugin version: `2.14.9`.
