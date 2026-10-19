# Mail-Report (Entwurf)

Stand 2026-10-19, Ergebnis der Abstimmung. **Umgesetzt am selben Tag** (Plan: `2026-10-19-mail-report-plan.md`); Abweichung: die Sprache der Mail heißt `--mail-language`, weil `--language` den Sprachfilter meint. Ersetzt den Punkt "Mail-Report" aus
`2026-10-08-linkvalidator-rewrite.md` (dort noch "pro Site", "Delta seit letztem Report",
`last_report_sent`), betrifft #106487 und #106677.

Herkunft der Aussagen: **(Georg)** = so entschieden, **(Annahme)** = meine Wahl, bitte prüfen.

## Absicht

Redakteure und Betreuer sollen kaputte Links per Mail bekommen, ohne das Modul zu öffnen. Ein
Bericht gilt für einen Seitenbereich, nicht für eine Site, damit sich Bereiche automatisch an
bestimmte Personen schicken lassen, analog zum Modul. (Georg)

## Ziele

- Ein Command `linkvalidator2:report` (Name: Annahme), der pro Aufruf **einen** Bericht an
  **eine** Empfängergruppe schickt. Mehrere Bereiche oder Empfänger = mehrere Aufrufe, z. B. als
  Scheduler-Aufgabe "Execute console commands" (dort sind Schalter als Checkboxen sichtbar). (Georg)
- Bereich = Page-Id plus Tiefe, genau wie im Modul. (Georg)
- Filter wie im Modul: Status, Linktyp, Sprache, Freitext. Für Tabellen
  `--include-tables` und `--exclude-tables` (Listen). (Georg)
- Altersfilter über `first_detected`: `--min-age` (mindestens so lange kaputt, Erinnerung) und
  `--max-age` (höchstens so lange kaputt, "neu"); beide zusammen ergeben einen Bereich. Beide
  Optionen sind optional. (Georg; Namen: Annahme)
- Inhalt wählbar mit `--content=inline|attach|both` (Name: Annahme, Modi: Georg). (Georg)
  - `inline`: nur die wichtigsten Angaben im Mailtext: Seitentitel, Datensatz, Ziel-URL, Fehler
    im Klartext, kaputt seit, Link zum Bearbeiten.
  - `attach`: Mailtext nur mit Zusammenfassung (Zähler, Link ins Modul), alle Treffer samt aller
    Spalten als CSV im Anhang.
  - `both`: Kurzliste inline und die vollständige CSV.
  - Zusätzliche CSV-Spalten: Tabelle, Uid, Feld, Page-Id, Sprache, Linktext, Status, Fehlertyp,
    Fehlerdetails, Linktyp, zuletzt gesehen, Link ins Modul. (Annahme, von Georg bestätigt)
