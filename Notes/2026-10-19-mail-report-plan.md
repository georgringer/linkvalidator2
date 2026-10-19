# Mail-Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Der Command `linkvalidator2:report` schickt kaputte Links eines Seitenbereichs per Mail, gefiltert wie im Modul, mit Altersfilter und wählbarem Inhalt (inline, CSV-Anhang, beides).

**Architecture:** Dieselbe Abfrage wie das Modul (`BrokenLinkQuery` über `BrokenLinkRepository`), erweitert um eine obere Altersgrenze und Tabellenlisten. Ein Builder macht aus den Zeilen `MailReportItem`s, eine Factory daraus ein `FluidEmail`, der Command verdrahtet das und verschickt. Kein gespeicherter Zustand.

**Tech Stack:** TYPO3 v15 (`main`), PHP ^8.5, Symfony Console, `TemplatedEmailFactory`/`FluidEmail`, Doctrine DBAL, PHPUnit.

**Spec:** `Notes/2026-10-19-mail-report-design.md`. Dort stehen alle Entscheidungen (Georg) und Annahmen; dieser Plan wiederholt sie nicht.

## Global Constraints

- Namespace `GeorgRinger\Linkvalidator2`, `declare(strict_types=1);`, GPL-Header wie in den vorhandenen Dateien, Constructor-DI, kein `$GLOBALS['TCA']`.
- Jede geworfene Exception trägt einen eindeutigen Code = Unix-Timestamp beim Schreiben (`date +%s`), nie kopiert.
- Labels nur in `Resources/Private/Language/messages.xlf` (Domain `linkvalidator2.messages`), nur Englisch.
- Tests: Klassen `final`, Attribute `#[Test]`/`#[DataProvider]`; Läufe aus dem Extension-Root mit `Build/Scripts/runTests.sh -s unit` bzw. `-s functional` (Pfad **oder** `-- --filter`, nie beides).
- Neue Optionen des Commands, wörtlich: `--to`, `--from`, `--subject`, `--depth`, `--status`, `--link-type`, `--language`, `--search`, `--include-tables`, `--exclude-tables`, `--min-age`, `--max-age`, `--content=inline|attach|both`, `--inline-limit`, `--send-empty`, `--template`, `--mail-language`, `--base-url`. Die Sprache der Mail heißt `--mail-language`, weil `--language` den Sprachfilter meint (Annahme dieses Plans, Spec nennt nur `--language`).
- Standardwerte: `--depth=0`, `--status=broken`, `--content=inline`, `--inline-limit=50`. `--status=all` heißt kein Statusfilter.
- Commits nur auf Georgs Aufforderung (AGENTS.md); sonst bleibt die Arbeit uncommittet und der Commit-Schritt entfällt.

## Review Focus

Eingaben, die die Spec nicht nennt und die am ehesten Ärger machen:

1. Seite ohne Site und ohne `--base-url`: Abbruch mit Fehler, **bevor** etwas gesendet wird (Task 4, Task 6).
2. `--min-age` jünger als `--max-age` (leerer Bereich) und unlesbare Altersangaben: Fehler statt leerer Mail (Task 2, Task 6).
3. Ungültige Adresse in `--to` (oder leere Liste): Fehler vor jeder Datenbankarbeit (Task 6).
4. Link- oder Record-Titel, die mit `=`, `+`, `-` oder `@` beginnen, in der CSV (Formel-Injektion in Excel), dazu Semikolon, Anführungszeichen und Zeilenumbrüche im Text (Task 3).
5. Sehr viele Treffer: die CSV wird seitenweise gelesen, nicht mit einem `find()` ohne Limit; inline bleibt bei `--inline-limit` (Task 4, Task 5).
6. HTML im Linktext oder in der Ziel-URL darf nicht ungefiltert im Mailtext landen (Task 5).
7. Datensatz zwischen Prüfung und Mail gelöscht: Titel fällt auf `tabelle:uid` zurück wie im Modul, kein Fehler (Task 4).

