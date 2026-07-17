# Simple Stats for Joomla

Simple Stats is a small, self-hosted statistics package for Joomla 6. It is intended for sites that need basic traffic information without Google Analytics, an external analytics account, analytics cookies, or third-party requests containing visitor data.

Version: **0.1.2**

## Package contents

- `com_simplestats`: administrator dashboard and configuration
- `plg_system_simplestats`: frontend request collector

The system plugin is enabled automatically when the package is installed.

## Collected information

For eligible frontend page requests, the extension stores:

- UTC timestamp and local site date
- Requested path
- Joomla component and view
- External referrer hostname only
- `DE` or `ZZ` country classification
- Primary browser language
- Broad device category
- Broad browser family
- Human/bot classification and a broad bot name
- A daily rotating visitor hash

It does **not** store:

- Raw IP addresses
- Complete User-Agent strings
- Complete referrer URLs
- Persistent cross-day visitor identifiers
- Analytics cookies

The visitor hash is an HMAC of the visitor IP address, User-Agent, local date, and the Joomla site secret. It is truncated and changes every local calendar day. The dashboard therefore reports **visitor-days**, not exact people or long-lived sessions.

## Dashboard

The administrator dashboard provides selectable 7, 30, 90, 365-day and all-time ranges with:

- Human visitor-days
- Human page views
- German visitor-days
- German-language page views
- Bot page views
- Daily totals
- Top pages
- External referrers
- Browser languages
- Device categories
- Browser families
- Detected bots

## German visitor detection

Simple Stats deliberately performs only the country check needed for this site: Germany versus unknown/non-Germany.

The **Update German IP ranges** toolbar action downloads current aggregated German IPv4 and IPv6 CIDR lists from IPdeny and compiles them into local fixed-record binary files under:

```text
cache/com_simplestats/
```

No visitor IP address is sent to IPdeny or any other external service. Only an administrator-triggered database download contacts IPdeny.

IP-based country detection is necessarily approximate. VPNs, mobile carrier gateways, corporate proxies, privacy relays, reverse proxies, and stale allocation data can produce incorrect classifications.

## Installation

1. In Joomla Administrator, open **System → Install → Extensions**.
2. Upload `pkg_simplestats-0.1.2.zip`.
3. Open **Components → Simple Stats**.
4. Click **Update German IP ranges**.
5. Open **Options** and review retention and exclusion settings.

The collector is enabled automatically. Logged-in users, Do Not Track requests, non-GET requests, task endpoints, non-HTML formats, and configured components or paths are excluded by default.

## Default configuration

- Collection enabled
- Respect Do Not Track enabled
- Logged-in users excluded
- Query strings not stored
- Retention: 180 days
- Opportunistic cleanup probability: 2 percent per eligible request
- Country detection: local German ranges
- Excluded components: `com_ajax`, `com_users`, `com_simplestats`
- Excluded paths: `/administrator`, `/api`

### Reverse proxies

By default, the extension uses `REMOTE_ADDR`. A trusted client-IP header may be configured only when a reverse proxy controlled by the site operator overwrites that header. Never trust an arbitrary client-supplied forwarding header.

A trusted two-letter country header can also be used instead of the local German range database, but only when a trusted proxy supplies and overwrites it.

## Privacy notes

This extension is designed for data minimisation, but it does not claim automatic legal compliance. A daily visitor hash may still be considered pseudonymous personal data. Document the processing in the site's privacy notice, choose an appropriate retention period, and assess the configuration for the site's circumstances.

Enabling query-string storage can collect search terms and other user input. It is disabled by default. A small list of obviously sensitive parameter names is removed when enabled, but this cannot cover every application-specific parameter.

## Technical notes

- Target: Joomla 6.x
- Database: MySQL/MariaDB
- License: GPL-2.0-or-later
- No runtime JavaScript is required
- No Composer dependency is bundled
- Data table: `#__simplestats_events`
- Uninstalling the component drops the statistics table

## Known limitations

- Bot detection is based on User-Agent patterns and is not authoritative.
- Visitor-days are estimates and are not equivalent to unique people.
- German ranges are updated manually in version 0.1.2.
- Existing rows are not reclassified after a range update.
- The local country mode distinguishes only Germany from unknown/non-Germany.
- Requests served before the collector runs, for example by an earlier page-cache plugin, may not be recorded. Place the Simple Stats system plugin before a full-page cache plugin when necessary.
- Archive-specific events such as playback starts, downloads, and searches are not yet collected; existing Audio Archive counters remain separate.

## Data source

German IPv4 and IPv6 country ranges are downloaded from [IPdeny](https://www.ipdeny.com/), which provides country IP block downloads free of charge.
