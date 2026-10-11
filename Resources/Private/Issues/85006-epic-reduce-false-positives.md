# #85006 – Reduce falsely reported broken links

- Forge: https://forge.typo3.org/issues/85006 (Epic, New, angelegt 2018-05-14)
- Verwandt: #83847, #84214, #85127, #86918, #89182, #89488, #92297, #101670, #96525, #92822, #89287, #97937
- **Status linkvalidator2:** 🟡 teilweise (Epic)

## Problem

Falsch gemeldete kaputte Links machen das Arbeiten mit linkvalidator mühsam: nicht
wirklich kaputt, nicht editierbar, irrelevant oder veraltet.

## Analyse linkvalidator2

| Ursache laut Epic | Stand |
|---|---|
| Externe Prüfung schlägt fehl (Fehlalarm) | 🟡 unknown-Status für alles Unsichere ✅; Eskalation, Crawl-Delay 📋 |
| 401/403 (Login) | ✅ unknown |
| Felder, die für den Typ nicht gerendert werden (bodytext bei Plugins) | ✅ nur Felder des Record-Types |
| Keine Rechte zum Bearbeiten | 📋 Berechtigungen (#105413, #104004), bis dahin admin-only |
| Veraltete Ergebnisse (gefixt, gelöscht) | ✅ Abgleich beim Prüfen einer Seite + DataHandler-Hook (#89426, #105911) |
| Ausschluss von URLs | 📋 Schritt 5 (#85127, #92822) |
| `&nbsp;` in Text-URLs | ✅ #97937 |
| Versteckte/unsichtbare Quelle | 🟡 eigener Datensatz ✅, Seite/Original/Sprache 📋 Schritt 7 (#94195) |
| Ziel über Elternseite unsichtbar | ✅ Frontend-Seitenauflösung (#106675) |
| Zugriffsbeschränkte/geplante Ziele | ✅ als Hinweis statt kaputt |
| Eigene LinkHandler | ✅ #94086 |

## Zu tun

Nicht als eigene Arbeit – die Kinder abarbeiten. Beim Einzug Forge-Kommentar mit dieser
Tabelle.
