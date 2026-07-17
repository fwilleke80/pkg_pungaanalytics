# Changelog

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
