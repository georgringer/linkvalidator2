# Linkvalidator rewrite – Entscheidungen (Grill-Session 2026-10-08)

## Strategie
- Kompletter Neubau, **ersetzt** den Core-Sysext unter dem Key `linkvalidator`
  (Namespace `TYPO3\CMS\Linkvalidator`, Tabellen `tx_linkvalidator_*`).
- Entwicklung lokal als `typo3conf/ext/linkvalidator2` (siehe unten). Gleiche Regeln
  wie Core (AGENTS.md, CGL, runTests, RST).
- Einzug in Gerrit erst, wenn fertig; dann als Patch-Serie zuschneiden.
- `backup/brofix` (sypets/brofix): nur Ideen übernehmen, keinen Code.
- Vor Einzug: `git log` über alten linkvalidator seit Entwicklungsbeginn durchgehen
  (Bugfixes = vergessene Anforderungen; 78 Commits 10/2025–10/2026).

## Entwicklungsumgebung / Naming
- Entwicklung in `typo3conf/ext/linkvalidator2` (gitignored im Core-Repo).
- **Konsequent `linkvalidator2`**: Namespace `TYPO3\CMS\Linkvalidator2`, Tabellen
  `tx_linkvalidator2_*`, Ext-Key-abgeleitetes alles. Alt + neu parallel aktiv →
  direkter Vergleich. Beim Einzug stumpfes Ersetzen `linkvalidator2` → `linkvalidator`
  (String darf nirgends absichtlich vorkommen).
- Nach Gerrit erst, wenn alles passt; Commit-Zuschnitt dann.
- Tabellennamen unten ohne „2“ notiert = Zielnamen.
- Eigenes Git-Repo in typo3conf/ext/linkvalidator2 (wie andere Exts dort).
- Tests/Autoload erst beim ersten Test klären. Plan: PSR-4 für
  `TYPO3\CMS\Linkvalidator2\(Tests\)` lokal in Root-composer.json (nie committen,
  Wächter in CLAUDE.local.md), runTests mit explizitem Pfad, cgl -n / phpstan per Pfad.
- Vor Gerrit-Einzug: volle Testabdeckung (Unit + Functional) Pflicht.

## Bau-Reihenfolge
1. Engine (Tabellen, Schema-Extraktion + Opt-out, Softref → LinkService → Checker,
   CheckResult, Status-Queue, Command; Checker page/record/file)
2. Externe Links  3. Modul (admin-only)  4. DataHandler  5. Ziel-Ausschlüsse
6. Site Settings  7. Sprachregel  8. Anker

## Datenmodell
- `tx_linkvalidator_target`: Prüf-Cache, ein Eintrag pro eindeutigem Ziel, auch ok.
  type, target, target_hash, status, error (JSON), last_checked.
  Extern: Re-Check erst nach TTL; intern (page/record/file): immer live.
- `tx_linkvalidator_broken_link` (neuer Name, damit Analyzer die alte Tabelle als
  obsolet erkennt): **nur kaputte** Vorkommen
  (target, table_name, record_uid, field, pid, language, link_text, …).
- `tx_linkvalidator_record_status`: table_name, record_uid, pid, language,
  last_checked, broken_count. Ersetzt Seiten-Buchführung; GROUP BY pid → Badges.
  Dient zugleich als Arbeits-Queue (ORDER BY last_checked).
- Lauf extrahiert immer neu (billig), spart nur beim Prüfen (HTTP).

## Status-Lifecycle
- **Lazy**: Zeile entsteht beim ersten Prüfen; „nie geprüft“ = LEFT JOIN ohne Zeile.
- DataHandler: Speichern → neu extrahieren, intern sofort prüfen, extern nur Cache,
  ungecachte extern → fällig markieren. Löschen/Verschieben → aufräumen/pid nachziehen.
- Waisen-Cleanup im Scheduler-Lauf.

## Ausführung
- Interaktiv: browser-getriebene Batches (AJAX, Fortschrittsbalken).
- Hintergrund: Scheduler/CLI mit Zeit-/Mengen-Budget.
- Ein gemeinsamer Service „prüfe Datensätze mit Budget“.

## Was wird geprüft
- Automatisch aus TcaSchema (type=link, RTE, softref typolink/…), nur Felder, die
  im Record-Type sichtbar sind. Opt-out pro Tabelle/Feld.

