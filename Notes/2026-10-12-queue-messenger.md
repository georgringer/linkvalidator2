# Queue mit Symfony Messenger (Entwurf)

Status: Entwurf aus der Diskussion vom 2026-10-12. Umgesetzt (2026-10-12): `fillqueue`,
`CheckRecordsMessage`, `CheckRecordsHandler`, Routing auf `doctrine`. Abweichung vom Entwurf: es
gibt nur noch Nachrichten pro Tabelle mit bis zu `--chunk-size` Records (kein `CheckPageMessage`),
das Aufräumen verschwundener Records macht `fillqueue` selbst beim Durchlaufen. Der Handler leert den Runtime-Cache pro Nachricht (siehe unten). Danach umgesetzt:
`RecordQueue` und der Queue-Modus von `linkvalidator2:check` ohne Optionen sind entfernt,
`check --page` läuft über dieselbe Seitenbaum-Abfrage und gibt den Fortschritt aus. Offen sind
`--only-if-empty`, der Hook, `CheckCommand` ohne Optionen und die Messung mit großen Beständen.

## Ausgangslage

- `RecordQueue` berechnet bei jedem Lauf, welche Records dran sind: nie geprüft
  (LEFT JOIN auf `tx_linkvalidator2_record_status`), dann nach `last_checked`
  (Keyset-Paging). `CheckCommand` führt das aus, mit eigenem Lock.
- `tx_linkvalidator2_record_status` hat eine Zeile pro geprüftem Record, **auch für
  Records ohne einen einzigen Link**. Sonst gälte ein Record ohne Links ewig als
  "nie geprüft". Die Tabelle wächst mit allen prüfbaren Records.
- Alles läuft in einem Prozess nacheinander. Keine Parallelität.
- `$checked[$table:$uid]` im Queue-Lauf wächst linear mit der Zahl der Records
  (grob 100 Byte pro Eintrag). Es verhindert nur doppelte Prüfungen im selben Lauf.

## Idee

Der Core bringt seit 14.2 `messenger:consume` mit (Symfony Messenger, Doctrine-
Transport). Die Queue wird zur Nachrichten-Queue des Core, die Extension liefert
Producer und Handler. Es gibt **keine Erkennung, welche Records dran sind**: ein
Command füllt die Queue für eine Seite samt Teilbaum mit allem, was dort liegt.

Drei Einstiegspunkte, eine Prüflogik (`RecordCheckService::checkRecord()`):

1. **Backend**: Seitenweise per AJAX wie bisher (`CheckController`). Unverändert.
2. **CLI mit Queue**: `fillqueue` reiht ein, `messenger:consume` arbeitet ab.
3. **CLI ohne Queue**: `--record` / `--page` prüfen direkt, ohne Messenger. Bleibt
   für Skripte, Debugging und kleine Installationen.

## Queue füllen

`linkvalidator2:fillqueue <pageId>` (optional `--depth`, Standard: der ganze Teilbaum):

1. Ab der angegebenen Seite den Seitenbaum nach unten laufen, **schrittweise** (Ebene
   für Ebene, nicht alle IDs auf einmal im Speicher).
2. Je Seite die prüfbaren Tabellen (`CheckableFieldResolver::getCheckableTables()`)
   mit `pid = Seite` ansehen und einreihen.
3. Records ohne Seite im Baum (`pid = 0`, z. B. `sys_redirect`, `sys_webhook`,
   `sys_news`) gehören zu keiner Seite und werden bewusst nicht geprüft.

Es gibt keinen Begriff "Site" im Command: ob die Seite eine Site-Wurzel ist oder
irgendeine Seite, ist egal. Wer mehrere Bereiche mit unterschiedlichem Rhythmus will,
ruft das Command mehrmals auf (z. B. im Scheduler je Eintrag). **Überschneidungen sind
Sache des Integrators** und harmlos: doppelt eingereihte Seiten kosten nur Zeit (siehe
Dubletten), deshalb muss der Abstieg auch nicht an verschachtelten Site-Wurzeln
anhalten.

Wegen der Dubletten braucht der Producer kein Gedächtnis. Er sollte nur nicht
schneller füllen, als abgearbeitet wird: bei nicht leerer Queue warnen oder mit
`--only-if-empty` überspringen. Ob der Doctrine-Transport die Nachrichtenanzahl
liefert (`MessageCountAwareInterface`), ist zu prüfen.

## Nachrichten: pro Seite, große Seiten in Stücken

