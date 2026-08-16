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

Version 2.8.0 adds read-only EPON ONU RX-power monitoring for the primary OLT.

1. Install and enable the PHP SNMP extension on the WordPress server.
2. On the OLT, create a dedicated read-only SNMPv3 `authPriv` identity using SHA authentication and DES privacy.
3. Restrict UDP/161 so only the WordPress server or its private VPN address can reach the OLT.
4. Open the protected `/airfiber/#optical` frontend page, save the OLT connection, and run the connection test.
5. Open `/airfiber/#operations`, import the customer if necessary, then use **Map ONU** to assign its PON and ONU ID.

The WordPress administration pages remain available as a recovery path, but normal OLT monitoring and configuration can be performed from the private Airfiber frontend app.

The default V1600D RX-power column OID is:

```text
1.3.6.1.4.1.37950.1.1.5.12.2.1.8.1.7
```

The plugin performs one bulk SNMP walk, caches the complete result for five minutes, and reuses the last successful snapshot if the OLT is temporarily unavailable. It never performs a separate SNMP request for every customer. OLT secrets are encrypted at rest with AES-256-GCM using the WordPress authentication key and are deleted when the plugin is uninstalled.

Customer-to-ONU bindings are stored on the customer record as the primary OLT, PON number, ONU ID, and optional ONU MAC. Duplicate PON/ONU assignments are rejected.
When the OLT exposes its learned-MAC table, the plugin compares each online PPP caller-ID with the learned subscriber MAC addresses and offers a PON/ONU mapping suggestion. Suggestions are never saved automatically; an administrator must review and confirm them.

## Development rules

- WordPress is the billing source of truth.
- MikroTik enforces connection state; it does not own billing dates.
- No individual RouterOS scheduler per customer.
- Actions describe events; filters modify values.
- Add-ons must use public functions and hooks rather than directly changing protected post meta.
- Operational records are never deleted automatically during plugin uninstall.

## Status

Current plugin version: `2.9.0`.
