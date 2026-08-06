# Handover — v2.3.0 in Arbeit

Session-Übergabe für die nächste Claude-Session am `user-audit`-Plugin.
Dieses Dokument ist die vollständige Kontextquelle — die Session, die
das Ganze eingerichtet hat, ist nicht mehr verfügbar.

**Status im Repo:** WIP-Commit `f93ce47` (Schema-Migration für Feature 1
liegt drin, kein Runtime-Code hängt dran). HEAD = `<siehe git log>`.
`origin/main` ist auf **v2.2.4** — nichts von v2.3.0 wurde gepusht.
Kein v2.3.0-Tag existiert.

---

## Auftrag

User will vier neue Features als **v2.3.0** ausrollen, plus saubere
Craft-Plugin-Doku. User-Zitat: *"wichtig: es darf nicht knallen nach
der installation, alles muss funktionieren!"* — Testdisziplin ist
non-negotiable.

Nach Feature-Ship: **kein Push, kein Tag** ohne explizites Go des Users.

## Vier Features (Reihenfolge egal, alle unabhängig)

### Feature 1 — Session-Lifecycle mit Duration (WIP: Schema done)

**Spec:**
- ✅ Migration + Install.php: `sessionId VARCHAR(36) NULL` mit Index
- ⬜ `ActivityLogService::log()`: neuer optionaler `$meta['sessionId']`,
  wird als eigener Column-Wert persistiert (nicht ins metadata-JSON)
- ⬜ `UserActivityLog` Record: `sessionId`-Property + docblock @property
- ⬜ Login-Hook (`EVENT_AFTER_LOGIN`): `$sessionId = StringHelper::UUID();`
  → in Craft-Session unter Key `'userAudit.sessionId'` ablegen → via
  `$meta['sessionId']` an `log()` weitergeben
- ⬜ Logout-Hook: **muss auf `EVENT_BEFORE_LOGOUT` refactoren** —
  `EVENT_AFTER_LOGOUT` feuert *nach* `session->destroy()`, die
  sessionId wäre dann schon weg. Bei BEFORE_LOGOUT sind Identity UND
  Session-Daten noch da. Innerhalb des Hooks: sessionId aus Session
  lesen, `login`-Row via Query auflösen (`WHERE sessionId = ? AND
  eventType = 'login'` LIMIT 1), Duration berechnen (`time() -
  strtotime(loginRow.dateCreated)`), an `$meta['metadata']
  ['sessionDurationSeconds']` hängen.
- ⬜ `AuditLogQuery`: neue Filter-Property `$sessionIdFilter` (NICHT
  `$sessionId` nennen — LSP-Falle wie bei v2.2.4-`$search`), mit
  Setter. Danach mit Class-Load-Check verifizieren.
- ⬜ Detail-View (`log.twig`): neuen Card-Block "Session" bei Logout-
  Rows mit Duration formatiert (`gmdate('H:i:s', $seconds)`) + Link
  auf Session-Übersicht.
- ⬜ Neue Action `actionSession(string $sessionId)` + Template — zeigt
  alle Rows die diese sessionId teilen (typischerweise 1 login + 1
  logout, aber Custom-Events können auch dazwischen liegen).
- ⬜ Neue Route: `user-audit/session/<sessionId:[a-f0-9-]+>` →
  `user-audit/activity/session`.

**Gotcha:** `session_expired` events tragen keine sessionId — sind
client-seitig detektiert vom PWA, ohne Server-Session-Bezug. Bewusst
so, dokumentieren.

### Feature 2 — Impersonation-Logging

**Spec:**
- Neue Konstanten in `ActivityLogService`:
  `EVENT_IMPERSONATION_STARTED = 'impersonation_started'`
  `EVENT_IMPERSONATION_STOPPED = 'impersonation_stopped'`
- Detection-Strategie (bevorzugt):
  Im bestehenden `EVENT_AFTER_LOGIN`-Hook prüfen:
  `$imp = Craft::$app->getUser()->getImpersonator();`
  Falls `$imp !== null` UND `$imp->id !== $identity->id` →
  event type ist `impersonation_started`, `metadata.impersonatorId
  = $imp->id`. Sonst normales `login`.
