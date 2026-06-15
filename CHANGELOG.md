# Release Notes for User Audit

All notable changes to this plugin are documented in this file. The format
is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 2.2.2 - 2026-06-12

### Fixed
- `ActivityLogService.php` was unparseable with
  `ParseError: syntax error, unexpected identifier "ctype"` because
  the docblock above `computePasswordStrengthFlags()` contained a
  literal `mb_*/ctype-style` — the `*/` inside terminated the
  docblock prematurely and PHP tried to parse the rest of the
  comment as code. Rewrote the docblock without an embedded `*/`
  sequence and grepped the rest of the v2.2 PHP surface for the
  same pitfall (none found). Should never have shipped — adding a
  PHP-lint pre-commit check to the project standards as follow-up.

## 2.2.1 - 2026-06-12

### Fixed
- Logs index and Monitor templates carried hardcoded event-type
  lists that did not include `password_changed`, so v2.2.0
  password-change events:
  - showed with the generic purple fallback status pill on the
    logs list instead of the violet `Pwd changed` pill,
  - were missing from the event-type filter dropdown on both
    pages (no way to filter to / unfilter from them through the
    UI).
  The PHP-side `MONITOR_EVENT_COLORS` constant did know about the
  new type, but the templates iterate their own Twig-side option
  lists; both lists are now in sync with the event-type set.

## 2.2.0 - 2026-06-12

### Added
- Password change logging. Two new hooks on `craft\elements\User`
  capture password set/change events:
  - `EVENT_BEFORE_SAVE` reads the still-plaintext `$user->newPassword`,
    derives five Boolean classifications (`meetsMin8`, `hasUpper`,
    `hasLower`, `hasDigit`, `hasSpecial`), an integer `length` and a
    `score` (0–5), and parks them in a request-local map keyed by
    user id.
  - `EVENT_AFTER_SAVE` pops the entry and writes a
    `password_changed` audit row with the flags in
    `metadata.passwordStrength`. AFTER_SAVE only fires on successful
    saves, so failed validations leave no audit trail (and the
    pending entry is discarded at request end).
  - Self-changes log `triggeredBy = null`; admin-initiated changes
    log the actor's user id.
- New element status `pwd_changed` with violet pill on the index.
- New source-sidebar entry **Password changes** under "By event".
- New status-filter option, sortable column, native-search target.
- Monitor chart picks up `password_changed` automatically as a
  violet (`#8b5cf6`) line, parallel to the other event-type lines.
- New plugin setting `recordPasswordChanges` (default on). Disable
  to skip both hooks entirely — no entries written, no plaintext
  observed.
- Detail-view page renders a dedicated *Password strength* card
  for `password_changed` rows: one tinted box per flag, the
  integer score, plus the `triggeredBy` actor id if not a
  self-change. Non-password events keep the existing JSON
  metadata pre-block.

### Privacy
- The plaintext password is observed in exactly one place
  (`ActivityLogService::computePasswordStrengthFlags`) and only
  long enough to derive the five booleans + length. The string is
  never stored, copied, hashed (we leave that to Craft), returned,
  logged or sent off-instance. The pending-changes map and the
  audit row only hold the derived flags.

## 2.1.2 - 2026-05-05

### Fixed
- Logs index threw `Integrity constraint violation 1052: Column
  'dateCreated' in order clause is ambiguous` because the v2.1.0
  LEFT JOIN onto `{{%elements}}` introduced a second `dateCreated`
  into scope and the controller's ORDER BY used the unqualified
  column. `buildQuery()` now qualifies every WHERE / ORDER BY
  reference with the resolved audit-table name.
- Logs index also returned a row containing only `elementDateDeleted`
  (and nothing else) because `addSelect()` on a Yii AR query with
  `select === null` *replaces* the implicit `SELECT *` instead of
  appending to it. Both `actionIndex` and `actionExport` now use an
  explicit `select()` that includes `<audit_table>.*` plus the
  aliased `elementDateDeleted`.
- Declared `UserActivityLog::$elementDateDeleted` as a public
  property so AR's `populateRecord()` accepts the aliased column
  via `canSetProperty()`.