---

## Dateistruktur

- Ändern: `Classes/Repository/BrokenLinkQuery.php`, `Classes/Repository/BrokenLinkRepository.php`, `ext_localconf.php` (Template-Pfad), `Resources/Private/Language/messages.xlf`.
- Neu: `Classes/Report/Mail/AgeParser.php`, `ReportContent.php`, `MailReportItem.php`, `MailReportRequest.php`, `MailReportBuilder.php`, `CsvWriter.php`, `BackendUrls.php`, `BackendUrlFactory.php`, `MailReportOptions.php`, `ReportMailFactory.php`; `Classes/Command/ReportCommand.php`; `Resources/Private/Templates/Email/BrokenLinksReport.html` (und `.txt`).
- Tests: `Tests/Unit/Report/Mail/AgeParserTest.php`, `CsvWriterTest.php`; `Tests/Functional/Repository/BrokenLinkRepositoryTest.php` (erweitern), `Tests/Functional/Report/Mail/MailReportBuilderTest.php`, `BackendUrlFactoryTest.php`, `ReportMailFactoryTest.php`; `Tests/Functional/Command/ReportCommandTest.php`.

---

### Task 1: Abfrage um obere Altersgrenze und Tabellenlisten erweitern

**Files:**
- Modify: `Classes/Repository/BrokenLinkQuery.php`, `Classes/Repository/BrokenLinkRepository.php` (`createQueryBuilder()`)
- Test: `Tests/Functional/Repository/BrokenLinkRepositoryTest.php`

**Interfaces:**
- Produces: `BrokenLinkQuery` bekommt am Ende der Konstruktorparameter `public ?int $detectedBefore = null` (nur Zeilen mit `first_detected <= Wert`), `public array $includeTables = []`, `public array $excludeTables = []` (beide `list<string>`). `ReportFilter` bleibt unverändert (das Modul filtert weiter nach einer Tabelle).

- [ ] **Step 1: Failing tests** in `BrokenLinkRepositoryTest`: `detectedBeforeKeepsOnlyLinksFirstDetectedAtOrBeforeTheTimestamp` (drei Zeilen mit `first_detected` 100/200/300, `detectedBefore: 200` ergibt 100 und 200, Grenze inklusive), `detectedSinceAndBeforeFormARange`, `includeTablesKeepsOnlyThoseTables`, `excludeTablesRemovesThoseTables`, `includeIsAppliedBeforeExclude` (include `[tt_content, pages]`, exclude `[pages]` ergibt nur `tt_content`).
- [ ] **Step 2: Run** `Build/Scripts/runTests.sh -s functional Tests/Functional/Repository/BrokenLinkRepositoryTest.php`; erwartet FAIL (unbekannte Parameter).
- [ ] **Step 3: Implement** die drei Parameter und die Bedingungen in `createQueryBuilder()` neben `detectedSince` (`lte` auf `b.first_detected`, `in`/`notIn` auf `b.table_name` mit `PARAM_STR_ARRAY`; leere Listen setzen keine Bedingung).
- [ ] **Step 4: Run** wie Step 2 plus `-s unit`; erwartet PASS, vorhandene Tests des Moduls bleiben grün.
- [ ] **Step 5: Commit** `[FEATURE] Filter broken links by maximum age and table lists`.

---

### Task 2: Altersangaben parsen

**Files:**
- Create: `Classes/Report/Mail/AgeParser.php`
- Test: `Tests/Unit/Report/Mail/AgeParserTest.php`

**Interfaces:**
- Produces: `final readonly class AgeParser { public function toTimestamp(string $age, int $now): int }` liefert `$now` minus die Dauer von `$age` (DateInterval-Syntax: "7 days", "1 day", "12 hours"). Wirft `\InvalidArgumentException` (eigener Code) bei leerem, unlesbarem oder nullem Wert.

