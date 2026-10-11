# #92822 – Ignore button for link targets

- Forge: https://forge.typo3.org/issues/92822 (Feature, Under Review, angelegt 2020-11-11)
- Gerrit: https://review.typo3.org/c/Packages/TYPO3.CMS/+/66608 (abandoned)
- Verwandt: #85127, #92297, #101670
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)

## Problem

Falsch-Positive lassen sich nicht wegklicken. Wunsch: „Ignorieren“-Button pro Ziel im
Report, Ausschluss als Datensatz (exakt, später editierbar), zentral oder pro Site,
eigene Liste der ignorierten Ziele (auch als Sammlung für Bugreports).

## Analyse linkvalidator2

Geplant als Schritt 5 (Notes „Ausschlüsse“): Datensätze `tx_linkvalidator_exclusion`
(TCA) mit exakter URL oder Domain inkl. Subdomains + Grund, Scope global, Anlegen aus
dem Report, Tab im Modul statt eigenem Modul, Recht per Custom Permission (oder Admin).

Entscheidungen gegenüber dem Issue:
- Scope nur global (nicht pro Site) – einfacher, Ziel-URLs sind siteübergreifend
  gleich. Bei Bedarf später `pid`/Site-Bezug.
- Kein Regex (Domain + exakt reichen; Regex ist für Redakteure fehleranfällig).
- Zusätzlich TODO „Vorkommen akzeptieren“ (Flag auf einer `broken_link`-Zeile) für
  einzelne Stellen, die bewusst so bleiben.

Umsetzungshinweise: Ausschluss wirkt vor der Prüfung (kein HTTP) und beim Anzeigen;
beim Anlegen vorhandene `broken_link`-Zeilen mit dem Ziel sofort entfernen bzw.
ausblenden.

## Lösung (2026-10-11)

- Datensätze `tx_linkvalidator2_exclusion` auf einer Seite, gültig für deren Teilbaum (Root-
  Ebene: überall) – statt global; so dürfen auch Redakteure ausschließen, nur in ihrem
  Bereich. Typen: „URL oder Domain“ (alles, was damit beginnt, inkl. Subdomains) und Zielseite. Regex
  bewusst nicht, aber über einen eigenen `ExclusionMatcherInterface` nachrüstbar.
- Greift vor der Prüfung (kein HTTP-Request), Ergebnisse verschwinden beim Anlegen sofort
  (DataHandler-Hook), beim Entfernen werden die Datensätze des Teilbaums fällig.
- Report: „Ignorieren“ pro Ergebnis (URL / Domain / Seite), Ansicht „Ausschlüsse“ im Modul
  (Liste mit Levels, Auswahl, geerbte Ausschlüsse).
- Tests: `Tests/Unit/Exclusion/ExclusionMatcherTest`, `Tests/Functional/Exclusion/*`,
  `Tests/Functional/Controller/ExclusionControllerTest`.
- Offen, nicht Teil des Issues: „Vorkommen akzeptieren“ (einzelner Link),
  `excludedDomains` per Deployment (später Site Setting).
