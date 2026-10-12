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
- **Konsequent `linkvalidator2`**: Namespace `GeorgRinger\Linkvalidator2`, Tabellen
  `tx_linkvalidator2_*`, Ext-Key-abgeleitetes alles. Alt + neu parallel aktiv →
  direkter Vergleich. Beim Einzug stumpfes Ersetzen `linkvalidator2` → `linkvalidator`
  (String darf nirgends absichtlich vorkommen).
- Nach Gerrit erst, wenn alles passt; Commit-Zuschnitt dann.
- Tabellennamen unten ohne „2“ notiert = Zielnamen.
- Eigenes Git-Repo in typo3conf/ext/linkvalidator2 (wie andere Exts dort).
- Tests/Autoload erst beim ersten Test klären. Plan: PSR-4 für
  `GeorgRinger\Linkvalidator2\(Tests\)` lokal in Root-composer.json (nie committen,
  Wächter in CLAUDE.local.md), runTests mit explizitem Pfad, cgl -n / phpstan per Pfad.
- Vor Gerrit-Einzug: volle Testabdeckung (Unit + Functional) Pflicht.

## Bau-Reihenfolge

(Stand 2026-10-11: 1, 2, 3 umgesetzt; 5 Ausschlüsse umgesetzt (siehe „Ausschlüsse“); 4 DataHandler umgesetzt, `Hook/DataHandlerHook`; Queue-Command
(Task 8) umgesetzt: `RecordQueue` – nie geprüft (LEFT JOIN) → fällig (0) → älter als `--interval`,
Keyset-Cursor, Lock `linkvalidator2-queue`, Waisen-Cleanup zu Beginn; 2.000 Datensätze ~10 s ohne extern.)
1. Engine (Tabellen, Schema-Extraktion + Opt-out, Softref → LinkService → Checker,
   CheckResult, Status-Queue, Command; Checker page/record/file)
2. Externe Links  3. Modul (admin-only)  4. DataHandler  5. Ziel-Ausschlüsse
6. Site Settings  7. Sprachregel  ~~8. Anker~~ (entfällt, Entscheidung 2026-10-11)

Forge-Issues je Schritt (Analyse in `Resources/Private/Issues/`, Stand 2026-10-11):
- vor Schritt 4, Bugfixes (Plan 1, Task 8d): #97937 `&nbsp;`, #94086 eigene LinkHandler,
  #106675/#101367 Rootline, #89426 Abgleich beim Prüfen einer Seite
