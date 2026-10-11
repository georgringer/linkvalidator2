# #97937 – Linkvalidator: Links and &nbsp; in tt_content.bodytext cause problems in UrlSoftReferenceParser

- Forge: https://forge.typo3.org/issues/97937 (Bug, Under Review, angelegt 2022-07-14)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/75200 (abandoned)
- Verwandt: #85006, #98120, #98328, #99909
- **Status linkvalidator2:** ❌ offen (reproduziert)

## Problem

`UrlSoftReferenceParser` auf `tt_content.bodytext`:
1. `https://weber.digital&nbsp;` wird inklusive `&nbsp` als URL erkannt → kaputt.
2. Ein riesiger Treffer über `</a>)<br /> … href="https://linearicons.com"` hinweg.

## Analyse linkvalidator2

Mit dem HTML aus dem Issue gegen den Core-Parser geprüft, so wie
`LinkExtractor::extractPlainUrls()` ihn aufruft (2026-10-11):

```
'https://weber.digital&nbsp'   ← Fehler 1 besteht weiter
'https://www.myfonts.com'
'https://www.fontsquirrel.com/fonts/droid-serif'
'https://iconsmind.com'
'https://linearicons.com'       ← Fehler 2 nicht mehr reproduzierbar
'https://perxis.com'
```

`htmlspecialchars_decode()` dekodiert `&nbsp;` nicht, `rtrim('.,;:!?')` entfernt nur
Satzzeichen. Ergebnis: Host `weber.digital&nbsp` → cURL 6 → **broken
`url.unknownHost`** – ein Fehlalarm.

Fix in `extractPlainUrls()` (nicht im Core-Parser, damit Softref-Index unberührt):
- Vor dem Parsen Entities in Leerraum umsetzen: `&nbsp;` und `&#160;` → Leerzeichen
  (bzw. `html_entity_decode()` + `\xC2\xA0` als Trenner), sodass die URL dort endet.
- Alternativ Treffer an der ersten Entity abschneiden. Erste Variante ist robuster.

Die HTML-Links (Fehler 2) betreffen linkvalidator2 nicht: `<a href>` werden über
`HtmlParser` gelesen, der URL-Parser überspringt URLs nach `"`/`=`.

## Test (Task 9)

Unit-Test für `LinkExtractor` mit genau diesem HTML: erwartet 6 URLs, keine endet auf
`&nbsp`.
