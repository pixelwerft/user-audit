# User Audit for Craft CMS

Audit log for user activity in Craft: login, logout, failed logins, session
expiries and arbitrary custom events. Captures IP, device type, operating
system, browser, client type (PWA vs. browser) and group membership snapshot
at login time.

Written for operators who need to answer questions like *who logged in when,
from where, on what device, as member of which groups, and did anyone try to
break in?* — without stitching it together from three different log files.

## Features

- **Automatic events**: `login`, `logout`, `login_failed`, `login_blocked`,
  `session_expired` — logged via Craft's `EVENT_AFTER_LOGIN` /
  `EVENT_AFTER_LOGOUT` / `EVENT_LOGIN_FAILURE` hooks.
- **Custom events API**: other modules/plugins can write their own event types
  into the same table (`property_exported`, `user_locked`, ...).
- **Per-row metadata**:
  - IP address
  - User agent (stored raw)
  - Parsed device type, OS (name + version), browser (name + version)
  - Context: `cp` (Craft control panel) or `fe` (frontend)
  - Client: `pwa` (if the `X-Reest-Client: pwa` header is sent) or `browser`
  - User groups snapshot (comma-separated handles at login time)
  - Free-form JSON metadata column for custom event payloads
- **Control-panel viewer**: searchable/filterable index, live filter (debounced
  350 ms, from 2 chars), active-sessions view, CSV export (streamed, UTF-8
  BOM for Excel).
- **Dashboard widget**: 24h logins / logouts / failed + top-5 failing IPs.
- **Failed-login throttling**: sliding-window rate limit per IP and per email.
  Exceeding the limit returns HTTP 429 and creates a `login_blocked` audit
  entry for visibility.
- **New-location mail**: notifies users by email when they log in from an IP
  that hasn't been seen for them within the configured lookback window.
- **Retention**: console command deletes entries older than N days.
- **User-facing activity list**: JSON endpoint so a frontend can show each
  user their own login history (the PWA in this project ships a `/konto` view
  consuming it).

## Installation

Via Craft's plugin store:

```
./craft plugin/install user-audit
```

Or via Composer:

```
composer require pixelwerft/user-audit
./craft plugin/install user-audit
```

Grant the **View activity log** permission in *Users → Permissions* to any
admin user who should see the CP viewer.

## Settings

Available under *Settings → Plugins → User Audit*:

| Setting | Default | Effect |
| --- | --- | --- |
| Record control panel events | on | Log `/admin` logins |
| Record frontend events | on | Log PWA/public-site logins |
| Record client type | on | Read `X-Reest-Client` header, store `pwa`/`browser` |
| Retention (days) | 0 | 0 = never purge. Otherwise purge during `user-audit/purge/run` |
| Throttling | off | Rate-limit failed logins |
| Failures per IP | 10 | Max failed logins per IP within the window |
| Failures per email/login | 5 | Max failed logins per login name within the window |
| Window (minutes) | 15 | Sliding window for the throttling counter |
| New-location alerts | off | Send email when a user logs in from a new IP |
| Lookback (days) | 90 | How far back an IP counts as "seen" |

## Console commands

```bash
# retention purge
./craft user-audit/purge/run
./craft user-audit/purge/run --dry-run=1

# unblock a throttled IP / email
./craft user-audit/throttle/reset --ip=1.2.3.4
./craft user-audit/throttle/reset --email=foo@example.com
```

Recommended cron (every 30 minutes) for retention:

```
*/30 * * * * cd /path/to/craft && php craft user-audit/purge/run >/dev/null 2>&1
```

## Custom events

Other parts of your application can write into the same table:

```php
use pixelwerft\useraudit\UserAudit;

UserAudit::getInstance()->activityLog->log(
    'property_exported',
    $user->id,
    [
        'email'    => $user->email,
        'metadata' => ['propertyId' => $property->id, 'format' => 'pdf'],
    ],
);
```

Accepted `$meta` keys:

| Key | Type | Notes |
| --- | --- | --- |
| `email` | string | Login name / email for the entry |
| `userGroups` | string \| string[] | Snapshot of group handles |
| `failureReason` | string | For failed-login / blocked rows |
| `ipAddress` | string | Override — otherwise read from the request |
| `userAgent` | string | Override — otherwise read from the request |
| `context` | `'cp'` \| `'fe'` | Override — otherwise detected |
| `client` | `'pwa'` \| `'browser'` | Override — otherwise detected from header |
| `metadata` | array | JSON-encoded into the `metadata` column |

Event-name collisions with core constants (`login`, `logout`, `login_failed`,
`login_blocked`, `session_expired`) are allowed — use what's meaningful to
your caller.

## Client-type detection (PWA vs. browser)

The plugin reads the `X-Reest-Client` request header. If the value is `pwa`,
the entry's `client` column is set to `pwa`; for any frontend request
without the header, it's `browser`. Control-panel and console requests leave
the column NULL.

To enable PWA attribution, have your frontend send the header on its
login/logout requests. Example for fetch:

```js
fetch('/actions/users/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-Reest-Client': 'pwa',
    'X-CSRF-Token': csrf,
    'X-Requested-With': 'XMLHttpRequest',
  },
  credentials: 'include',
  body: JSON.stringify({ loginName, password }),
})
```

The header name is fixed — if you need a different name, fork the plugin or
open a PR.

## Failed logins without a matching user

Every failed attempt is recorded as `login_failed`, including those where
the login name didn't match any existing user. In that case:

- `userId` = NULL
- `email` = the exact string the attacker typed
- `failureReason` = the Craft auth-error code (e.g. `invalid_credentials`,
  `account_locked`)

This is intentional: if someone is iterating through a list of candidate
addresses, you want the list visible in the log.

## Database

Table: `{{%user_activity_log}}`. Schema is owned by the plugin — no FKs
outside of a `SET NULL` to `{{%users}}` so audit rows survive user deletion
(important for compliance).

## License

MIT — see [LICENSE.md](LICENSE.md).
