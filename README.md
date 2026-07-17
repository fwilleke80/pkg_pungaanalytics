# Simple Stats for Joomla

Simple Stats is a small, self-hosted statistics package for Joomla 6. It provides basic traffic and engagement information without Google Analytics, an external analytics account, analytics cookies, or third-party requests containing visitor data.

> **Current version:** `0.2.1`  
> **Package:** `pkg_simplestats`

## Package contents

- `com_simplestats` — administrator dashboard, configuration, database schema, and country-database maintenance
- `plg_system_simplestats` — frontend page-view collector and custom-event listener

The system plugin is enabled automatically when the package is installed.

## Collected information

For eligible frontend page views and custom events, Simple Stats stores:

- UTC timestamp and site-local calendar date
- Daily rotating visitor hash
- Public page path
- Joomla component and view name
- External referrer hostname for ordinary page views
- ISO 3166-1 alpha-2 country code, or `ZZ` when unknown
- Primary browser language
- Broad device category
- Broad browser family
- Human/bot classification and a broad bot name
- Logged-in status as a boolean value
- Event type
- Optional generic item type, item ID, and item title for custom events

It does **not** store:

- Raw IP addresses
- Complete User-Agent strings
- Complete referrer URLs
- Joomla user IDs with events
- Usernames or email addresses
- Persistent cross-day visitor identifiers
- Analytics cookies
- Arbitrary custom-event metadata

The visitor hash is an HMAC of the visitor IP address, User-Agent, site-local date, and Joomla site secret. It changes every local calendar day. The dashboard therefore reports **visitor-days**, not exact people or persistent sessions.

## Page views

Ordinary public HTML page views are recorded automatically. This includes, subject to configured exclusions:

- Home and article pages
- Archive/list pages
- Audio Archive clip detail pages
- Module and component pages with their own public URL

The **Most viewed pages** report groups these page views by path. Query strings are excluded by default, so filtered variants of one page normally remain grouped together.

A page view does not prove that embedded audio was played. Play and download counts use the custom-event bridge described below.

## Custom event bridge

Other Joomla extensions can record a semantic event by dispatching `onSimpleStatsRecord`. Simple Stats listens when installed and enabled. The emitting extension does not need to require or import any Simple Stats PHP class.

Supported event arguments:

| Argument | Required | Purpose |
| --- | --- | --- |
| `event_type` | Yes | Machine name up to 64 characters, such as `audio.play` |
| `component` | No | Source component, such as `com_audioarchive` |
| `view_name` | No | Joomla view name |
| `path` | No | Public page path associated with the event |
| `item_type` | No | Generic entity type, such as `audioarchive.clip` |
| `item_id` | No | Stable entity identifier |
| `item_title` | No | Human-readable entity title |

Example:

```php
use Joomla\CMS\Event\GenericEvent;

$dispatcher->dispatch(
	'onSimpleStatsRecord',
	new GenericEvent(
		'onSimpleStatsRecord',
		[
			'event_type' => 'audio.play',
			'component' => 'com_audioarchive',
			'view_name' => 'clip',
			'item_type' => 'audioarchive.clip',
			'item_id' => (string) $clipId,
			'item_title' => $clipTitle,
		]
	)
);
```

Recommended Audio Archive event types are:

- `audio.play`
- `audio.download`

Audio Archive can dispatch these events next to its existing aggregate play/download counter updates. If Simple Stats is not installed, the Joomla event is simply dispatched without a listener and Audio Archive continues normally.

## Logged-in users

Authenticated users on the public website are counted by default. Administrator backend requests are never collected.

The event table stores only whether the visitor was authenticated. It does not store their Joomla user ID. Specific accounts can be excluded under **Components → Simple Stats → Options → Collection → Excluded Joomla user IDs**. This is useful for excluding the site owner while retaining statistics from ordinary registered visitors.

## Country detection

Simple Stats can resolve all countries locally using the free monthly DB-IP Lite IP-to-Country database.

Use **Components → Simple Stats → Update country database** after installation. The action downloads the compressed CSV, streams it into compact fixed-record IPv4 and IPv6 lookup files, and stores them under:

```text
cache/com_simplestats/
```

Visitor IP addresses never leave the server. Only the administrator-triggered database download contacts DB-IP.

The dashboard reports country codes and, when PHP's Internationalisation extension is available, readable country names. IP geolocation is approximate and can be affected by VPNs, proxies, mobile gateways, privacy relays, and stale allocations.

DB-IP Lite is updated monthly and licensed under Creative Commons Attribution 4.0. Attribution is displayed in the dashboard.

## Dashboard

The redesigned administrator dashboard includes:

- Installed Simple Stats version
- Selectable 7, 30, 90, 365-day, and all-time ranges
- Human visitor-days and page views
- Logged-in frontend page views
- Audio plays and downloads received through custom events
- Bot page views
- Recent daily activity
- Most viewed pages
- Most played and downloaded items
- Countries
- External referrers
- Browser languages
- Device categories
- Browser families
- Detected bots
- Custom event types
- Retention and country-database status

## Default configuration

- Collection enabled
- Do Not Track respected
- Logged-in frontend users counted
- No user IDs excluded
- Query strings not stored
- Retention: 180 days
- Opportunistic cleanup probability: 2 percent per eligible event
- Country detection: local DB-IP Lite database
- Excluded components: `com_ajax`, `com_users`, `com_simplestats`
- Excluded paths: `/administrator`, `/api`

### Requests excluded from ordinary page-view collection

- Joomla administrator and API clients
- Non-GET requests
- Requests with a Joomla task
- Non-HTML formats
- Do Not Track requests when enabled
- Configured components and path prefixes
- Authenticated users when that optional collection setting is disabled
- Explicitly excluded Joomla user IDs

Task and non-GET requests may still create a **custom event** when an extension intentionally dispatches `onSimpleStatsRecord`. This allows AJAX playback counters and download controllers to report engagement without being misclassified as page views.

### Reverse proxies

By default, Simple Stats uses `REMOTE_ADDR`. A trusted client-IP header may be configured only when a reverse proxy controlled by the site operator overwrites that header. Never trust an arbitrary client-supplied forwarding header.

A trusted two-letter country header can be used instead of the local DB-IP database when a trusted proxy supplies and overwrites it.

## Installation and update

1. Open **System → Install → Extensions** in Joomla Administrator.
2. Upload `pkg_simplestats-0.2.1.zip`.
3. Open **Components → Simple Stats**.
4. Click **Update country database**.
5. Review the options and optionally exclude the site owner's Joomla user ID.

Install newer versions directly over the existing package. Version 0.2.1 fixes the DB-IP Country Lite CSV compiler and does not alter or remove collected statistics.

## Uninstallation

Uninstalling the package removes:

- `#__simplestats_events`
- Compiled country files and metadata under `cache/com_simplestats/`
- The component and system plugin

## Privacy notes

Simple Stats is designed for data minimisation, but it does not claim automatic legal compliance. A daily visitor hash can still be considered pseudonymous personal data. Document the processing in the site's privacy notice, select an appropriate retention period, and assess the configuration for the site's circumstances.

Enabling query-string storage can collect search terms and other user input. It is disabled by default. A short list of obviously sensitive parameter names is removed when enabled, but no generic filter can identify every application-specific sensitive value.

## Technical notes

- Target: Joomla 6.x
- Database: MySQL/MariaDB
- PHP: a version supported by Joomla 6
- License: GPL-2.0-or-later
- Runtime JavaScript: none required
- Composer dependencies: none bundled
- Data table: `#__simplestats_events`
- Country source: DB-IP Lite CSV

## Known limitations

- Bot detection is based on User-Agent patterns and is not authoritative.
- Visitor-days are estimates, not unique people.
- Existing rows are not reclassified after a country-database update.
- Country data must currently be updated manually.
- Requests served before the collector runs, for example by an earlier full-page cache plugin, may not be recorded.
- Simple Stats cannot infer a media play from a page view; the media extension must emit a custom event.