- Für Stopped: Craft's UsersController hat eine Action wie
  `actionImpersonate` und ein Gegenstück zum Zurückkehren. Der
  Rückkehr-Weg fires wieder AFTER_LOGIN mit dem original Admin als
  identity, PLUS es gibt eine Session-Info über den vorher-
  impersonierten User (Craft merkt sich `previousUserId` in der
  Session). Detektion:
  1. Im BEFORE_LOGIN oder BEFORE_ACTION-Hook auf UsersController
     mit action.id === 'return-from-impersonated-user' (oder ähnlich —
     genauen Namen in Craft 5 verifizieren!) einen Marker setzen
  2. Im AFTER_LOGIN dann diesen Marker verwenden, event type
     `impersonation_stopped` schreiben, `metadata.wasImpersonatingUserId
     = <old id>`
  Alternative simpler Weg: einfach nur `_started` loggen, `_stopped`
  weglassen. Erste Iteration reicht das. Deutlich cleaner Diff.
- Status-Pill `amber` (color-key), Element-Statuses erweitern
  (`STATUS_IMPERSONATION`), source-sidebar-Item "Impersonation",
  statusCondition in AuditLogQuery, MONITOR_EVENT_COLORS, plus
  index.twig-`statusPillMap` und event-dropdown UND monitor.twig
  `eventOptions` (**Lehre aus v2.2.1**: an ALLE fünf Stellen denken —
  Element, Query-statusCondition, Monitor-PHP-const, Twig-statusPillMap,
  Twig-event-select).

### Feature 3 — Suspicious-Activity-Score

**Spec:**
- Neue Methode auf `ActivityLogService`:
  ```php
  public function computeRiskScore(int $userId, ?string $ip, ?string $deviceType, ?string $browserName): array
  ```
  Rückgabe: `['score' => int 0..5, 'signals' => string[]]`
- Fünf Signale (jedes +1):
  1. `new_location` — via existing `isNewLocation($userId, $ip, 90)`
  2. `unusual_hour` — Query: wieviele Logins hatte dieser User in
     (weekday × hour of current time) in den letzten 90 Tagen?
     Wenn < 3 → `unusual_hour`.
  3. `new_device` — Query: hatte dieser User schon mal einen Login mit
     dieser deviceType+browserName-Combo in den letzten 90 Tagen?
     Wenn nein → `new_device`.
  4. `preceded_by_failures` — `login_failed`-Count von dieser IP ODER
     dieser Email in den letzten 15 Minuten. Wenn >= 1 → Signal.
  5. `ip_blacklist` — Distinct-Count der userIds die von dieser IP
     `login_failed` hatten in den letzten 30 Tagen. Wenn >= 3 → Signal.
- Angewandt **nur bei `EVENT_LOGIN`** (nicht bei impersonation, nicht
  bei password_changed). Im AFTER_LOGIN-Hook nach den bestehenden
  Snapshot-Steps.
- Persistiert in `metadata.riskScore` (int) + `metadata.riskSignals`
  (string[]).
- Neue AuditLog-Element-Table-Attribute `riskScore` (nicht default-
  sichtbar, im column-picker wählbar). Sortable.
- Filter im walked-back Index-Template: neue Checkbox "Show
  suspicious only" (query-param `risk=1`) — führt zu WHERE clause
  auf `JSON_EXTRACT(metadata, '$.riskScore') >= 3`. **Portabilität:**
  MySQL/MariaDB haben `JSON_EXTRACT`, Postgres hat `metadata::json ->>
  'riskScore'`. Driver-detect erforderlich (Pattern wie beim Heatmap-
  weekday-extract in v1.3.2).
- Detail-View: Risk-Score-Card bei `login`-Rows mit `riskScore >= 1`
  → Score-Zahl gross + Chips für aktive Signale.
- Perf: 5 sub-queries pro Login. Alle sind indiziert (userId,
  eventType, ipAddress, dateCreated existieren als Indexes). Für
  Standard-Volumes OK. Bei Hochlast: async via Craft Queue als Follow-
  up-Optimierung, nicht v2.3.

### Feature 4 — User-Self-Service-View

**Spec:**
- Neue Action `ActivityController::actionMy(): Response` —
  `$this->requireLogin()`, kein Admin-Gate. Query auf `userId =
  Craft::$app->getUser()->getId()`, letzte 30 Rows, ORDER BY
  dateCreated DESC.