## 2.1.1 - 2026-05-05

### Fixed
- Logs index threw `SQLSTATE[42000] 1064 syntax error` because the
  v2.1.0 elements LEFT JOIN nested `{{%user_activity_log}}` inside a
  `[[…]]` quoting block. Yiis quoter unwraps `[[col]]` and
  `{{%table}}` separately and refuses to nest them, so the literal
  `[[`user_activity_log`.elementId]]` string ended up in the SQL.
  Resolves the table name with `getRawTableName()` first and embeds
  the raw name. Same fix applied to `actionExport`.

## 2.1.0 - 2026-05-05

### Changed
- Walked back the v2.0 element-index UX. The CP logs page at
  `/admin/user-audit` is back to a hand-rolled filter bar at the top
  + single-table query + custom table, like v1.x — but with the
  status-pill column ported over from v2.0 (login=green,
  logout=gray, login_failed=orange, login_blocked=red,
  session_expired=blue, custom=fuchsia) and the time column made
  click-through to the read-only detail view at
  `/admin/user-audit/log/<elementId>`. The element-index source
  sidebar on the left is gone.
- Filter bar gained an *Include archive* checkbox. Off by default —
  rotated (soft-deleted) entries are hidden so the live activity
  stays the focus. On → trashed rows show in the list with a
  *Rotated* badge next to the timestamp and a dimmed row.
- AuditLog elements are no longer indexed by Crafts search engine
  (`defineSearchableAttributes()` returns an empty array).
  ActivityLogService writes are no longer slowed down by a
  search-index job per row.

### Why the walk-back
The v2.0 element-index needed two joins on `user_activity_log` plus
left-joins on `elements` and `elements_sites` per page load, which
became the dominant cost on large audit tables (5+ table scans
where v1.3.2 had one). The custom filter form covers the same
filtering need with single-table queries and stays snappy at 100k+
rows. The element layer underneath stays intact — element rows,
elementId FK, Craft-Trash soft-delete, hard-purge console command
and CSV `deleted_at` column are unchanged from v2.0.

### Notes
- No data migration is required. v2.0 → v2.1 is purely a UI swap
  on top of the same schema.

## 2.0.1 - 2026-05-05

### Fixed
- v2.0 conversion migration crashed with
  `UnknownMethodException: ...::stdout()` when run from the CP web
  updater. `stdout()` is a `craft\console\Controller` method, not a
  `craft\db\Migration` method — the CLI runner injects one at
  runtime but the web updater
  (`UpdaterController::actionMigrate`) does not. Replaced with a
  local `note()` helper that uses plain `echo` (captured by both
  runners) and mirrors to `Craft::info()` for persistent log.
- The crash happened before any audit row was backfilled, so the
  schema half (elementId column + unique index + FK) was applied
  but no element rows were created. v2.0.1 re-running the same
  migration is idempotent — it skips the schema-add steps and
  proceeds straight to the backfill.

## 2.0.0 - 2026-05-05

### Breaking
- The audit log has been promoted to a first-class **Craft element
  type**. Existing audit rows are migrated automatically: the install
  migration adds an `elementId` FK to `{{%user_activity_log}}` and
  back-fills one element per existing row in 500-row batches.
  Resumable on interruption — rerun `./craft up` if it stops mid-way.
  Plan for upgrade time roughly proportional to row count
  (~10 ms/row direct insert).
- `./craft user-audit/purge/run` no longer hard-deletes. It now
  **soft-deletes** via Crafts element trash (sets `dateDeleted`).
  Rotated rows disappear from the default index but remain queryable
  via the new "Soft-deleted" source and exportable via CSV with the
  new `deleted_at` column.
- Hard-delete is now an **explicit, console-only operation**:
  `./craft user-audit/purge/hard --before=YYYY-MM-DD [--user-id=N]`.
  Refuses to run without at least one filter, prompts for
  confirmation interactively, and warns that hard-deleted rows are
  unrecoverable and will not appear in any subsequent CSV export.
- The CSV export now contains an additional trailing `deleted_at`
  column (empty for live rows, ISO timestamp for soft-deleted ones).
  Downstream importers that hard-coded the column count must adjust.

