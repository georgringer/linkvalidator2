# #105873 – Should always store page id in tx_linkvalidator_broken_links not pid

- Forge: https://forge.typo3.org/issues/105873 (Task, New, angelegt 2025-01-01)
- Verwandt: #105413
- **Status linkvalidator2:** ✅ gelöst (Datenmodell)

## Problem

`tx_linkvalidator_link.record_pid` enthält bei Seiten die *Eltern*seite. Jede Abfrage
muss deshalb Seiten und andere Tabellen getrennt behandeln
(`record_uid IN (…) AND table_name='pages' OR record_pid IN (…) AND table_name<>'pages'`),
und Berechtigungen werden gegen die falsche Seite geprüft (#105413).

## Analyse linkvalidator2

Gelöst durch das neue Datenmodell: `broken_link.pid` und `record_status.pid` speichern
immer die Seite, auf der der Fehler zu reparieren ist.
`RecordCheckService::getContainerPid()`: Seiten sind ihr eigener Container, übersetzte
Seiten gehören zur Seite der Standardsprache (`l10n_parent`). Alle Abfragen
(`BrokenLinkRepository::findByPids()`/`countByPids()`) filtern nur noch über `pid IN (…)`.

Verifiziert: `--record=pages:1300` (DE-Übersetzung von 1292) → `pid` 1292.

## Test (Task 9)

Functional: kaputter Link in `pages.url`/Shortcut einer Seite unter einer anderen Seite →
`broken_link.pid` = uid der Seite selbst; übersetzte Seite → uid der Standardsprach-Seite.