- Neues Template `templates/my_activity.twig` — Layout wählen:
  wenn CP-Request → extends `_layouts/cp`, sonst pure HTML
  (Frontend-Consumer stylet selbst). Cleanster Ansatz: separate
  Templates für CP vs FE.
- Zwei Routen registrieren in `registerCpRoutes()`:
  `'user-audit/my-activity' => 'user-audit/activity/my'`.
  Und in einer neuen `registerFeRoutes()` (analog registerCpRoutes,
  aber `EVENT_REGISTER_SITE_URL_RULES`):
  `'user-audit/my-activity' => 'user-audit/activity/my'`.
- Bestehende `actionMyRecent()`-JSON-API erweitern um `sessionDuration`
  (falls Feature 1 gelandet) und `riskScore` (falls Feature 3 gelandet).
  Kein Breaking-Change: additive Felder.
- Frontend-Include-Snippet `templates/_widgets/my_activity_snippet.twig`:
  minimales HTML das eine Kundensite via
  `{% include 'user-audit/_widgets/my_activity_snippet' %}` einbauen
  kann. Zeigt letzte 5 Logins mit Zeit/IP/Device + Warnung bei hohem
  Risk-Score.
- Auth: `beforeAction` in `ActivityController` erweitern:
  `if ($action->id === 'my') { $this->requireLogin(); return true; }`
  ähnlich wie bestehendes `my-recent`-Handling.

## Doku (Task #7)

**README.md-Überarbeitung nach Craft-Plugin-Standards:**
- Badges am Anfang (Packagist version, Craft-CMS ^5.1, PHP ^8.2, MIT)
- Features-Sektion um v2.3-Themen erweitern
- **Requirements** explizit auflisten (Craft 5.1+, PHP 8.2+, MySQL 5.7+/MariaDB 10.4+/Postgres 13+)
- **Installation** unverändert (plugin store / composer)
- **Settings**-Tabelle um `recordPasswordChanges` (v2.2) und die
  v2.3-Toggles ergänzen (falls Feature 3/4 welche brauchen)
- **Console commands** vollständig dokumentiert:
  `user-audit/purge/run` (soft-delete Rotation)
  `user-audit/purge/hard --before=YYYY-MM-DD [--user-id=N]` (Hard
  purge mit Pflicht-Filter)
  `user-audit/throttle/reset --ip=... --email=...` (Throttle-Reset)
- **Custom-Event-API** dokumentieren:
  ```php
  \pixelwerft\useraudit\UserAudit::getInstance()
      ->activityLog
      ->log('property_exported', $userId, [
          'metadata' => ['propertyId' => 42],
      ]);
  ```
- **Frontend-Integration** dokumentieren:
  `{% include 'user-audit/_widgets/my_activity_snippet' %}`
  plus JSON-Endpoint für Vue/PWA `/actions/user-audit/activity/my-recent`
