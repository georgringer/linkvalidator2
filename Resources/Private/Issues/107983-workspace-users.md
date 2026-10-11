# #107983 – Make Link Validator (linkvalidator) accessible for Preview-Workspace users

- Forge: https://forge.typo3.org/issues/107983 (Feature, Accepted, angelegt 2025-11-05)
- Verwandt: #32096
- **Status linkvalidator2:** 📋 offen (Berechtigungen, Workspaces)

## Problem

Redakteure, die nur im Workspace arbeiten dürfen, sehen das Modul nicht
(`workspaces => live`). Erwartung laut Kommentar: Live-Ergebnisse sehen, im Workspace
korrigieren, normal publizieren.

## Analyse linkvalidator2

Passt zum Konzept: v1 prüft nur Live (Spalte `workspace` ist vorbereitet, immer 0),
Spec sieht im Workspace den Hinweis „Ergebnisse zeigen Live-Stand“ vor.
Aktuell aber noch `'workspaces' => 'live'` und `'access' => 'admin'` in
`Configuration/Backend/Modules.php`.

Abhängigkeiten:
- Erst Berechtigungen umsetzen (Report zeigt nur, was der User reparieren kann),
  vorher bleibt das Modul admin-only (harte Bedingung, Info-Leck).
- „Bearbeiten“ aus dem Report öffnet im Workspace die Workspace-Version – das ist
  gewollt. Aber: der Report zeigt den Fehler weiter, bis publiziert ist. Hinweis im UI.

## Zu tun

- `workspaces` auf `*` stellen, Hinweis „Live-Stand“ im Modul, wenn
  `workspace !== 0`.
- „Prüfen“-Button im Workspace: prüft Live (Workspace-Overlay darf nicht greifen –
  `BackendUtility::getRecord()` liest Live, DataHandler-Hook (Schritt 4) muss
  Workspace-Speichern ignorieren bzw. erst beim Publish reagieren).
- Functional-Test: Workspace-User sieht Modul + Live-Ergebnisse.
