# Punga Analytics for Joomla

Punga Analytics is a small, self-hosted statistics package for Joomla 6. It provides basic traffic and engagement information without Google Analytics, an external analytics account, analytics cookies, or third-party requests containing visitor data.

> **Package:** `pkg_pungaanalytics`

## Table of Contents

- [Package contents](#package-contents)
- [Collected information](#collected-information)
- [Retention and permanent reports](#retention-and-permanent-reports)
- [Page views](#page-views)
- [Custom event bridge](#custom-event-bridge)
  - [Configuring generic event definitions](#configuring-generic-event-definitions)
- [Cookieless sessions](#cookieless-sessions)
- [Logged-in users](#logged-in-users)
- [Country detection](#country-detection)
- [Network / ASN detection](#network--asn-detection)
- [Traffic-quality classification](#traffic-quality-classification)
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

- `com_pungaanalytics` — Administrator dashboard, configuration, database schema, and local country/ASN database maintenance
- `mod_pungaanalytics` — Compact statistics overview for the Joomla administrator Home Dashboard
- `plg_system_pungaanalytics` — Frontend page-view collector and custom-event listener

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
- Autonomous System number (ASN), or `0` when unavailable or disabled
- Primary browser language
- Broad device category
- Broad browser family
- Traffic-quality classification: human, suspected automated, or detected bot
- Conservative automation score and positive reason codes for retained non-bot traffic
- Broad bot name for detected bots
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

- Daily clean-human visitor-day/page-view, suspected-automation, detected-bot, authenticated-view, and generic custom-event totals
- Site-local hour-of-day and weekday totals, including configured custom events
- Countries, pages, referrers, traffic-source categories, browser languages,
  devices, browsers, and detected bots
- Not-found paths with separate human, suspected-automated, and detected-bot request totals, first and last
  occurrence, and the most common external referrer
- Custom-event types and generic item-level event totals
- Cookieless daily session totals and configured-event session participation for data archived by Punga Analytics 0.9.0 and later
- Daily ASN/network clean-human, suspected-automated, and detected-bot totals for data collected by Punga Analytics 0.11.0 and later
- Permanent country, network, path, and detection-reason summaries for suspected automation classified by Punga Analytics 0.11.0 and later

Dashboard queries combine retained raw events with the permanent reports.
Cleanup therefore does not reduce all-time totals, rewrite older charts, or
change dimension breakdowns. The cleanup probability only controls how soon an
eligible request performs this archival work; it does not decide which
statistics survive.

The dashboard's **System** tab also provides **Archive expired raw data** for
running the same safe archival process manually. It archives every eligible
complete day before removing its detailed rows.

## Page views

Ordinary public HTML page views are recorded automatically. This includes, subject to configured exclusions:

- Home and article pages
- Archive/list pages
- Module and component pages with their own public URL
- Basically all menu items

The **Most viewed pages** report groups these page views by path. Query strings are excluded by default, so filtered variants of one page normally remain grouped together.

A page view does not prove that embedded audio was played. Play and download counts use the custom-event bridge described below.

## Custom event bridge

Other Joomla extensions can record a semantic event by dispatching `onPungaAnalyticsRecord`. Punga Analytics listens when installed and enabled. The emitting extension does not need to require or import any Punga Analytics PHP class.

This enables extension developers to have any Joomla extension hook into Punga Analytics and record custom events.

### Event production

Event production and event presentation are deliberately separate:

- The source extension decides when a real action happened and dispatches the event.
- Punga Analytics options decide whether that identifier is accepted and where its totals appear.
- Adding a definition does not create a browser listener or infer an action from a page view.

Supported event arguments (using the extension _Audio Archive_ as example):

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
 * @brief Records an optional Punga Analytics event without creating a package dependency.
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

Then call the helper function at the two successful server-side action points:

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

You can configure which events to listen to:

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
| --- | --- | --- | --- | --- | --- | --- | --- |
| `audio.play` | Audio plays | `com_audioarchive` | Yes | Yes | Yes | Yes | Most played clips |
| `audio.download` | Audio downloads | `com_audioarchive` | Yes | Yes | Yes | Yes | Most downloaded clips |

Item rankings group by the generic `item_title`, `item_id`, `item_type`, and
`path` arguments. A producer that enables rankings should therefore send
`item_type` plus a stable `item_id` and preferably `item_title`. Events without
item data are still counted, but their ranking row is shown as an unknown item.

The history icon beside a ranking row opens an exact daily breakdown for that
same item identity and selected reporting range. The table combines retained
raw events with permanent daily item reports, so its total matches the ranking
count even after raw-event archival.

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

## Cookieless sessions

Punga Analytics 0.9.0 adds session analytics without setting a tracking cookie or
creating a persistent cross-day visitor identifier. Sessions are inferred from
recorded **human** events that share the existing daily visitor hash.

A new session begins when:

- the visitor has had no recorded event for more than 30 minutes, or
- site-local midnight is crossed and the daily visitor hash rotates.

The second rule is deliberate: sessions never link an anonymous browser across
calendar days. As with visitor-days, sessions remain privacy-conscious estimates;
the daily hash is derived from network/browser characteristics rather than a
persistent browser ID.

The Sessions dashboard reports:

- total sessions
- average session duration
- page views per session
- custom events per session
- single-page sessions and their rate among sessions containing page views
- sessions over time
- participation by every configured custom event

Configured custom events are handled generically. For each definition Punga
Analytics can report the total event count, sessions containing that event,
share of all sessions, sessions containing two or more occurrences, and events
per matching session. Identifiers such as `audio.play` have no special behavior.

Session data is derived directly from retained raw events. Before those raw rows
are removed, Punga Analytics 0.9.0 and later archives additive daily session
summaries into `#__pungaanalytics_daily_sessions` and
`#__pungaanalytics_daily_session_events`, so future cleanup does not erase the
session reports.

Event ordering cannot be reconstructed from the older aggregate tables. Therefore
complete days whose raw events had already been purged **before upgrading to
0.9.0** are unavailable for historical session analysis. Retained raw days become
available immediately after the update and will be preserved when they are later
archived.

## Logged-in users

Authenticated users on the public website are counted by default. Administrator backend requests are never collected.

The event table stores only whether the visitor was authenticated. It does not store their Joomla user ID. Specific accounts can be excluded under **Components → Punga Analytics → Options → Collection → Excluded Joomla user IDs**. This is useful for excluding the site owner while retaining statistics from ordinary registered visitors.

## Country detection

Country and ASN/network lookup settings share the **Components → Punga Analytics → Options → Location & Network** tab.

Punga Analytics can resolve all countries locally using the free monthly DB-IP Lite IP-to-Country database.

Use **Components → Punga Analytics → System → Update country database**
after installation. The action downloads the compressed CSV, streams it into
compact fixed-record IPv4 and IPv6 lookup files, and stores them under:

```text
cache/com_pungaanalytics/
```

It is recommended to do this right after installing Punga Analytics.

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

## Network / ASN detection

Punga Analytics 0.10.0 can resolve the visitor IP to an **Autonomous System
number (ASN)** locally using the free monthly **DB-IP ASN Lite** database. An ASN
identifies the network that announces an IP range on the Internet. Depending on
the address, this can reveal an ISP, mobile carrier, hosting company, cloud
provider, university, corporate network, or other network operator.

Use **Components → Punga Analytics → System → Update ASN database** after
upgrading to 0.10.0. The action downloads the compressed monthly ASN dataset and
compiles it into fixed-record IPv4/IPv6 lookup files plus a local ASN-to-name map
under:

```text
cache/com_pungaanalytics/
```

Lookups happen during event collection while the request IP is already available.
Punga Analytics stores only the resulting numeric ASN in the event row; it does
**not** retain the raw IP address. The network/organization name shown in reports
comes from the locally installed ASN database and is not duplicated into every
event.

The **Audience → Networks / ASNs** report shows, per ASN:

- clean-human visitor-days and page views
- suspected-automated visitor-days and page views
- detected-bot page views
- total page views in the full report
- the network/organization name from the installed DB-IP ASN database
- row-level daily history for clean-human visitor-days

Network type is only one signal in the separate traffic-quality classifier. A
hosting/cloud ASN by itself is deliberately insufficient to classify a visitor
as automated. This keeps ordinary users of cloud relays, VPNs, corporate
networks, or unusual infrastructure from being removed from human statistics
without corroborating behaviour.

ASN attribution is forward-only. Events collected before 0.10.0 have no stored
source IP and therefore cannot be backfilled. Likewise, if ASN detection was
disabled or no usable ASN database was installed when an event was recorded, its
ASN remains unknown. Future raw-event cleanup preserves resolved ASN totals in
`#__pungaanalytics_daily_networks`.

DB-IP ASN Lite is updated monthly and distributed under Creative Commons
Attribution 4.0. The required attribution is displayed in the Networks / ASNs
report.

## Traffic-quality classification

Punga Analytics 0.11.0 separates traffic into **Human**, **Suspected
automated**, and **Detected bot** categories. Detected bots continue to be detected
from explicit crawler/bot and automation-client User-Agent patterns. Suspected
automation is a separate conservative visitor-day classification for traffic
that looks human at the User-Agent level but exhibits multiple infrastructure
and behavioural signals.

The classifier currently considers signals such as:

- a hosting/cloud/datacenter ASN resolved by the local ASN database
- rapid traversal of many different public paths
- unusually high request rates
- unusually high page-view volume within one anonymous visitor-day
- repeated page views without any configured custom-event engagement

A hosting/cloud ASN contributes to the score but **never reaches the automation
threshold on its own**. Configured custom-event engagement reduces suspicion.
The classifier is intentionally biased toward leaving ambiguous traffic in the
human category rather than aggressively discarding legitimate visitors.

When a retained visitor-day crosses the threshold, its requests are marked as
suspected automated and are excluded from the normal human visitor, page-view,
page, country, acquisition, session, and configured-event totals. They are not
deleted: the **Traffic Quality → Suspected automation** area retains diagnostic
breakdowns by country, network, page, and detection reason. **Detected bots** remain
a separate child tab.

Classification can change while a visitor-day develops. Each new request
re-evaluates the complete retained anonymous visitor-day, so earlier requests in
that day move with the final classification. **System → Reclassify retained
traffic** can re-run the current rules against all raw data, for example after
updating the ASN database or changing configured engagement events.

Only retained raw visitor-days can be reclassified. Aggregates archived before
0.11.0 do not contain the request sequence required to distinguish suspected
automation retrospectively and therefore retain their historical classification.

## Dashboard

Administrator maintenance actions and child-tab/range state are wired independently of the former top-level tab widget as of 0.11.3.


The administrator component uses Joomla sidebar entries for **Overview**, **Traffic**, **Content & Engagement**, **Audience**, **Traffic Quality**, and **System**. Related secondary reports remain grouped as child tabs within those pages. The selected reporting range is remembered when moving between these sidebar pages.

The administrator dashboard includes:

- Installed Punga Analytics version
- Range picker for Today, Yesterday, Last 24 hours, 7, 30, 90, 365-day, and all-time reports
- Compact overview of clean-human visitor-days/page views plus separate suspected-automation and detected-bot totals
- Logged-in frontend page views
- Configurable custom-event overview totals
- Suspected-automated page views and detected-bot page views
- Adaptive day, ISO-week, or month activity trend for the complete selected range
- Site-local hour-of-day and weekday visitor bar charts with exact activity tables and compact fixed-bucket rendering
- Dedicated cookieless Sessions tab with summary metrics, sessions-over-time data, and configured-event session participation
- Sortable dashboard and paginated full-report tables with report-specific default ordering
- Most viewed pages
- Not-found (404) paths with human, suspected-automated, detected-bot, and combined request totals
- Broad acquisition categories for direct, search, social, AI-assistant, and external referral traffic
- Configurable generic item-ranking cards for events such as plays and downloads
- Countries pie chart, localized country names, Unicode flags, and exact table
- Networks / ASNs with clean-human, suspected-automated, and detected-bot columns plus organization names, full report, CSV, and daily row history
- External referrers
- Browser-language, device-category, and browser-family pie charts with exact tables
- Dedicated Traffic Quality area for suspected automation and detected bots
- Custom event types
- Row-level daily history links for pages, 404 paths, countries, networks/ASNs, sources, referrers, languages, devices, browsers, bots, event types, and configured event items
- Clearly labeled paginated full-report links on dashboard panels
- CSV export of every full report and the complete selected range
- One-click ZIP download containing every core CSV, session reports, and configured event-ranking CSVs
- Configurable row count for dashboard activity and custom-event ranking tables
- Retention plus country- and ASN-database status
- System-tab actions for updating country/ASN databases, reclassifying retained traffic, archiving expired raw data, and permanently resetting all collected statistics

The main administrator navigation is organised by question rather than storage
dimension. **Overview**, **Traffic**, **Content & Engagement**, **Audience**,
**Traffic Quality**, and **System** are Joomla sidebar entries rather than one
large page of top-level tabs. Traffic contains Activity, Sessions, and
Acquisition; Content & Engagement contains Pages & 404s, configured engagement
rankings, and Custom Events; Audience contains Countries, Networks / ASNs,
Languages, Devices, and Browsers; Traffic Quality separates Suspected automation
from Detected bots. Report back-links and range changes preserve the relevant child
tab.

Bot labels use the specific crawler name when recognised. Strong generic bot signatures
are reported as categories such as Generic crawler, Headless browser, Python Requests,
cURL, or No User-Agent instead of being collapsed into one `Other bot` bucket.

The **Activity by hour** and **Activity by weekday** panels are stacked vertically so
each chart and table gets the full dashboard width.

## Administrator dashboard module

The package includes a small administrator module for seeing the most useful
figures without opening the full component dashboard. It displays:

- Human visitor-days and page views
- Per-module selections from all globally configured custom events
- Suspected-automated page views and bot page views (each independently configurable)
- A configurable number of most-viewed pages with frontend links
- A direct link to the complete Punga Analytics dashboard

The module defaults to the last seven days. Its reporting range includes Today,
Yesterday, Last 24 hours, the normal multi-day ranges, and all time. Its custom-event
checkbox selection, suspected-automation total, bot total, top-pages list, and top-pages limit can be
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
- Dashboard preview rows (Activity over time, Sessions over time, and configured custom-event rankings): 8
- Default dashboard reporting range: 7 days
- Location & Network: country detection uses the local DB-IP Country Lite database; ASN/network detection uses the local DB-IP ASN Lite database
- Conservative suspected-automation detection enabled
- Excluded components: `com_ajax`, `com_users`, `com_pungaanalytics`
- Excluded paths: `/administrator`, `/api`

All component options and custom-event-definition settings include Joomla inline-help text. Use **Toggle Inline Help** in the Options toolbar to show or hide these descriptions.

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

1. Open **System → Install → Extensions** in Joomla Administrator
2. Upload `pkg_pungaanalytics_vx-x-x.zip`
3. Open **Components → Punga Analytics**
4. Open the **System** tab and click **Update country database**
5. Click **Update ASN database**
6. For an update from an earlier version with retained raw data, click **Reclassify retained traffic**
7. Review the options

Install newer versions directly over the existing package.

## Resetting statistics

Use **Components → Punga Analytics → System → Reset all statistics** to
permanently remove every detailed raw event and every permanent aggregate
report. A confirmation dialog is shown before anything is deleted.
Configuration and the downloaded country and ASN databases are retained.

## Uninstallation

Uninstalling the package removes:

- `#__pungaanalytics_events`
- `#__pungaanalytics_daily`
- `#__pungaanalytics_daily_dimensions`
- `#__pungaanalytics_daily_items`
- `#__pungaanalytics_daily_time`
- `#__pungaanalytics_daily_event_time`
- `#__pungaanalytics_daily_404`
- `#__pungaanalytics_daily_sessions`
- `#__pungaanalytics_daily_session_events`
- `#__pungaanalytics_daily_networks`
- `#__pungaanalytics_daily_automation`
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
- Frontend analytics collection requires no JavaScript; administrator UI JavaScript is used only for dashboard interaction
- Calendar dates, Today/Yesterday boundaries, hour/weekday buckets, session day boundaries, archival cutoffs, and displayed timestamps use the configured Joomla site timezone; canonical event timestamps remain stored in UTC
- Composer dependencies: none bundled
- Raw-event table: `#__pungaanalytics_events`
- Permanent report tables: `#__pungaanalytics_daily`,
  `#__pungaanalytics_daily_dimensions`, `#__pungaanalytics_daily_items`,
  `#__pungaanalytics_daily_time`, `#__pungaanalytics_daily_event_time`,
  `#__pungaanalytics_daily_sessions`, `#__pungaanalytics_daily_session_events`,
  `#__pungaanalytics_daily_networks`, `#__pungaanalytics_daily_automation`, and `#__pungaanalytics_daily_404`
- Country source: DB-IP Country Lite CSV
- ASN/network source: DB-IP ASN Lite CSV

## Known limitations

- Detected-bot detection is based on User-Agent patterns and is not authoritative. Suspected-automation detection is heuristic and deliberately conservative; it can produce false positives or false negatives.
- Visitor-days are estimates, not unique people.
- Existing Unknown country rows cannot be reclassified after a country-database update because raw IP addresses are not stored.
- Existing events cannot be assigned an ASN retrospectively after installing or updating the ASN database because raw IP addresses are not stored.
- ASN organization names and IP-to-ASN assignments are approximate Internet-routing metadata and can change over time; the report is diagnostic context, not proof that a request originated from a particular person or company.
- Traffic already archived before version 0.11.0 cannot be retrospectively separated into human and suspected-automated categories because visitor-level request sequences are not preserved in older aggregates.
- Country and ASN data must currently be updated manually.
- Requests served before the collector runs, for example by an earlier full-page cache plugin, may not be recorded.
- Punga Analytics cannot infer a media play from a page view; the media extension must emit a [custom event](#custom-event-bridge).
- Traffic-source categorisation is heuristic and depends on the referrer header supplied by the visitor's browser.
- Cookieless sessions cannot be reconstructed for days whose raw events were already archived before version 0.9.0, because the older aggregates do not preserve event ordering.


### Suspected automation tuning

Hosting/cloud infrastructure alone is not an automation verdict. When configured semantic engagement events exist, hosting/cloud traffic without any such engagement supplies a second signal and can be classified provisionally. Later engagement automatically re-evaluates the complete visitor-day.
