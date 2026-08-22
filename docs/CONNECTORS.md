# Airfiber Next Connectors

## Purpose

Connections and Modules answer different questions:

- **Modules**: what Airfiber software/add-ons are installed and active?
- **Connections**: what real routers, OLTs, cloud accounts, gateways and endpoints are configured?

The Connections Hub is therefore a normal Airfiber add-on, while the generic storage/security/registry primitives it uses live in Core.

## Architecture

```text
Airfiber Next Core
├── Connector_Registry      lightweight connector-type metadata
├── Connection_Store        non-secret configured connection records
├── Secret_Store            encrypted credentials
├── Connection_Health       cached status/latency/last-check data
├── HTTP_Client              measured remote requests
├── Cache                    stale/fresh data helpers
└── Task_Queue               background work

Feature modules
├── OLT       advertises VSOL/Huawei/etc connector types
├── MikroTik  advertises MikroTik connector types
├── SMS       advertises gateway/provider connector types
└── ...

Connections add-on
└── gathers configured records into one grouped card UI
```

Core does **not** contain vendor knowledge such as VSOL commands, RouterOS logic, ONU provisioning or Google-specific APIs. The feature module that owns a connector implements that logic.

## Fast-by-design rule

Connector metadata belongs in `module.json` so Airfiber can discover available connection types without booting the owning module PHP.

Opening the Connections Hub reads stored connection records and cached health. It must not contact every router, OLT or cloud API just to render the page.

A remote check happens only when explicitly requested or through a bounded background task. External latency is diagnostic and does not count as a module-code failure.

## Module manifest example

```json
{
  "id": "mikrotik",
  "name": "MikroTik",
  "connectors": [
    {
      "id": "mikrotik-routeros",
      "name": "MikroTik RouterOS",
      "description": "RouterOS API connection.",
      "group": "network",
      "icon": "router",
      "test_action": "test-connection",
      "fields": [
        {"key":"host","label":"Host","type":"text","required":true,"display":"endpoint"},
        {"key":"port","label":"API port","type":"number","required":true},
        {"key":"username","label":"Username","type":"text","required":true,"display":"meta"},
        {"key":"password","label":"Password","type":"password","required":true,"secret":true}
      ]
    }
  ]
}
```

Supported field types are `text`, `password`, `number`, `email`, `url`, `select` and `checkbox`.

`display` may be `endpoint` or `meta` for non-secret fields. Secret fields are never used as card display values.

## Stored records

`Connection_Store` keeps only generic, non-secret data:

```text
id
connector type
owning module
connection group
name
endpoint
sanitized config
card position
created/updated timestamps
```

Credentials are kept separately in `Secret_Store`.

## Credential security

`Secret_Store` encrypts values with a key derived from WordPress authentication salts. It prefers Sodium secretbox and falls back to AES-256-GCM through OpenSSL. There is intentionally no plaintext fallback.

Changing the WordPress salts after credentials have been stored makes the previous encrypted values unreadable. Re-enter credentials after an intentional salt rotation.

Secrets must never be placed in:

- module manifests
- connection records
- debug/audit context
- URLs
- background task payloads
- browser bootstrap data

## Health

`Connection_Health` stores small cached health records separately from configuration:

```text
state
message
latency_ms
checked_at
small sanitized details map
```

Supported states include `online`, `offline`, `warning`, `unconfigured`, `error` and `unknown`.

The Connections UI normalizes these into user-facing card states without forcing a live check.

## Test contract

If a connector declares `test_action`, the Connections Hub lazy-loads the owning feature module and calls that module action with:

```php
array( 'connection_id' => $connection_id )
```

The provider may return:

```php
array(
    'state'      => 'online',
    'message'    => 'Connected.',
    'latency_ms' => 42.7,
    'details'    => array( 'version' => '7.x' ),
)
```

The provider obtains non-secret config from `Connection_Store` and credentials from `Secret_Store`. Provider-specific network work stays in the provider module.

## Connections Hub grouping

Current built-in groups are:

- Network
- Cloud & Integrations
- Payments
- Messaging
- Storage
- Other

Modules can advertise another sanitized group key; the Hub can render it without changing Core.

## Classic migration bridge

While Airfiber Classic remains live, BETA's Connections add-on exposes existing Classic OLT, MikroTik and Google Sheets entries as **read-only CLASSIC cards**.

This bridge:

- does not copy credentials
- does not change Classic ownership
- does not run a live remote request just to render the card
- links management back to Classic
- preserves the old Connections Hub ordering where possible

As each feature becomes a real BETA module, its connection can be migrated to `Connection_Store` and the legacy card removed without redesigning the Hub.
