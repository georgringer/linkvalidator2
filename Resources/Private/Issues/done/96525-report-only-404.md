# #96525 – Add the possibility to repport only 404 errors (Don't show 403, 999 LinkedIn and SSL problems)

- Forge: https://forge.typo3.org/issues/96525 (Feature, Accepted, angelegt 2022-01-12)
- Verwandt: #85006, #101935
- **Status linkvalidator2:** ✅ gelöst (Klassifizierung), Eskalation 📋 offen

## Problem

LinkedIn (999), 403, cURL 60 (TLS) usw. werden als kaputt gemeldet, obwohl die Seiten
funktionieren. Wunsch: nur 404 melden. Ergänzung Oliver Hader: Schwelle – erst nach
mehreren aufeinanderfolgenden Fehlschlägen als kaputt werten.

## Analyse linkvalidator2

Umgesetzt in `ExternalUrlChecker`: drei Zustände statt zwei.

- **broken** nur bei eindeutiger Antwort: 404, 410, Host unbekannt (cURL 6).
- **unknown** für alles, was am prüfenden Server liegen oder vorübergehend sein kann:
  401, 403, 429, 5xx, 999 und alle anderen Statuscodes, Timeout, TLS-Fehler,
  Verbindungsfehler, zu viele Redirects.
- Das Modul filtert nach Status; „kaputt“ zeigt genau die eindeutigen Fälle.

Die Schwelle aus dem Kommentar ist als Folgeschritt geplant: unknown → broken nach
3 Prüfungen über ≥ 7 Tage. `failure_count` und `first_failed_at` werden in
`tx_linkvalidator2_target` bereits geschrieben, die Auswertung fehlt (`@todo` im Checker).

Zu klären: ob „unknown“ im Modul standardmäßig ausgeblendet sein soll (heute: Filter
leer = alle Status). Spec sagt „unknown nicht in kaputt, eigener Filter“.

## Mögliches Todo: Eskalation und Anzeige

Die Spalten `failure_count` und `first_failed_at` bleiben, solange das hier offen ist. Sie
werden von `TargetRepository::store` gepflegt (Reset bei „ok“, `first_failed_at` bleibt
beim ersten Fehlschlag stehen), aber von nichts gelesen.

- **Eskalation:** `unknown` wird zu `broken`, wenn `failure_count >= 3` und
  `now - first_failed_at >= 7 Tage`. Die Regel gehört in den `RecordCheckService` nach dem
  Check, nicht in den `ExternalUrlChecker`. Schwellen zunächst als Konstanten, später
  eventuell Extension-Konfiguration. Test zuerst: dritter Fehlschlag nach 7 Tagen, zweiter
  Fehlschlag, Reset durch ein „ok“.
- **Anzeige im Report:** Bei Zeilen mit Status `unknown` ein Hinweis wie „seit 12 Tagen,
  4 Prüfungen“. `BrokenLinkRepository::find` selektiert dafür beide Spalten aus `t` mit,
  `ReportController` übernimmt sie in die Zeile, das Fluid-Template gibt sie aus, Label in
  `messages.xlf`. Im Backend prüfen.
- **Entscheidung:** Wird beides nicht gebraucht, die Spalten und den `@todo` im
  `ExternalUrlChecker` entfernen.
