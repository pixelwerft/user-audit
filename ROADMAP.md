# Roadmap

Strategische, versionsbezogene Sicht. Taktischer Posteingang: [TODO.md](TODO.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#roadmap-während-der-entwicklung-führen).
Versionierung: 10–30 Patches pro Increment bündeln (siehe [git.md](../../apps/code-with-claude/topics/git.md#versionierung)).

## v2.4.0 — geplant: optionales Geo-Logging

(Ursprünglich als v2.3.0 geplant — v2.3.0 wurde stattdessen für die vier
Security-/Self-Service-Features verwendet, siehe Erledigt-Block. Geo
rückt damit auf v2.4.0.)

Opt-in pro Setting + per-User-Consent über ein konfigurierbares Custom-Field-Handle. Vollständige Spec:

- Plugin-Setting `enableGeoLogging` (Default `false` — opt-in)
- Plugin-Setting `geoConsentFieldHandle` (Default-Vorschlag `userAuditGeoConsent`). Verweist auf eine Lightswitch-Custom-Field auf dem User-Field-Layout, die der Admin selbst anlegt. Setting sagt nur welcher Handle gelesen wird.
- Logging-Bedingung: geo wird nur gespeichert wenn (a) Setting on AND (b) Header `X-Geo-Lat`/`X-Geo-Lng` oder `$meta['latitude']`/`$meta['longitude']` liefert Werte AND (c) ein User existiert AND (d) sein Consent-Feld ist `true`. Anonyme Events (z.B. `login_failed` ohne Match) → kein geo.
- Settings-Seite zeigt einen aufklappbaren "Suggested legal copy"-Block mit Copy-Buttons: Vorschlag für die Field-Description auf dem User-Feld, Absatz für die Datenschutzerklärung, Hinweis-Text für die Consent-UI. Read-only Vorlagen — Admin kopiert und passt an.
- Schema: zwei neue Spalten `latitude DECIMAL(10,7) NULL`, `longitude DECIMAL(10,7) NULL` (portabel MySQL/MariaDB/Postgres, ~1cm-Präzision)
- Detail-View bekommt eine "Location"-Karte mit Coords + OpenStreetMap-Link (`https://www.openstreetmap.org/?mlat=…&mlon=…`) wenn Werte vorhanden — keine Google-Embeds wegen Privacy
- CSV-Export: zwei neue Spalten `latitude`, `longitude`
- Element-Index: nicht standardmässig sichtbar, via Spalten-Picker auswählbar
- CHANGELOG-Entry stellt klar: Plugin ist Empfänger der bereits abgesegneten Werte; Consent-Handling liegt beim Caller

## v2.x — laufender Patch-Bündel

Folge-Bündel zwischen Minor-Releases. Aktuell leer.

Mögliche Kandidaten (nicht zugesagt):

- Detail-View als echtes Slideout (Crafts Element Editor, Vue-Slot oder eigener Mechanismus). v2.0 schickt Title-Click auf eine Volltext-Seite — robust, aber kein Modal-Workflow.
- Mass-Action "Export Auswahl als CSV" über das Element-Index-Action-Menu (aktuell nur Voll-Export via Settings-Link).
- Translation-Parität-Check als Pre-Tag-Konvention automatisieren.
- Index auf `email` für noch schnellere Such-Queries auf grossen Tabellen.

## v3.0.0 — vage

Aktuell keine konkreten Major-Themen geplant. Wenn ein klarer architektureller Sprung sichtbar wird, hier eintragen.

## Erledigt

### v2.3.0 — 2026-08-06

Vier unabhängige Features (Geo auf v2.4.0 verschoben):

- **Session-Lifecycle + Duration.** Server-issued sessionId (Migration `m260806`) verknüpft Login↔Logout; BEFORE_LOGOUT-Refactor (AFTER_LOGOUT feuert nach Session-Destroy); Duration UTC-korrekt via `DateTimeHelper`; Session-Overview-Page, Detail-Card, CSV-Spalte
- **Impersonation-Logging.** `impersonation_started`/`_stopped` via `getImpersonator()` + self-managed Session-Marker; amber Status an allen fünf Stellen; New-Location-Mail bei Impersonation unterdrückt
- **Suspicious-Score.** 5-Signal-Score (0–5) nur bei echten Logins in `metadata.riskScore`/`riskSignals`; Index-Filter "Suspicious only" + Risk-Badge; Detail-Card; opt-in Element-Spalte; JSON-Extraktion driver-detected (MySQL/Postgres)
- **Self-Service-View.** `user-audit/my-activity` CP+FE (Site-Template-Root), `craft.userAudit`-Twig-Variable + einbettbares Snippet, `my-recent`-JSON additiv um sessionId/duration/riskScore erweitert
- **schemaVersion** 2.0.0 → 2.3.0, damit die sessionId-Migration im normalen Update-Flow läuft (Runtime hängt jetzt an der Spalte)

### v2.2.3 — 2026-08-06

- `snapshotUserGroups()`-Helper eingeführt und in allen vier Auth-Hooks angewendet (login, logout, login_failed, password_changed). Fixt die leere `userGroups`-Spalte bei Logout-Rows — Daten waren am Hook-Event zwar verfügbar, wurden aber nicht gesnapshottet
- Snapshot-Semantik dokumentiert: jede Row zeigt Groups zum Event-Zeitpunkt (Group-Änderung zwischen Login und Logout ist forensisch nachvollziehbar)

### v2.2.0 — 2026-06-12

- Password-Change-Logging mit BEFORE_SAVE/AFTER_SAVE-Hooks auf dem User-Element
- Stärke-Klassifikationen (`meetsMin8`, `hasUpper`, `hasLower`, `hasDigit`, `hasSpecial`, `score 0–5`) in `metadata.passwordStrength`
- Plaintext-Privacy-Garantie: einmalige Beobachtung in `computePasswordStrengthFlags`, nie gespeichert/kopiert/versendet
- Neuer Status `pwd_changed` (violet) + Source "Password changes" + Monitor-Linie
- Settings-Toggle `recordPasswordChanges` (Default on)
- Detail-View bekommt strukturierte Password-Strength-Karte für `password_changed`-Events

### v2.1.0 — 2026-05-05

- UI-Walk-Back vom Element-Index-Stil zurück auf v1.x-Filter-Bar oben + Single-Table-Queries — Element-Layer bleibt strukturell drin, nur die Listen-UI wurde getauscht
- Status-Pills aus v2.0 als neue Spalte in der Tabelle übernommen
- Click-through von Time-Spalte auf neue Detail-Seite (`/admin/user-audit/log/<elementId>`)
- Neuer "Include archive"-Filter zeigt soft-deleted Rows zusätzlich, mit "Rotated"-Badge
- Such-Index für AuditLog-Elemente abgeschaltet, um Logging-Schreiblast zu reduzieren

### v2.0.1 — 2026-05-05

- Hotfix: `stdout()` aus der Conversion-Migration entfernt — schlug im CP-Web-Updater fehl, weil Migrations nicht von Console-Controller erben

### v2.0.0 — 2026-05-05

- Logs-Index als nativer Craft-Element-Index (Status-Pills, Source-Sidebar, Sort/Search/Pagination, Spalten-Picker)
- Soft-Delete-Semantik im Retention-Cron `purge/run` (statt Hard-Delete)
- Expliziter Hard-Delete-Trigger `purge/hard --before --user-id` mit Pflicht-Filter und Confirm-Prompt
- CSV-Export inkl. soft-deleted Rows mit `deleted_at`-Marker
- Read-only Detail-View (`/admin/user-audit/log/<id>`) mit allen Feldern + Link auf User-Trace
- AuditLog-Element-Typ + ElementQuery + Migration mit Backfill in Batches

### v1.3.x

- v1.3.2 — User-Trace-Heatmap als SQL-`GROUP BY weekday, hour`, Stats-Query auf 1 Roll-up, Logs-`perPage` 100 → 50
- v1.3.1 — Email-Adresse in Logs-Liste verlinkt zur User-Trace-Seite
- v1.3.0 — User-Trace-Seite mit Stats, Top-N und Login-Heatmap

## Backlog (unsortiert)

- Tags `v1.0.2`–`v1.1.6` existieren im CHANGELOG-Verlauf, aber nicht als Git-Tags. Niedrige Prio — kosmetisch.
- GitFlow-Umstellung (`develop`-Branch + `feature/*` + `release/*` + `hotfix/*`) sobald die erste Production-Version live ist. Aktuell trunk-based auf `main` mit explizitem User-Entscheid.
