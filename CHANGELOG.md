# Release Notes for User Audit

All notable changes to this plugin are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.2.0 - 2026-04-22

### Added
- `client` column on `{{%user_activity_log}}`: stores `pwa` or `browser`
  based on the `X-Reest-Client` request header. Indexed, filterable in the
  CP viewer.
- `userGroups` column: comma-separated snapshot of the user's group handles
  at login time. Searchable.
- `recordClientType` setting (lightswitch): when off, the column is left
  NULL and the client filter is hidden.
- CP index: settings-button in the top-right, live filter (debounced 350 ms,
  activates from 2 characters), new Client and Groups columns, extended
  search across email/groups/IP/browser+version/OS+version/device/
  failureReason/eventType/client.

### Changed
- Settings page: default strings are now English; German translations live
  in `src/translations/de/user-audit.php`.
- CSV export now includes `client` and `userGroups` columns.

### Fixed
- CSV export previously returned `ERR_INVALID_RESPONSE` because the stream
  callable didn't yield. Rewritten as a generator yielding CSV lines.

## 1.1.0 - 2026-04-22

### Added
- `context` column (`cp` / `fe`). Control-panel logins are tagged `cp`,
  frontend logins `fe`, console/custom events stay NULL.
- Per-context recording toggles (`recordCpEvents`, `recordFrontendEvents`).
  Disabled contexts are skipped before the log write, not filtered later,
  so purge and stats queries stay accurate.
- Context filter and badges in the CP viewer.
- Mail subject uses the new translation channel.

## 1.0.0 - 2026-04-22

### Added
- Initial release.
- Automatic logging of `login`, `logout`, `login_failed`, `login_blocked`,
  `session_expired`.
- Custom events API (`ActivityLogService::log`).
- Regex-only user-agent parser (device type, OS, browser) — zero runtime
  dependencies.
- CP viewer (index, filters, paginated), active-sessions view, CSV export.
- Dashboard widget: 24h logins/logouts/failed + top-5 failing IPs.
- Retention purge console command (`user-audit/purge/run`).
- Failed-login throttling (sliding-window, per IP and per email).
- Throttle reset console command (`user-audit/throttle/reset`).
- New-location email alerts (configurable lookback).
- Session-expired endpoint for frontend-initiated entries.
- User-facing recent-activity JSON endpoint.
- Permission `user-audit-view` for viewer access.
