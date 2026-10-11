# #105413 – Linkvalidator BE-Module wrong permissions for invalid links in page attributes

- Forge: https://forge.typo3.org/issues/105413 (Bug, Under Review, angelegt 2024-10-23)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/87610 (offen)
- Verwandt: #105873, #104004
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)

## Problem

Kaputter Link in Seiteneigenschaften (z. B. `pages.url`) einer Seite, deren
*Elternseite* der Redakteur nicht bearbeiten darf, wird ihm nicht angezeigt – weil
`record_pid` die Elternseite ist und die Berechtigung daran geprüft wird.
Zweites Problem (Kommentar Sybille): die Seitenliste rekursiert nicht in Seiten ohne
Recht, deren Unterseiten aber erlaubt sein können.

## Analyse linkvalidator2

1. Datenmodell: gelöst, `pid` ist bei Seiten die Seite selbst (#105873).
2. Berechtigungen: noch nicht umgesetzt, Modul ist `admin`-only. Konzept (Notes,
   „Berechtigungen“): Report zeigt, was der User reparieren kann – Seitenrecht
   (Seiten-Felder: `PAGE_EDIT`, Inhalte: `CONTENT_EDIT`), `tables_modify`,
   `allowed_languages`, `non_exclude_fields`, Filterung in SQL.
3. Rekursion: `PageRecordFinder::findPageIds()` muss alle Seiten des Baums sammeln und
   *erst danach* nach Recht filtern – nicht beim Abstieg abbrechen. Gleiches gilt für
   Mountpoints (DB-Mounts als Wurzeln).

Achtung bei der Umsetzung: für Seiten-Felder zählt das Recht `PAGE_EDIT` an der Seite
selbst, für Inhalte `CONTENT_EDIT` an der Seite – unterschiedliche Bits je
`table_name`.

## Test (Task 9)

Functional als Redakteur: Elternseite ohne Recht, Unterseite mit Recht und kaputtem
`pages.url` → wird angezeigt; Inhalt auf Seite ohne Recht → nicht angezeigt.

## Lösung (2026-10-11)

`Report/ReportPermissionsFactory` baut aus dem Backend-User (Admin: keine Einschränkung)
`ReportPermissions`; `BrokenLinkRepository` wendet sie in SQL an (Liste, Anzahl/Pagination,
Filteroptionen, Zahlen der AJAX-Prüfung):
- Seiten-Felder: `PAGE_EDIT` an der Seite selbst (pid = Seite), Inhalte: `CONTENT_EDIT` an
  ihrer Seite – Join auf `pages` mit `getPagePermsClause()`; der Seitenbaum wird nicht beim
  Abstieg beschnitten, gefiltert wird pro Zeile.
- `tables_modify`, Exclude-Felder nur mit `non_exclude_fields`.
- `authMode` für **jedes** `select`-Feld mit `authMode` (nicht nur das Type-Feld), per
  Unterabfrage auf die Quelltabelle; leere Werte erlaubt wie in `checkAuthMode()`; Werte
  aus `items` (itemsProcFunc-Werte sind nicht abgedeckt).
- Sprachen: `allowed_languages`, `-1` immer erlaubt (wie `checkLanguageAccess()`).
Modul `access: user`. Tests: `Tests/Functional/Report/ReportPermissionsTest.php`
(SQLite, MariaDB, Postgres). Testuser in DDEV: `lv2_styleguide_basic`, `lv2_styleguide_rte`.
