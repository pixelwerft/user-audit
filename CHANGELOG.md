# Release Notes for User Audit

All notable changes to this plugin are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 1.2.2 - 2026-04-24

### Fixed
- Logs table distended the whole CP content area wider than the
  viewport on narrow windows instead of scrolling inside its own
  bounds. The table is now wrapped in a `.ua-table-scroll` element
  with `display: block`, `width: 100%`, `min-width: 0` and
  `overflow-x: auto`. The `min-width: 0` override is the critical
  piece — Craft's content pane is a flex container, and flex
  children default to `min-width: auto` which refuses to shrink
  below their intrinsic content width. Without that override the
  wrapper kept growing with the table and `overflow-x: auto` never
  triggered. Inner `.tableview` overflow is neutralized inside the
  wrapper, and `.data` / `.data.fullwidth` are set to `width: auto;
  min-width: 100%` so the table keeps its natural column widths
  and scrolls horizontally inside its own frame.

## 1.2.1 - 2026-04-23

### Fixed
- Plugin settings page threw `Twig\Error\SyntaxError` ("Unexpected
  endjs tag (expecting closing tag for the namespace tag defined
  near line 195)") because a JS comment inside a `{% js %}` block
  referenced Craft's wrapper tag literally as `{% namespace
  'settings' %}`. Twig parses the body of `{% js %}` blocks too —
  it's not a raw region — so the literal braces were read as a
  real tag opening. Comment rephrased without Twig syntax.

## 1.2.0 - 2026-04-23

### Fixed
- Monitor chart stayed blank when every row in the audit log had
  `client = NULL` (the common case on fresh installs where all
  activity is CP logins — CP requests don't carry a client type).
  The default query always appended `WHERE context IN (…) AND
  client IN (…)` which silently excluded NULL columns. Now
  "all options selected" is treated as "no filter on this
  dimension" and NULL-column rows are included; partial
  selections still exclude NULLs as operators expect.
- Stat cards on top of the Monitor page use the same rule so the
  header numbers match what's in the chart.

### Changed
- Page titles renamed:
  - *Logs* → *User audit logs* (DE: "User Audit Logs")
  - *Monitor* → *User audit monitor* (DE: "User Audit Monitor")
  The subnav items in the CP sidebar keep their short labels
  (*Logs*, *Monitor*) so the navigation stays compact.
- Monitor chart series regain per-line area fills at
  `fill-opacity: 0.08`. Up to five overlapping series stack
  additively without turning muddy, and on sparse data the
  faint tint surfaces which series has activity before the
  stroke alone would show it.
- All/None toggle in the filter dropdowns uses a slash separator
  (`All / None`) instead of a middle dot — reads more clearly as
  two opposite actions.
- German translations consolidated: dead strings from the
  removed *Active Sessions* page pruned, new filter/monitor
  strings added. Current state: 96 keys in code = 96 keys in
  `de/user-audit.php`, no missing, no orphans.

## 1.1.6 - 2026-04-23

### Added
- Monitor chart is now multi-series: one colored line per event type
  (`login`, `logout`, `login_failed`, `login_blocked`, `session_expired`).
  The line colors match the swatches next to each event checkbox in
  the filter dropdown, and a legend appears below the chart when more
  than one series is visible.
- Event / context / client checkbox dropdowns gain an *All* / *None*
  toggle at the top — one click to reset a dimension.
- Filter defaults: first-time visits now show every event / context /
  client pre-selected (full dataset out of the box). A hidden
  `_filters` sentinel lets the form legitimately submit "none selected"
  without being mistaken for a fresh page load.
- Hover tooltip lists every visible series' count at the hovered
  bucket with a matching color dot, plus a Σ total when more than one
  series is shown. A dashed vertical guide line and per-series accent
  dots highlight the active column.

### Fixed
- Nav: main "User Audit" item now stays highlighted (subnav open)
  while on the Monitor page. Parent URL was pointing at /logs, which
  made Craft's prefix-match consider /monitor an unrelated top-level
  URL. Restored to the plugin root.

## 1.1.5 - 2026-04-23

### Added
- Monitor: time-range options now include *This month*, *Last 30 days*
  and *Last 90 days* in addition to the existing 24 h / 48 h / 7 d.
  *This month* is calendar-bounded (1st of the current month → today);
  all other ranges are trailing windows.
- Monitor chart: Y-axis now shows 5 numeric ticks (0 → max event count)
  so the vertical scale is readable, not just implied.
- Monitor chart: interactive hover tooltip. Moving the cursor across
  the chart highlights the nearest bucket with an accent dot and shows
  a floating tooltip with the richer bucket label and event count.
- Event / Context / Client filters on the Monitor are now multi-select
  checkbox dropdowns — pick any combination, the chart updates
  accordingly. The range picker stays single-choice.

### Changed
- Monitor URL parameter switched from `?hours=N` to `?range=<key>`
  (`24h` / `48h` / `7d` / `month` / `30d` / `90d`). Old `?hours=` URLs
  are mapped to the nearest new range so existing bookmarks stay valid.
- Filter URL parameters are now arrays: `?event[]=login&event[]=logout`
  etc. Single-value URLs (`?event=login`) remain accepted.

### Fixed
- Smooth-curve chart no longer renders segments below the x-axis when
  a sudden drop to zero triggered a Catmull-Rom overshoot. Control
  points are now clamped inside the plot area.

## 1.1.4 - 2026-04-23

### Added
- *Reset to defaults* button at the bottom of the settings page. Refills
  every form field with its class-declared default without touching
  saved state — the reset is only persisted when the operator clicks
  Save afterwards, so it doubles as a safe preview of the defaults.

## 1.1.3 - 2026-04-23

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

## 1.1.2 - 2026-04-23

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

## 1.1.1 - 2026-04-22

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
