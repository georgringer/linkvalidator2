# #101367 – page link to not hidden child of hidden page with extendToSubpages is not considered broken (linkvalidator)

- Forge: https://forge.typo3.org/issues/101367 (Bug, New, angelegt 2023-07-17)
- Verwandt: #106675 (gleiches Problem, ausführlicher)
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)
- Core-Fix in Review: Gerrit 96918 (2026-10-20, `InternalLinktype`, nur Sichtbarkeit der Rootline, fe_group ausdrücklich nicht)

## Problem

Link auf nicht versteckte Unterseite einer versteckten Seite mit `extendToSubpages`
wird nicht als kaputt erkannt.

## Analyse linkvalidator2

Identisch mit #106675, dort ist die Analyse und der Fix-Vorschlag
(Rootline in `PageChecker`). Beim Lösen beide Issues referenzieren
(`Resolves: #106675`, `Related: #101367` oder umgekehrt) und eins als Duplikat schließen.

## Lösung (2026-10-11)

Ziel-Seiten werden über die Seitenauflösung des Frontends geprüft
(`FrontendPageResolver` → `PageInformationFactory::create()`, anonym, ohne Rendern, ohne
`errorHandling` der Site; Spike in den Notes). Die Rootline mit `extendToSubpages` kommt
damit genau wie im Frontend. Für den Fehlertyp schaut `PageChecker` bei einem Fehlschlag auf
Seite und Rootline: `page.hiddenByParent` / `page.expiredByParent` mit `parentPage`.

Abweichend vom Issue: `fe_group` und `starttime` (auch über die Elternseite) sind **nicht
kaputt**, sondern ein **Hinweis** (`page.accessRestricted`, `page.notYetVisible`), ebenso
eine künftige Stoppzeit (`page.visibleUntil`). Hinweise zählen nicht als kaputt, im Report
stehen sie mit eigenem Badge und sind per Status filterbar.

Tests: `Tests/Functional/Check/PageCheckerTest.php`.
