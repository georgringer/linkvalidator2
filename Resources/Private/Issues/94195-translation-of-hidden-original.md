# #94195 – Translated (connected mode) content will still get checked by linkvalidator if original content is hidden

- Forge: https://forge.typo3.org/issues/94195 (Bug, New, angelegt 2021-05-26)
- Verwandt: #94196
- **Status linkvalidator2:** 📋 offen (Schritt 7: Sprachregel)

## Problem

Übersetzung im Connected Mode, Original versteckt → Übersetzung wird im Frontend nicht
angezeigt, aber trotzdem geprüft und gemeldet.

## Analyse linkvalidator2

Nicht gelöst. `RecordCheckService::isVisible()` prüft nur `hidden`/`endtime` des
Datensatzes selbst. Für Übersetzungen mit `l18n_parent` gilt im Frontend auch die
Sichtbarkeit des Originals (Overlay-Logik).

Passt in die geplante Sprachregel (Schritt 7, Notes „Sprachregel: auch die Quelle“):
Sichtbarkeit der Quelle über die Frontend-API bestimmen
(`PageRepository` + `LanguageAspect` pro Sprache) statt selbst zu rechnen. Damit
wären abgedeckt:
- Original versteckt → Übersetzung unsichtbar (dieses Issue)
- Seite nicht übersetzt (strict) → Inhalte unsichtbar
- Seite versteckt / `extendToSubpages` (#106675 auf Quellseite)

Unsichtbare Quellen nicht melden, aber im Modul als „übersprungen“ zählen.
Free Mode (`l18n_parent = 0`) ist davon nicht betroffen.

## Test (Task 9)

Original versteckt + Übersetzung mit kaputtem Link → keine `broken_link`-Zeile.
