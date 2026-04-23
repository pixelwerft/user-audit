# Release Notes for User Audit

All notable changes to this plugin are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.4.0 - 2026-04-23

### Added
- Subnav under the *User Audit* control-panel item:
  - **Logs** — the filterable, paginated event list (previously the root).
  - **Monitor** — a dedicated activity dashboard with stat cards and a
    smooth-curve time-series chart. Clicking the main nav item lands on
    Logs.
- Monitor view: filterable by event, context and client (same dropdowns
  as the log list) plus a time-window picker (24 h / 48 h / 7 d). Buckets
  auto-switch between hourly and daily based on the window.
- Log list: column headers are now sort links (↑ / ↓ / ↕). Sort column
  and direction are preserved across filter changes and pagination.

### Changed
- `/user-audit/active` now redirects to the Monitor view.

### Removed
- The standalone *Active Sessions* page — its role is absorbed by the
  Monitor dashboard.

## 1.3.0 - 2026-04-23

### Changed
- **Breaking:** Viewer access is now admin-only by default. The previous
  `user-audit-view` permission has been removed. Any CP user who should
  keep access must either be an admin or be a member of a group listed
  under the new *Access → Allowed user groups* setting.
- Settings page: new *Access* section at the top with a user-group picker
  (stores group UIDs, safe to deploy via project config).

### Migration
- On upgrade, non-admin users lose access until the deploying admin opens
  *Settings → Plugins → User Audit* and whitelists the relevant CP user
  group(s). Admins are unaffected.

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
