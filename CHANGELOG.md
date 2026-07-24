# Changelog

## 0.6.0 — 2026-07-24

- Added repeatable generic custom-event definitions in the component options.
- Added open or configured-only recording policies, per-event recording switches, and optional source-component restrictions.
- Made Overview totals, activity chart/table series, hour and weekday columns, item-ranking cards, full reports, sorting, and CSV exports independently configurable per event.
- Added the generic `#__simplestats_daily_event_time` archive so configured event time reports survive raw-event cleanup.
- Migrated already-recorded custom event types into editable generic ranking definitions on update.
- Removed runtime Audio Archive special cases; Audio Archive now uses the same public bridge and configuration model as every other producer.
- Documented the complete definition workflow and producer payload contract.

## 0.5.6 — 2026-07-24

- Reorganized Overview, Traffic, Time distribution, Content, Engagement, Audience and technology, and System into clean collapsible cards.
- Used native `details` and `summary` elements so every card works with keyboard and assistive technology without JavaScript.
- Kept Overview and Traffic open initially while leaving detailed report groups compact.
- Automatically reopened the relevant card after sorting one of its tables.
- Automatically opened System when the country database is missing or unreadable.
- Added distinct section icons, modern card headers, clear focus states, responsive spacing, and contained inner report panels.
- Removed an extra malformed table closing tag from the Top pages panel.
- Registered a fresh versioned stylesheet so the redesigned dashboard loads immediately.

## 0.5.5 — 2026-07-24

- Rendered the active ascending or descending arrow directly in each sortable heading instead of relying on generated CSS content.
- Used one shared group-center coordinate for every activity bar cluster, date tick, and date label.
- Distributed up to eight X-axis labels across the complete plot and reserved enough space to prevent the first or last date from being clipped.
- Added a contained horizontal chart viewport on narrow screens so labels remain legible without overflowing the dashboard.
- Registered a fresh versioned stylesheet so the corrected arrows and chart geometry load immediately.

## 0.5.4 — 2026-07-24

- Replaced dashboard JavaScript sorting with server-side sorting controlled by table, column, and direction URL parameters.
- Made every dashboard sort link reload directly at its table instead of navigating to the top of the page.
- Rendered the active ascending or descending indicator and `aria-sort` state on the server.
- Removed the dashboard sorting script and its Joomla asset registration completely.
- Kept the existing server-side full-report sorting, pagination, and CSV ordering intact.

## 0.5.3 — 2026-07-24

- Replaced button-like sortable headers with plain table-heading links and compact direction arrows.
- Made the dashboard sorter initialize both before and after `DOMContentLoaded`, fixing inactive headers on Joomla pages that load the asset late.
- Replaced embedded eyebrow labels with standalone semantic section headings for Overview, Traffic, Time distribution, Content, Engagement, Audience and technology, and System.
- Promoted the dashboard and full-report hero titles to proper top-level headings.
- Registered fresh versioned administrator assets so the corrected markup, JavaScript, and heading design cannot be hidden by stale caches.

## 0.5.2 — 2026-07-24

- Made every dashboard data table sortable by clicking its column headings.
- Added server-side full-report sorting before pagination, with the selected order preserved in CSV exports.
- Defaulted Activity over time to newest-first while retaining ascending hour and weekday order.
- Removed the redundant full-report link from the complete seven-row weekday panel.
- Applied the configurable dashboard row count to the optional audio-play and audio-download tables.
- Reworked eyebrow labels into unmistakable section markers and added a fresh versioned stylesheet.
- Relabeled and restyled dashboard report links as “Full report / CSV” so the existing reporting and export features are easy to find.

## 0.5.1 — 2026-07-24

- Prevented adjacent final date labels from overlapping by distributing at most eight labels evenly across the activity chart.
- Added a configurable dashboard limit for the recent rows shown beneath the activity chart; the default is eight and zero shows the complete range.
- Replaced the misleading hour and weekday page-view meters with exact numeric values.
- Restyled dashboard eyebrow labels as visible section headings with a strong accent marker.
- Converted the country, language, device, and browser doughnut charts into solid pie charts without a center hole.
- Made audio-play and audio-download metrics self-activating optional reports, based on the corresponding custom event types in raw or archived data.
- Registered a new version-specific administrator stylesheet so the fixes are not hidden by browser or Joomla asset caches.

## 0.5.0 — 2026-07-24

- Added adaptive activity trends: daily buckets up to 90 days, ISO-week buckets up to one year, and monthly buckets for longer and all-time ranges.
- Added exact site-local hour-of-day and weekday reports for visitors, page views, audio plays, downloads, and bots.
- Added permanent hourly and weekday aggregates so time-of-day reports survive raw-event cleanup.
- Added full paginated reports for activity, time, pages, audio items, countries, referrers, languages, devices, browsers, bots, and custom event types.
- Added UTF-8 CSV export for every full report and the complete selected date range.
- Added `simplestats_recorded` bridge acknowledgement for diagnostics and verified the custom-event dispatch, validation, insertion, archival, and reporting path.
- Reconstructed weekdays for existing raw events and permanent daily reports while leaving historical hour values explicitly unknown.
- Updated the package, component, plugin, administrator assets, language strings, schema, and documentation to version 0.5.0.

