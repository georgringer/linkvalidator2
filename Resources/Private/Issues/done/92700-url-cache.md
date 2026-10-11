# #92700 – Add URL cache for linkvalidator

- Forge: https://forge.typo3.org/issues/92700 (Feature, New, angelegt 2020-10-24)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/66279 (abandoned)
- **Status linkvalidator2:** ✅ gelöst

## Problem

Externe URLs werden bei jedem Lauf neu geprüft, ein Ergebnis gilt nur innerhalb eines
Laufs. Gewünscht: Cache über Läufe hinweg und die Modi *default* (Cache, sonst prüfen),
*noCache* und *cacheOnly* (nur Cache, nicht Gecachtes nicht melden). Begründung u. a.:
Prüfung beim Speichern wird möglich, weniger Last auf fremden Sites.

## Analyse linkvalidator2

Umgesetzt:

- `tx_linkvalidator2_target` ist der Cache, ein Eintrag pro eindeutigem Ziel (auch ok).
- `CacheableLinkCheckerInterface::getCacheLifetime()`; `ExternalUrlChecker` liefert die
  Lebensdauer aus `externalCacheLifetime` (Stunden, Default 168).
- `RecordCheckService::checkRecord(…, cachedOnly: true)` = Modus *cacheOnly*: nicht
  gecachte URLs werden übersprungen, nicht gemeldet. Nutzt das Modul (ohne
  „Externe prüfen“) und das CLI mit `--no-external`.
- Wiederverwendete Ergebnisse behalten ihr `last_checked`, sie laufen also nach der TTL ab.

Nicht umgesetzt: *noCache* als eigener Modus – entspricht `externalCacheLifetime = 0`.
Ein expliziter Recheck einzelner URLs aus dem Report ist als Idee in der README notiert.

Offen bleibt, übersprungene URLs als „fällig“ zu markieren (Queue, Task 8) – das ist
Teil von #89287/#89266, nicht dieses Issues.