- `--send-empty`: verschickt die Mail auch ohne Treffer. Ohne den Schalter bleibt sie dann aus. (Georg)
- Volle, klickbare Ziel-URL; Links zum Bearbeiten und ins Modul mit absoluter Backend-URL. (Georg, aus #106677)

## Nicht-Ziele

- **Kein gespeicherter Zustand.** Kein `last_report_sent`, keine Tabelle pro Bericht. Dieselben
  Links dürfen in mehreren Mails stehen; ausgefallene oder doppelte Läufe werden in Kauf genommen. (Georg)
- Keine Berechtigungsprüfung pro Empfänger. Der Integrator legt über die Tabellenlisten fest, was
  eine Adresse bekommt. (Georg)
- Keine Berichte, die Redakteure im Backend selbst anlegen. (Annahme; folgt aus der Scheduler-Variante)
- Kein Erkennen von "repariert" (nur kaputte bzw. nach Filter gewählte Links erscheinen).
- Keine PSR-14-Events in der ersten Version (README: nur wo nötig). (Annahme)

## Anti-Ziele

- Eine Mail, die bei großen Beständen riesig wird: `inline` hat eine Obergrenze (siehe unten).
- Ein Report, der Links aus Bereichen verrät, die der Integrator nicht eingestellt hat: der Bereich
  kommt nur aus Page-Id und Tiefe, nie aus dem ganzen System.

## Entscheidungen aus dem Gespräch, die den Bau bestimmen

- Die Abfrage ist dieselbe wie im Modul: `BrokenLinkQuery` mit Seitenliste, `ReportFilter` und
  `permissions: null`. `detectedSince` gibt es bereits; für `--min-age` fehlt eine zweite Grenze
  (`detectedBefore`) in `BrokenLinkQuery` und `BrokenLinkRepository`.
- `ReportFilter::table` ist heute eine einzelne Tabelle. Für include/exclude braucht es Listen; das
  Modul bleibt bei der einen Tabelle.
- `first_detected` bleibt erhalten, solange ein Link kaputt bleibt; wird er repariert, ist die Zeile
  weg, bei erneutem Defekt beginnt `first_detected` neu
  (`BrokenLinkRepository::replaceForRecord()`, nachgelesen).
- Der Core entscheidet über das Senden aus dem Vergleich der Zähler pro Linktyp vor und nach dem Lauf
  (`isDifferentToLastResult()`). Das wird bewusst nicht übernommen.

## Meine Entscheidungen (Annahmen, bitte prüfen)

1. **Absender, Empfänger:** `--to` (Liste, Komma oder mehrfach), Pflicht. Absender und Reply-To aus
   dem System (`MailUtility::getSystemFrom*`), überschreibbar mit `--from`.
2. **Betreff:** `--subject` als Freitext; Standard ist "Broken links: <Seitentitel der Start-Seite>".
3. **Backend-URL:** aus der Page-Id. Die Site der Start-Seite (`SiteFinder`) liefert Host und Schema;
   damit wird ein Request für `BackendEntryPointResolver::getUriFromRequest()` gebaut, der die absolute
   Modul-URL liefert. Ist `BE/entryPoint` eine volle URL (Backend auf anderer Domain), gewinnt sie;
   ist er relativ, liegt das Backend auf dem Host der Site. `--base-url` ist nur ein Notnagel für
   Seiten ohne Site, sonst bricht der Command mit Fehler ab.
4. **Standardstatus:** nur `broken`. `--status` ändert das. Das Modul zeigt standardmäßig alle Status;
   für die Mail wären "Hinweis" und "nicht prüfbar" sonst Rauschen.
5. **Obergrenze inline:** `--inline-limit`, Standard 50. Darüber steht "und N weitere" mit Link ins
   Modul. Reihenfolge: nach `first_detected` aufsteigend (ältester zuerst).
6. **Alter:** Werte als `DateInterval`-String ("7 days", "1 day", "12 hours").
7. **Template:** Fluid-Mail über `TemplatedEmailFactory`, Template überschreibbar über
   `--template` wie `emailTemplateName` im Core; Texte in der Domain `linkvalidator2.messages`.
8. **Sprache der Mail:** Option `--mail-language` (Standard `default`), weil der Command keinen
   Backend-User hat.
9. **Bei `attach`/`both` ohne Treffer** (nur mit `--send-empty`) keine leere CSV anhängen.
10. **Nur Live-Workspace**, wie die ganze Extension. Mit den Workspaces (Pflicht, siehe
    `2026-10-12-workspaces.md`) ist zu klären, ob ein Bericht den Workspace kennt.
11. **Aufruf:** Page-Id als Pflichtargument, `--depth` mit Standard 0 wie im Modul. Geben
    `--include-tables` und `--exclude-tables` zusammen, gilt zuerst include, dann exclude.
12. **Fehler beim Senden:** Exit-Code ungleich 0 und Meldung; Transportfehler dürfen den
    Scheduler-Lauf als fehlgeschlagen markieren.

## Dem Bauenden überlassen

- Aufbau der Klassen und des Templates, Namen innerhalb von `Classes/`, CSV-Details (Trennzeichen,
  Kodierung, Kopfzeile). Vorschlag: UTF-8 mit BOM, Semikolon, wie Excel in DE es erwartet, nur wenn
  das ohne Zusatzabhängigkeit geht.
- Aufteilung in Services (z. B. Zusammenstellen des Berichts getrennt vom Versand).

## Tests und Doku

- Functional: Command mit Testdaten, Mailer auf Null-Transport; geprüft wird, ob und mit welchem
  Inhalt gesendet wird (Filter, `min-age`/`max-age`, include/exclude, `--send-empty`,
  `--content`-Modi, Obergrenze).
- Unit: Alters-Parser, CSV-Erzeugung.
- README: Zeilen "E-mail report", "Site name in the mail" und "Full, clickable target URL" aktualisieren.
- `Documentation/todo.md`: Scheduler-Beispiele pro Bereich, Tabellenlisten statt Berechtigungen.
- Forge-Issues #106487 und #106677 nachziehen (Status, Index).
