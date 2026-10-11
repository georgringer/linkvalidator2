# #101367 – page link to not hidden child of hidden page with extendToSubpages is not considered broken (linkvalidator)

- Forge: https://forge.typo3.org/issues/101367 (Bug, New, angelegt 2023-07-17)
- Verwandt: #106675 (gleiches Problem, ausführlicher)
- **Status linkvalidator2:** ❌ offen

## Problem

Link auf nicht versteckte Unterseite einer versteckten Seite mit `extendToSubpages`
wird nicht als kaputt erkannt.

## Analyse linkvalidator2

Identisch mit #106675, dort ist die Analyse und der Fix-Vorschlag
(Rootline in `PageChecker`). Beim Lösen beide Issues referenzieren
(`Resolves: #106675`, `Related: #101367` oder umgekehrt) und eins als Duplikat schließen.