- [ ] **Step 1: Failing tests** (`#[DataProvider]`): `"7 days"`, `"1 day"`, `"12 hours"` mit festem `$now` ergeben exakte Zeitstempel; `""`, `"soon"`, `"0 days"` werfen.
- [ ] **Step 2: Run** `-s unit Tests/Unit/Report/Mail/AgeParserTest.php`; erwartet FAIL.
- [ ] **Step 3: Implement** mit `\DateInterval::createFromDateString`; ab PHP 8.3 wirft ein ungültiger String selbst, trotzdem alles in die eine `InvalidArgumentException` übersetzen; Monats-/Jahresangaben sind erlaubt, gerechnet wird über `\DateTimeImmutable::setTimestamp($now)->sub($interval)`.
- [ ] **Step 4: Run** wie Step 2; erwartet PASS.
- [ ] **Step 5: Commit** `[FEATURE] Parse age options of the mail report`.

---

### Task 3: CSV schreiben

**Files:**
- Create: `Classes/Report/Mail/MailReportItem.php`, `Classes/Report/Mail/CsvWriter.php`
- Test: `Tests/Unit/Report/Mail/CsvWriterTest.php`

**Interfaces:**
- Produces: `final readonly class MailReportItem` mit `string $pageTitle`, `int $pageUid`, `string $table`, `int $uid`, `string $field`, `int $language`, `string $recordTitle`, `string $linkText`, `string $target`, `string $linkType`, `string $status`, `string $errorType`, `string $errorMessage`, `int $firstDetected`, `int $lastSeen`, `string $editUrl`, `string $moduleUrl`.
- Produces: `final readonly class CsvWriter { public function write(iterable $items): string }` (Elemente `MailReportItem`). Spalten und Reihenfolge: Seitentitel, Page-Id, Tabelle, Uid, Feld, Sprache, Datensatz, Linktext, Ziel, Linktyp, Status, Fehlertyp, Fehler, kaputt seit, zuletzt gesehen, Bearbeiten-Link, Modul-Link (englische Kopfzeile). UTF-8 mit BOM, Semikolon, Zeitangaben als `Y-m-d H:i:s` (UTC).

- [ ] **Step 1: Failing tests:** `headerAndOneRowAreWritten`; `fieldsWithSemicolonQuoteAndNewlineAreQuoted`; `valuesStartingWithFormulaCharactersAreNeutralised` (`=SUM(A1)`, `+1`, `-1`, `@x` bekommen ein führendes `'`); `anEmptyListYieldsOnlyTheHeader`; `outputStartsWithTheUtf8Bom`.
- [ ] **Step 2: Run** `-s unit Tests/Unit/Report/Mail/CsvWriterTest.php`; erwartet FAIL.
- [ ] **Step 3: Implement** über `fputcsv` auf `php://temp`; die Neutralisierung gilt für **alle** Textspalten, auch für Ziel und Linktext.
- [ ] **Step 4: Run** wie Step 2; erwartet PASS.
- [ ] **Step 5: Commit** `[FEATURE] Write the mail report result as CSV`.

---

### Task 4: Bericht zusammenstellen und Backend-URLs bauen

**Files:**
- Create: `Classes/Report/Mail/MailReportRequest.php`, `MailReportBuilder.php`, `BackendUrls.php`, `BackendUrlFactory.php`
- Test: `Tests/Functional/Report/Mail/MailReportBuilderTest.php`, `BackendUrlFactoryTest.php`

