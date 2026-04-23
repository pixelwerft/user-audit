<?php

return [
    // ─── Settings: reset defaults ───────────────────────────────────────
    'Reset to defaults' => 'Standard-Einstellungen setzen',
    'Fills every field on this page with its built-in default value. Nothing is written to disk — click Save afterwards to persist the reset.' => 'Befüllt alle Felder auf dieser Seite mit ihren Standardwerten. Es wird noch nichts gespeichert — klicke danach auf Speichern, um die Rücksetzung zu übernehmen.',
    'Reset all fields to defaults? Your changes will only be saved once you click Save.' => 'Alle Felder auf Standardwerte zurücksetzen? Die Änderungen werden erst mit einem Klick auf Speichern übernommen.',

    // ─── Settings: access ───────────────────────────────────────────────
    'Access' => 'Zugriff',
    'Admins always have access to the User Audit viewer. Additionally grant access to selected CP user groups below. If no group is selected the plugin stays admin-only.' => 'Admins haben immer Zugriff auf den User-Audit-Viewer. Zusätzlich können ausgewählte CP-Benutzergruppen unten freigeschaltet werden. Ist keine Gruppe ausgewählt, bleibt das Plugin Admin-only.',
    'Allowed user groups' => 'Freigeschaltete Benutzergruppen',
    'Members of these groups can see the User Audit nav entry and the viewer. Admins always have access regardless of this setting.' => 'Mitglieder dieser Gruppen sehen den User-Audit-Nav-Eintrag und den Viewer. Admins haben unabhängig von dieser Einstellung immer Zugriff.',
    'You are not permitted to view the User Audit.' => 'Du hast keine Berechtigung, den User Audit zu sehen.',

    // ─── Settings: recording ────────────────────────────────────────────
    'What gets recorded' => 'Was wird aufgezeichnet',
    'Controls which events are written to the activity log. Changes take effect immediately — existing entries are kept. Custom events from other modules (log() with a custom eventType) are not affected.' => 'Steuert, welche Events ins Aktivitätslog geschrieben werden. Änderungen wirken ab sofort — vorhandene Einträge bleiben. Custom-Events anderer Module (log() mit eigenem eventType) sind davon nicht betroffen.',
    'Record control panel events' => 'CP-Events aufzeichnen (Backend)',
    'Admin logins via /admin.' => 'Admin-Logins über /admin.',
    'Record frontend events' => 'Frontend-Events aufzeichnen',
    'Logins from the PWA or public site.' => 'Logins aus PWA oder Publikumsseite.',
    'Record client type (PWA vs. browser)' => 'Client-Typ aufzeichnen (PWA vs. Browser)',
    'Reads the X-Reest-Client header on login/logout requests and stores pwa or browser. Turn off to keep the column NULL and hide the client filter.' => 'Liest den X-Reest-Client-Header bei Login/Logout-Requests und speichert pwa oder browser. Aus → Spalte bleibt leer und der Client-Filter wird ausgeblendet.',

    // ─── Settings: retention ────────────────────────────────────────────
    'Retention' => 'Aufbewahrung',
    'Retention (days)' => 'Aufbewahrung (Tage)',
    'Entries older than N days are deleted during the purge run. 0 = never delete.' => 'Einträge, die älter sind als N Tage, werden beim Purge-Lauf gelöscht. 0 = niemals löschen.',
    'Manual run:' => 'Manueller Lauf:',
    'Recommended cron (every 30 minutes):' => 'Empfohlener Cron (alle 30 Minuten):',

    // ─── Settings: throttling ───────────────────────────────────────────
    'Throttling' => 'Throttling',
    'Blocks login requests by IP or email when too many login_failed entries fall within the window. Hitting the limit returns HTTP 429 and creates a login_blocked audit entry.' => 'Blockt Login-Requests für IP oder Email, wenn innerhalb des Fensters zu viele login_failed-Einträge vorliegen. Trifft das Limit → HTTP 429 + Audit-Eintrag login_blocked.',
    'Enable throttling' => 'Throttling aktivieren',
    'Failures per IP' => 'Fehler pro IP',
    'How many failed logins per IP are allowed within the window. 0 = no IP limit.' => 'Wie viele fehlgeschlagene Logins pro IP innerhalb des Fensters erlaubt sind. 0 = kein IP-Limit.',
    'Failures per email/login' => 'Fehler pro Email/Login',
    'How many failed logins per login name are allowed within the window. 0 = no email limit.' => 'Wie viele fehlgeschlagene Logins pro Loginname innerhalb des Fensters erlaubt sind. 0 = kein Email-Limit.',
    'Window (minutes)' => 'Fenster (Minuten)',
    'Sliding window. Older failures outside this range no longer count.' => 'Sliding Window. Alte Fehler ausserhalb dieser Zeit zählen nicht mehr.',
    'Manual unblock:' => 'Manuelles Entsperren:',
    'Too many failed login attempts. Please try again in a few minutes.' => 'Zu viele fehlgeschlagene Login-Versuche. Bitte versuche es in ein paar Minuten erneut.',

    // ─── New-location email ─────────────────────────────────────────────
    // The source string in UserAudit.php is already German. This
    // identity mapping is explicit so the maintenance script sees the
    // key as covered and doesn't report it as missing.
    'Neuer Login auf deinem Konto' => 'Neuer Login auf deinem Konto',

    // ─── Settings: new-location alerts ──────────────────────────────────
    'New-location alerts' => 'Neue-Location-Alerts',
    'Sends the user an email when someone logs in from an IP that has not been seen for them within the lookback period. Helps detect compromised accounts early.' => 'Schickt dem User eine Mail, wenn sich jemand von einer IP einloggt, die in den letzten N Tagen noch nicht für ihn gesehen wurde. Hilft beim frühen Erkennen kompromittierter Accounts.',
    'Send new-location emails' => 'Neue-Location-Mails senden',
    'Lookback (days)' => 'Lookback (Tage)',
    'How far back to look when deciding whether an IP is new. Larger values reduce false positives — but a stolen account will take longer to detect.' => 'Wie weit zurück gesucht wird, um zu entscheiden ob eine IP neu ist. Je grösser, desto weniger False-Positives — aber länger dauert es, bis ein gestohlener Account entdeckt wird.',

    // ─── Nav & page titles ──────────────────────────────────────────────
    'User Audit' => 'User Audit',
    'Logs' => 'Logs',
    'Monitor' => 'Monitor',
    'User audit logs' => 'User Audit Logs',
    'User audit monitor' => 'User Audit Monitor',

    // ─── Monitor: filters & chart ───────────────────────────────────────
    'Apply' => 'Anwenden',
    'All' => 'Alle',
    'None' => 'Keine',
    'None selected' => 'Keine Auswahl',
    'All events' => 'Alle Events',
    'All contexts' => 'Alle Kontexte',
    'All clients' => 'Alle Clients',
    'Event' => 'Ereignis',
    'Context' => 'Kontext',
    'Client' => 'Client',
    'events' => 'Ereignisse',
    'contexts' => 'Kontexte',
    'clients' => 'Clients',
    'Last 24 hours' => 'Letzte 24 Stunden',
    'Last 48 hours' => 'Letzte 48 Stunden',
    'Last 7 days' => 'Letzte 7 Tage',
    'This month' => 'Diesen Monat',
    'Last 30 days' => 'Letzte 30 Tage',
    'Last 90 days' => 'Letzte 90 Tage',
    'Activity over time' => 'Aktivität im Zeitverlauf',
    'Bucket size' => 'Gruppierung',
    'one bar per hour' => 'pro Stunde',
    'one bar per day' => 'pro Tag',
    'max' => 'Max',

    // ─── Monitor: stat cards + widget ───────────────────────────────────
    'Logins' => 'Logins',
    'Logouts' => 'Logouts',
    'Failed' => 'Fehlgeschlagen',
    'Blocked' => 'Blockiert',
    'Top-IPs (Failed)' => 'Top-IPs (fehlgeschlagen)',
    'Attempts' => 'Versuche',
    'User Audit — 24h Stats' => 'User Audit — 24h-Statistik',
    'Full log' => 'Volles Log',

    // ─── Logs: list UI ──────────────────────────────────────────────────
    'CP (Backend)' => 'CP (Backend)',
    'Frontend' => 'Frontend',
    'Browser' => 'Browser',
    'Groups' => 'Gruppen',
    'Filter' => 'Filtern',
    'Reset' => 'Zurücksetzen',
    'Export CSV' => 'CSV exportieren',
    'Search by email / group / IP / browser / OS / device / event' => 'Suche nach Email / Gruppe / IP / Browser / OS / Gerät / Event',
    'Time' => 'Zeit',
    'User' => 'Benutzer',
    'Email / Login' => 'E-Mail / Login',
    'IP' => 'IP',
    'Device' => 'Gerät',
    'OS' => 'OS',
    'Reason' => 'Grund',
    'No entries yet.' => 'Noch keine Einträge.',
    'Previous' => 'Zurück',
    'Next' => 'Weiter',
    'Page %s of %s' => 'Seite %s von %s',
    '%s entries' => '%s Einträge',
    'Settings' => 'Einstellungen',
];
