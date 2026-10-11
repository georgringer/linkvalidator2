# #92542 – Linkvalidator should check shortcut pages

- Forge: https://forge.typo3.org/issues/92542 (Feature, New, angelegt 2020-10-12)
- Verwandt: #89182, #89177
- **Status linkvalidator2:** 🟡 teilweise

## Problem

Shortcut-Seiten werden nicht geprüft und lassen sich nicht über TSconfig hinzufügen,
weil das Ziel von `shortcut_mode` abhängt.

## Analyse linkvalidator2

- ✅ `shortcut_mode` = NONE (feste Zielseite): `LinkExtractor::extractShortcut()` liefert
  `t3://page?uid=<shortcut>` als Link im Feld `shortcut`, geprüft vom `PageChecker`.
  Verifiziert: pages:1288 → gelöschte Zielseite 1297 → broken.
- ❌ Andere Modi (`@todo` im Code):
  - „Erste Unterseite“ / „Zufällige Unterseite“: kaputt, wenn es keine sichtbare
    Unterseite gibt.
  - „Elternseite“: kaputt auf Root-Seite oder wenn die Elternseite selbst nicht
    erreichbar ist.
  - Leeres `shortcut` bei Modus NONE: im Frontend Fehler → melden?

## Zu tun

- Für die Modi eigener Fehlertyp (`shortcut.noSubpage`, `shortcut.noParent`), Prüfung
  am besten direkt im Extractor/einem `ShortcutChecker` über `PageRepository`
  (`getMenu()` mit Sichtbarkeit) – dieselbe Logik wie das Frontend
  (`PageRepository::getPageShortcut()`), nicht nachbauen.
- `pages.url` (Doktyp Link) ist schon abgedeckt (Feld vom Typ `link`, nur für Doktyp 3
  sichtbar).

## Test (Task 9)

Shortcut „erste Unterseite“ ohne Unterseiten → broken; mit versteckter einziger
Unterseite → broken; mit sichtbarer → ok.
