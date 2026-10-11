# #105911 – Linkvalidator should hide results for hidden records

- Forge: https://forge.typo3.org/issues/105911 (Feature, New, angelegt 2025-01-10)
- **Status linkvalidator2:** 🟡 teilweise

## Problem

Wird ein Datensatz *nach* der Prüfung versteckt, bleibt der kaputte Link im Report
stehen, obwohl versteckte Datensätze sonst ignoriert werden.

## Analyse linkvalidator2

- Versteckte/abgelaufene Datensätze werden nie gemeldet
  (`RecordCheckService::isVisible()`); wird ein Datensatz neu geprüft, verschwinden
  seine Ergebnisse (`removeForRecord`).
- Bis zur nächsten Prüfung bleibt der Eintrag aber stehen – genau das Issue.

Fix:
1. **DataHandler-Hook (Schritt 4)**: beim Speichern sofort neu prüfen → Verstecken
   räumt die Ergebnisse direkt ab. Löst das Issue vollständig für Änderungen im Backend.
2. Für Änderungen am DataHandler vorbei (SQL, Import, `endtime` läuft ab):
   Abgleich beim Prüfen der Seite (siehe #89426) und Anzeige im Modul ggf. per JOIN
   auf die Quelltabelle filtern. Abwägen: JOIN pro Tabelle ist teuer; reicht der
   regelmäßige Lauf?

## Test (Task 9)

Functional: Datensatz mit kaputtem Link prüfen, per DataHandler verstecken →
keine `broken_link`-Zeile mehr.
