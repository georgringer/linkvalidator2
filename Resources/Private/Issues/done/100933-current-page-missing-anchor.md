# #100933 – Checking internal links to current page with missing anchor is not reported as broken link

- Forge: https://forge.typo3.org/issues/100933 (Bug, Under Review, angelegt 2023-05-31)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/79154 (offen, Core-Softref)
- Verwandt: #100934
- **Status linkvalidator2:** ✅ erledigt durch Entscheidung (nicht umsetzen, 2026-10-11) –
  Issue soll geschlossen werden

## Problem

`t3://page?uid=current#99999999` mit nicht existierendem Inhaltselement wird nicht als
kaputt erkannt; `t3://page?uid=27#99999999` schon. Ursache im alten linkvalidator:
`TypolinkTagSoftReferenceParser` liefert `uid=current` nicht.

## Entscheidung (2026-10-11)

Anker werden nicht geprüft – weder `#c<uid>` noch freie Fragmente, auf keiner Seite.

- Ein Fragment kann alles sein: `id` aus dem Template, aus einem Plugin, aus JS, ein
  Sprungziel im RTE-Text. Dass `#c123` immer das Inhaltselement 123 meint, ist eine zu
  einfache Annahme; der alte linkvalidator meldet damit Fehlalarme (und übersieht echte
  Fehler bei allen anderen Ankern).
- Sicher wäre nur das gerenderte HTML – das ist ein Frontend-Request pro Zielseite und
  passt nicht in den Prüflauf. Wer das will, registriert einen eigenen Checker für den Typ
  `page` (siehe `Documentation/todo.md`, „Check pages through the frontend“).
- Geprüft wird die Zielseite; das Fragment wird ignoriert. `t3://page?uid=current`
  (mit oder ohne Fragment) aus Inhalten ist valide, solange der Core `current` auflöst,
  und wird übersprungen. Ausnahme: eine Link-Seite, deren Ziel `current` ist, verweist auf
  sich selbst → broken `page.linksToItself` (der `PageLinkBuilder` verlinkt sie nicht).
  Todo (Notes, „Interne Links“): fällt `current` im Core weg, als kaputt melden.

Damit gibt es nichts zu beheben; den Core-Patch 79154 braucht linkvalidator2 nicht.
Abweichung zum alten linkvalidator: `#c<nicht vorhanden>` auf anderen Seiten wird nicht
mehr gemeldet (README, Feature-Vergleich).
