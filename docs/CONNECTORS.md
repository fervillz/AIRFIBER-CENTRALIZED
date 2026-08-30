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
├── HTTP_Client              measured HTTP remote requests
├── Cache                    stale/fresh data helpers
└── Task_Queue               background work

Feature modules
├── OLT       owns SNMP/OLT behavior
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

A field may use one simple equality condition so a connector form shows only fields relevant to the selected mode:

```json
{
  "key": "community",
  "label": "SNMPv2c Community",
  "type": "password",
  "secret": true,
  "show_when": {"field":"version","value":"2c"}
}
```

`show_when` is intentionally small: one controlling field and one expected value. Do not put business logic or an expression language in connector manifests. Hidden conditional fields are disabled in the browser; on edit, omitted conditional values keep their previously saved value.

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

## Test and form-probe contract

If a connector declares `test_action`, the Connections Hub lazy-loads the owning feature module and calls that module action for a normal saved connection test with:

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

Connections may also expose a **Connect** button inside Add/Edit dialogs. That button probes the current sanitized form values without persisting them. For an edit, blank secret fields may fall back to the already encrypted saved secret. A successful form probe changes the button to **Connected** for the current form state; changing a field resets it to **Connect**. Form probes do not rewrite `Connection_Health`, because unsaved values must not be presented as the health of the saved connection.

## First native OLT connector — Core 0.4.7

`next/modules/olt/` is the first real provider module proving the connector contract. It advertises `olt-snmp` from manifest metadata and reuses the proven Classic OLT SNMP model without calling Classic classes during native operation.

The native OLT scope remains deliberately read-only:

- GPON or EPON
- SNMPv3 authPriv using SHA/DES, matching the current Classic implementation
- SNMPv2c read-only community support
- SNMP-version-aware form fields: v2c shows Community; v3 shows Username/Auth/Privacy
- system name/description identity read
- configured/default RX-power OID walk during an explicit connection test/probe
- encrypted credentials through `Secret_Store`
- cached connection status/details through `Connection_Health`
- external SNMP latency recorded as diagnostic performance data
- Classic-compatible bounded GETNEXT fallback for GPON SNMPv2c firmware that responds to GET/GETNEXT but stalls on GETBULK/`real_walk`

The GPON fallback is important for the V1600G-family behavior already handled by Classic. BETA keeps this transport workaround inside the OLT provider rather than Core.

Opening **Connections**, **OLT**, or Dashboard does not poll the device. OLT pages and the `dashboard.summary` slot render from `Connection_Store` + `Connection_Health` only.

Classic OLT cards remain as a migration safety net. When a native BETA OLT with the same host has passed a successful explicit saved connection test, Connections prefers that verified native card and stops showing the duplicate Classic OLT card. A failed or untested native setup does not hide Classic.

This slice does **not** provision ONUs or copy Classic credentials. Provisioning and deeper PON/ONU inventory come only after the native read-only connection path is verified.

## Native MikroTik RouterOS connector — Core 0.4.22

`next/modules/routers/` advertises `mikrotik-routeros` for RouterOS API (`8728`) or API over TLS (`8729`). Credentials are encrypted through `Secret_Store`; Classic MikroTik credentials are never copied.

The first native router slice is deliberately read-only and explicit:

- opening Routers reads only `Connection_Store` and cached `Connection_Health`;
- Test connection reads bounded identity and system-resource properties;
- administrators choose which scopes the connection is allowed to expose;
- each PPP, Interfaces, System scripts, Firewall, Netwatch, Logs, SSH, Services, Hotspot or Neighbors read is a separate explicit request;
- the browser supplies only the saved connection ID and a server-defined scope key, never a RouterOS sentence;
- responses are row/property/length bounded and remote latency is diagnostic;
- PPP passwords/comments, script source, Netwatch script bodies, Hotspot passwords and private SSH key material are never requested;
- the module does not mutate RouterOS configuration.

The saved RouterOS account should itself have the narrowest read-only RouterOS policy that supports the selected scopes. Application-side allow lists are defense in depth, not a replacement for device-side least privilege.

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
- suppresses only a Classic OLT duplicate whose matching native endpoint has already passed a BETA health test

As each feature becomes a real BETA module, its connection can be migrated to `Connection_Store` and the legacy card removed without redesigning the Hub.