Pro Seite eine Nachricht (`CheckPageMessage(pageUid)`), Handler ruft
`PageCheckService::checkPage()` auf, wie das Backend-Modul. Die Queue hat so viele
Nachrichten wie Seiten, nicht wie Records, und der Abgleich "Record gibt es nicht mehr"
(`removeStaleResults`) liegt schon in `checkPage`.

**Problem: Seiten mit sehr vielen Records**, z. B. ein News-Ordner mit 20.000 Records.
`checkPage()` lädt bei jedem Aufruf alle Records der Seite (`findOnPage`) und alle
schon geprüften (`findRecordsCheckedSince`). Eine Seite in vielen Fortsetzungs-
Aufrufen mit Zeitbudget abzuarbeiten, wiederholt diese Arbeit jedes Mal (O(n) pro
Aufruf), und ein einzelner Handler-Lauf wird sehr lang.

Lösung: der Producer zählt je Seite und Tabelle (`COUNT` über den `pid`-Index) und
teilt große Seiten auf:

- Seite bis zu N Records (N etwa 200): eine `CheckPageMessage`.
- Größer: je Tabelle Stücke zu N Records (`CheckRecordsMessage(table, uids[])`, die
  uids per Keyset sortiert nach `uid`).

Dafür braucht der Handler eine Methode "diese Records prüfen" neben `checkPage()`.
Offen: der Abgleich verschwundener Records (`removeStaleResults`) gehört dann zu
einer eigenen Nachricht je Seite, sonst läuft er bei Stücken nie.

## Dubletten sind egal

Ein Record oder eine Seite doppelt in der Queue ist harmlos: `checkRecord()` ersetzt die
Ergebnisse eines Records komplett (`replaceForRecord`), externe URLs kommen aus dem
Target-Cache. Es gibt keinen zweiten HTTP-Request, solange die Cache-Lebensdauer läuft.
Damit entfallen das `$checked`-Array und jede Dubletten-Verwaltung.

## Was verworfen wurde

Geprüft mit `Notes/refindex-coverage.php` auf den DDEV-Daten (7967 prüfbare Records,
5351 davon mit Links):

- **Reference Index als Kandidatenquelle** taugt nicht. Der Core indexiert externe URLs
  nicht (`sys_refindex` hat keine Zeile für den Parser `url`, `typolink` nur für
  Seiten, Dateien, Records). 48 Records mit Links hatten keine Index-Zeile, 990 Records
  hatten Index-Zeilen ohne Links. Der Index war außerdem veraltet (5231 Records laut
  `referenceindex:update --check`).
- **`tstamp`-Wasserstand pro Tabelle** wäre möglich, hätte aber Lücken bei direkten
  DB-Änderungen und bräuchte eine Regel für Tabellen ohne `UpdatedAt`-Capability. Mit
  `fillqueue` nicht nötig.

## Rootline und Sichtbarkeit

Die Rootline spielt beim Füllen nur als Seitenbaum eine Rolle (Abstieg ab der angegebenen Seite).
Ob eine Quelle über eine Elternseite versteckt ist (`extendToSubpages`, "Schritt 7"),
sollte der **Report** beim Anzeigen entscheiden, nicht die Prüfung. Eine versteckte
Elternseite ändert nichts an den Records darunter, es muss also nichts neu geprüft
werden.

## Betrieb

- `messenger:consume` blockiert im Scheduler andere Tasks (Changelog 14.2). Es braucht
  `--time-limit` unter dem Cron-Intervall oder einen echten Cron bzw. systemd-Dienst.
  `fillqueue` läuft problemlos über den Scheduler.
- Retry und Failure-Transport kommen vom Messenger.
- Mehrere Worker sind möglich. Das ist der größte Gewinn, bringt aber eine Frage mit:
  zwei Worker können gleichzeitig dieselbe Domain abfragen. Eine Höflichkeitsregel
  pro Domain (Forge #89287) braucht dann gemeinsamen Zustand (Cache oder Lock pro
  Domain).
- Default-Routing: Nachrichten ohne Routing verarbeitet der Messenger synchron. Die
  Extension muss das Routing auf den Doctrine-Transport mitliefern oder in der Doku
  verlangen. Offen.
- Der DataHandler-Hook kann einreihen statt sofort zu prüfen. Offen, ob die Prüfung
  interner Ziele weiter sofort läuft (so ist es heute) und nur externe URLs in die
  Queue gehen.

## Worker-Prozess: Runtime-Cache und Datenbankverbindung