**Interfaces:**
- Consumes: `BrokenLinkQuery` (Task 1), `AgeParser` (Task 2), `MailReportItem` (Task 3), `PageRecordFinder::findPageIds(int, int): list<int>`, `ErrorMessageFormatter::format(string $errorType, array $details): string`.
- Produces: `final readonly class MailReportRequest { __construct(int $pageUid, int $depth, ReportFilter $filter, array $includeTables, array $excludeTables, ?string $minAge, ?string $maxAge) }`.
- Produces: `MailReportBuilder::count(MailReportRequest $request, BackendUrls $urls, int $now): int`, `::first(MailReportRequest $request, BackendUrls $urls, int $now, int $limit): list<MailReportItem>`, `::iterate(MailReportRequest $request, BackendUrls $urls, int $now): \Generator<int, MailReportItem>` (liest in Seiten von 1000 über `find($query, ordering, $limit, $offset)`, Reihenfolge `first_detected` aufsteigend).
- Produces: `BackendUrlFactory::create(int $pageUid, ?string $baseUrl): BackendUrls` (wirft `\RuntimeException` mit eigenem Code, wenn weder Site noch `$baseUrl`); `BackendUrls::module(int $pageUid): string`, `::edit(string $table, int $uid): string`, beide absolut.

- [ ] **Step 1: Failing tests, `BackendUrlFactoryTest`** (mit `SiteConfigurationTrait`): `moduleUrlIsAbsoluteOnTheSiteHost` (erwartet `https://…/typo3/module/link-management/linkvalidator2?id=<uid>` mit der Basis der Testsite), `editUrlTargetsRecordEdit`, `anAbsoluteBackendEntryPointWins` (`BE/entryPoint` als volle URL), `aPageWithoutSiteAndWithoutBaseUrlThrows`, `baseUrlIsUsedWhenThereIsNoSite`.
- [ ] **Step 2: Failing tests, `MailReportBuilderTest`** mit Fixtures wie `FillQueueCommandTest` und `BrokenLinkResultsTrait`: `minAgeKeepsOnlyOldLinks`, `maxAgeKeepsOnlyNewLinks`, `depthIncludesSubpages`, `includeAndExcludeTablesApply`, `firstReturnsAtMostTheLimitOldestFirst`, `iterateReturnsEverythingAcrossPages` (mehr Zeilen als eine Seite, `limit` für die Test-Seitengröße über Konstante), `aDeletedRecordFallsBackToTableAndUid`, `anEmptyRangeThrows` (`minAge` jünger als `maxAge`, `\InvalidArgumentException`).
- [ ] **Step 3: Run** `-s functional Tests/Functional/Report/Mail`; erwartet FAIL.
- [ ] **Step 4: Implement.** `BackendUrlFactory`: Site über `SiteFinder::getSiteByPageId`, daraus einen Request für `BackendEntryPointResolver::getUriFromRequest()` bauen, Pfade für Modul und `record_edit` über die Backend-Routen auflösen und mit dem Host aus dem Resolver absolut machen. `MailReportBuilder`: gemeinsame private Methode baut `BrokenLinkQuery` (`permissions: null`, `detectedSince` aus `maxAge`, `detectedBefore` aus `minAge`, beide über `AgeParser`); Titel und Fehlertext wie `ReportController::getBrokenLinks()`.
- [ ] **Step 5: Run** wie Step 3; erwartet PASS.
- [ ] **Step 6: Commit** `[FEATURE] Compose the mail report from the broken links of a page tree`.

---

### Task 5: Mail bauen

**Files:**
- Create: `Classes/Report/Mail/ReportContent.php`, `MailReportOptions.php`, `ReportMailFactory.php`, `Resources/Private/Templates/Email/BrokenLinksReport.html` und `.txt`
- Modify: `ext_localconf.php` (Template-Pfad in `$GLOBALS['TYPO3_CONF_VARS']['MAIL']['templateRootPaths']`, `??=` mit eigenem Schlüssel), `Resources/Private/Language/messages.xlf`
- Test: `Tests/Functional/Report/Mail/ReportMailFactoryTest.php`

