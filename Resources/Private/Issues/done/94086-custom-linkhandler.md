# #94086 – Softref for custom linkhandler

- Forge: https://forge.typo3.org/issues/94086 (Feature, Needs Feedback, angelegt 2021-05-07)
- Verwandt: #96110, #102468, #103403, #107186
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)

## Problem

Links eines eigenen LinkHandlers (`t3://foo?subtype=bar&identifier=123`, kein Record-
Handler) werden nicht geprüft. Workaround seit v11: eigener Softref-Parser +
eigener Linktype + `AppendLinkHandlerElementsEvent`. Fehlende Doku.

## Analyse linkvalidator2

Erweiterbarkeit ist vorgesehen: Checker sind getaggte Services pro
`LinkService`-Typ (`CheckerRegistry`). Ein eigener Handler braucht nur einen Checker
mit `getSupportedTypes(): ['foo']` – kein Softref-Parser mehr, die Links kommen aus
Link- und RTE-Feldern automatisch.

**Aber:** `LinkTargetFactory::createFromResolved()` ordnet unbekannte Typen im
`default`-Zweig `createUnresolvableTarget()` zu → `t3://…`-Links eines eigenen
Handlers werden als **`link.invalid` (broken)** gemeldet, auch wenn ein Checker für den
Typ registriert ist. Das ist schlechter als heute (alt: ignoriert). Im SDD-Ledger als
Risiko notiert.

Fix:
- Für Typen mit registriertem Checker generisches `LinkTarget($type, $type . ':' . <link>, $resolved)`.
- Typen, die ein registrierter LinkHandler liefert
  (`$GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler']`), aber ohne Checker →
  überspringen (nicht prüfbar), nicht melden.
- Nur wirklich nicht auflösbare `t3://`-Links (Legacy-Converter-Fallback) → invalid.
- Optional Interface für einen Identifier, damit Cache/Deduplizierung pro Ziel greifen.

Doku (RST/Developer): „Eigenen Linktyp prüfen“ mit Beispiel-Checker.

## Test (Task 9)

Fixture-Extension mit eigenem LinkHandler: ohne Checker → nicht gemeldet; mit Checker
→ dessen Ergebnis.

## Lösung (2026-10-11)

- `LinkTargetFactory::createCustomTarget()`: Typ eines in `SYS.linkHandler` registrierten
  Handlers → `LinkTarget(<type>, '<type>:<link>')`; `RecordCheckService` prüft ihn mit dem
  Checker des Typs oder überspringt ihn ohne Checker. Nur nicht registrierte/nicht
  auflösbare `t3://`-Links bleiben `link.invalid`. Gilt auch für die RTE-Markierung.
- Erweiterbarkeit: Checker-Tag per `registerForAutoconfiguration()` in
  `Configuration/Services.php` – vorher `_instanceof` in `Services.yaml`, das nur für
  Klassen dieser Datei gilt; Checker anderer Extensions wären nie registriert worden.
- Tests: `CustomLinkHandlerTest` mit Fixture-Extension `test_link_handler` (Handler mit
  und ohne Checker, unbekannter Handler).
- Doku für Entwickler vorgemerkt: `Documentation/todo.md`, „Check a custom link type“.
