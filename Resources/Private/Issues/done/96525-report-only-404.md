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
