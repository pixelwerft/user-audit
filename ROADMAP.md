# Roadmap

Strategische, versionsbezogene Sicht. Taktischer Posteingang: [TODO.md](TODO.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#roadmap-während-der-entwicklung-führen).
Versionierung: 10–30 Patches pro Increment bündeln (siehe [git.md](../../apps/code-with-claude/topics/git.md#versionierung)).

## v2.x — laufender Patch-Bündel

Folge-Bündel auf v2.0.0. Aktuell leer; sobald Praxis-Inputs nach v2.0 reinkommen, hier sammeln und nach 10–30 Commits taggen.

Mögliche Kandidaten (nicht zugesagt):

- Detail-View als echtes Slideout (Crafts Element Editor, Vue-Slot oder eigener Mechanismus). v2.0 schickt Title-Click auf eine Volltext-Seite — robust, aber kein Modal-Workflow.
- Mass-Action "Export Auswahl als CSV" über das Element-Index-Action-Menu (aktuell nur Voll-Export via Settings-Link).
- Translation-Parität-Check als Pre-Tag-Konvention automatisieren.
- Index auf `email` für noch schnellere Such-Queries auf grossen Tabellen.

## v3.0.0 — vage

Aktuell keine konkreten Major-Themen geplant. Wenn ein klarer architektureller Sprung sichtbar wird, hier eintragen.

## Erledigt

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