## Sonderfelder
- v1: `pages.shortcut` (je nach shortcut_mode) und `pages.url` gesondert prüfen.
- Relationen (group/select/file) sind keine Links → nicht linkvalidators Aufgabe.
- Extractor arbeitet auf „Feldwert mit Pfad“ → FlexForm später als weitere Quelle.

## Konfiguration
- Global (Extension-Konfiguration): TTL, HTTP-Timeout, User-Agent, Crawl-Delay,
  Tabellen-/Feld-Opt-out.
- Pro Site (Site Set `typo3/linkvalidator`): Linktypen, Mail-Report, Empfänger.
- **Kein Page TSconfig** mehr → Breaking-RST.

## Ausschlüsse
- Ziel-Ausschlüsse als Datensätze `tx_linkvalidator_exclusion` (TCA): exakte URL
  oder Domain inkl. Subdomains, Grund. Scope global. Anlegen aus dem Report.
  Kein eigenes Modul, Tab im Modul. Recht: Custom Permission oder Admin.

## Workspaces
- v1 nur Live. Spalte `workspace` (immer 0) in record_status + link von Anfang an.
- Hinweis im Modul, wenn in Workspace: „Ergebnisse zeigen Live-Stand“.

## Linktypen / Erweiterbarkeit
- Pipeline: Softref-Parser → `LinkService::resolve()` → Checker nach LinkService-Typ.
- Checker = getaggte Services (`linkvalidator.checker`); Core: page, record, file,
  folder, url. email/telephone werden übersprungen.
- Rückgabe `CheckResult` (ok | broken | unknown, Fehlertyp, Details).
- `LinktypeInterface`/`LinktypeRegistry` entfallen (Breaking).

## Externe Links
- ok: 2xx nach Redirects (max 5). broken: 404, 410, DNS NXDOMAIN.
- unknown: 401, 403, 429, 5xx, Timeout, Verbindungs-/TLS-Fehler.
- unknown → broken nach 3 Prüfungen über ≥ 7 Tage (global konfigurierbar)
  → target braucht `failure_count`, `first_failed_at`.
- HEAD, Fallback GET (nur Header). 429 + Retry-After respektieren.
  Crawl-Delay pro Domain. Konfigurierbarer User-Agent.
- Modul: unknown nicht in „kaputt“, eigener Filter „nicht prüfbar“.

## Interne Links
- Seite broken: fehlt/gelöscht, hidden, endtime abgelaufen, Doktyp ohne FE
  (Sysfolder, Papierkorb, Spacer), Anker #cNNN fehlt/andere Seite.
- Seite ok: starttime Zukunft, fe_group, andere Site.
- Record: gelöscht/hidden/abgelaufen → broken.
- Datei: sys_file fehlt oder missing=1 → broken; Storage offline → unknown.
- **Sprachregel (v1, eigener späterer Commit)**: Ziel in Sprache der Quelle unter
  Beachtung von fallbackType/Fallback-Kette der Site erreichbar? strict/free ohne
  Übersetzung → broken; versteckte Übersetzung → broken.

## UI/UX (v1)
- Modul-Platzierung wie heute: unter „Link Management“ (`parent: link_management`,
  heute `linkvalidator_checklinks` nach `short_urls`).
- Modul am Seitenbaum: kaputte Links der Seite **rekursiv** (kein Tiefen-Selektor),
  paginiert (berechtigungsbewusst erst mit Berechtigungs-TODO).
- Filter: Status (kaputt/nicht prüfbar), Linktyp, Tabelle, Sprache, Freitext.
- Zeile: Seite › Datensatz › Feld, Linktext, Ziel, Fehler im Klartext, zuletzt geprüft.
- Aktionen: Bearbeiten (mit returnUrl), Ziel ausnehmen, Neu prüfen.
- „Diesen Bereich prüfen“ mit Fortschrittsbalken; Info „zuletzt geprüft / nie geprüft“.

## Datenmodell-Ergänzung
- `tx_linkvalidator_broken_link.first_detected` schon in v1 (UI „kaputt seit“, später Mail-Delta).

## Upgrade / Ausführung
- **Kein Upgrade-Wizard.** Alte Tabelle `tx_linkvalidator_link` → Analyzer räumt ab.
- Breaking-RST: Ergebnisse verfallen (Report leer bis erster Lauf), alte
  ValidatorTask-Tasks neu anlegen, `mod.linkvalidator.*` entfällt (Mapping-Tabelle).
