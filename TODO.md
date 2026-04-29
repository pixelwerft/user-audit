# TODO

Taktischer Posteingang. Strategische Sicht: [ROADMAP.md](ROADMAP.md).
Format-Vorgabe: [behavior.md](../../apps/code-with-claude/topics/behavior.md#inputs-immer-in-todomd-erfassen).

## Offen

- [ ] Entscheiden, ob v1.3.2-Tag (lokal auf `d2910be`) jetzt gepusht wird oder ob die drei Perf-Patches in einen grösseren Bundle (10–30 Commits, neue Versionierungs-Vorgabe aus git.md) einfliessen und der lokale Tag rückwirkend abgeräumt wird (erfasst 2026-04-29)
- [ ] Bind-Mount im Test-Projekt (`reest-starter/craft/.ddev/docker-compose.user-audit.yaml`) entfernen, sobald v1.3.2 gepusht und regulär via `composer update` gezogen ist (erfasst 2026-04-29)

## Erledigt

- [x] Email-Adresse in Logs-Liste als Link auf die User-Trace-Seite — Release v1.3.1 — erledigt 2026-04-27
- [x] User-Trace Headline-Stats: 6 separate `COUNT(*)`-Queries → 1 Roll-up via `SUM(CASE WHEN …)` — lokal in v1.3.2 — erledigt 2026-04-29
- [x] User-Trace Login-Heatmap: PHP-`foreach` über alle 90-Tage-Login-Rows → SQL-`GROUP BY weekday, hour` (≤168 Buckets statt n Rows) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Logs-Liste `perPage` von 100 → 50 (entspricht Crafts Element-Index-Default) — lokal in v1.3.2 — erledigt 2026-04-29
- [x] Memory-Regel "Bind-Mount + `composer update` = Datenverlust" angelegt nach Vorfall am 2026-04-27 (Composer löschte den Host-Plugin-Source via Bind-Mount) — erledigt 2026-04-27
- [x] Plugin-Repo nach Datenverlust restauriert via `git clone`, Email-Link-Patch + CHANGELOG re-applied — erledigt 2026-04-27
