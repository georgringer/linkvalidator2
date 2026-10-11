# #96447 – Linkvalidator concept & open decisions

- Forge: https://forge.typo3.org/issues/96447 (Feature, New, angelegt 2022-01-01)
- **Status linkvalidator2:** 🟡 größtenteils durch den Neubau beantwortet

## Problem

Sammel-Issue: Konzept, Roadmap, offene Entscheidungen (Architektur, Konfiguration,
Tests, wann/wie prüfen, externe Prüfung, GUI, Mail-Report).

## Analyse linkvalidator2

Die Notes (`Notes/2026-10-08-linkvalidator-rewrite.md`) sind dieses Konzept. Stand je
Punkt:

| Punkt | Stand |
|---|---|
| Klassen aufteilen, API trennen | ✅ Extraktion / Ziel / Checker / Repository / Service getrennt |
| sys_refindex nutzen | ❌ bewusst nicht: Refindex kennt keine Sichtbarkeit/Record-Types; eigene Extraktion aus TcaSchema |
| TSconfig überdenken | ✅ kein Page TSconfig mehr, Extension-Konfiguration + Site Settings (📋) |
| Tests | 📋 Task 9, volle Abdeckung vor Gerrit |
| Wann prüfen (Scheduler, beim Speichern, asynchron) | 🟡 CLI ✅; DataHandler (Schritt 4) und Browser-Batches 📋 |
| Drosselung extern | 📋 Crawl-Delay, Retry-After (#89287) |
| Link-Target-Cache | ✅ (#92700) |
| Ausschlussliste | ✅ Datensätze pro Teilbaum, Ignorieren im Report (#85127, #92822) |
| Falsch-Positive | 🟡 unknown-Status ✅, Rest siehe #85006 |
| Nicht gerenderte Inhalte (bodytext bei Plugins, pages.url bei doktype≠3) | ✅ nur Felder des Record-Types (`CheckableFieldResolver`) |
| l18n_cfg, versteckte Grid-Container, Übersetzung mit verstecktem Original | 📋 Sprachregel Schritt 7 (#94195); Grid-Container ❌ nicht geplant |
| Shortcut-Seiten | 🟡 nur `shortcut_mode` NONE (#92542) |
| Mehrere Start-Pids / alle Sites | 📋 CLI `--site`, Queue (Task 8) |
| Eigenes Modul, Pagination, Sortierung, Filter | ✅ |
| Seite/Seitenmodul öffnen, Recheck einzelner Links | ❌ / 💭 |
| Mail-Report, Editor-Reports | 📋 später |

## Zu tun

Beim Einzug Forge-Kommentar mit Verweis auf die neue Implementierung; Issue
schließen, wenn der Patch gemerged ist. Nicht abgedeckt und neu zu entscheiden:
versteckte Container (EXT:container/gridelements) – Core kennt sie nicht, ggf. Event.
