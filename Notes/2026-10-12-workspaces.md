# Workspaces (zurückgestellt, für den Core-Ersatz Pflicht)

Stand 2026-10-12: Die Extension prüft nur den Live-Workspace. Das Modul ist wie das des
Core-Linkvalidators für Workspaces gesperrt (`'workspaces' => 'live'` in
`Configuration/Backend/Modules.php`). Der Hook prüft beim Veröffentlichen, das Speichern im
Workspace wird ignoriert. Die Spalte `workspace` gibt es in `tx_linkvalidator2_broken_link` und
`tx_linkvalidator2_record_status` (immer `0`).

## Entscheidung

**Workspace-Unterstützung ist kein optionales Extra, sondern Voraussetzung dafür, dass die
Extension den Core-Linkvalidator ersetzen darf.** Der Core-Linkvalidator ist dabei nicht der
Maßstab, sondern nur das absolute Minimum: er kann Workspaces gar nicht, die neue Extension
soll sie können. Bis dahin steht die Zeile "Workspaces" in der README auf ❌, und das Ersetzen
des Core-Linkvalidators ist nicht freigegeben.

Zurückgestellt wurde nur die Umsetzung, nicht die Anforderung.

## Was bereits entschieden ist

- **Prüfumfang:** nicht alle Records pro Workspace, sondern die *relevanten Workspace-Records*:
  was im Workspace geändert, neu angelegt oder gelöscht wurde. **Verschieben (Move-Pointer)
  gehört nicht zum ersten Schritt.** Unveränderte Records bleiben bei den Live-Ergebnissen.
- **Hintergrund:** `fillqueue --workspace=<id|all>`, geprüft werden alle Workspaces mit
  offenen Änderungen. Der Hook prüft beim Speichern und Veröffentlichen sofort.

## Offen

- **Report im Workspace:** noch nicht entschieden. Zur Wahl standen: zusammengeführt (Live-
  Ergebnisse der unveränderten Records plus Workspace-Ergebnisse der geänderten, mit Hinweis an
  den geänderten Zeilen) oder nur die Änderungen des Workspace (wie ein Diff gegen Live).
- Umgang mit Löschen im Workspace: Das Live-Ergebnis des Records darf im Workspace nicht mehr
  erscheinen, der Delete-Placeholder braucht dafür eine Status-Zeile mit `workspace = <id>`
  ohne Ergebnisse.

## Plan in Phasen

1. **Prüfen:** Records mit Workspace-Version mit Overlay (`BackendUtility::workspaceOL`)
   prüfen, Ergebnisse und Status mit `workspace = <id>`. Records, die es nur im Workspace gibt
   (New-Placeholder), und Delete-Placeholder gehören dazu.
2. **Seitenziele im Workspace:** `PageChecker` liest die Seite direkt aus der Tabelle
   (`getPage()`, `getParentPages()`) und fragt `FrontendPageResolver` an (anonymer Besucher,
   Live-Kontext). Beides muss mit Workspace-Overlay und Workspace-Kontext laufen, damit ein
   Link auf eine im Workspace neue oder dort versteckte Seite richtig bewertet wird. Das ist
   der unsicherste Teil.
3. **Hook:** Speichern im Workspace prüft die Version. Veröffentlichen und Verwerfen
   (`discard`) räumen die Workspace-Ergebnisse weg, nach dem Veröffentlichen wird Live neu
   geprüft.
4. **Oberfläche und Queue:** Modul mit `'workspaces' => 'all'`, Report, "Check links", erneutes
   Prüfen (`LinkRechecker`: `applyResultToTarget()` muss nach Workspace begrenzen), Widget,
   `CheckRecordsMessage`/`CheckDueRecordsMessage` und `fillqueue` mit Workspace.
5. **Tests** mit `$coreExtensionsToLoad = ['workspaces']` und DataHandler-Workspace-Operationen,
   Doku, README.

## Stellen im Code, die davon betroffen sind

- `PageRecordFinder::findOnPages()` filtert auf `t3ver_wsid = 0`.
- `RecordCheckService::checkRecord()` lädt mit `BackendUtility::getRecord()` (ohne Overlay).
- `RecordStatusRepository` und `BrokenLinkRepository` nutzen `workspace = 0` fest
  (`createQueryBuilderForPids()`, `markChecked()`, `findOneVisible()`, ...).
- `DataHandlerHook` ignoriert alles außer dem Live-Workspace (`isLiveWorkspace()`).
- `tx_linkvalidator2_target` ist pro Ziel, nicht pro Workspace: Ergebnisse interner Ziele
  (Seite, Record) können vom Workspace abhängen und dürfen dort nicht aus dem gemeinsamen
  Ziel-Ergebnis übernommen werden.
