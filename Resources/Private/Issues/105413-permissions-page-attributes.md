# #105413 – Linkvalidator BE-Module wrong permissions for invalid links in page attributes

- Forge: https://forge.typo3.org/issues/105413 (Bug, Under Review, angelegt 2024-10-23)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/87610 (offen)
- Verwandt: #105873, #104004
- **Status linkvalidator2:** 🟡 Ursache im Datenmodell gelöst, Berechtigungen 📋 offen

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
