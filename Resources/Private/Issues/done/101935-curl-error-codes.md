# #101935 – Better handling of curl error codes in linkvalidator

- Forge: https://forge.typo3.org/issues/101935 (Feature, New, angelegt 2023-09-17)
- Verwandt: #96525
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)

## Problem

Ein cURL-Code steht für mehrere Ursachen (60: Hostname passt nicht / Zwischenzertifikat
fehlt). Der alte linkvalidator zeigt nur einen lokalisierten Text pro Code, nicht die
echte Meldung. Vorschlag: kurze lokalisierte Meldung + volle Meldung als Detail;
`ssl_verify_result` aus dem Handler-Kontext auswerten.

## Analyse linkvalidator2

- `ExternalUrlChecker::classifyConnectionError()` gruppiert Codes (unknownHost,
  timeout, tlsError, connectionFailed) und speichert die volle Meldung in
  `error_details.message`. TLS-Fehler sind **unknown**, nicht broken – das nimmt dem
  Issue viel Gewicht (keine Fehlalarme mehr).
- Das Modul zeigt aber nur den lokalisierten Fehlertyp
  (`error.{error_type}` mit `httpStatus`), die gespeicherte Meldung nicht.

## Zu tun

- Im Report die Detailmeldung anzeigen (z. B. aufklappbar / `title`), Text
  escaped.
- `errno` und `ssl_verify_result` in `error_details` mitschreiben, damit sich TLS-Fälle
  unterscheiden lassen (z. B. eigener Text für „Zertifikatskette unvollständig“ –
  häufigster Fehlalarm laut #85127).

## Lösung (2026-10-11)

- Report zeigt unter dem lokalisierten Text die cURL-Meldung (escaped), ohne den Hinweis
  „(see https://curl.se/…)“ und ohne die URL, die schon in der Zeile steht. Damit sind
  die Fälle hinter einem Code unterscheidbar (60: „unable to get local issuer
  certificate“ = Kette unvollständig).
- `error_details` enthält `message` und `errno`.
- `ssl_verify_result` bewusst nicht: nur Guzzle 7 liefert den Handler-Kontext, Guzzle 8
  nicht; die Meldung trägt dieselbe Information.
- TLS-Fehler bleiben „nicht prüfbar“, nicht kaputt (#96525).
- Nebenbei: Klassifizierung unter Guzzle 8 repariert (cURL-Nummer aus der Meldung).
- Tests: `Tests/Unit/Check/ExternalUrlCheckerTest`.
