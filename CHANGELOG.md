# Changelog

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
