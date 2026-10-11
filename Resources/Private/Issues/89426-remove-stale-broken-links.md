# #89426 – Remove stale broken links in tx_linkvalidator_link

- Forge: https://forge.typo3.org/issues/89426 (Feature, New, angelegt 2019-10-15)
- **Status linkvalidator2:** ❌ offen (bekannter Bug, reproduziert 2026-10-08)

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

## Test (Task 9)

Functional: Inhalt prüfen, `deleted=1` setzen, Seite erneut prüfen → keine Zeilen mehr.
Seite löschen, Cleanup → keine Zeilen.