Der Core schließt nach jeder Nachricht die Datenbankverbindung
(`DoctrineCloseConnectionMiddleware`). `BackendUtility::BEgetRootLine()` hält aber ein
vorbereitetes Statement im Runtime-Cache. Die zweite Nachricht im selben Worker scheitert
dann mit `MySQL server has gone away`, die Nachricht wird nach den Wiederholungen
verworfen und es sieht aus, als hätte der Worker alles abgearbeitet. Der Handler leert
deshalb den Runtime-Cache vor jeder Nachricht (das ist im langlebigen Worker auch aus
Gründen der Aktualität richtig). Forge-Meldung dazu: `fileadmin/forge/`.

## Was aus dem Bestand wird

- `RecordQueue` und das Keyset-Paging entfallen, ebenso das eigene Lock und das
  `$checked`-Array im Command.
- `record_status` bleibt, aber nur noch für den Fortschritt innerhalb eines Laufs
  (`findRecordsCheckedSince`), Zähler im Report und den Abgleich. Ob die Zeile für
  Records ohne Links nötig bleibt, ist offen. `markChecked()` wird zum Upsert.
- `CheckCommand` ohne Optionen: Alias für `fillqueue` oder entfällt. Offen.
- README: Zeilen "Scheduler" und "CLI command" in der Feature-Tabelle anpassen, sobald
  etwas davon gebaut ist.

## Messung mit großem Datenbestand

DDEV, MariaDB 10.11, PHP 8.5, ein Container, Messdaten per SQL: ein Seitenordner mit
20.000 Inhaltselementen (jedes fünfte mit kaputtem Link) und ein Seitenbaum mit 19.609
Seiten (7 Unterseiten pro Seite, 5 Ebenen) mit je einem Inhaltselement, jedes zehnte mit
kaputtem Link. Zusammen 59.216 Records. Kein externer Link, es geht um die Datenbank- und
Speicherseite, nicht um HTTP.

| Was | Zeit | Spitze RSS |
|---|---|---|
| `fillqueue` für den Ordner (20.001 Records, 801 Nachrichten) | 0,6 s | 116 MB |
| `fillqueue` für den ganzen Baum (19.609 Seiten, 59.216 Records, 2.370 Nachrichten) | 3,5 s | 117 MB |
| dasselbe nochmal, mit 65.000 vorhandenen Status-Zeilen (Aufräumen) | 3,4 s | 125 MB |
| Worker, 2.270 Nachrichten (56.700 Records, 11.000 Ergebnisse) | 45 s | 114 MB, flach |
| `markChecked()` 5.000 mal (Zählen plus Schreiben) | 0,5 s | |
| Report für den ganzen Baum, unbegrenzte Tiefe | 4,0 s, jetzt 0,5-0,7 s | 113 MB |

Ergebnisse:

- **`fillqueue` und Worker tragen:** Der Speicher wächst nicht mit der Größe, ein Worker
  schafft etwa 800 bis 1.250 Records pro Sekunde (ohne HTTP). Die Seite mit 20.000 Records
  ist unkritisch: sie wird in 801 Nachrichten zerlegt.
- **`markChecked()` als Upsert ist unnötig:** 0,1 ms pro Aufruf.
- **Engpass im Report war `findPageIds()`:** Die Core-Funktion
  `PageRepository::getDescendantPageIdsRecursive()` fragt eine Seite nach der anderen ab
  und brauchte 2,8 s für 19.609 Seiten. Der Ebenen-Walker (`iteratePageIdBatches()`) braucht
  0,11 s. `findPageIds()` nutzt jetzt den Walker. Mount-Points werden dabei nicht mehr
  verfolgt: die Records einer eingebundenen Seite werden dort geprüft, wo sie liegen.
- **Echte Grenze beim Report: etwa 65.000 Seiten.** `countByPids()`, `findByPids()` und
  `findFilterValues()` hatten die Seiten als Platzhalter in einem `IN (…)`. Bei 60.000
  ging es noch, ab 66.000 brach es mit "Prepared statement contains too many placeholders"
  ab. Die Seiten stehen jetzt als Ganzzahlen im SQL, ein Test mit 100.000 Seiten sichert
  das auf MariaDB ab (auf SQLite fällt es nicht auf, das Limit liegt dort höher).
- **Nicht gemessen:** externe URLs (dort bestimmen die Timeouts, nicht die Datenbank), mehrere
  Worker gleichzeitig und andere Datenbanken als MariaDB.

## Offen

- Schwelle N und das Aufteilen großer Seiten (`CheckRecordsMessage`), Abgleich
  verschwundener Records bei Stücken.
- Queue-Länge abfragbar (`--only-if-empty`)?
- Routing und Transport-Default.
- Hook: sofort prüfen oder einreihen.
- Lock-Verhalten und Abbruch mitten im Lauf in einem Test belegen.