## 0.4.0 — 2026-07-23

- Added transactional archival of expired raw events into permanent daily totals, dimension breakdowns, and custom-event item reports.
- Serialised maintenance with a site-specific database advisory lock to prevent concurrent cleanup requests from aggregating the same events twice.
- Updated every dashboard query to combine retained raw events with archived reports, preserving all-time totals, charts, countries, top pages, referrers, device/browser data, and audio engagement statistics after cleanup.
- Changed automatic and manual cleanup to remove raw rows only after their aggregates have been stored successfully.
- Extended Reset all statistics and uninstallation to remove the aggregate tables as well as raw events.
- Clarified the raw-event retention and cleanup-probability settings in English and German.
- Added Unicode country flags beside localized country names in the Countries table.
- Standardised component and plugin namespaces on the `Willeke` vendor prefix used by Audio Archive.
- Added a 0.4.0 schema update that creates the three permanent report tables without altering existing event data.

## 0.3.4 — 2026-07-23

- Added a compact doughnut chart with color-and-percentage legend to the Countries panel.
- Used localized country names in chart tooltips and legends while retaining ISO country codes in the detailed table.

## 0.3.3 — 2026-07-23

- Registered the administrator stylesheet under a version-specific asset name to bypass stale Joomla asset-registry entries.
- Fixed doughnut charts at 12.5 rem (normally 200 × 200 pixels), with intrinsic SVG dimensions as a cache-resistant fallback.
- Added compact color-and-percentage legends below the browser-language, device, and browser charts.
- Added intrinsic SVG color swatches to the daily chart legend and dimension tables.

## 0.3.2 — 2026-07-23

- Replaced inline-CSS doughnut charts with server-rendered SVG charts so browser languages, devices, and browsers render reliably under Joomla's content security policy.
- Arranged the daily-chart legend in five evenly spaced columns and colored every label to match its bar series.
- Styled section-category cues as compact badges so they are visually distinct from the actual heading hierarchy.
- Renamed the administrator stylesheet again to ensure the chart fixes load immediately after updating.

## 0.3.1 — 2026-07-23

- Renamed the administrator stylesheet to force browsers and intermediate caches to load the chart styles after updating.
- Expanded the daily activity chart across the complete dashboard width and increased its plot height and bar spacing.
- Added a confirmed **Reset all statistics** toolbar action that permanently removes all collected events while preserving configuration and the country database.
- Added a clear Countries-panel note that only newly recorded events can receive country codes after a database update.

## 0.3.0 — 2026-07-23

- Replaced the six tall overview cards with one compact, responsive statistics table.
- Added a grouped daily-activity bar chart while retaining the exact daily table.
- Added doughnut charts with exact legends for browser languages, devices, and browsers.
- Normalised IPv4-mapped IPv6 visitor addresses before country lookup.
- Added a safe automatic `X-Forwarded-For` fallback when `REMOTE_ADDR` is private or reserved.
- Added validation of compiled country lookup files and clearer diagnostics for all-Unknown reports.
- Explained that existing Unknown rows cannot be backfilled because raw visitor IP addresses are never stored.
- Expanded the custom-event documentation with a complete, dependency-free Audio Archive integration example and the correct play/download hook locations.
- Fixed the Joomla database schema-check warning by using `MODIFY event_type` rather than `MODIFY COLUMN event_type`.
- Added the 0.3.0 schema update marker without removing collected data.

## 0.2.1 — 2026-07-17

- Fixed DB-IP Country Lite CSV parsing. The official file contains three fields (`ip_start`, `ip_end`, `country`), while version 0.2.0 incorrectly expected a fourth field and rejected every valid row.
- Added UTF-8 BOM handling for the first IP field.
- Improved malformed-database diagnostics with read, accepted, and rejected row counts.
- Added compiler and binary-lookup tests covering IPv4 and IPv6 records.

## 0.2.0 — 2026-07-17

- Added local all-country detection through the monthly DB-IP Country Lite CSV database.
- Added generic `onSimpleStatsRecord` custom events for integrations such as audio plays and downloads.
- Added custom-event fields for event type, item type, item ID, and item title.
- Counted authenticated frontend visitors by default while retaining configurable user exclusions.
- Redesigned the administrator dashboard and added the installed extension version.
- Added country, authenticated-traffic, custom-event, most-played, and most-downloaded reports.
- Preserved all existing page-view data during the schema update.

## 0.1.2 — 2026-07-17

- Fixed the Joomla update schema path. Update schema paths are resolved relative to the installed administrator component directory and therefore must use `sql/updates/mysql`, not `admin/sql/updates/mysql`.
- Retained source-package-relative paths for initial install and uninstall SQL files.
- Added a 0.1.2 schema update file as an additional repair path for missing installations.

## 0.1.1 — 2026-07-17

- Initial Joomla 6 package.
- Added privacy-minimal frontend page-view collection.
- Added daily rotating visitor hashes without raw IP storage.
- Added human and bot classification.
- Added top pages, referrers, languages, devices, browsers, and bot reports.
- Added local Germany-only IP range matching for IPv4 and IPv6.
- Added configurable exclusions, retention, Do Not Track handling, and cleanup.
- Added English and German administrator language files.
