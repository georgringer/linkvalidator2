# Externe URLs nach dem Speichern ohne Worker prüfen (Idee)

Stand 2026-10-19, am Code geprüft (`DataHandlerHook`, `DueQueue`, `ext_localconf.php`).

## Ist-Zustand

Beim Speichern prüft `DataHandlerHook::checkRemembered()` Records sofort: interne Links
direkt, externe nur aus dem URL-Cache (`checkRecord(..., cachedOnly: true)`). Records mit
noch nicht geprüften externen URLs gehen über `DueQueue::queue()` als
`CheckDueRecordsMessage` (Chunks à 25) auf den Transport `linkvalidator2_priority`, den ein
Worker vor der regulären Queue abarbeitet.

Die Messenger-Variante ist also umgesetzt. Lücke: **ohne laufenden Worker**
(`messenger:consume`) bleibt der Record fällig, bis der reguläre Lauf kommt; der
Redakteur sieht externe Links nicht im Report.

## Vorbild: wazum/solr-eager-flush

Dieselbe Problematik beim Solr-Indexieren (Save schreibt nur in die Queue). Die Extension
arbeitet die Queue am Ende desselben Requests ab:

- **Nach der Antwort:** `register_shutdown_function`, davor `fastcgi_finish_request()`
  (bzw. LiteSpeed) mit `ignore_user_abort(true)` und `session_write_close()`. Der Save ist
  nicht langsamer. Klappt das Ablösen nicht, wird nur geloggt.
- **Ein Flush pro Request:** Der Scheduler sammelt Site-Roots, hängt den Flush einmal an.
  (`DataHandlerHook` sammelt schon in `checkRemembered()`.)
- **Gates:** Rückstau (mehr als `indexQueueLimit` Items), laufender Worker, Lock pro Site
  (nicht blockierend: wer ihn nicht bekommt, gibt auf), Solr nicht erreichbar, CLI.
- **Deckel:** `deltaMax` Items pro Request.
- **Fehler** werden geloggt und brechen das Speichern nie ab. Was ausgelassen wird, bleibt
  für den regulären Worker in der Queue.

## Mögliche Umsetzung

Nach `queue()` die gerade fälligen externen URLs im selben Request nach der Antwort prüfen,
nur wenn:

- die Antwort abgelöst werden kann (`fastcgi_finish_request` vorhanden), sonst wartet der
  Redakteur auf die HTTP-Requests, schlimmer als heute;
- kein Worker die Priority-Queue abarbeitet (sonst doppelte Prüfung) und kein Rückstau;
- ein nicht blockierender Lock frei ist;
- ein Deckel pro Request nicht überschritten ist.

## Nachteile

- Ein langsamer Zielserver belegt bis `externalTimeout` Sekunden einen FPM-Worker, auch
  nach der Antwort. Viele Saves gleichzeitig können den Pool leeren, deshalb Deckel und Lock.
- Zweiter Weg neben dem Worker: mehr Zustände (Worker läuft/läuft nicht) und schwerer zu
  testen. Ob "kein Worker" zuverlässig erkennbar ist, ist offen; solr-eager-flush prüft
  dafür die Scheduler-Aktivität, hier gäbe es kein Gegenstück.
- Das Ergebnis erscheint erst nach dem Reload des Reports, nicht beim Speichern.
- Ohne FPM/LiteSpeed (mod_php, Built-in-Server) bringt es nichts.

## Offen

- Lohnt sich der Aufwand, wenn ein Worker ohnehin Pflicht für `fillqueue` ist? Wer ihn
  betreibt, braucht das nicht; es hilft nur Installationen ohne Worker.
- Alternative ohne zweiten Weg: im README/der Doku klar sagen, dass externe Links nach dem
  Speichern einen laufenden Worker brauchen.