### Added
- Standard Craft element-index UI for `/admin/user-audit`:
  source-sidebar with quick filters (All, by event type, by context,
  Soft-deleted archive), status pills coloured by event type
  (login=green, logout=gray, login_failed=orange,
  login_blocked=red, session_expired=blue, custom=fuchsia), sortable
  sticky-header table, native search index, ajax pagination, column
  picker that remembers per-user preference, keyboard navigation.
- New read-only detail view at `/admin/user-audit/log/<elementId>`,
  reachable by clicking the title in the index. Shows identity,
  context, network, user-agent (parsed + raw) and the JSON metadata
  payload of custom events. Soft-deleted entries display a banner
  with the rotation timestamp. "Show user trace" button jumps to
  the per-user dashboard when `userId` is set.
- `./craft user-audit/purge/hard` console command (see Breaking).

### Changed
- `ActivityLogService::log()` now writes the element shell first and
  then the audit row in a single DB transaction, so a save-failure
  on either side leaves no orphans behind. Public signature
  unchanged — callers feel nothing.
- The legacy hand-rolled filter form, ad-hoc sortable headers and
  custom pagination on the logs index were removed; their job is
  handled by the standard Craft element-index layout now.

### Notes for v2.0 release planning
- v2.0 ships option **α** for the detail view (full-page detail
  reachable via title-click). Slideout option β
  (Element-Editor-based) was dropped from the v2.0 scope to avoid
  fighting Crafts new element editor — page-level detail is robust
  and a follow-up release can iterate on slideout polish.

## 1.3.2 - 2026-04-29

### Changed
- Logs list page size lowered from 100 to 50 entries per page,
  matching Craft's element-index default. The Twig render of 11
  columns × per-row macros at 100 rows was the dominant cost on
  the page; halving it cuts log-list render time roughly in half
  without losing useful density.

### Performance
- User trace page now executes a single roll-up query for the six
  headline stats (total, logins 24h/7d, failed 24h/total, blocked
  total) via conditional `SUM(CASE WHEN …)` aggregation, replacing
  six separate `COUNT(*)` round-trips. The CASE-WHEN form stays
  portable across MySQL/MariaDB/Postgres.
- User trace login heatmap (7 × 24 buckets over 90 days) is now
  bucketed at the database level with a single
  `GROUP BY weekday, hour` query — result set is bounded to ≤168
  rows regardless of how active the user is. v1.3.1 pulled every
  login row of the last 90 days into PHP and counted them in a
  foreach, which turned the page into a multi-second load for
  power users with thousands of login events. Driver-specific
  weekday expressions (`WEEKDAY+1` on MySQL/MariaDB,
  `EXTRACT(ISODOW)` on Postgres) keep the 1=Mon..7=Sun mapping
  identical to the previous PHP-side `format('N')`.

## 1.3.1 - 2026-04-27

### Changed
- *Email / Login* column in the Logs list is now also a link to the
  user trace page when the row has a `userId`. Failed-login rows
  without a matched user keep showing the email as plain text.

## 1.3.0 - 2026-04-24

### Added
- User trace page at `/admin/user-audit/user/<userId>` — drill into
  the full activity log of a single user. Reachable by clicking the
  user-id in the *User* column of the Logs list.
- Identity card surfacing user name, email, group memberships and
  account state (active / suspended / locked). Deleted Craft users
  remain inspectable via the email + groups snapshot stored on the
  user's last audit row.
- Headline stat cards: total events, logins (24h / 7d), failed logins
  (24h / total), blocked attempts (total).
- Last-login and last-failed-login summary lines including IP,
  device, OS and browser context.
- Login pattern heatmap: 7 (Mon-Sun) × 24 (hours of day) grid showing
  when this user actually uses the system over the last 90 days.
  Cell color intensity scales with the bucket count; hover reveals
  the exact time-slot and count.
- Top-IPs / top-devices / top-browsers boxes (last 90 days).
- Sortable, paginated activity log (50 per page) scoped to the user.

### Changed
- *User* column in the Logs list is now a link when `userId` is set;
  failed logins without a matching user keep showing a plain `—`.

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
