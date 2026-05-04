# TODO

Taktischer Posteingang. Strategische Sicht: [ROADMAP.md](ROADMAP.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#inputs-immer-in-todomd-erfassen).

## Offen

- [ ] Bind-Mount im Test-Projekt (`reest-starter/craft/.ddev/docker-compose.user-audit.yaml`) entfernen, sobald v2.0.0 regulär via `composer update` gezogen ist (erfasst 2026-04-29)
- [ ] In DDEV testen: v2.0.0 Migration läuft sauber durch, Element-Index lädt, Source-Sidebar filtert korrekt, Status-Pills haben die richtigen Farben, Detail-View rendert, Soft-Delete via `purge/run --dry-run=1` und Hard-Delete via `purge/hard --before=… --dry-run=1` blocken/erlauben wie spezifiziert (erfasst 2026-05-05, post-Push-Verifikation)
- [ ] Falls in der Praxis das Title-Klick-zu-Volltext-Detail nervt (statt Slideout): Slideout-Polish in eigenem v2.x-Patch, siehe ROADMAP (erfasst 2026-05-05)

## Erledigt

- [x] **v2.0.0 — Logs-Liste als nativer Craft-Element-Index** mit Source-Sidebar (All / By event / By context / Archive), Status-Pills aus Event-Type, sortierbaren Standard-Spalten, nativer Search/Pagination, Read-only Detail-View bei Title-Klick. Soft-Delete-Semantik für `purge/run`, neuer expliziter Hard-Delete-Trigger `purge/hard --before --user-id` mit Confirm-Prompt. CSV-Export bekommt `deleted_at`-Spalte. Migration mit chunked Backfill (500er-Batches, idempotent, resumable). Major-Bump auf v2.0.0 — erledigt 2026-05-05
- [x] v1.3.2 gepusht — Praxis-Bedarf rechtfertigt Default-Abweichung von "10–30 Patches bündeln"; ab v1.3.3 strikt nach neuer Regel — erledigt 2026-04-29
- [x] Email-Adresse in Logs-Liste als Link auf die User-Trace-Seite — Release v1.3.1 — erledigt 2026-04-27
- [x] User-Trace Headline-Stats: 6 separate `COUNT(*)`-Queries → 1 Roll-up via `SUM(CASE WHEN …)` — lokal in v1.3.2 — erledigt 2026-04-29
- [x] User-Trace Login-Heatmap: PHP-`foreach` über alle 90-Tage-Login-Rows → SQL-`GROUP BY weekday, hour` (≤168 Buckets statt n Rows) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Logs-Liste `perPage` von 100 → 50 (entspricht Crafts Element-Index-Default) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Memory-Regel "Bind-Mount + `composer update` = Datenverlust" angelegt nach Vorfall am 2026-04-27 (Composer löschte den Host-Plugin-Source via Bind-Mount) — erledigt 2026-04-27
- [x] Plugin-Repo nach Datenverlust restauriert via `git clone`, Email-Link-Patch + CHANGELOG re-applied — erledigt 2026-04-27
