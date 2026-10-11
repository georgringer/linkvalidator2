# #92586 – Configuration for linktypes not considered in scheduler for linkvalidator

- Forge: https://forge.typo3.org/issues/92586 (Bug, New, angelegt 2020-10-18)
- **Status linkvalidator2:** ✅ gelöst (entfällt durch Konzept)

## Problem

Wird im Scheduler `mod.linkvalidator.linktypes=external` gesetzt, prüft der Task nur
externe Links, der Report zeigt aber weiter alte Ergebnisse der anderen Typen
(veraltet). Wunsch: Prüf- und Report-Konfiguration trennen, Ergebnisse „frisch“.

## Analyse linkvalidator2

Die Ursache entfällt:

- Es gibt keine Linktyp-Auswahl für die Prüfung mehr (kein Page TSconfig, kein eigener
  Task). Ein Lauf prüft immer alle Links eines Datensatzes und ersetzt dessen
  Ergebnisse vollständig (`BrokenLinkRepository::replaceForRecord()`) – teilweise
  veraltete Ergebnisse eines Datensatzes kann es nicht geben.
- Teure Prüfungen (extern) werden über den Cache gesteuert, nicht über Linktypen.
- Die Auswahl nach Linktyp ist reine Anzeige (Filter im Modul).

Wer externe Prüfung ganz abschalten will: CLI `--no-external` (nur Cache). Die geplante
Site-Setting „Linktypen“ (Schritt 6) darf dieses Problem nicht wieder einführen:
wenn, dann Linktypen *nicht melden*, aber Ergebnisse trotzdem pro Datensatz komplett
ersetzen.
