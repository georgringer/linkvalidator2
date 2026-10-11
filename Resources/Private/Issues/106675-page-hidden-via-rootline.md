# #106675 – Checking links to pages does not check if page is hidden via rootline (e.g. if child of hidden=1 and extendToSubpages=1)

- Forge: https://forge.typo3.org/issues/106675 (Bug, New, angelegt 2025-05-11)
- Verwandt: #101367 (Duplikat)
- **Status linkvalidator2:** ❌ offen

## Problem

Link auf eine sichtbare Unterseite einer Seite mit `hidden=1` und
`extendToSubpages=1` wird als ok gewertet, ist im Frontend aber nicht erreichbar.
Ebenso für `starttime`, `endtime`, `fe_group` mit `extendToSubpages`.

## Analyse linkvalidator2

Nicht gelöst. `PageChecker::check()` liest nur die Zielseite selbst
(`deleted`, `hidden`, `endtime`, `doktype`), die Rootline wird nicht betrachtet.

Fix:
- Rootline der Zielseite laden (`RootlineUtility` bzw. eigene Abfrage ohne
  Restrictions) und für jede Vorfahrenseite mit `extendToSubpages=1` dieselben Regeln
  anwenden: `hidden` → `page.hiddenByParent`, `endtime` abgelaufen →
  `page.expiredByParent` (neue Fehlertypen mit `parentPage` in den Details, damit
  der Redakteur weiß, wo er suchen muss).
- `starttime` in der Zukunft bleibt ok (Spec: ok, ggf. später Warnung),
  `fe_group` bleibt ok (Spec: Zugriffsschutz ≠ kaputt) – im Issue gewünscht, aber
  bewusst anders entschieden; in der Forge-Antwort begründen.
- Pro Lauf cachen (viele Links auf denselben Teilbaum).

Gleiche Logik gilt für die **Quelle** (TODO in `RecordCheckService::isVisible()`:
Inhalte auf versteckter Seite / unter `extendToSubpages` werden gemeldet). Beide
zusammen bauen.

## Test (Task 9)

Functional: Seite hidden+extendToSubpages mit sichtbarer Unterseite, Link auf
Unterseite → broken; ohne extendToSubpages → ok; abgelaufene Elternseite analog.
