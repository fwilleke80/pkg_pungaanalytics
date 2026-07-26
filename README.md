# Punga Analytics for Joomla

Punga Analytics is a small, self-hosted statistics package for Joomla 6. It provides basic traffic and engagement information without Google Analytics, an external analytics account, analytics cookies, or third-party requests containing visitor data.

> **Current version:** `0.8.1`
>
> **Package:** `pkg_pungaanalytics`

## Table of Contents

- [Package contents](#package-contents)
- [Collected information](#collected-information)
- [Retention and permanent reports](#retention-and-permanent-reports)
- [Page views](#page-views)
- [Custom event bridge](#custom-event-bridge)
  - [Configuring generic event definitions](#configuring-generic-event-definitions)
- [Logged-in users](#logged-in-users)
- [Country detection](#country-detection)
- [Dashboard](#dashboard)
- [Administrator dashboard module](#administrator-dashboard-module)
- [Default configuration](#default-configuration)
  - [Requests excluded from ordinary page-view collection](#requests-excluded-from-ordinary-page-view-collection)
  - [Reverse proxies](#reverse-proxies)
- [Installation and update](#installation-and-update)
- [Resetting statistics](#resetting-statistics)
- [Uninstallation](#uninstallation)
- [Privacy notes](#privacy-notes)
- [Technical notes](#technical-notes)
- [Known limitations](#known-limitations)

## Package contents

- `com_pungaanalytics` — administrator dashboard, configuration, database schema, and country-database maintenance
- `mod_pungaanalytics` — compact statistics overview for the Joomla administrator Home Dashboard
- `plg_system_pungaanalytics` — frontend page-view collector and custom-event listener

The system plugin is enabled automatically when the package is installed. A
published Punga Analytics module is also created on the administrator Home
Dashboard. Existing configured module instances and their settings are never
overwritten during an update.

## Collected information

For eligible frontend page views and custom events, Punga Analytics stores:

- UTC timestamp, site-local calendar date, hour, and weekday
- Daily rotating visitor hash
- Public page path
- Joomla component and view name
- External referrer hostname for ordinary page views
- Broad traffic-source category for ordinary page views: direct, search,
  social, AI assistant, referral, or internal
- Final HTTP response status for ordinary page views
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

## Retention and permanent reports

Detailed raw events are retained for the configured number of complete
site-local calendar days. When cleanup runs, Punga Analytics first converts every
expired complete day into permanent aggregate reports inside one database
transaction. Only after every aggregate succeeds are the corresponding raw
event rows removed.

The permanent reports preserve:

- Daily visitor-day, page-view, authenticated-view, bot, and generic custom-event totals
- Site-local hour-of-day and weekday totals, including configured custom events
- Countries, pages, referrers, traffic-source categories, browser languages,
  devices, browsers, and detected bots
- Not-found paths with separate human and bot request totals, first and last
  occurrence, and the most common external referrer
- Custom-event types and generic item-level event totals

Dashboard queries combine retained raw events with the permanent reports.
Cleanup therefore does not reduce all-time totals, rewrite older charts, or
change dimension breakdowns. The cleanup probability only controls how soon an
eligible request performs this archival work; it does not decide which
statistics survive.

## Page views

Ordinary public HTML page views are recorded automatically. This includes, subject to configured exclusions:

- Home and article pages
- Archive/list pages
- Audio Archive clip detail pages
- Module and component pages with their own public URL

The **Most viewed pages** report groups these page views by path. Query strings are excluded by default, so filtered variants of one page normally remain grouped together.

A page view does not prove that embedded audio was played. Play and download counts use the custom-event bridge described below.

## Custom event bridge

Other Joomla extensions can record a semantic event by dispatching `onPungaAnalyticsRecord`. Punga Analytics listens when installed and enabled. The emitting extension does not need to require or import any Punga Analytics PHP class.

Event production and event presentation are deliberately separate:

- The source extension decides when a real action happened and dispatches the event.
- Punga Analytics options decide whether that identifier is accepted and where its totals appear.
- Adding a definition does not create a browser listener or infer an action from a page view.

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

Complete example:

```php
use Joomla\CMS\Event\GenericEvent;
use Joomla\CMS\Factory;

/**
 * Records an optional Punga Analytics event without creating a package dependency.
 *
 * @param string $eventType Event type.
 * @param object $clip      Audio Archive clip.
 *
 * @return void
 */
private function recordPungaAnalyticsEvent(string $eventType, object $clip): void
{
	$eventName = 'onPungaAnalyticsRecord';
	$dispatcher = Factory::getApplication()->getDispatcher();

	$statsEvent = new GenericEvent(
		$eventName,
		[
			'subject' => $this,
			'event_type' => $eventType,
			'component' => 'com_audioarchive',
			'view_name' => 'clip',
			'item_type' => 'audioarchive.clip',
			'item_id' => (string) $clip->id,
			'item_title' => (string) $clip->title,
		]
	);

	$dispatcher->dispatch($eventName, $statsEvent);

	// Optional diagnostic only. A missing listener leaves this argument unset.
	$recorded = (bool) $statsEvent->getArgument('pungaanalytics_recorded', false);
}
```

Then call the helper at the two successful server-side action points:

```php
$this->recordPungaAnalyticsEvent('audio.play', $clip);
$this->recordPungaAnalyticsEvent('audio.download', $clip);
```

For Audio Archive, `audio.play` belongs in the controller or Ajax endpoint that
accepts the player’s confirmed play-counter request. `audio.download` belongs
in the download controller after the clip and access permission have been
validated, immediately before the file response is sent.

The event bridge runs in PHP. A browser’s HTMLMediaElement `play` event does not
reach Joomla by itself, so the Audio Archive player must already make a request
to its counter endpoint or add one. Do not dispatch `audio.play` merely while
rendering a player; that would count impressions rather than actual plays.

The following shorter form is equivalent when the dispatcher is already
available:

```php
use Joomla\CMS\Event\GenericEvent;

$dispatcher->dispatch(
	'onPungaAnalyticsRecord',
	new GenericEvent(
		'onPungaAnalyticsRecord',
		[
			'subject' => $this,
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

Audio Archive can dispatch these events next to its existing aggregate play/download counter updates. If Punga Analytics is not installed, the Joomla event is simply dispatched without a listener and Audio Archive continues normally.

### Configuring generic event definitions

Open **Components → Punga Analytics → Options → Custom events**. The
**Recording policy** has two modes:

- **Record all valid custom events** preserves the open bridge. A valid event
  is stored even if it has no definition; it remains visible in **Custom event
  types**.
- **Record configured events only** turns the definitions into an allowlist.
  Undefined identifiers are rejected and `pungaanalytics_recorded` is `false`.

Add one repeatable **Event definition** for every event that needs dedicated
presentation. The fields have these meanings:

| Setting | Effect |
| --- | --- |
| Event identifier | Exact `event_type` from the producer, for example `audio.play` |
| Display title | Label used in overview totals, trend series, and time columns |
| Required source component | Optional `component` allowlist value, for example `com_audioarchive` |
| Record this event | Accept or reject new events with this identifier |
| Show overview total | Add a metric to the Overview card |
| Show in activity trend | Add a series and table column to Activity over time |
| Show in hour and weekday reports | Add a column to both time-distribution tables and their full reports/CSV files |
| Show item ranking | Add an Engagement card table plus a sortable full report and CSV |
| Ranking card title | Dashboard heading, for example `Most played clips` |
| Full report title | Heading used on the event’s full-report page |
| Chart color | Color of the event’s activity-series bars |
| Summary icon | Joomla icon shown with its Overview metric |

For Audio Archive, a useful configuration is:

| Event identifier | Display title | Component | Overview | Trend | Time | Ranking | Ranking title |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `audio.play` | Audio plays | `com_audioarchive` | Yes | Yes | Yes | Yes | Most played clips |
| `audio.download` | Audio downloads | `com_audioarchive` | Yes | Yes | Yes | Yes | Most downloaded clips |

Item rankings group by the generic `item_title`, `item_id`, `item_type`, and
`path` arguments. A producer that enables rankings should therefore send
`item_type` plus a stable `item_id` and preferably `item_title`. Events without
item data are still counted, but their ranking row is shown as an unknown item.

Changing presentation switches does not delete statistics. Disabling
**Record this event** stops new matching events while preserving existing raw
and archived counts. Removing a definition removes its dedicated metrics,
columns, and item report; under the open recording policy, the identifier can
still be collected and remains in the generic event-type report.

Any event type matching `[a-z][a-z0-9._-]{0,63}` can be used. `pageview` is
reserved. Every dispatch creates one row; the source extension decides what
constitutes one event and should avoid duplicate dispatches.

Punga Analytics writes the boolean `pungaanalytics_recorded` argument back onto the
mutable `GenericEvent`. It is `true` when the event was accepted and stored and
`false` when Punga Analytics rejected it or an insert failed. This is useful for
diagnostics and integration tests. Audio Archive should not make its own
playback or download behavior depend on the flag because it is absent when
Punga Analytics is not installed or the plugin is disabled.

Version 0.7.0 also listens for the former `onSimpleStatsRecord` event and writes
its former `simplestats_recorded` acknowledgement. This compatibility alias
keeps existing integrations working through the rebrand. New and updated
integrations should dispatch `onPungaAnalyticsRecord` and read
`pungaanalytics_recorded`.

## Logged-in users

Authenticated users on the public website are counted by default. Administrator backend requests are never collected.

The event table stores only whether the visitor was authenticated. It does not store their Joomla user ID. Specific accounts can be excluded under **Components → Punga Analytics → Options → Collection → Excluded Joomla user IDs**. This is useful for excluding the site owner while retaining statistics from ordinary registered visitors.

## Country detection

Punga Analytics can resolve all countries locally using the free monthly DB-IP Lite IP-to-Country database.

Use **Components → Punga Analytics → Update country database** after installation. The action downloads the compressed CSV, streams it into compact fixed-record IPv4 and IPv6 lookup files, and stores them under:

```text
cache/com_pungaanalytics/
```

Visitor IP addresses never leave the server. Only the administrator-triggered database download contacts DB-IP.

IPv4-mapped IPv6 addresses are normalised before lookup. When the web server
reports a private or reserved reverse-proxy address in `REMOTE_ADDR`, Punga
Analytics can automatically use the rightmost public address from
`X-Forwarded-For`. With a proxy that has a public address, configure the exact
server variable under **Trusted client-IP header** so the collector never
blindly trusts a client-supplied forwarding header.

Country resolution is forward-only. Updating the database cannot repair old
`Unknown` rows because their original IP addresses were deliberately never
stored. The dashboard now verifies that the compiled files match the installed
metadata and explains how to diagnose an all-Unknown report.

The dashboard reports country codes and, when PHP's Internationalisation extension is available, readable country names. IP geolocation is approximate and can be affected by VPNs, proxies, mobile gateways, privacy relays, and stale allocations.

DB-IP Lite is updated monthly and licensed under Creative Commons Attribution 4.0. Attribution is displayed in the dashboard.

## Dashboard

The redesigned administrator dashboard includes:

- Installed Punga Analytics version
- Modern range picker for Today, Yesterday, Last 24 hours, 7, 30, 90,
  365-day, and all-time reports
- Compact tabular overview of human visitor-days and page views
- Logged-in frontend page views
- Configurable custom-event overview totals
- Bot page views
- Adaptive day, ISO-week, or month activity trend for the complete selected range
- Site-local hour-of-day and weekday visitor bar charts with exact activity tables
- Sortable dashboard and full-report table columns with report-specific default ordering
- Most viewed pages
- Not-found (404) paths with human, bot, and combined request totals
- Broad acquisition categories for direct, search, social, AI-assistant, and
  external referral traffic
- Configurable generic item-ranking cards for events such as plays and downloads
- Countries pie chart, localized country names, Unicode flags, and exact table
- External referrers
- Browser-language, device-category, and browser-family pie charts with exact tables
- Detected bots
- Custom event types
- Row-level history links for pages, 404 paths, countries, sources, referrers,
  languages, devices, browsers, bots, event types, and configured event items
- Clearly labeled paginated full-report links on dashboard panels
- CSV export of every full report and the complete selected range
- One-click ZIP download containing every core CSV plus configured event-ranking CSVs
- Configurable row count for dashboard activity and custom-event ranking tables
- Retention and country-database status
- Confirmed toolbar action for permanently resetting all collected statistics

## Administrator dashboard module

The package includes a small administrator module for seeing the most useful
figures without opening the full component dashboard. It displays:

- Human visitor-days and page views
- Per-module selections from all globally configured custom events
- Bot page views
- A configurable number of most-viewed pages with frontend links
- A direct link to the complete Punga Analytics dashboard

The module defaults to the last seven days. Its reporting range includes Today,
Yesterday, Last 24 hours, the normal multi-day ranges, and all time. Its custom-event
checkbox selection, bot total, top-pages list, and top-pages limit can be
changed independently for every module instance. Existing module instances
initially retain the former selection of events enabled for the component
Overview; saving an explicit checkbox selection replaces that compatibility
fallback. The package creates and publishes one instance in the administrator
`cpanel` position when no Punga Analytics module exists yet.

## Default configuration

- Collection enabled
- Do Not Track respected
- Logged-in frontend users counted
- No user IDs excluded
- Query strings not stored
- Detailed raw-event retention: 180 complete local calendar days
- Opportunistic archival probability: 2 percent per recorded event
- Dashboard activity and custom-event ranking tables: 8 rows
- Default dashboard reporting range: 7 days
- Country detection: local DB-IP Lite database
- Excluded components: `com_ajax`, `com_users`, `com_pungaanalytics`
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

Task and non-GET requests may still create a **custom event** when an extension intentionally dispatches `onPungaAnalyticsRecord`. This allows AJAX playback counters and download controllers to report engagement without being misclassified as page views.

### Reverse proxies

By default, Punga Analytics uses `REMOTE_ADDR`. It considers `X-Forwarded-For`
automatically only when the direct address is private or reserved and chooses
the rightmost public address. A different trusted client-IP header may be
configured only when a reverse proxy controlled by the site operator overwrites
that header. Never configure an arbitrary client-supplied forwarding header.

A trusted two-letter country header can be used instead of the local DB-IP database when a trusted proxy supplies and overwrites it.

## Installation and update

1. Open **System → Install → Extensions** in Joomla Administrator.
2. Upload `pkg_pungaanalytics_v0-8-1.zip`.
3. Open **Components → Punga Analytics**.
4. Click **Update country database**.
5. Review the options and optionally exclude the site owner's Joomla user ID.

Install newer versions directly over the existing package. Version 0.3.0 also
repairs the Joomla database-maintenance definition for `event_type`; it does not
alter or remove collected statistics.

When 0.7.0 is installed over SimpleStats, the package automatically migrates the
statistics tables, component options, and local country database,
enables the Punga Analytics collector, and removes the old component, plugin,
and package records. Existing raw events and permanent reports remain intact.
The legacy custom-event name remains accepted so an integration can be updated
independently.

Versions 0.3.1 and later use versioned administrator stylesheets to bypass
stale browser and server caches. Version 0.3.2 also renders the browser
language, device, and browser doughnut charts as SVG so they work under
restrictive content security policies. Version 0.3.3 and later register their stylesheet
under a version-specific asset name to avoid stale Joomla asset-registry entries.

Version 0.4.0 creates permanent daily aggregate tables. Existing raw events are
left untouched during the update and remain fully visible. On the next eligible
cleanup, expired complete days are archived before their raw rows are removed.
Data already deleted by an earlier Punga Analytics version cannot be reconstructed.

Version 0.5.0 adds permanent site-local hour and weekday aggregates, adaptive
long-term trends, full paginated reports, and CSV export. Weekdays are
backfilled from the calendar date of both existing raw events and permanent
daily reports. Exact hours were not stored by older versions, so hour-of-day
reporting begins with events collected after updating to 0.5.0. This avoids
inventing historical time-of-day data.

Version 0.5.1 refines the dashboard presentation, defaults its activity table
to the eight most recent periods, and makes the audio report family activate
automatically from recorded bridge events. It does not remove or rewrite any
statistics.

Version 0.5.2 adds sortable dashboard and full-report tables, applies the
dashboard row setting to the optional audio tables, and makes full reports and
their CSV exports visibly discoverable from the dashboard. Full-report sorting
is applied before pagination and is also preserved in CSV exports.

Version 0.5.3 replaces button-like dashboard sort controls with ordinary table
heading links and compact direction arrows. It also initializes dashboard
sorting reliably whether the script loads before or after the document-ready
event, and promotes the former eyebrow labels to standalone visual and semantic
section headings.

Version 0.5.4 replaces dashboard JavaScript sorting with the same server-side
URL pattern used by Audio Archive. Sort links preserve the selected date range,
reload at the affected table, and expose the active direction through both a
small arrow and `aria-sort`. Punga Analytics no longer ships dashboard JavaScript.

Version 0.5.5 renders sort arrows as explicit HTML so they remain visible
independently of generated CSS content. It also gives every activity-chart
label and its bar group one shared X coordinate, reserves horizontal edge
space for complete dates, and keeps the chart readable inside a horizontally
scrollable viewport on narrow screens.

Version 0.5.6 reorganizes the dashboard into modern native collapsible cards.
Overview and Traffic are open initially, while detailed sections remain compact
until requested. Sorting automatically reopens the section that contains the
selected table, and System opens when the country database needs attention.
The card controls use semantic headings and native keyboard-accessible
`details` and `summary` elements without JavaScript.

Version 0.6.0 replaces the audio-specific report switches with repeatable,
generic custom-event definitions. Administrators can allowlist identifiers,
optionally restrict their source component, and independently place each event
in the Overview, activity trend, time-distribution reports, or an item-ranking
card with a full sortable report and CSV export. Custom-event hour and weekday
data now use a generic permanent archive table. Existing recorded event types
are converted into editable ranking definitions during update; no statistics
are deleted.

Version 0.7.0 rebrands the complete extension as Punga Analytics. Component,
plugin, package, language, media, namespace, cache, and database identifiers now
use `pungaanalytics`, with PHP classes under `Punga\Component\PungaAnalytics`
and `Punga\Plugin\System\PungaAnalytics`. The dashboard adds a selected-state
range dropdown, an all-reports CSV ZIP, and a larger set of event-summary
icons. The version remains available in the System card but is no longer
repeated in the dashboard header.

Version 0.7.1 adds a compact administrator dashboard module with configurable
range, Overview event totals, bot traffic, most-viewed pages, and a direct link
to the full analytics dashboard. Joomla's new unconfigured module instance is
completed automatically, or one published Home Dashboard instance is created
when no instance exists.

Version 0.7.2 restores the requested human-visitor bar charts above the
hour-of-day and weekday tables, changes the full dashboard and report fallback
range from 30 to 7 days, and gives the collapsible-card titles their distinct
accent color. The existing exact tables and full reports remain available
below and through the chart panels.

Version 0.7.3 makes the hour-of-day and weekday charts use the exact same
visual system as the existing Activity over time bar chart: chart container,
legend, scrolling viewport, grid and axes, label typography, visitor color,
bar corners, and responsive behavior are shared instead of independently
styled.

Version 0.7.4 also gives those charts the Activity over time chart's exact SVG
dimensions, aspect ratio, plot margins, plot height, and bar width. This keeps
the charts compact inside their half-width panels and prevents their axis text
from being scaled larger than the traffic chart.

Version 0.7.5 corrects the administrator stylesheet URIs for both the component
and Home Dashboard module. It gives the module properly spaced two-column
metrics, a smaller Most viewed pages heading, and clickable frontend page
links. Hard-coded Overview-event behaviour is replaced by a dynamic checkbox
list of every globally configured custom event, with independent selections
per module instance and a compatibility fallback for existing instances.

Version 0.7.6 completes the Home Dashboard module styling with the same visual
separation used by Joomla's built-in administrator cards: a divider beneath the
module title and a consistent inset between the module content and card border.

Version 0.8.0 adds Today, Yesterday, and exact rolling Last 24 hours ranges,
row-level history charts and tables, broad traffic-source categorisation, and
a dedicated 404 report. Page views are now recorded immediately before Joomla
sends the response so the collector can store the final HTTP status. The raw
retention minimum is two days so a rolling 24-hour query remains exact.

Traffic-source categories deliberately use only the external referrer hostname;
Punga Analytics still does not store full referrer URLs. Internal navigation is
classified separately and omitted from the acquisition report. Traffic-source
history is complete for events collected by 0.8.0 and later. Existing archived
external referrer hostnames are categorised during update, but older blank
archived referrers cannot reliably be divided into direct and internal traffic.

Version 0.8.1 corrects final HTTP-status detection for Joomla error documents.
Joomla can place a status such as `404 Not Found` in its special `Status`
response header while the PSR-7 response object's numeric status remains 200.
The collector now reads that header first and retains the response object and
`http_response_code()` as fallbacks. Newly requested nonexistent paths are
therefore recorded correctly for both human and bot traffic. Reliable 404
history begins with version 0.8.1; requests misclassified by version 0.8.0
cannot be reconstructed from the stored data.

## Resetting statistics

Use **Components → Punga Analytics → Reset all statistics** in the toolbar to
permanently remove every detailed raw event and every permanent aggregate
report. A confirmation dialog is shown before anything is deleted.
Configuration and the downloaded country database are retained.

## Uninstallation

Uninstalling the package removes:

- `#__pungaanalytics_events`
- `#__pungaanalytics_daily`
- `#__pungaanalytics_daily_dimensions`
- `#__pungaanalytics_daily_items`
- `#__pungaanalytics_daily_time`
- `#__pungaanalytics_daily_event_time`
- `#__pungaanalytics_daily_404`
- Compiled country files and metadata under `cache/com_pungaanalytics/`
- The component, administrator module, and system plugin

## Privacy notes

Punga Analytics is designed for data minimisation, but it does not claim automatic legal compliance. A daily visitor hash can still be considered pseudonymous personal data. Document the processing in the site's privacy notice, select an appropriate retention period, and assess the configuration for the site's circumstances.

Enabling query-string storage can collect search terms and other user input. It is disabled by default. A short list of obviously sensitive parameter names is removed when enabled, but no generic filter can identify every application-specific sensitive value.

## Technical notes

- Target: Joomla 6.x
- Database: MySQL/MariaDB
- PHP: a version supported by Joomla 6
- License: GPL-2.0-or-later
- Runtime JavaScript: none required
- Composer dependencies: none bundled
- Raw-event table: `#__pungaanalytics_events`
- Permanent report tables: `#__pungaanalytics_daily`,
  `#__pungaanalytics_daily_dimensions`, `#__pungaanalytics_daily_items`,
  `#__pungaanalytics_daily_time`, `#__pungaanalytics_daily_event_time`, and
  `#__pungaanalytics_daily_404`
- Country source: DB-IP Lite CSV

## Known limitations

- Bot detection is based on User-Agent patterns and is not authoritative.
- Visitor-days are estimates, not unique people.
- Existing Unknown country rows cannot be reclassified after a country-database update because raw IP addresses are not stored.
- Country data must currently be updated manually.
- Requests served before the collector runs, for example by an earlier full-page cache plugin, may not be recorded.
- Punga Analytics cannot infer a media play from a page view; the media extension must emit a custom event.
- Exact hour-of-day data is unavailable for events recorded before version 0.5.0.
- Traffic-source categorisation is heuristic and depends on the referrer header
  supplied by the visitor's browser.
- Traffic-source history is complete from version 0.8.0 onward. Older archived
  external referrers are categorised during update, but old blank referrers
  cannot be distinguished as direct or internal.
- 404 responses recorded before version 0.8.1, including requests misclassified
  by version 0.8.0, cannot be reconstructed.
