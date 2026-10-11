# #110615 – Disable the link validator in certain contexts, or run it only in certain contexts

- Forge: https://forge.typo3.org/issues/110615 (Feature, New, angelegt 2026-09-03)
- **Status linkvalidator2:** 📋 offen (Mail-Report, Ausführung)

## Problem

Scheduler-Tasks laufen auch auf Staging und verschicken dort Mails. Den Task im
Backend abschalten hilft nicht, weil die Datenbank von Live synchronisiert wird.
Wunsch: per `additional.php` oder per erlaubtem/verbotenem `TYPO3_CONTEXT` steuern.

## Analyse linkvalidator2

Neue Ausführung: nur das CLI-Command, im Scheduler über „Execute console commands“,
kein eigener Task. Der Mail-Report wird vom Prüflauf entkoppelt und pro Site über
Site Settings konfiguriert (geplant). Damit gibt es zwei saubere Hebel, beide liegen
in Dateien statt in der Datenbank:

1. **Site Settings mit `%env()%`**: Empfänger/Aktivierung des Mail-Reports aus einer
   Umgebungsvariable → auf Staging leer, keine Mail. Kein eigener Code nötig, muss nur
   dokumentiert werden.
2. **Extension-Konfiguration** (in `settings.php`/`additional.php` überschreibbar):
   globaler Schalter „Mail-Report deaktivieren“ bzw. „externe Prüfung deaktivieren“.

Kontext-Listen im Task selbst (Vorschlag im Issue) sind ein allgemeines Scheduler-
Thema, nicht linkvalidator-spezifisch.

## Zu tun

- Beim Mail-Report (später) Schalter in der Extension-Konfiguration vorsehen.
- Doku: `%env()%` in Site Settings für Empfänger.
