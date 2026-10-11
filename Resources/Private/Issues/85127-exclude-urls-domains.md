# #85127 – linkvalidator: Add possibility to exclude specific external URLs / domains  or patterns

- Forge: https://forge.typo3.org/issues/85127 (Feature, New, angelegt 2018-05-31)
- Verwandt: #85006 (Parent), #92822, #86918, #99909, #101670
- **Status linkvalidator2:** 📋 offen (Schritt 5: Ziel-Ausschlüsse)

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