- Queue/Batches (Task 8 + Browser-Batches): #89266, #89287 (Crawl-Delay, Retry-After)
- 2 Extern Rest: #96525 Eskalation, ~~#101935 Fehlerdetails im Modul~~ ✅
- 4 DataHandler: #105911, #89426
- 5 Ausschlüsse ✅: #92822, #85127
- 6 Site Settings + Mail-Report: #106487, #106677 (#110615 verworfen: Staging-Mails über Mail-Transport/Cron der Umgebung lösen, kein eigener Schalter)
- 7 Sprachregel: #94195 (+ Quelle unter versteckter Seite)
- ~~8 Anker~~: #100933 durch Entscheidung erledigt – Anker werden nicht geprüft
- Berechtigungen: #105413, #104004, danach #107983 (Workspace-User)
- #92542 Shortcut-Modi ✅ (2026-10-11): Modus „feste Seite“ mit Ziel → Link aufs Ziel (Feld
  `shortcut`); sonst Link der Shortcut-Seite auf sich selbst (Feld `shortcut_mode`), die
  Frontend-Auflösung entscheidet – kein Nachbau von `getPageShortcut()`.

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
- **Bekannter Bug (bis dahin), reproduziert 2026-10-08:** Ergebnisse gelöschter oder
  verschobener Datensätze bleiben stehen.
  - Gelöschtes Inhaltselement auf bestehender Seite: Zeilen in `broken_link`/`record_status`
    bleiben **auch nach erneuter Prüfung der Seite** und erscheinen weiter im Report –
    `PageRecordFinder::findOnPage()` findet gelöschte Datensätze nicht mehr, sie werden nie
    erneut angefasst (Repro: tt_content auf `deleted=1`, `--page=<pid>`, Zeilen bleiben).
  - Gelöschte Seite: Zeilen bleiben in der DB, im Report unsichtbar (Seite nicht mehr im Baum).
  - Verschobener Datensatz/Seite: `pid` veraltet, Treffer erscheint unter der alten Seite, bis
    er am neuen Ort geprüft wird.
  - **Stand 2026-10-11:** (1) umgesetzt (`RecordCheckService::removeStaleResults()`, Tests in
    `CheckCommandTest`); offen bleibt die gelöschte Seite selbst → (2)/(3).
  - Geplante Abhilfe: (1) beim Prüfen einer Seite Ergebnis-/Statuszeilen dieser pid mit den
    gefundenen Datensätzen abgleichen und Überzählige löschen (deckt auch SQL/Import und neu
    ausgeschlossene Felder/Tabellen ab); (2) DataHandler-Hook (Schritt 4) für sofortige
    Korrektur; (3) Waisen-Cleanup für gelöschte Seiten.

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
**Umgesetzt 2026-10-11** (#92822, #85127), Entscheidungen gegenüber dem ersten Plan:
- Datensätze `tx_linkvalidator2_exclusion` liegen **auf einer Seite** und gelten für deren
  Teilbaum (Rootline der Quelle), auf Root-Ebene (pid 0) überall. Damit dürfen Redakteure
  sie anlegen (Tabellenrecht + Inhalt bearbeiten auf der Seite) und schalten nur ihren
  Bereich stumm. Statt „global + Custom Permission“.
- Generisch: Feld `type` (TCA-Types) + `ExclusionMatcherInterface` je Typ (getaggt).
  Typen: `url` („URL oder Domain“: alles, was damit beginnt – `example.org` = Domain inkl.
  Subdomains, `example.org/blog` = alles darunter; Schema und Anker egal) und `page` (Links
  auf eine Seite) und `all` (nichts prüfen im Teilbaum, z. B. Archiv). Domain und URL
  bewusst zusammengelegt, weil Redakteure den Unterschied nicht einhalten. Felder
  `value` bzw. `target_page`.
- Bloomfilter wie bei Redirects (Gerrit 96538) geprüft und verworfen: wenige Einträge,
  Präfix-/Subdomain-Match statt exakter Mitgliedschaft, kein Frontend-Hot-Path.
- hidden/starttime/endtime gelten; nicht übersetzbar, nicht versioniert.
- TCA-Fallback-Typ `1`: Datensätze eines nicht mehr vorhandenen Typs (z. B. Extension
  entfernt) lassen sich öffnen, umstellen oder löschen; ohne Matcher wirken sie nicht.
- Ausschluss greift vor der Prüfung (kein HTTP), ausgeschlossene Links werden nicht
  gespeichert (kein Status „ausgeschlossen“). Ziel-Cache bleibt unberührt.
- DataHandler-Hook: neuer/geänderter Ausschluss → Datensätze mit Ergebnissen im Teilbaum
  sofort aus dem Cache neu prüfen; gelöscht/versteckt/verschoben/geändert → Teilbaum
  fällig (Queue), weil bisher ausgeschlossene Links nie geprüft wurden.
- Modul: Ansicht „Ausschlüsse“ als Route `linkvalidator2_report.exclusions`, Toggle
  rechts im DocHeader (kein eigenes Modul). `f:be.tableList` mit Levels + Auswahl, darunter
  die geerbten Ausschlüsse der Rootline.
- Report: „Ignorieren“-Dropdown pro Zeile (URL / Domain / Zielseite), AJAX
  `linkvalidator2_exclusion_create` über den DataHandler, gespeichert auf der Seite des
  Datensatzes.
- Bewusst nicht: Regex (eigener Matcher möglich), `excludedDomains` in der
  Extension-Konfiguration (später Site Setting, Schritt 6).
- Zähler: `record_status.excluded_count` je Datensatz (je Feld + Ziel einmal), der Report
  summiert ihn für die gewählten Seiten („N Links nicht geprüft wegen Ausschlüssen“).
  Ausgeschlossene Links selbst werden weiter nicht gespeichert.
- Offen: „Vorkommen akzeptieren“ (einzelner Link in einem Datensatz) als weiterer Typ.
- Verworfen: „für die ganze Site ignorieren“ als Button (siteweit → Site Settings, s. u.),
  Grund beim Ignorieren abfragen (Feld im Formular reicht).
- **Site Settings (eigener Punkt, offen):** `linkvalidator2.excludedUrls` – Liste mit
  derselben Regel wie Typ `url`, für die ganze Site, deployt mit dem Projekt, `%env()%`
  möglich (Intranet/SSO, #85127). Für Integratoren; Datensätze bleiben für Redakteure
  (Teilbaum). `ExclusionRepository::findForPid()` hängt sie als virtuelle Ausschlüsse an,
  Ausschlussansicht zeigt sie unter „Also applying here“ mit Herkunft „Site settings“.

## Workspaces
- v1 nur Live. Spalte `workspace` (immer 0) in record_status + link von Anfang an.
- Hinweis im Modul, wenn in Workspace: „Ergebnisse zeigen Live-Stand“.
- Modul auch in Workspaces erreichbar (`workspaces => '*'`, #107983), sobald Berechtigungen
  umgesetzt sind. „Prüfen“ im Workspace prüft Live; DataHandler-Hook reagiert erst auf Publish.

## Linktypen / Erweiterbarkeit
- Pipeline: Softref-Parser → `LinkService::resolve()` → Checker nach LinkService-Typ.
- Checker = getaggte Services (`linkvalidator.checker`); Core: page, record, file,
  folder, url. email/telephone werden übersprungen.
- Rückgabe `CheckResult` (ok | broken | unknown, Fehlertyp, Details).
- Eigene LinkHandler (#94086): Typ mit registriertem Checker → generisches Ziel und prüfen;
  Typ eines registrierten Handlers ohne Checker → überspringen; nur wirklich nicht
  auflösbare `t3://` → `link.invalid`. Heute fälschlich alles `link.invalid`.
- `LinktypeInterface`/`LinktypeRegistry` entfallen (Breaking).

## Externe Links
- ok: 2xx nach Redirects (max 5). broken: 404, 410, DNS NXDOMAIN.
- unknown: 401, 403, 429, 5xx, Timeout, Verbindungs-/TLS-Fehler.
- unknown → broken nach 3 Prüfungen über ≥ 7 Tage (global konfigurierbar)
  → target braucht `failure_count`, `first_failed_at`.
- HEAD, Fallback GET (nur Header). 429 + Retry-After respektieren.
  Crawl-Delay pro Domain. Konfigurierbarer User-Agent.
- Modul: unknown nicht in „kaputt“, eigener Filter „nicht prüfbar“.
- Fehlerdetails (cURL-Meldung) im Modul angezeigt, `errno` gespeichert (#101935 ✅);
  `ssl_verify_result` verworfen (nur Guzzle 7).
- `robots.txt`: nicht beachten (einzelne HEAD-Requests, kein Crawler) – in Doku begründen (#89287).

- Plain-Text-URLs (Softref `url`, z. B. `tt_content.bodytext`) mit Schritt 2 extrahieren:
  Core-`UrlSoftReferenceParser` über `SoftReferenceParserFactory`, `matchString` als
  Link. Regex überspringt URLs nach `"`/`'`/`=` (also keine `href`-Duplikate); URL als
  Linktext dedupliziert `field|identifier`. Dafür `CheckableField` mit mehreren
  Extraktionsarten pro Feld (bodytext: Html + PlainUrl). Liefert nur http(s)/ftp →
  erst mit externer Prüfung sinnvoll.

### Umsetzung Stand 2026-10-08 (Basis, erweiterbar)
- Guzzle 7 und 8 (Core erlaubt beide): gefangen wird `NetworkExceptionInterface`, cURL-Nummer
  aus `getHandlerContext()` (7) oder der Meldung „cURL error N“ (8, Timeouts dort als eigene
  `NetworkTimeoutException`). Vorher Fatal Error unter Guzzle 8 (2026-10-11).
- `ExternalUrlChecker` (Guzzle über `RequestFactory`): HEAD, GET nur bei 405/501 (gestreamt,
  Body wird nie gelesen), max. 5 Redirects. ok = 2xx; broken = 404 (`url.notFound`),
  410 (`url.gone`), cURL 6 (`url.unknownHost`); unknown = sonstige Status (`url.httpStatus`
  mit `httpStatus`), Timeout, TLS, Verbindung, zu viele Redirects, nicht http(s).
- Cache über neues `CacheableLinkCheckerInterface::getCacheLifetime()`: `RecordCheckService`
  nimmt das gespeicherte Target-Ergebnis, solange frisch, **ohne** `last_checked` zu erhöhen.
  Weitere teure Checker bekommen den Cache damit automatisch.
- `checkRecord(..., cachedOnly: true)`: cachebare Checks nur aus dem Cache, ungecachte werden
  übersprungen. Nutzt das Modul (sonst Timeout im großen Baum), CLI mit `--no-external`.
- Extension-Konfiguration: `externalCacheLifetime` (h, 168), `externalTimeout` (s, 10),
  `externalUserAgent`.
- URL-Identifier ohne Fragment. Plain-Text-URLs umgesetzt (`ExtractionMode::PlainUrl`,
  Felder können mehrere Modi haben).
- Offen (`@todo`): Eskalation unknown → broken (Zähler werden schon geschrieben),
  Crawl-Delay, Retry-After, „fällig markieren“ für übersprungene URLs (Queue),
  Modul live prüfen erst mit Batches.
- Testelement „Externe Fälle“ tt_content:20277 auf 1292 (httpbin.org-Statuscodes, Redirects,
  Timeout, `.invalid`-Domain, ftp, Text-URLs mit Satzpunkt und `&amp;`); per SQL angelegt,
  nicht im Seed-Skript.

## Interne Links
- Seite broken: fehlt/gelöscht, hidden, endtime abgelaufen, Doktyp ohne FE
  (Sysfolder, Papierkorb, Spacer).
- **Anker werden nicht geprüft** (entschieden 2026-10-11, #100933): ein Fragment kann jede
  `id` sein (Template, Plugin, JS, RTE); `#c<uid>` = Inhaltselement ist eine zu einfache
  Annahme. Geprüft wird die Zielseite, das Fragment ignoriert. Gerendertes HTML nur über
  einen eigenen `page`-Checker (Frontend-Request).
- Seite broken auch über die Rootline: Vorfahr mit `extendToSubpages` hidden/abgelaufen
  (#106675, #101367) → eigene Fehlertypen mit `parentPage`. Rootline pro Lauf cachen.
- Seite ok: andere Site. **Hinweis** (Status `info`, entschieden 2026-10-11, umgesetzt):
  fe_group (`page.accessRestricted`), starttime Zukunft (`page.notYetVisible`), künftige
  Stoppzeit (`page.visibleUntil`) – jeweils auch über Elternseite mit extendToSubpages
  (`parentPage`). Use Case: Ziel nach dem Verlinken eingeschränkt. Hinweise zählen nicht
  als kaputt (Fortschritt, Notification, `broken_count`); Report zeigt standardmäßig alle
  Status mit Badge, Filter nach kaputt / nicht prüfbar / Hinweis.
- **Umgesetzt 2026-10-11:** Ziel-Seiten über `FrontendPageResolver`
  (`PageInformationFactory`, Stufe 2 unten); `PageChecker` bestimmt nur noch den Grund.
  Code 1705656657 (Site ohne TypoScript) gilt als erreichbar (Setup, kein Link-Problem).
  Seite ohne Site → `page.noSite`. Laufstart (`runStartedAt`) kommt aus dem Context-Datum
  (gleiche Uhr wie `last_checked`/`first_detected`), nicht aus `time()`.
- Offen: Quelle über die Seite (Inhalt auf unsichtbarer Seite) – Achtung, nur für
  `pages`/`tt_content` anwenden (Datensätze in Sysfoldern werden über Plugins gezeigt), und
  bei `pages` als Quelle nicht die eigene Shortcut-/Link-Auflösung werten.
- `t3://page?uid=current` (+ `#…`, #100933): aus Inhalten valide und übersprungen, solange
  der Core `current` kennt (`PageLinkHandler::resolveHandlerData()`, `PageLinkBuilder`).
  Link-Seite (`pages.link`) mit `current` → broken `page.linksToItself` (der
  `PageLinkBuilder` wirft 1784639469). **Todo:** Entfernt/deprecated der Core `current`,
  muss `LinkTargetFactory::createPageTarget()` solche Links als kaputt melden.
- Record: gelöscht/hidden/abgelaufen → broken.
- Datei: sys_file fehlt oder missing=1 → broken; Storage offline → unknown.
- **Sprachregel (v1, eigener späterer Commit)**: Ziel in Sprache der Quelle unter
  Beachtung von fallbackType/Fallback-Kette der Site erreichbar? strict/free ohne
  Übersetzung → broken; versteckte Übersetzung → broken.

### Frontend-Wahrheit statt nachbauen (Notiz 2026-10-11, vor Rootline/Sprachregel klären)
- Links **finden** bleibt wie es ist (Schema + Extraktion). Offen ist, ob sich
  **Erreichbarkeit/Sichtbarkeit** (Rootline/`extendToSubpages`, Sprache strict/fallback,
  Shortcuts, Mountpoints, Zugriff, Workspaces) zuverlässig rein im Backend nachbauen lässt
  – Tendenz: nicht selbst rechnen.
- Drei Stufen:
  1. **Eigene DB-Logik** (heute `PageChecker`, `RecordCheckService::isVisible()`): schnell,
     überall nutzbar (CLI, RTE, DataHandler), aber Nachbau, deckt nur Bekanntes ab.
  2. **`PageInformationFactory::create()`** – Frontend-Entscheidung ohne Rendern, wie
     EXT:redirects `RedirectService::bootFrontendController()` (Request mit `site`,
     `language`, `routing` = `PageArguments`, `frontend.cache.instruction`; Context
     `frontend.preview`). Wirft `PageInformationCreationFailedException` mit Response +
     Code (Seite versteckt, Rootline `checkRootlineForIncludeSection`, Sprache nicht
     verfügbar/strict, Shortcut ohne Ziel, …) → auf Fehlertypen abbilden. `@internal`, für
     einen Core-Sysext vertretbar (redirects nutzt es). Achtung: setzt/ändert den
     Context-Language-Aspect (`@todo` in der Klasse) → Context isolieren/zurücksetzen.
     Kosten pro Ziel-Seite+Sprache messen, pro Lauf cachen.
  3. **HTTP-Request ans Frontend** wie cs_seo `FrontendPageService`
     (https://github.com/clickstorm/cs_seo/blob/main/Classes/Service/FrontendPageService.php:
     `PreviewUriBuilder` → `RequestFactory`, eigener Header, Basic Auth der BE-Session
     weiterreichen). Volle Wahrheit inkl. gerendertem HTML (Anker `#c123`), aber teuer,
     Seiteneffekte, 500er von Plugins = falscher Alarm, Basis-URL/Auth auf Staging. Nur als
     optionaler Checker (Extension registriert Checker für `page`), nicht Standard.
- **Spike Stufe 2 (2026-10-11, `Notes/spike-page-information-factory.php`, DDEV, Site
  `main`):** trägt.
  - Läuft im CLI ohne Frontend-Request (Request mit `site`, `language`, `routing`,
    `normalizedParams`). Kosten ~1 ms pro Seite+Sprache (erster Aufruf ~20 ms).
  - Ergebnisse (Code der `PageInformationCreationFailedException` / Status):

    | Fall | Ergebnis |
    |---|---|
    | sichtbar, Shortcut → sichtbar, Kind einer versteckten Seite *ohne* extend | OK |
    | versteckt / gelöscht / fehlt | 1674144383 / 404 (nicht unterscheidbar) |
    | abgelaufen, **starttime Zukunft** | 1533931330 / 404 |
    | Sysfolder, Spacer | 1533931343 / 404 |
    | Shortcut → gelöscht | 1705335065 / 404 |
    | Seitentyp Link → versteckte Seite | 1705335066 / 404 |
    | Kind von versteckt/abgelaufen/**fe_group** + extendToSubpages | 1705337296 / 403 (Grund nicht unterscheidbar) |
    | fe_group an der Seite selbst | 1705325336 / 403 |
    | nicht übersetzt, strict | 1533931402 / 404 |
    | nicht übersetzt, fallback | OK (Inhalt aus Fallback) |

  - **Fallstricke:** (1) Mit eingeloggtem BE-User (Modul-AJAX) zählen versteckte Seiten als
    sichtbar, und ohne `frontend.preview`-Aspekt kracht es → für die Prüfung
    `backend.user` anonym + `visibility` default setzen, danach wiederherstellen; damit
    identisch zum CLI. (2) Setzt den Context-Language-Aspekt und lässt ihn stehen →
    sichern/wiederherstellen. (3) Bei Fehlern läuft `ErrorController` → mit
    `errorHandling` der Site (PageContent-Handler) evtl. HTTP-Abruf der 404-Seite pro
    Fehler; nicht gemessen (Site `main` hat keins) → Site für die Prüfung ohne
    `errorHandling` erzeugen. (4) Privater Service, im Spike per Reflection geholt; im Code
    per DI.
  - **Bewertung:** Als *Entscheidung* „für Besucher erreichbar ja/nein“ ideal – deckt
    Rootline/extendToSubpages, Sprache strict/fallback, Shortcuts (auch Modi „erste
    Unterseite“, #92542), Link-Seiten, Events von Extensions ab. Für die *Begründung*
    reicht der Code nicht (versteckt vs. gelöscht, versteckt vs. fe_group über Elternseite)
    → bei Fehlschlag eigener günstiger DB-Blick (Seite/Rootline) für den Fehlertyp.
  - **Zu entscheiden:** fe_group (Seite oder via Elternseite) → laut Spec ok, Frontend 403
    → als ok werten. starttime Zukunft → Frontend 404, Spec ok → ok lassen oder Warnung
    „noch nicht sichtbar“ (TODO existiert).
  - Quelle: dieselbe Abfrage für Seite+Sprache des Datensatzes beantwortet „Seite für
    Besucher sichtbar“ (versteckte Elternseite, nicht übersetzt in strict). Ob ein
    einzelner Inhalt gerendert wird (verstecktes Original, #94195) deckt sie **nicht** ab.

### Sprachregel: auch die Quelle (Notiz, Schritt 7)
- Beobachtet auf Seite 1210: Seite ohne Übersetzung, aber übersetzte Inhaltselemente
  (366 DE, 367 FR, l18n_parent 363). Werden heute geprüft (pid = 1210).
- Diese sind unsichtbar: im Seitenmodul (PageContextFactory schneidet die Sprachen mit
  den existierenden Seitenübersetzungen, `array_intersect(..., getAllExistingLanguageIds())`)
  und im Frontend bei `strict` (keine `/de/`-Seite).
- Vorschlag: Datensätze, deren Sprache auf der Seite nicht existiert (und kein Fallback
  greift), wie versteckte behandeln → nicht melden, aber zählen und im Modul als
  „übersprungen: Seite nicht übersetzt“ zeigen (Waisen auffindbar).
- **Offene Frage: eine Core-API nutzen statt selbst rechnen?** Kandidaten:
  - `LanguageAspectFactory::createFromSiteLanguage()` + `PageRepository::getPageOverlay()`
    / `getLanguageOverlay()` mit eigenem `Context` – dieselbe Logik wie das Frontend
    (fallbackType strict/fallback/free, Fallback-Kette). Öffentliche API. Deckt Quelle
    (ist der Datensatz in Sprache X sichtbar?) und Ziel (ist die verlinkte Seite in
    Sprache X erreichbar?) mit einer Logik ab. Zu prüfen: Kosten pro Datensatz,
    Verhalten außerhalb eines Frontend-Requests, Visibility-/Workspace-Aspekt.
  - `PageLanguageInformationService::getLanguageInformationForPage()` (Backend, das
    nutzt das Seitenmodul) – aber `@internal` und abhängig vom Backend-User → für die
    Engine ungeeignet, höchstens als Referenz für „im Seitenmodul sichtbar“.
  - Tendenz: Frontend-API (PageRepository + LanguageAspect), weil „für Besucher kaputt“
    der Maßstab ist. Vor Umsetzung per Spike klären.

## UI/UX (v1)
- Modul-Platzierung wie heute: unter „Link Management“ (`parent: link_management`,
  heute `linkvalidator_checklinks` nach `short_urls`).
- Modul am Seitenbaum: kaputte Links der Seite **rekursiv** (kein Tiefen-Selektor),
  paginiert (berechtigungsbewusst erst mit Berechtigungs-TODO).
- Filter: Status (kaputt/nicht prüfbar), Linktyp, Tabelle, Sprache, Freitext.
- Zeile: Seite › Datensatz › Feld, Linktext, Ziel, Fehler im Klartext, zuletzt geprüft.
- Aktionen: Bearbeiten (mit returnUrl), Ziel ausnehmen, Neu prüfen.
- „Diesen Bereich prüfen“ mit Fortschrittsbalken; Info „zuletzt geprüft / nie geprüft“.

## RTE-Markierung (v1-Pflicht)
- Core `RteHtmlParser::markBrokenLinks()` dispatcht beim Öffnen im RTE pro Link ein
  `BrokenLinkAnalysisEvent`; `data-rte-error` zeigt CKEditor (`typo3-link`) an.
  Einziger Listener bisher: alter `CheckBrokenRteLinkEventListener` (nur Seite fehlt/
  versteckt, Datei, externe aus `tx_linkvalidator_link`). Ersetzen ohne eigenen Listener
  = Regression.
- `MarkBrokenRteLinkListener`: nutzt dieselben Checker wie der Report, extern aus dem
  Target-Cache (kein HTTP beim Öffnen). Läuft `before` den alten Listenern, weil
  `markAsCheckedLink()` die Propagation stoppt.
- Event kennt die Quelle nicht → Sprachregel (Schritt 7) greift im RTE nicht. Seite für
  `TCEMAIN.linkHandler` (Record-Identifier → Tabelle) aus dem FormEngine-Request
  (`edit[table][uid]`, bei `new` die pid bzw. die pid des Datensatzes bei negativer uid);
  `record.unknownTable` wird im RTE nie markiert (2026-10-11).
- Beim Speichern normalisiert `TS_links_db()` Links über `LinkService::asString()`
  (fehlende Datei → `href=""`, `t3://page` → `uid=current`).

## Datenmodell-Ergänzung
- `tx_linkvalidator_broken_link.first_detected` schon in v1 (UI „kaputt seit“, später Mail-Delta).

## Upgrade / Ausführung
- **Kein Upgrade-Wizard.** Alte Tabelle `tx_linkvalidator_link` → Analyzer räumt ab.
- Breaking-RST: Ergebnisse verfallen (Report leer bis erster Lauf), alte
  ValidatorTask-Tasks neu anlegen, `mod.linkvalidator.*` entfällt (Mapping-Tabelle).
- **Nur CLI-Command** `linkvalidator:check` (`--time-limit`, `--limit`, `--site`,
  `--[no-]external`); Scheduler über „Execute console commands“. Kein eigener Task.

## Berechtigungen (umgesetzt 2026-10-11)
- Report zeigt nur, was der User **reparieren** kann: Seiten-Bearbeitungsrecht
  (Inhalt), tables_modify, allowed_languages, non_exclude_fields. Admin: alles.
- Filterung in SQL (Paginierung korrekt), Feldrechte via `field IN (…)` pro Tabelle.
- Seiten-Felder: `PAGE_EDIT` an der Seite selbst, Inhalte: `CONTENT_EDIT` an der Seite.
  Seitenbaum erst komplett sammeln, dann nach Recht filtern – nicht beim Abstieg
  abbrechen, Unterseiten können erlaubt sein (#105413).
- `authMode` für jedes `select`-Feld mit `authMode`, nicht nur das Type-Feld; leere
  Erlaubt-Liste = nichts erlaubt; auch für pages (#104004).
- Sprachen (entschieden 2026-10-11): **Prüfen immer alle Sprachen** einer Seite (Ergebnisse
  sind geteilt, gleich alt; intern billig, extern pro Lauf einmal). Sprache nur beim
  **Anzeigen** filtern: `language IN (allowed_languages)` in SQL, Sprachfilter bietet nur
  erlaubte Sprachen an, `brokenLinkCount` der AJAX-Prüfung ebenso gefiltert. Ein Redakteur,
  der prüft, prüft alle Sprachen mit (kein Leck, er sieht nur seine). Sprachauswahl beim
  Prüfen erst bei Performance-Bedarf, dann über den aktiven Sprachfilter.
  Sprache `-1`: immer sichtbar, wie `checkLanguageAccess()`.
- „Ziel ausnehmen“: Custom Permission.
- Modul-Access `user` seit der Umsetzung (vorher `admin`, Info-Leck). `authMode`-Werte nur aus
  `items`, nicht aus itemsProcFunc.

## TODO später
- [ ] Vorkommen akzeptieren (Flag auf Link-Zeile, taucht bei Fehleränderung/
      Bearbeitung wieder auf).
- [ ] Eager-Befüllung der Status-Tabelle (Initial-Command; DataHandler legt seit Schritt 4
      beim Anlegen an).
- [x] Notification-Text: Singular/Plural – ICU-Labels + `~labels/linkvalidator2.messages` im JS (2026-10-11).
- [x] **Ziel ändert sich** (2026-10-11): Ändert sich ein Datensatz (Update, Löschen,
      Verschieben, Wiederherstellen), werden alle Datensätze, die laut `sys_refindex` auf ihn
      verweisen (prüfbare Tabellen, Live), **fällig markiert** – allgemein, ohne Feld- oder
      Typ-Sonderfälle. Nicht sofort geprüft: der Hook läuft vor dem Cache-Clear des
      DataHandlers, die Seitenauflösung sähe den alten Stand (Runtime-Cache ohne Tags).
      `extendToSubpages` bewusst nicht (Unterseiten): fängt der Queue-Lauf ab.
- [ ] Messenger als Transport für Hintergrund-Prüfung.
- [ ] Workspace-Prüfung (Quelle + Ziel-Sicht, Publish/Discard umhängen).
- [ ] Events (alle): welche Erweiterungspunkte braucht es wirklich.
- [ ] Redirect-Hinweis (301 → „Link aktualisieren“).
- [ ] Warnung „Ziel noch nicht sichtbar“ (starttime in Zukunft).
- [ ] Seitenmodul-Callout „N kaputte Links auf dieser Seite“.
- [ ] Hinweis im Bearbeitungsformular (FormEngine).
- [ ] Modul: nach „Seite prüfen“ zeigen, welche Datensätze geprüft wurden (nicht nur Anzahl).
- [ ] Dashboard-Widget, Badges im Seitenbaum.
- [ ] FlexForms (Auto-Discovery über FlexFormFieldType, Pfad z. B.
      `pi_flexform:sDEF/settings.detailPid`).
- [x] Berechtigungen umsetzt, Modul für Redakteure offen (2026-10-11, `ReportPermissions`).
- [ ] Mail-Report: Delta seit letztem Report (neu kaputt + Summe + Link ins Modul),
      keine Mail ohne Änderung, pro Site (Site Settings), periodisch entkoppelt vom
      Prüflauf, `last_report_sent` pro Site, Fluid-Mail. Site-Titel im Betreff,
      volle klickbare Ziel-URL, Links zum Bearbeiten/ins Modul mit absoluter
      Backend-URL (Basis aus Site bzw. Site Setting, CLI kennt keinen Host;
      #106487, #106677). Auf Staging abschaltbar ohne DB: Empfänger per `%env()%` in
      Site Settings (kein eigener Schalter, #110615 verworfen).
- [ ] Sichtbarkeit der Quelle über die Seite: `RecordCheckService::isVisible()` prüft nur
      den Datensatz selbst. Inhalte auf versteckter/abgelaufener Seite oder unter
      `extend_to_subpages` werden gemeldet, obwohl unsichtbar → mit Sprachregel (Schritt 7)
      als „übersprungen“ behandeln.
- [x] Synchrone Prüfung im Modul durch Browser-Batches ersetzt (2026-10-11).
- [ ] Modul-Liste: `ReportController::getBrokenLinks()` lädt Datensatz + Seitentitel pro
      Zeile (max. eine Seite à 50) → gesammelt laden.
- [ ] Dedupe nur pro Feld (`field|identifier`): gleiches Ziel in zwei Feldern = zwei Zeilen.
      Bestätigen, dass gewollt (Felder werden getrennt repariert).
- [ ] **Bug RTE-Listener:** Event ohne pid → Record-Links mit per TSconfig gemapptem
      Identifier (`category` → `sys_category`) werden als `record.unknownTable` markiert,
      obwohl gültig. Seite aus Request/FormEngine-Kontext ermitteln oder unbekannte Tabellen
      im RTE nicht markieren. Reproduzierbar mit `seed-bigtree.php` (Kategorie-Links).
- [x] **Bug Plain-URLs (#97937):** `&nbsp;` beendet die URL jetzt (2026-10-11, mit Test).
- [x] **Bug eigene LinkHandler (#94086):** gelöst 2026-10-11, Doku-Notizen in
      `Documentation/todo.md`.
- [x] Ziel per Rootline prüfen (`extendToSubpages`, #106675/#101367) – über Frontend-Auflösung.
- [x] Fehlerdetails (`error_details.message`) im Modul anzeigen, `errno` gespeichert (#101935, 2026-10-11).
- Analyse aller offenen Forge-Issues: `Resources/Private/Issues/`.
- [ ] Shortcut-Seiten mit `shortcut_mode` ≠ NONE prüfen (erste/zufällige Unterseite ohne
      sichtbare Unterseite, Elternseite auf Root-Seite).

## Modul: Level, Filter, Pagination (entschieden 2026-10-08)
- **Level-Dropdown bleibt** (diese Seite, 1–4, unendlich; moduleData `depth`) und gilt
  für Prüfen **und** Anzeigen – ein Bereich, keine getrennten Einstellungen wie im alten
  Modul (dort Report-Ebene 0 + keine Linktypen vs. Check-Ebene 3 → „No broken links“).
  Revidiert „rekursiv, kein Tiefen-Selektor“ aus UI/UX.
- Filter: Status, Linktyp (`target.link_type`), Tabelle, Sprache, Freitext (Link,
  Linktext). In moduleData persistiert, angeboten werden nur Werte, die im Bereich
  vorkommen. Reset-Link setzt alle Filter auf leer.
- Sortierung per Klick auf die Spaltenüberschrift (zweiter Klick dreht um), moduleData
  `orderField`/`orderDirection`, stabile Nebensortierung für die Pagination.
- Pagination in SQL (`LIMIT/OFFSET`, 50 pro Seite), `page` nur in der URL; Partial nach
  Vorbild EXT:redirects (`Partials/Pagination`), ohne Seiten-Eingabefeld (bräuchte JS).
- Prüfen im Modul (umgesetzt 2026-10-11): **Browser-Batches pro Seite**. JS holt die
  Seiten des Bereichs (`ajax linkvalidator2_check_start`), dann pro Seite
  `linkvalidator2_check_page` → `PageCheckService::checkPage()` mit 5 s Budget; nicht
  fertig → dieselbe Seite erneut (bereits im Lauf geprüfte Datensätze werden über
  `record_status.last_checked >= runStartedAt` übersprungen, URLs aus dem Lauf über
  `$freshSince` wiederverwendet). Seiten nacheinander, nicht parallel (fremde Server).
  Fortschrittsbalken über den Filtern, Abbrechen; am Ende Notification mit Kurzinfo
  (Seiten, kaputte Links, davon neu = `first_detected >= runStartedAt`; Warnung bei neuen)
  und Neuladen des Reports. Kein Modal (verworfen).
  Layout (2026-10-11): Ebenen **getrennt** – Button-Gruppe im DocHeader „Ebenen ▾ | Links
  prüfen“ setzt nur den Prüfbereich (kein Reload, vorbelegt mit den Report-Ebenen);
  Ebenen im Filterformular für die Anzeige. Revidiert „gleicher Bereich für Prüfen und
  Anzeigen“ von oben. Checkbox „Externe prüfen“
  entfällt – extern wird immer geprüft, Frisches kommt aus dem Cache.
- Verifikation ohne BE-Login: `Notes/render-module.php` rendert das Modul als Admin
  (`ddev exec "TYPO3_PATH_ROOT=/var/www/html TYPO3_PATH_APP=/var/www/html php
  typo3conf/ext/linkvalidator2/Notes/render-module.php 'id=1287&depth=999&linkType=page'"`).

## Testdaten (lokal, DDEV, Seed: `Notes/seed-testdata.php`)
- **Lasttest-Baum** (`Notes/seed-bigtree.php [fanout] [levels] [contentPerPage]`, Default
  10 3 4, `--delete` räumt inkl. linkvalidator2-Ergebnissen ab): Seite „Großer Baum“
  unter 1287, 1110 Seiten, jede 10. mit DE-Übersetzung (111), 4440 Inhaltselemente
  + 444 Übersetzungen. Direkt per SQL (nur die Wurzel über DataHandler), ~2 s.
  Pro Element 5 gültige Links (2 Seiten im Baum, typo3.org, Datei, Kategorie) + 1
  kaputter (reihum aus 11 Varianten) + jedes 3. ein externer Link auf example.org;
  `header_link` am ersten Element (je 10 Seiten: 1× gelöschte Seite, 1× versteckte
  Kategorie, 1× gültige Kategorie, sonst Seite im Baum). Status-Tabelle bleibt leer
  (= nie geprüft) → Queue-Test.
  Record-Links: Page TSconfig an der Wurzel `TCEMAIN.linkHandler.category` →
  `sys_category` (Identifier ≠ Tabelle, testet das Mapping), Sysfolder „Kategorien“ mit
  10 gültigen + versteckter/abgelaufener/gelöschter Kategorie. Jedes Element 1 gültiger
  Kategorie-Link; kaputte reihum inkl. Kategorie versteckt/abgelaufen/gelöscht/nie
  vorhanden und `tt_content` 467 (ohne Handler-Konfiguration → Fallback Identifier =
  Tabelle).
  EXT:styleguide als weitere **Quellen** (nicht Ziele), Sysfolder „Styleguide-Datensätze“:
  50 `tx_styleguide_elements_basic` (link_1 Seite, link_2 Kategorie, link_3 typo3.org,
  link_4 kaputt reihum, link_5 mailto = übersprungen, link_6 jedes 3. example.org-404) und
  30 `tx_styleguide_elements_rte` (rte_1 wie Inhaltselemente, rte_11 ein kaputter Link);
  jeder 10. versteckt (darf nichts melden). Erwartet: 60 + 63 kaputte Links. NOT-NULL-Spalten
  ohne Default (uuid_1 …) füllt das Skript über information_schema.
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
- `Notes/` (diese Notizen, Plan), `AGENTS.md`/`CLAUDE.md` und `.superpowers/` gehören nicht in den Core: beim
  Einzug weglassen. Sie enthalten `linkvalidator2` absichtlich.
- RTE-Transformation beim Speichern macht aus Links auf nicht existierende Dateien/
  Ordner `href=""`, aus `#top` `t3://page?uid=current#top`. Leeres href = für Besucher
  kaputt → melden (`link.empty`)? Entscheidung offen. Tendenz: melden, für Besucher kaputt
  (`@todo` in `LinkExtractor::extractFromHtml()`).
- Beim Einzug in typo3/sysext: composer.json `type` zurück auf `typo3-cms-framework`
  (lokal `typo3-cms-extension`, sonst kein Autoload im Classic Mode), Root-composer.json
  PSR-4 ergänzen, `version`/`providesPackages` entfernen (Framework-Pakete brauchen es nicht).
- Feature-Freeze v15 nachsehen (Zeitplan).
- Vor Einzug: git log alter linkvalidator seit 2026-10-08 auf neue Anforderungen prüfen.
