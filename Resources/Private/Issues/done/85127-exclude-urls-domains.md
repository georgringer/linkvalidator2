# #85127 – linkvalidator: Add possibility to exclude specific external URLs / domains  or patterns

- Forge: https://forge.typo3.org/issues/85127 (Feature, New, angelegt 2018-05-31)
- Verwandt: #85006 (Parent), #92822, #86918, #99909, #101670
- **Status linkvalidator2:** ✅ gelöst (2026-10-11)

## Problem

Bestimmte externe URLs/Domains/Muster von der Prüfung ausnehmen (LinkedIn, Seiten mit
unvollständiger Zertifikatskette, Cloudflare, Intranet-Systeme hinter SSO).
Admin-editierbar, global reicht.

## Analyse linkvalidator2

Zwei Ebenen:
- Viele der genannten Fälle sind jetzt **unknown** statt broken (403, 999, TLS,
  Timeout) → erscheinen nicht mehr unter „kaputt“ (#96525). Das löst einen großen Teil
  ohne Ausschlussliste.
- Ausschlüsse selbst: Schritt 5, siehe #92822 (Datensätze, exakte URL oder Domain inkl.
  Subdomains, global, aus dem Report anlegbar).

Für Intranet-Fälle zusätzlich sinnvoll: Domain-Ausschluss auch in der
Extension-Konfiguration (Deployment statt Datensatz), z. B. `excludedDomains` – zu
entscheiden.

Regex/Präfix-Muster: bewusst nicht (Domain + exakt), Präfix ggf. nachrüsten, falls
Bedarf.

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
