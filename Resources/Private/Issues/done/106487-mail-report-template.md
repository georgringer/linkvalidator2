# #106487 – Improve linkvalidator report mail template

- Forge: https://forge.typo3.org/issues/106487 (Feature, Accepted, angelegt 2025-04-02)
- Verwandt: #106677
- **Status linkvalidator2:** ✅ gelöst (2026-10-19)

Umgesetzt mit `linkvalidator2:report` (`Notes/2026-10-19-mail-report-design.md`): Betreff mit Seitentitel oder `--subject`,
Links zum Bearbeiten und ins Modul mit absoluter Backend-URL, Ergebnis wahlweise als CSV-Anhang.

## Problem

1. Multi-Site: viele Reports an verschiedene Empfänger, nicht unterscheidbar →
   Site-Name oder Freitext in der Mail.
2. Direkte Links aus der Mail zum Bearbeiten der betroffenen Datensätze.

## Analyse linkvalidator2

Noch kein Mail-Report. Der geplante Report ist **pro Site** (Site Settings: Empfänger,
Aktivierung) – damit ist (1) strukturell gelöst: Site-Titel/-Base gehört in Betreff
und Kopf. (2) siehe #106677 (absolute Backend-URLs im CLI).

## Zu tun

- Fluid-Template mit Site-Titel im Betreff, optionaler Freitext aus Site Settings.
- Pro kaputtem Link: Seite, Datensatz, Feld, Ziel, Fehler, „kaputt seit“
  (`first_detected`), Link zum Bearbeiten + Link ins Modul.
- Ergebnis als CSV an die Mail anhängen (gleiche Spalten wie im Report, vollständige
  Ziel-URL); gerade bei vielen Treffern besser auswertbar als die Mail selbst.
