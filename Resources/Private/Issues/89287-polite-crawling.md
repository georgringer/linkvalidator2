# #89287 – Make linkvalidator crawling polite

- Forge: https://forge.typo3.org/issues/89287 (Task, New, angelegt 2019-09-26)
- Verwandt: #85006, #86918, #93547, #101670, #101671
- **Status linkvalidator2:** 🟡 teilweise

## Problem

Linkvalidator ist kein höflicher Crawler: kein erkennbarer User-Agent, kein
`robots.txt`, keine Pause zwischen Requests pro Domain. Vorschläge: externe Prüfung
in separaten Task auslagern, nur Geändertes neu prüfen, Cache.

## Analyse linkvalidator2

| Punkt | Stand |
|---|---|
| Konfigurierbarer User-Agent | ✅ `externalUserAgent` |
| HEAD statt GET, Body nie lesen | ✅ GET nur bei 405/501, gestreamt |
| Cache über Läufe | ✅ (#92700), Default 7 Tage |
| Nur Fälliges prüfen | 🟡 Status-Tabelle als Queue vorbereitet, Command mit `--limit`/`--time-limit` 📋 Task 8 |
| Crawl-Delay pro Domain | 📋 `@todo` im `ExternalUrlChecker` |
| `Retry-After` bei 429 | 📋 `@todo` |
| `robots.txt` | ❌ nicht geplant |

Zu `robots.txt`: ein Linkchecker, der einzelne verlinkte URLs per HEAD abruft, ist kein
Crawler im Sinne von robots.txt (folgt keinen Links). Entscheidung dokumentieren
oder als Option nachrüsten.

## Zu tun

- Crawl-Delay: letzte Anfrage pro Host merken (pro Lauf in-memory; bei parallelen
  Läufen ggf. in `target`/eigener Tabelle), Default 1–5 s, konfigurierbar.
- 429 + `Retry-After`: Host für die Dauer sperren, URL als „fällig“ lassen statt
  unknown zu speichern.
- Queue-Lauf (Task 8) prüft externe URLs nur im Zeitbudget.