**Interfaces:**
- Consumes: `MailReportItem`, `CsvWriter` (Task 3), `TemplatedEmailFactory`.
- Produces: `enum ReportContent: string { Inline = 'inline'; Attach = 'attach'; Both = 'both' }` mit `includesInline(): bool` und `includesCsv(): bool`.
- Produces: `final readonly class MailReportOptions { __construct(list<string> $to, ?string $from, ?string $subject, ReportContent $content, int $inlineLimit, string $template, string $language) }`.
- Produces: `ReportMailFactory::create(MailReportOptions $options, string $pageTitle, int $total, list<MailReportItem> $inline, ?string $csv, string $moduleUrl): FluidEmail`. Die Quelle der Zeilen für `$csv` ist Sache des Aufrufers.

- [ ] **Step 1: Failing tests:** `inlineModeListsItemsAndAttachesNothing`, `attachModeHasASummaryAndTheCsvButNoRows`, `bothModeHasRowsAndCsv`, `moreThanTheLimitShowsAndNMoreWithModuleLink` (`$total` größer als Zeilen in `$inline`), `defaultSubjectNamesThePage` ("Broken links: <Seitentitel>"), `customSubjectWins`, `fromOverridesTheSystemSender`, `htmlInLinkTextAndTargetIsEscaped`, `noCsvIsAttachedWhenThereIsNone` (`$csv === null`), `theCsvAttachmentIsNamedAndTyped` (`text/csv`, Dateiname mit Datum).
- [ ] **Step 2: Run** `-s functional Tests/Functional/Report/Mail/ReportMailFactoryTest.php`; erwartet FAIL.
- [ ] **Step 3: Implement.** Template-Name aus `$options->template` (Standard `BrokenLinksReport`); Texte aus `messages.xlf` mit der Sprache der Option; Anhang über `FluidEmail::attach()`; Absender nur setzen, wenn `from` gegeben, sonst Systemabsender.
- [ ] **Step 4: Run** wie Step 2; erwartet PASS.
- [ ] **Step 5: Commit** `[FEATURE] Build the mail of the report`.

---

### Task 6: Command

**Files:**
- Create: `Classes/Command/ReportCommand.php`
- Test: `Tests/Functional/Command/ReportCommandTest.php`

**Interfaces:**
- Consumes: alles aus Task 1–5, `MailerInterface`.
- Produces: `#[AsCommand('linkvalidator2:report', …)] final class ReportCommand`, Argument `page` (Pflicht), Optionen laut Global Constraints. Rückgabe `Command::SUCCESS` bei gesendeter Mail **und** bei "keine Treffer, keine Mail"; `Command::FAILURE` bei jedem Fehler unten.

- [ ] **Step 1: Failing tests** (Mails abgreifen: Test-Instanz mit `MAIL.transport = mbox` und der Datei auslesen, oder Mailer im Command per Konstruktor ersetzen; Assertions auf Empfänger, Betreff, Anhang, Text):
  `sendsOneMailWithTheMatchingLinks`, `sendsNothingWithoutHitsAndSucceeds`, `sendsAnEmptyMailWithSendEmpty`, `noCsvWithoutHitsEvenInAttachMode`, `minAndMaxAgeNarrowTheResult`, `statusDefaultsToBrokenAndAllRemovesTheFilter`, `includeAndExcludeTablesApply`, `failsForAnUnknownPage`, `failsForAnInvalidRecipient`, `failsForAnEmptyRecipientList`, `failsForAPageWithoutSiteAndWithoutBaseUrl` (nichts gesendet), `failsForAnEmptyAgeRange`, `failsForAnUnknownContentMode`, `aTransportErrorMakesTheCommandFail`.