- **Privacy-Notes** für v2.2 (Passwort-Handling) und v2.4 (Geo,
  wenn's dahin geht)
- Screenshots-Sektion beibehalten, ggf. neue Screenshots für v2.3-
  Features nachziehen

## Development-Environment (Kontext)

**Bind-Mount-Setup ist AKTIV, aber SAFE:**
- Datei liegt: `/Users/manuel/Development/sites/unihome/reest-starter/craft/.ddev/docker-compose.user-audit-dev.yaml`
- Mountet Plugin-Source auf `/opt/user-audit-dev` **im Container** —
  **nicht** über `vendor/pixelwerft/user-audit/`. Composer sieht den
  Mount deshalb nie. Data-loss-Footgun von 2026-04-27 / 2026-05-28
  kann so nicht wiederauftreten.
- Zweck: `ddev exec php -l /opt/user-audit-dev/src/...` und
  Class-Load-Checks gegen WIP-Source, ohne Publish + Composer-Update-
  Zyklus.

**Vor Push von v2.3.0:**
```
rm /Users/manuel/Development/sites/unihome/reest-starter/craft/.ddev/docker-compose.user-audit-dev.yaml
cd /Users/manuel/Development/sites/unihome/reest-starter/craft && ddev restart
```

**Test-Projekt-Verifikation nach Push:**
```
cd /Users/manuel/Development/sites/unihome/reest-starter/craft
ddev composer update pixelwerft/user-audit
ddev craft up   # läuft die m260806-Migration
```

Erst DANN in Production ausrollen. Nie umgekehrt.

## Test-Discipline (Regeln aus Vorfällen)

**Nach jeder PHP-Änderung:**
```
cd /Users/manuel/Development/sites/unihome/reest-starter/craft
ddev exec php -l /opt/user-audit-dev/src/<changed-file>.php
```

**Nach Änderungen an Element / ElementQuery / Model / Migration
(Vererbungshierarchie):** zusätzlich Class-Load-Check —
`php -l` findet **keine** LSP-Verletzungen (v2.2.4-Lesson):

```bash
ddev exec php -r "
require '/var/www/html/vendor/autoload.php';
spl_autoload_register(function(\$c) {
  if (str_starts_with(\$c, 'pixelwerft\\\\useraudit\\\\')) {
    \$rel = str_replace('pixelwerft\\\\useraudit\\\\', '', \$c);
    \$file = '/opt/user-audit-dev/src/' . str_replace('\\\\', '/', \$rel) . '.php';
    if (file_exists(\$file)) include \$file;
  }
}, true, true);
new pixelwerft\\useraudit\\elements\\db\\AuditLogQuery(pixelwerft\\useraudit\\elements\\AuditLog::class);
echo 'OK'.PHP_EOL;
"
```

Analog für jede touchierte Klasse. Wenn Craft's Parent-Property/Method
mit gleichem Namen aber striktem Typ konfligiert, wirft PHP hier den
`Type of ... must be mixed`-Fehler bereits vor dem Push.

**Sanity-Test der Migration** vor Push (im Test-Projekt mit safe
mount würde man das nicht sehen, weil Craft die migrations aus
`vendor/` liest). Alternative: nach Push auf `origin/main`, VOR Tag,
im Test-Projekt via `ddev composer update` + `ddev craft up --dry-run`
laufen lassen. `--dry-run` gibt es nicht standardmässig — realistisch
ist: `ddev craft up`, prüfen dass keine Errors kommen, dann Tag setzen.

## Commit-Discipline

- Sprache: **Englisch** (git.md-Standard)
- **Kein Co-Authored-By: Claude**-Trailer (Memory-Regel)
- Logische Chunks pro Feature statt einem Riesen-Commit
- WIP-Commits klar gelabelt (`wip(v2.3): ...` prefix)
- `chore: release notes for X.Y.Z` als eigener finaler Commit
- Tag `vX.Y.Z` auf den chore-Commit (Pattern seit v1.2.1)

## Task-Status

| # | Task | Status |
|---|---|---|
| 3 | Feature 1: Session-Lifecycle | 🟡 WIP (Schema done, hooks + query + templates + action fehlen) |
| 4 | Feature 2: Impersonation | ⬜ Pending |
| 5 | Feature 3: Suspicious-Score | ⬜ Pending |
| 6 | Feature 4: Self-Service | ⬜ Pending |
| 7 | README/CHANGELOG/ROADMAP/TODO | ⬜ Pending |
| 8 | Class-Load-Checks + Tag + Push | ⬜ Pending |

TODO.md und ROADMAP.md sind noch auf v2.2.3-Stand — bei v2.3.0-Ship
mit aktualisieren:
- ROADMAP: v2.3.0 done-Block, Geo (aktuell v2.3) auf v2.4.0 schieben
- TODO: v2.3-Features als done, Test-Checkliste für Production-
  Verifikation als offen

## Erste Schritte für die neue Session

1. `git log --oneline -5` und dieses Handover lesen
2. Feature 1 fortsetzen: `ActivityLogService::log()` um sessionId-
   Handling erweitern, dann UserAudit.php Login-Hook, dann
   BEFORE_LOGOUT-Refactor
3. Nach Feature 1 fertig: Class-Load-Check via dem Snippet oben
4. Feature 2 → 3 → 4 wie in Spec beschrieben
5. Doku (Task #7)
6. Vor Push: Bind-Mount-File entfernen + `ddev restart`
7. Auf explizites User-Go: `git push origin main v2.3.0`

Kein Push, kein Tag, ohne User-Bestätigung.
