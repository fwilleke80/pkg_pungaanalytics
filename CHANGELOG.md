# Changelog

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
