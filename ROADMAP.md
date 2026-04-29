# Roadmap

Strategische, versionsbezogene Sicht. Taktischer Posteingang: [TODO.md](TODO.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#roadmap-während-der-entwicklung-führen).
Versionierung: 10–30 Patches pro Increment bündeln (siehe [git.md](../../apps/code-with-claude/topics/git.md#versionierung)).

## v1.3.x — laufender Patch-Bündel

Sammelphase nach neuer Versionierungs-Vorgabe. Aktuell akkumuliert (lokal, ungepusht, Tag `v1.3.2` gesetzt aber Push offen):

- *Email / Login*-Spalte in Logs-Liste verlinkt auf User-Trace
- User-Trace Stats auf 1 Query mit `SUM(CASE WHEN …)`
- User-Trace Heatmap auf SQL-GROUP-BY (Driver-spezifisch: MySQL/MariaDB `WEEKDAY+1`, Postgres `EXTRACT(ISODOW)`)
- Logs `perPage` 100 → 50

Nächste Kandidaten (nicht zugesagt — wenn sie auftauchen, hier rein):

- Weitere Perf-Optimierungen am User-Trace (Top-N-Aggregates, `lastLogin`/`lastFailed` evtl. zusammenfassen)
- Index auf `email` für schnellere `LIKE`-Suchen in der Logs-Liste, sobald Praxis-Workload Indizien liefert
- Translation-Parität-Check als Pre-Tag-Konvention (aktuell nur im Brief dokumentiert, nicht automatisiert)

## v1.4.0 — Idee

Themen, die als Minor-Sprung Sinn machen — neue Features oder grössere Verbesserungen, die als Bündel zusammengehören.

- Noch keine konkreten Punkte. Praxis entscheidet, was hier landet.

## v2.0.0 — Idee

Architektur-Sprung: User-Audit-Listing als Crafts nativer Element-Index.

- `UserActivityLog` von simpler ActiveRecord auf `craft\base\Element` umstellen
- Standard-CP-UX: Checkboxen, Mass-Actions, Status-Pills, Sticky-Header, Source-Sidebar
- Native Ajax-Pagination (im Element-Index ohne Eigenbau)

Major-Bump weil sich die öffentliche Record-API ändert (Element-Methoden, Element-Query) und Twig-Integration in Drittprojekten betroffen sein könnte.

Trigger: erst wenn die Praxis zeigt, dass die jetzige Listing-UX (eigenes Filter-Form, eigene Sortier-Header) gegenüber dem Standard-Element-Index spürbar stört. Bis dahin ist der Aufwand (1–2 Tage Refactor mit Migrationsrisiko) zu hoch für den Gewinn.

## v3.0+ — vage

Leer.

## Backlog (unsortiert)

- Tags `v1.0.2`–`v1.1.6` existieren im CHANGELOG-Verlauf, aber nicht als Git-Tags. Niedrige Prio — kosmetisch, wenn jemand die Lücke stört, kann sie via `git tag` auf den entsprechenden Commits nachgesetzt werden, falls die Anker noch auffindbar sind.
- GitFlow-Umstellung (`develop`-Branch + `feature/*` + `release/*` + `hotfix/*`) sobald die erste Production-Version live ist. Aktuell trunk-based auf `main` mit explizitem User-Entscheid.
