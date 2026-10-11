# #104004 – Incorrect handling of authMode in EditableRestriction in linkvalidator

- Forge: https://forge.typo3.org/issues/104004 (Bug, Accepted, angelegt 2024-06-08)
- Verwandt: #105413
- **Status linkvalidator2:** 📋 offen (Berechtigungen)

## Problem

`EditableRestriction` des alten linkvalidators wertet `authMode` falsch aus: nur für
das Type-Feld, nicht für `pages`, und eine leere Erlaubt-Liste bedeutet „keine
Einschränkung“ statt „nichts erlaubt“.

## Analyse linkvalidator2

Noch keine Berechtigungsfilterung (Modul admin-only). Für die Umsetzung als
Anforderung übernehmen:

- `authMode` gilt für **jedes** `select`-Feld mit `authMode`, nicht nur das Type-Feld.
  Die Prüfung muss sich am Datensatz orientieren, nicht an einer in `broken_link`
  gespeicherten Kopie (alt: nur `element_type`). → Entweder Wert der authMode-Felder
  beim Prüfen mitspeichern (generisch: JSON/eigene Spalte) oder beim Anzeigen gegen
  die Quelltabelle joinen.
- Leere Liste erlaubter Werte = nichts erlaubt.
- Pages einbeziehen.
- Möglichst Core-API nutzen (`BackendUserAuthentication::checkAuthMode()`), keine
  eigene Logik nachbauen. Wegen SQL-Pagination wird es trotzdem eine SQL-Bedingung
  brauchen – Tests mit Datensätzen, deren Type erlaubt/nicht erlaubt ist.

## Test (Task 9)

Redakteur mit `explicit_allowdeny` ohne `tt_content:CType:html` → kaputter Link in
HTML-Element erscheint nicht; mit leerer Liste → nichts.