- [ ] **Step 2: Run** `-s functional Tests/Functional/Command/ReportCommandTest.php`; erwartet FAIL.
- [ ] **Step 3: Implement.** Reihenfolge in `execute()`: Optionen und Adressen validieren, Seite prüfen, `BackendUrlFactory::create`, `count`; bei 0 und ohne `--send-empty` Meldung und `SUCCESS`; sonst `first()` für inline, `iterate()` in `CsvWriter` für den Anhang (nur wenn der Modus es verlangt und `total > 0`), Mail bauen, senden, Zahl der Treffer und Empfänger ausgeben. `--to` akzeptiert Komma-getrennte Listen und Mehrfachangabe (`VALUE_IS_ARRAY`). Fehlermeldungen über `SymfonyStyle::error()`, Exit `FAILURE`.
- [ ] **Step 4: Run** wie Step 2 und danach `-s unit` sowie `-s functional` komplett; erwartet PASS.
- [ ] **Step 5: Commit** `[FEATURE] Add the command to mail a report of broken links`.

---

### Task 7: Dokumentation und Verwaltung

**Files:**
- Modify: `README.md`, `Documentation/todo.md`, `Resources/Private/Issues/106487-mail-report-template.md`, `Resources/Private/Issues/106677-mail-report-clickable-urls.md`, `Resources/Private/Issues/README.md`, `Notes/2026-10-08-linkvalidator-rewrite.md`

- [ ] **Step 1:** README, Feature-Tabelle: Zeilen "E-mail report", "Site name in the mail, links to edit / to the module" und "Full, clickable target URL in the mail" von 📋 auf ✅ mit kurzer Beschreibung (Seitenbereich statt Site, Filter, Altersfilter, `--content`, CSV, `--send-empty`), *Forge*-Spalte #106487/#106677 behalten; die Zeile "E-mail report" nennt auch, dass kein Zustand gespeichert wird.
- [ ] **Step 2:** `Documentation/todo.md`: Abschnitt für Integratoren mit Scheduler-Beispielen (je Bereich eine Aufgabe "Execute console commands", Erinnerung `--min-age="7 days"`, "neu" `--max-age="1 day"`), Hinweis auf `BE/entryPoint` und `--base-url`, Hinweis, dass Tabellenlisten keine Berechtigungen ersetzen.
- [ ] **Step 3:** Beide Issue-Notizen: Status auf "umgesetzt"; wenn danach nichts mehr offen ist, nach `done/` verschieben und `Resources/Private/Issues/README.md` nachziehen (Tabellen "Open"/"Done").
- [ ] **Step 4:** `Notes/2026-10-08-linkvalidator-rewrite.md`: Punkt "Mail-Report" in der TODO-Liste mit `[x]` abhaken und auf `2026-10-19-mail-report-design.md` verweisen.
- [ ] **Step 5: Commit** `[DOCS] Document the mail report`.

---

## Self-Review

- **Spec-Abdeckung:** Bereich aus Page-Id und Tiefe (Task 4, 6); Filter wie im Modul (Task 4, 6, `ReportFilter` bleibt); Tabellenlisten (Task 1); Alter min/max (Task 1, 2, 4); `--content` (Task 5, 6); `--send-empty` (Task 6); CSV (Task 3, 6); absolute URLs aus Page-Id (Task 4); kein Zustand (kein Task nötig, Nicht-Ziel); Entscheidungen 1–12 der Spec liegen in Task 5 und 6 (Absender, Betreff, Template, Sprache, Standardstatus, Obergrenze, Aufruf, Fehlerverhalten); Doku und Forge (Task 7). Lücke: Entscheidung 10 (Workspaces) braucht nichts, steht als offener Punkt in der Spec.
- **Typkonsistenz:** `MailReportItem` (Task 3) wird in Task 4 erzeugt und in Task 5 verbraucht; `BackendUrls` aus Task 4 in `MailReportBuilder::count/first/iterate`; `MailReportOptions` und `ReportContent` aus Task 5 im Command.
- **Abweichung von der Spec:** Die Option für die Mailsprache heißt `--mail-language` statt `--language` (kollidiert mit dem Sprachfilter).
- **Review Focus:** Punkte 1–7 haben je einen Test in den genannten Tasks.
