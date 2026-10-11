# #89454 – Limit list of broken links displayed / add pagination

- Forge: https://forge.typo3.org/issues/89454 (Feature, New, angelegt 2019-10-18)
- **Status linkvalidator2:** ✅ gelöst

## Problem

Alle kaputten Links werden ungefiltert auf einer Seite ausgegeben, bei großen Bäumen
lange Ladezeiten.

## Analyse linkvalidator2

Umgesetzt im Modul: Pagination in SQL (`LIMIT`/`OFFSET`, 50 pro Seite,
`ReportController::ITEMS_PER_PAGE`), Partial `Partials/Pagination` nach Vorbild
EXT:redirects; dazu Filter (Status, Linktyp, Tabelle, Sprache, Freitext) und Sortierung
über jede Spalte mit stabiler Nebensortierung.

Rest: `getBrokenLinks()` lädt Datensatz und Seitentitel pro Zeile (max. 50) –
Performance-TODO in den Notes, kein Funktionsproblem.
