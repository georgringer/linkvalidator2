# #100933 – Checking internal links to current page with missing anchor is not reported as broken link

- Forge: https://forge.typo3.org/issues/100933 (Bug, Under Review, angelegt 2023-05-31)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/79154 (offen, Core-Softref)
- Verwandt: #100934
- **Status linkvalidator2:** 📋 offen (Schritt 8: Anker)

## Problem

`t3://page?uid=current#99999999` mit nicht existierendem Inhaltselement wird nicht als
kaputt erkannt; `t3://page?uid=27#99999999` schon. Ursache im alten linkvalidator:
`TypolinkTagSoftReferenceParser` liefert `uid=current` nicht.

## Analyse linkvalidator2

- Unabhängig vom Softref-Parser: linkvalidator2 liest RTE-Links selbst
  (`LinkExtractor::extractFromHtml()`) und löst über `LinkService` auf.
- `LinkTargetFactory::createPageTarget()` gibt für `pageuid === 'current'` aber `null`
  zurück → komplett übersprungen. Die RTE-Transformation speichert `#top` &
  Co. als `t3://page?uid=current#…`, das ist also häufig.
- Anker werden generell noch nicht geprüft (Schritt 8), `#c999999` gilt heute als ok.

Fix (mit Schritt 8):
- `current` mit Fragment: Seite aus der Quelle bestimmen (`LinkCandidate->pid`, für
  `pages` die Seite selbst) und als `page:<pid>#<fragment>` prüfen.
- `current` ohne Fragment bleibt übersprungen (Link auf sich selbst).
- Anker-Prüfung: `#c<uid>` → `tt_content` existiert, sichtbar, `pid` = Zielseite,
  passende Sprache. Andere Fragmente (`#top`, freie IDs) → nicht prüfbar, ok.

Den Core-Patch 79154 braucht linkvalidator2 nicht.

## Test (Task 9)

Inhalt mit `t3://page?uid=current#c<nicht vorhanden>` → broken `page.anchorMissing`;
mit vorhandenem Element auf derselben Seite → ok.
