# TODO

Taktischer Posteingang. Strategische Sicht: [ROADMAP.md](ROADMAP.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#inputs-immer-in-todomd-erfassen).

## Offen

- [ ] Bind-Mount im Test-Projekt (`reest-starter/craft/.ddev/docker-compose.user-audit.yaml`) entfernen, sobald v2.1.0 regulär via `composer update` gezogen ist (erfasst 2026-04-29)
- [ ] In DDEV / Production testen: v2.1.0 Logs-Liste lädt zügig (kein Element-Index-Overhead mehr), Status-Pills korrekt gefärbt, "Include archive"-Checkbox zeigt Trashed-Rows mit Rotated-Badge, Time-Klick öffnet Detail-Seite (erfasst 2026-05-05)

## Erledigt

- [x] **v2.1.0 — UI-Walk-Back auf v1.x-Filter-Bar mit Status-Pills aus v2.0** — adressiert (a) Slow-Load durch Element-Index-Joins und (b) verlorene Filter-Bar oben. Element-Layer (elementId, Soft-Delete, Hard-Purge, CSV-deleted_at, Detail-Seite) bleibt komplett. Such-Index für AuditLog abgeschaltet — entlastet auch das Logging — erledigt 2026-05-05
- [x] v2.0.1 Hotfix: `stdout()` aus Migration entfernt (CP-Web-Updater-Inkompatibilität) — erledigt 2026-05-05
- [x] **v2.0.0 — Logs-Liste als nativer Craft-Element-Index** mit Source-Sidebar (All / By event / By context / Archive), Status-Pills aus Event-Type, sortierbaren Standard-Spalten, nativer Search/Pagination, Read-only Detail-View bei Title-Klick. Soft-Delete-Semantik für `purge/run`, neuer expliziter Hard-Delete-Trigger `purge/hard --before --user-id` mit Confirm-Prompt. CSV-Export bekommt `deleted_at`-Spalte. Migration mit chunked Backfill (500er-Batches, idempotent, resumable). Major-Bump auf v2.0.0 — erledigt 2026-05-05
- [x] v1.3.2 gepusht — Praxis-Bedarf rechtfertigt Default-Abweichung von "10–30 Patches bündeln"; ab v1.3.3 strikt nach neuer Regel — erledigt 2026-04-29
- [x] Email-Adresse in Logs-Liste als Link auf die User-Trace-Seite — Release v1.3.1 — erledigt 2026-04-27
- [x] User-Trace Headline-Stats: 6 separate `COUNT(*)`-Queries → 1 Roll-up via `SUM(CASE WHEN …)` — lokal in v1.3.2 — erledigt 2026-04-29
- [x] User-Trace Login-Heatmap: PHP-`foreach` über alle 90-Tage-Login-Rows → SQL-`GROUP BY weekday, hour` (≤168 Buckets statt n Rows) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Logs-Liste `perPage` von 100 → 50 (entspricht Crafts Element-Index-Default) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Memory-Regel "Bind-Mount + `composer update` = Datenverlust" angelegt nach Vorfall am 2026-04-27 (Composer löschte den Host-Plugin-Source via Bind-Mount) — erledigt 2026-04-27
- [x] Plugin-Repo nach Datenverlust restauriert via `git clone`, Email-Link-Patch + CHANGELOG re-applied — erledigt 2026-04-27