- **Nur CLI-Command** `linkvalidator:check` (`--time-limit`, `--limit`, `--site`,
  `--[no-]external`); Scheduler über „Execute console commands“. Kein eigener Task.

## Berechtigungen (Konzept entschieden, Umsetzung später)
- Report zeigt nur, was der User **reparieren** kann: Seiten-Bearbeitungsrecht
  (Inhalt), tables_modify, allowed_languages, non_exclude_fields. Admin: alles.
- Filterung in SQL (Paginierung korrekt), Feldrechte via `field IN (…)` pro Tabelle.
- „Ziel ausnehmen“: Custom Permission.
- **HARTE BEDINGUNG:** bis zur Umsetzung Modul-Access `admin` (sonst Info-Leck).

## TODO später
- [ ] Vorkommen akzeptieren (Flag auf Link-Zeile, taucht bei Fehleränderung/
      Bearbeitung wieder auf).
- [ ] Eager-Befüllung der Status-Tabelle (DataHandler beim Anlegen + Initial-Command).
- [ ] Messenger als Transport für Hintergrund-Prüfung.
- [ ] Workspace-Prüfung (Quelle + Ziel-Sicht, Publish/Discard umhängen).
- [ ] Events (alle): welche Erweiterungspunkte braucht es wirklich.
- [ ] Redirect-Hinweis (301 → „Link aktualisieren“).
- [ ] Warnung „Ziel noch nicht sichtbar“ (starttime in Zukunft).
- [ ] Seitenmodul-Callout „N kaputte Links auf dieser Seite“.
- [ ] Hinweis im Bearbeitungsformular (FormEngine).
- [ ] Dashboard-Widget, Badges im Seitenbaum, Markierung im RTE.
- [ ] FlexForms (Auto-Discovery über FlexFormFieldType, Pfad z. B.
      `pi_flexform:sDEF/settings.detailPid`).
- [ ] Berechtigungen umsetzen (siehe oben), danach Modul für Redakteure öffnen.
- [ ] Mail-Report: Delta seit letztem Report (neu kaputt + Summe + Link ins Modul),
      keine Mail ohne Änderung, pro Site (Site Settings), periodisch entkoppelt vom
      Prüflauf, `last_report_sent` pro Site, Fluid-Mail.

## Testdaten (lokal, DDEV, Seed: `Notes/seed-testdata.php`)
- Unter pages:1287. Seiten: sichtbar 1299, versteckt 1298, gelöscht 1297,
  abgelaufen 1296, Zukunft 1295, Sysfolder 1294, Spacer 1293, Testinhalte 1292
  (DE-Übersetzung 1300), Link-Seite extern 1291, Link-Seite → versteckt 1290,
  Shortcut → sichtbar 1289, Shortcut → gelöscht 1288.
- tt_content: „Alle Fälle“ 471 (DE 472, Sprache 5), „Nur gültige Links“ 470,
  versteckt mit kaputtem Link 468, abgelaufen mit kaputtem Link 469,
  Anker-/Record-Ziel 466, verstecktes Record-Ziel 467.
- Datei-/Ordner-Links in 471/472 am DataHandler vorbei gesetzt (RTE-Transformation leert
  sie), Übersetzungen eingeblendet – beides macht das Seed-Skript selbst.

## Offen
- `Notes/` (diese Notizen, Plan) und `.superpowers/` gehören nicht in den Core: beim
  Einzug weglassen. Sie enthalten `linkvalidator2` absichtlich.
- RTE-Transformation beim Speichern macht aus Links auf nicht existierende Dateien/
  Ordner `href=""`, aus `#top` `t3://page?uid=current#top`. Leeres href = für Besucher
  kaputt → melden (`link.empty`)? Entscheidung offen.
- Beim Einzug in typo3/sysext: composer.json `type` zurück auf `typo3-cms-framework`
  (lokal `typo3-cms-extension`, sonst kein Autoload im Classic Mode), Root-composer.json
  PSR-4 ergänzen, `version`/`providesPackages` entfernen (Framework-Pakete brauchen es nicht).
- Feature-Freeze v15 nachsehen (Zeitplan).
- Vor Einzug: git log alter linkvalidator seit 2026-10-08 auf neue Anforderungen prüfen.
