# #89266 – Prevention of running link checking more than once and visible feedback about still running checks

- Forge: https://forge.typo3.org/issues/89266 (Feature, New, angelegt 2019-09-25)
- **Status linkvalidator2:** 📋 offen (Browser-Batches)

## Problem

Prüfung im Modul: keine Rückmeldung, wann sie fertig ist, keine Sperre gegen parallele
Läufe (→ Duplikate), während des Laufs zeigt der Report „keine kaputten Links“, weil
vorher alles gelöscht wurde.

## Analyse linkvalidator2

- Duplikate/leerer Report: strukturell gelöst. Ergebnisse werden pro Datensatz
  ersetzt (`replaceForRecord()`), nicht vorher pro Seite gelöscht; der Report zeigt
  während eines Laufs den letzten Stand.
- Feedback: die Prüfung ist heute **synchron** mit Flash-Message am Ende („N Datensätze
  auf M Seiten geprüft“) und Zeitbudget für externe URLs
  (`ReportController::EXTERNAL_TIME_BUDGET`). Bei großen Bäumen droht der
  Request-Timeout.
- Parallelität: zwei gleichzeitige Prüfungen desselben Datensatzes könnten sich
  überschneiden (`replaceForRecord` = DELETE + INSERT). Prüfen, ob in einer Transaktion.

## Zu tun

- Browser-Batches (geplant vor externer Live-Prüfung): AJAX-Route ruft den Queue-
  Service mit ~5 s Budget, Fortschrittsbalken, Ende-Meldung, Report lädt danach neu.
- `replaceForRecord()` in Transaktion, damit parallele Läufe keine Duplikate erzeugen.
- Optional: Lock (`LockFactory`) pro Seitenbereich für das Modul.
