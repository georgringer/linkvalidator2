# #89426 – Remove stale broken links in tx_linkvalidator_link

- Forge: https://forge.typo3.org/issues/89426 (Feature, New, angelegt 2019-10-15)
- **Status linkvalidator2:** 🟡 teilweise – Datensätze ✅, gelöschte Seite selbst ❌

## Problem

Wird eine Seite (mit Inhalten mit kaputten Links) gelöscht, bleiben die Einträge in
`tx_linkvalidator_link` für immer stehen.

## Analyse linkvalidator2

Besteht auch im Neubau, sogar breiter (Notes, „Bekannter Bug“):
- Gelöschtes Inhaltselement auf bestehender Seite: Zeilen bleiben **auch nach erneuter
  Prüfung der Seite** und erscheinen weiter im Report – `PageRecordFinder::findOnPage()`
  findet gelöschte Datensätze nicht, sie werden nie mehr angefasst. (Schlimmer als alt:
  dort wurde pro Seite vorher alles gelöscht.)
- Gelöschte Seite: Zeilen bleiben, im Report unsichtbar.
- Verschobener Datensatz/Seite: `pid` veraltet, Treffer unter alter Seite.

Fix (drei Teile, in dieser Reihenfolge):
1. **Abgleich beim Prüfen einer Seite**: alle `record_status`/`broken_link`-Zeilen mit
   `pid` = Seite, die nicht unter den gefundenen Datensätzen sind, löschen. Deckt auch
   SQL/Import und neu ausgeschlossene Felder/Tabellen ab. Kleiner Eingriff, zuerst.
2. **DataHandler-Hook (Schritt 4)**: Löschen → Zeilen entfernen; Verschieben → `pid`
   nachziehen (bei Seiten auch Unterseiten-Inhalte unverändert, da deren pid gleich
   bleibt).
3. **Waisen-Cleanup** im Queue-Lauf (Task 8): Zeilen, deren Datensatz nicht existiert
   oder `deleted=1` ist, entfernen; Zeilen, deren `pid` auf gelöschte Seiten zeigt.

## Stand 2026-10-11

Teil 1 umgesetzt: `RecordCheckService::removeStaleResults()` gleicht beim Prüfen einer
Seite (CLI `--page`, Modul „Prüfen“) die gespeicherten Zeilen der `pid` mit
`PageRecordFinder::findOnPage()` ab. Gelöschte, verschobene und am DataHandler vorbei
entfernte Datensätze und Seitenübersetzungen verschwinden.
Tests: `CheckCommandTest::resultsOfADeletedRecordDisappearWhenItsPageIsCheckedAgain`,
`…MovedRecordDisappearFromItsFormerPage`, `…DeletedPageTranslationDisappear…`.

Offen – genau der Fall aus dem Issue: **die gelöschte Seite selbst**. Sie ist nicht mehr
im Baum, ihre `pid` wird nie mehr geprüft; die Zeilen bleiben in der DB (im Report
unsichtbar). Lösung über Teil 2 (DataHandler) bzw. Teil 3 (Waisen-Cleanup: Zeilen, deren
`pid` auf keine bestehende Seite zeigt).
