# Linkvalidator2 – Schritt 1: Engine – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eine lauffähige Prüf-Engine in `typo3conf/ext/linkvalidator2`, die per CLI
Datensätze aus einer Status-Queue abarbeitet, Links extrahiert, interne Ziele (Seite,
Datensatz, Datei, Ordner) prüft und kaputte Vorkommen persistiert.

**Architecture:** Felder werden über die Schema-API ausgewählt (Link-Felder, RTE,
Softref `typolink`/`typolink_tag`, Opt-out per Extension-Konfiguration). Der Extractor
liest `<a href>` bzw. Typolinks selbst aus (die Core-Softref-Parser verlieren den
Original-Link-String, sie sind für den Referenzindex gebaut), `LinkService::resolve()`
typisiert sie, ein getaggter Checker pro LinkService-Typ liefert ein `CheckResult`.
Eine Status-Tabelle pro Datensatz ist zugleich Arbeits-Queue (lazy: „nie geprüft“ =
LEFT JOIN ohne Status-Zeile).

**Tech Stack:** TYPO3 v15.0-dev (Core `main`), PHP ^8.5, Doctrine DBAL QueryBuilder,
Symfony Console/DI, DDEV-Instanz `t3-main` (Classic Mode, `typo3conf/PackageStates.php`).

**Spec:** `Notes/2026-10-08-linkvalidator-rewrite.md`

## Global Constraints

- Ort: `typo3conf/ext/linkvalidator2`, eigenes Git-Repo; im Core-Repo nichts committen.
- Naming **konsequent `linkvalidator2`**: Namespace `GeorgRinger\Linkvalidator2`, Tabellen
  `tx_linkvalidator2_*`, Service-Tag `linkvalidator2.checker`, Command `linkvalidator2:check`.
  Der String `linkvalidator2` darf nur als Bezeichner vorkommen (späteres stumpfes Ersetzen).
- Core-Regeln: `declare(strict_types=1);`, GPL-Header des TYPO3-Projekts, PSR-12,
  Klassen `final` wo möglich, Constructor-DI (kein `GeneralUtility::makeInstance()` außer
  für Query-Restrictions, wie im Core üblich), kein `$GLOBALS['TCA']` – nur `TcaSchemaFactory`.
- Jede geworfene Exception trägt einen eindeutigen Code = Unix-Timestamp beim Schreiben.
- Nur Live-Workspace: Spalte `workspace` existiert, ist immer `0`; Datensätze mit
  `t3ver_wsid != 0` werden nicht geprüft.
- Externe URLs (`url`) werden in Schritt 1 extrahiert, aber **nicht** geprüft (kein Checker
  registriert → übersprungen). E-Mail, Telefon, `inpage`, `unknown`, „aktuelle Seite“: übersprungen.
- Tests: laut Absprache **nicht zu Beginn**. Verifikation je Task per CLI-Smoke-Test,
  Test-Infrastruktur + Tests in Task 9.
- CLI in DDEV: `ddev exec bin/typo3 …` (Projektwurzel `t3-main`).

## Review Focus

1. **Gleicher Link mehrfach** (zweimal im selben Feld, oder RTE-Link dessen Text die URL
   selbst ist) → genau ein Vorkommen pro (Feld, Ziel). Smoke: Task 3, Test: Task 9.
2. **Quelle verschwindet** (Datensatz gelöscht oder versteckt seit letztem Lauf) → seine
   kaputten Links und die Status-Zeile verschwinden bzw. Status zeigt 0. Smoke: Task 7, Test: Task 9.
3. **Kaputte Link-Syntax** (`t3://page` ohne uid, `t3://gibtsnicht?uid=1`, `t3://record`
   ohne identifier) → `broken` mit `link.invalid`, kein Absturz des Laufs. Smoke: Task 4, Test: Task 9.
4. **Übersetzte Datensätze** (`sys_language_uid > 0`, übersetzte Seiten) → `language` korrekt,
   `pid` einer übersetzten Seite = uid der Default-Seite. Smoke: Task 7, Test: Task 9.
5. **Queue terminiert**: `--limit` größer als Anzahl Datensätze prüft jeden höchstens
   einmal pro Lauf und endet. Smoke: Task 8, Test: Task 9.
6. **Forge-Issues** (`Resources/Private/Issues/`): jeder Bugfix aus Task 8d bekommt einen
   Regressionstest in Task 9, der genau das Szenario des Issues nachstellt.

---

## Dateistruktur

```
typo3conf/ext/linkvalidator2/
  composer.json, ext_emconf.php, ext_tables.sql, ext_conf_template.txt
  Configuration/Services.yaml
  Resources/Public/Icons/Extension.svg          (Kopie aus sysext/linkvalidator)
  Classes/
    Configuration/ExtensionSettings.php          Opt-out-Liste lesen
    Extraction/ExtractionMode.php                enum Typolink|Html
    Extraction/CheckableField.php                VO Feldname + Modus
    Extraction/CheckableFieldResolver.php        Schema → prüfbare Felder/Tabellen
    Extraction/LinkCandidate.php                 VO: ein gefundener Link mit Herkunft
    Extraction/LinkExtractor.php                 Datensatz → LinkCandidate[]
    Target/LinkTarget.php                        VO: typisiertes, normalisiertes Ziel
    Target/LinkTargetFactory.php                 LinkCandidate → ?LinkTarget (LinkService)
    Check/CheckStatus.php                        enum ok|broken|unknown
    Check/CheckResult.php                        VO Status + Fehlertyp + Details
    Check/LinkCheckerInterface.php
    Check/CheckerRegistry.php                    tagged iterator → Checker pro Typ
    Check/Checker/InvalidLinkChecker.php
    Check/Checker/PageChecker.php
    Check/Checker/RecordChecker.php
    Check/Checker/FileChecker.php                file + folder
    Repository/TargetRepository.php
    Repository/BrokenLinkRepository.php
    Repository/RecordStatusRepository.php
    Service/CheckedLink.php                      VO Kandidat + Ziel + Ergebnis
    Service/RecordCheckService.php               einen Datensatz komplett prüfen
    Service/RecordQueue.php                      nächste fällige Datensätze
    Command/CheckCommand.php                     linkvalidator2:check
```

GPL-Header (in **jede** PHP-Datei nach `declare(strict_types=1);`):

```php
/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */
```

In den Code-Blöcken unten ist der Header aus Platzgründen als `// GPL header` abgekürzt –
beim Anlegen den vollen Block einsetzen.

---

### Task 1: Extension-Gerüst, Schema, Aktivierung

**Files:**
- Create: `typo3conf/ext/linkvalidator2/composer.json`
- Create: `typo3conf/ext/linkvalidator2/ext_emconf.php`
- Create: `typo3conf/ext/linkvalidator2/ext_tables.sql`
- Create: `typo3conf/ext/linkvalidator2/ext_conf_template.txt`
- Create: `typo3conf/ext/linkvalidator2/Configuration/Services.yaml`
- Create: `typo3conf/ext/linkvalidator2/Resources/Public/Icons/Extension.svg`

**Interfaces:**
- Produces: Tabellen `tx_linkvalidator2_target`, `tx_linkvalidator2_broken_link`,
  `tx_linkvalidator2_record_status` (Spalten siehe SQL); Extension-Konfiguration
  `excludedFields`; Service-Tag `linkvalidator2.checker`.

- [ ] **Step 1: Git-Repo und Ordner anlegen**

```bash
mkdir -p typo3conf/ext/linkvalidator2/{Classes,Configuration,Resources/Public/Icons}
cd typo3conf/ext/linkvalidator2 && git init
cp ../../../typo3/sysext/linkvalidator/Resources/Public/Icons/Extension.svg Resources/Public/Icons/
```

- [ ] **Step 2: `composer.json`** (Tabs, wie im Core)

```json
{
	"name": "georgringer/linkvalidator2",
	"type": "typo3-cms-extension",
	"description": "TYPO3 CMS LinkValidator (rewrite) - Checks links in records and reports broken ones.",
	"license": [
		"GPL-2.0-or-later"
	],
	"require": {
		"typo3/cms-core": "15.0.*@dev"
	},
	"autoload": {
		"psr-4": {
			"GeorgRinger\\Linkvalidator2\\": "Classes/"
		}
	},
	"extra": {
		"typo3/cms": {
			"extension-key": "linkvalidator2",
			"version": "15.0.0",
			"Package": {
				"providesPackages": {}
			}
		}
	}
}
```

- [ ] **Step 3: `ext_emconf.php`** (Classic Mode braucht das Autoload hier)

```php
<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'LinkValidator (rewrite)',
    'description' => 'Checks links in records and reports broken ones.',
    'category' => 'module',
    'author' => 'TYPO3 Core Team',
    'author_email' => 'typo3cms@typo3.org',
    'state' => 'alpha',
    'version' => '15.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '15.0.0-15.0.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
    'autoload' => [
        'psr-4' => [
            'GeorgRinger\\Linkvalidator2\\' => 'Classes/',
        ],
    ],
];
```

- [ ] **Step 4: `ext_tables.sql`**

```sql
# Define tables and fields since they have no TCA
CREATE TABLE tx_linkvalidator2_target (
	uid int(11) unsigned NOT NULL auto_increment,
	link_type varchar(30) DEFAULT '' NOT NULL,
	identifier text,
	identifier_hash varchar(40) DEFAULT '' NOT NULL,
	status varchar(10) DEFAULT '' NOT NULL,
	error_type varchar(64) DEFAULT '' NOT NULL,
	error_details text,
	last_checked int(11) unsigned DEFAULT '0' NOT NULL,
	failure_count int(11) unsigned DEFAULT '0' NOT NULL,
	first_failed_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY identifier_hash (identifier_hash)
);

CREATE TABLE tx_linkvalidator2_broken_link (
	uid int(11) unsigned NOT NULL auto_increment,
	target int(11) unsigned DEFAULT '0' NOT NULL,
	table_name varchar(255) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	field varchar(255) DEFAULT '' NOT NULL,
	pid int(11) DEFAULT '0' NOT NULL,
	language int(11) DEFAULT '0' NOT NULL,
	workspace int(11) DEFAULT '0' NOT NULL,
	link_string text,
	link_text text,
	status varchar(10) DEFAULT '' NOT NULL,
	error_type varchar(64) DEFAULT '' NOT NULL,
	error_details text,
	first_detected int(11) unsigned DEFAULT '0' NOT NULL,
	last_seen int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	KEY record (table_name, record_uid),
	KEY pid (pid),
	KEY target (target)
);

CREATE TABLE tx_linkvalidator2_record_status (
	uid int(11) unsigned NOT NULL auto_increment,
	table_name varchar(255) DEFAULT '' NOT NULL,
	record_uid int(11) unsigned DEFAULT '0' NOT NULL,
	pid int(11) DEFAULT '0' NOT NULL,
	language int(11) DEFAULT '0' NOT NULL,
	workspace int(11) DEFAULT '0' NOT NULL,
	last_checked int(11) unsigned DEFAULT '0' NOT NULL,
	broken_count int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY record (table_name, record_uid, workspace),
	KEY last_checked (last_checked),
	KEY pid (pid)
);
```

Hinweis: `broken_link` hält Status/Fehler **pro Vorkommen** (nicht nur über `target`),
weil das Ergebnis interner Ziele später von der Sprache der Quelle abhängt (Sprachregel,
Schritt 7). `target` ist Cache für externe Ziele (Schritt 2) und Anker für Ausschlüsse (Schritt 5).

- [ ] **Step 5: `ext_conf_template.txt`**

```
# cat=basic; type=string; label=Excluded fields: Comma-separated list of "table.field" or "table.*" that are never checked
excludedFields =
```

- [ ] **Step 6: `Configuration/Services.yaml`**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  GeorgRinger\Linkvalidator2\:
    resource: '../Classes/*'
```

(`_instanceof` und die Registry-Verdrahtung kommen in Task 2, sobald die Klassen existieren.)

- [ ] **Step 7: Aktivieren, Autoload, DB-Schema**

```bash
ddev exec bin/typo3 extension:activate linkvalidator2
ddev exec bin/typo3 dumpautoload
ddev exec bin/typo3 extension:setup -e linkvalidator2
ddev mysql -e "SHOW TABLES LIKE 'tx_linkvalidator2_%'"
```

Expected: drei Tabellen `tx_linkvalidator2_broken_link`, `_record_status`, `_target`.

- [ ] **Step 8: Commit** (im Extension-Repo)

```bash
cd typo3conf/ext/linkvalidator2
git add -A && git commit -m "Add extension skeleton and database schema"
```

---

### Task 2: Value-Objekte, Checker-Vertrag, Registry, Command-Gerüst

**Files:**
- Create: `Classes/Check/CheckStatus.php`, `Classes/Check/CheckResult.php`,
  `Classes/Check/LinkCheckerInterface.php`, `Classes/Check/CheckerRegistry.php`
- Create: `Classes/Extraction/LinkCandidate.php`, `Classes/Target/LinkTarget.php`
- Create: `Classes/Command/CheckCommand.php` (Gerüst, wächst in Task 3/7/8)
- Modify: `Configuration/Services.yaml`

**Interfaces:**
- Produces:
  - `enum CheckStatus: string { Ok='ok'; Broken='broken'; Unknown='unknown' }`
  - `CheckResult::ok(): self`, `::broken(string $errorType, array $details = []): self`,
    `::unknown(string $errorType, array $details = []): self`, public readonly
    `CheckStatus $status`, `string $errorType`, `array $details`, `isOk(): bool`
  - `LinkCandidate(string $table, int $recordUid, int $pid, int $language, string $field, string $link, string $linkText)`
  - `LinkTarget(string $type, string $identifier, array $resolved)`, `getHash(): string`,
    `LinkTarget::invalid(string $link, string $reason): self`, `const TYPE_INVALID = 'invalid'`
  - `LinkCheckerInterface::getSupportedTypes(): list<string>`,
    `LinkCheckerInterface::check(LinkTarget $target, LinkCandidate $source): CheckResult`
  - `CheckerRegistry::getChecker(string $type): ?LinkCheckerInterface`

- [ ] **Step 1: `CheckStatus.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check;

enum CheckStatus: string
{
    case Ok = 'ok';
    case Broken = 'broken';
    case Unknown = 'unknown';
}
```

- [ ] **Step 2: `CheckResult.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check;

final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $errorType = '',
        public array $details = [],
    ) {}

    public static function ok(): self
    {
        return new self(CheckStatus::Ok);
    }

    public static function broken(string $errorType, array $details = []): self
    {
        return new self(CheckStatus::Broken, $errorType, $details);
    }

    public static function unknown(string $errorType, array $details = []): self
    {
        return new self(CheckStatus::Unknown, $errorType, $details);
    }

    public function isOk(): bool
    {
        return $this->status === CheckStatus::Ok;
    }
}
```

- [ ] **Step 3: `LinkCandidate.php` und `LinkTarget.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Extraction;

/**
 * A link found in a field of a record, before it is resolved.
 */
final readonly class LinkCandidate
{
    public function __construct(
        public string $table,
        public int $recordUid,
        public int $pid,
        public int $language,
        public string $field,
        public string $link,
        public string $linkText,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Target;

/**
 * A resolved link target. The identifier is normalized, so equal targets
 * found in different records share one identifier.
 */
final readonly class LinkTarget
{
    public const TYPE_INVALID = 'invalid';

    public function __construct(
        public string $type,
        public string $identifier,
        public array $resolved = [],
    ) {}

    public static function invalid(string $link, string $reason): self
    {
        return new self(self::TYPE_INVALID, self::TYPE_INVALID . ':' . $link, ['reason' => $reason]);
    }

    public function getHash(): string
    {
        return sha1($this->identifier);
    }
}
```

- [ ] **Step 4: `LinkCheckerInterface.php` und `CheckerRegistry.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check;

use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

interface LinkCheckerInterface
{
    /**
     * @return list<string> Link types as resolved by LinkService, e.g. "page", "file"
     */
    public function getSupportedTypes(): array;

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult;
}
```

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check;

final class CheckerRegistry
{
    /** @var array<string, LinkCheckerInterface> */
    private array $checkersByType = [];

    /**
     * @param iterable<LinkCheckerInterface> $checkers
     */
    public function __construct(iterable $checkers)
    {
        foreach ($checkers as $checker) {
            foreach ($checker->getSupportedTypes() as $type) {
                $this->checkersByType[$type] = $checker;
            }
        }
    }

    public function getChecker(string $type): ?LinkCheckerInterface
    {
        return $this->checkersByType[$type] ?? null;
    }
}
```

- [ ] **Step 4b: `Configuration/Services.yaml` vervollständigen**

```yaml
services:
  _defaults:
    autowire: true
    autoconfigure: true
    public: false

  _instanceof:
    GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface:
      tags: ['linkvalidator2.checker']

  GeorgRinger\Linkvalidator2\:
    resource: '../Classes/*'

  GeorgRinger\Linkvalidator2\Check\CheckerRegistry:
    arguments:
      $checkers: !tagged_iterator linkvalidator2.checker
```

- [ ] **Step 5: `CheckCommand.php` (Gerüst mit `--record`)**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('linkvalidator2:check', 'Check links in records and store broken ones.')]
final class CheckCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Check a single record, format "table:uid"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $record = (string)$input->getOption('record');
        if ($record !== '') {
            [$table, $uid] = $this->parseRecordOption($record);
            if ($table === '') {
                $io->error('Option --record expects "table:uid", e.g. "tt_content:12".');
                return Command::INVALID;
            }
            $io->writeln(sprintf('Record %s:%d', $table, $uid));
            return Command::SUCCESS;
        }
        $io->note('Queue processing follows in a later task.');
        return Command::SUCCESS;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseRecordOption(string $value): array
    {
        if (!preg_match('/^([a-z0-9_]+):(\d+)$/i', $value, $matches)) {
            return ['', 0];
        }
        return [$matches[1], (int)$matches[2]];
    }
}
```

- [ ] **Step 6: Cache leeren, Command ausführen**

```bash
ddev exec bin/typo3 cache:flush
ddev exec bin/typo3 linkvalidator2:check --record=tt_content:1
ddev exec bin/typo3 linkvalidator2:check --record=quatsch
```

Expected: `Record tt_content:1`, bzw. Fehlermeldung mit Exit-Code 2.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "Add value objects, checker contract and command skeleton"
```

---

### Task 3: Prüfbare Felder und Link-Extraktion

**Files:**
- Create: `Classes/Configuration/ExtensionSettings.php`
- Create: `Classes/Extraction/ExtractionMode.php`, `Classes/Extraction/CheckableField.php`
- Create: `Classes/Extraction/CheckableFieldResolver.php`, `Classes/Extraction/LinkExtractor.php`
- Modify: `Classes/Command/CheckCommand.php` (Debug-Ausgabe der Kandidaten)

**Interfaces:**
- Consumes: `LinkCandidate` (Task 2)
- Produces:
  - `ExtensionSettings::isFieldExcluded(string $table, string $field): bool`
  - `CheckableFieldResolver::getFieldsForRecord(string $table, array $row): list<CheckableField>`
  - `CheckableFieldResolver::getCheckableTables(): list<string>`
  - `LinkExtractor::extract(string $table, array $row, int $pid, int $language): list<LinkCandidate>`

- [x] **Step 1: Testinhalt** – erledigt per `Notes/seed-testdata.php`, uids siehe
  Spec-Abschnitt „Testdaten“. Haupt-CE: `tt_content:471` (DE: 472), Shortcut-Seiten 1288/1289,
  Link-Seiten 1290/1291. Hinweis: `#top` und `t3://page` stehen nach RTE-Transformation als
  `t3://page?uid=current…` in der DB → werden übersprungen (Review-Focus #3 betrifft dann
  nur `t3://gibtsnicht?uid=1` und `t3://record?uid=1`).

- [ ] **Step 2: `ExtensionSettings.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ExtensionSettings
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isFieldExcluded(string $table, string $field): bool
    {
        $excluded = $this->getList('excludedFields');
        return in_array($table . '.*', $excluded, true) || in_array($table . '.' . $field, $excluded, true);
    }

    /**
     * @return list<string>
     */
    private function getList(string $path): array
    {
        try {
            $value = (string)$this->extensionConfiguration->get('linkvalidator2', $path);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return [];
        }
        return GeneralUtility::trimExplode(',', $value, true);
    }
}
```

- [ ] **Step 3: `ExtractionMode.php` und `CheckableField.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Extraction;

enum ExtractionMode
{
    /** The field value is a single typolink, e.g. "t3://page?uid=1 _blank" */
    case Typolink;
    /** The field value is HTML containing <a href> tags */
    case Html;
}
```

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Extraction;

final readonly class CheckableField
{
    public function __construct(
        public string $name,
        public ExtractionMode $mode,
    ) {}
}
```

- [ ] **Step 4: `CheckableFieldResolver.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Extraction;

use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\TextFieldType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Schema\VisibleSchemaFieldsCollector;
use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;

/**
 * Decides which fields may contain links, derived from the schema.
 */
final readonly class CheckableFieldResolver
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private VisibleSchemaFieldsCollector $visibleSchemaFieldsCollector,
        private ExtensionSettings $extensionSettings,
    ) {}

    /**
     * Only fields shown for the record type of this row are returned.
     *
     * @return list<CheckableField>
     */
    public function getFieldsForRecord(string $table, array $row): array
    {
        $result = [];
        foreach ($this->visibleSchemaFieldsCollector->getFields($table, $row) as $field) {
            $checkableField = $this->toCheckableField($table, $field);
            if ($checkableField !== null) {
                $result[] = $checkableField;
            }
        }
        return $result;
    }

    /**
     * @return list<string>
     */
    public function getCheckableTables(): array
    {
        $tables = [];
        foreach ($this->tcaSchemaFactory->all() as $schema) {
            foreach ($schema->getFields() as $field) {
                if ($this->toCheckableField($schema->getName(), $field) !== null) {
                    $tables[] = $schema->getName();
                    break;
                }
            }
        }
        return $tables;
    }

    private function toCheckableField(string $table, FieldTypeInterface $field): ?CheckableField
    {
        if ($this->extensionSettings->isFieldExcluded($table, $field->getName())) {
            return null;
        }
        $softReferenceParsers = array_map(
            static fn(string $key): string => explode('[', $key, 2)[0],
            $field->getSoftReferenceKeys() ?: []
        );
        if ($field->isType(TableColumnType::LINK) || in_array('typolink', $softReferenceParsers, true)) {
            return new CheckableField($field->getName(), ExtractionMode::Typolink);
        }
        if (($field instanceof TextFieldType && $field->isRichText()) || in_array('typolink_tag', $softReferenceParsers, true)) {
            return new CheckableField($field->getName(), ExtractionMode::Html);
        }
        return null;
    }
}
```

- [ ] **Step 5: `LinkExtractor.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Extraction;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;

final readonly class LinkExtractor
{
    public function __construct(
        private CheckableFieldResolver $checkableFieldResolver,
        private TypoLinkCodecService $typoLinkCodecService,
        private HtmlParser $htmlParser,
    ) {}

    /**
     * @return list<LinkCandidate>
     */
    public function extract(string $table, array $row, int $pid, int $language): array
    {
        $candidates = [];
        foreach ($this->checkableFieldResolver->getFieldsForRecord($table, $row) as $field) {
            $value = (string)($row[$field->name] ?? '');
            if ($value === '') {
                continue;
            }
            $links = match ($field->mode) {
                ExtractionMode::Typolink => $this->extractFromTypolink($value),
                ExtractionMode::Html => $this->extractFromHtml($value),
            };
            foreach ($links as $link => $linkText) {
                $candidates[] = new LinkCandidate($table, (int)$row['uid'], $pid, $language, $field->name, (string)$link, $linkText);
            }
        }
        $shortcut = $this->extractShortcut($table, $row, $pid, $language);
        if ($shortcut !== null) {
            $candidates[] = $shortcut;
        }
        return $candidates;
    }

    /**
     * @return array<string, string> link => link text
     */
    private function extractFromTypolink(string $value): array
    {
        $link = trim((string)($this->typoLinkCodecService->decode($value)['url'] ?? ''));
        return $link === '' ? [] : [$link => ''];
    }

    /**
     * Keyed by link, so a link used twice in the same field is reported once.
     *
     * @return array<string, string> link => link text
     */
    private function extractFromHtml(string $html): array
    {
        $links = [];
        $blocks = $this->htmlParser->splitIntoBlock('a', $html);
        for ($i = 1, $count = count($blocks); $i < $count; $i += 2) {
            $attributes = $this->htmlParser->get_tag_attributes($this->htmlParser->getFirstTag($blocks[$i]), true)[0];
            $href = trim((string)($attributes['href'] ?? ''));
            if ($href === '' || isset($links[$href])) {
                continue;
            }
            $links[$href] = trim(strip_tags($this->htmlParser->removeFirstAndLastTag($blocks[$i])));
        }
        return $links;
    }

    private function extractShortcut(string $table, array $row, int $pid, int $language): ?LinkCandidate
    {
        if ($table !== 'pages'
            || (int)($row['doktype'] ?? 0) !== PageRepository::DOKTYPE_SHORTCUT
            || (int)($row['shortcut_mode'] ?? 0) !== PageRepository::SHORTCUT_MODE_NONE
        ) {
            return null;
        }
        $targetUid = (int)preg_replace('/^pages_/', '', (string)($row['shortcut'] ?? ''));
        if ($targetUid <= 0) {
            return null;
        }
        return new LinkCandidate($table, (int)$row['uid'], $pid, $language, 'shortcut', 't3://page?uid=' . $targetUid, '');
    }
}
```

- [ ] **Step 6: Command – Kandidaten ausgeben.** In `CheckCommand` Konstruktor ergänzen und
  den `--record`-Zweig ersetzen (temporär; Task 7 ersetzt ihn durch den Service):

```php
    public function __construct(
        private readonly LinkExtractor $linkExtractor,
    ) {
        parent::__construct();
    }
```

```php
            $row = BackendUtility::getRecord($table, $uid);
            if ($row === null) {
                $io->error(sprintf('Record %s:%d not found.', $table, $uid));
                return Command::FAILURE;
            }
            foreach ($this->linkExtractor->extract($table, $row, (int)$row['pid'], 0) as $candidate) {
                $io->writeln(sprintf('%s  %s  "%s"', $candidate->field, $candidate->link, $candidate->linkText));
            }
            return Command::SUCCESS;
```

(Imports: `TYPO3\CMS\Backend\Utility\BackendUtility`, `GeorgRinger\Linkvalidator2\Extraction\LinkExtractor`.)

- [ ] **Step 7: Smoke**

```bash
ddev exec bin/typo3 cache:flush
ddev exec bin/typo3 linkvalidator2:check --record=tt_content:<CE-uid>
ddev exec bin/typo3 linkvalidator2:check --record=pages:<E-uid>
```

Expected tt_content: je eine Zeile `bodytext` für A, B, C, D, 999999, Datei,
`https://typo3.org` (**nur einmal**), `mailto:…`, `#top`; plus `header_link  t3://page?uid=<B>`.
Expected pages E: `shortcut  t3://page?uid=<C>`.
Danach `ddev exec bin/typo3 configuration:set EXTENSIONS/linkvalidator2/excludedFields 'tt_content.header_link'`
→ `header_link` fehlt in der Ausgabe; wieder leeren.

- [ ] **Step 8: Commit**

```bash
git add -A && git commit -m "Extract links from schema-derived fields"
```

---

### Task 4: Ziele auflösen (`LinkTargetFactory`)

**Files:**
- Create: `Classes/Target/LinkTargetFactory.php`
- Modify: `Classes/Command/CheckCommand.php` (Ziel-Identifier mit ausgeben)

**Interfaces:**
- Consumes: `LinkCandidate`, `LinkTarget` (Task 2)
- Produces: `LinkTargetFactory::create(LinkCandidate $candidate): ?LinkTarget` –
  `null` = nicht prüfen (E-Mail, Telefon, inpage, unknown, aktuelle Seite).
  `resolved`-Keys je Typ: page → `pageuid` (int), `fragment`?; record → `table`, `uid`;
  file → `file` (?File); folder → `folder` (?Folder); url → `url`; invalid → `reason`.

- [ ] **Step 1: `LinkTargetFactory.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Target;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\LinkHandling\Exception\UnknownLinkHandlerException;
use TYPO3\CMS\Core\LinkHandling\Exception\UnknownUrnException;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\MathUtility;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;

final readonly class LinkTargetFactory
{
    public function __construct(
        private LinkService $linkService,
    ) {}

    public function create(LinkCandidate $candidate): ?LinkTarget
    {
        try {
            $resolved = $this->linkService->resolve($candidate->link);
        } catch (UnknownLinkHandlerException|UnknownUrnException|\InvalidArgumentException $e) {
            return LinkTarget::invalid($candidate->link, $e->getMessage());
        }
        $type = (string)($resolved['type'] ?? LinkService::TYPE_UNKNOWN);
        return match ($type) {
            LinkService::TYPE_PAGE => $this->createPageTarget($candidate, $resolved),
            LinkService::TYPE_RECORD => $this->createRecordTarget($candidate, $resolved),
            LinkService::TYPE_FILE => new LinkTarget(
                $type,
                'file:' . (($resolved['file'] ?? null) instanceof File ? $resolved['file']->getUid() : $candidate->link),
                $resolved
            ),
            LinkService::TYPE_FOLDER => new LinkTarget(
                $type,
                'folder:' . (($resolved['folder'] ?? null) instanceof Folder ? $resolved['folder']->getCombinedIdentifier() : $candidate->link),
                $resolved
            ),
            LinkService::TYPE_URL => new LinkTarget($type, 'url:' . $resolved['url'], $resolved),
            default => null,
        };
    }

    private function createPageTarget(LinkCandidate $candidate, array $resolved): ?LinkTarget
    {
        $pageUid = $resolved['pageuid'] ?? null;
        if ($pageUid === 'current') {
            return null;
        }
        if (!MathUtility::canBeInterpretedAsInteger($pageUid) || (int)$pageUid <= 0) {
            return LinkTarget::invalid($candidate->link, 'Page link without valid uid');
        }
        $resolved['pageuid'] = (int)$pageUid;
        $fragment = (string)($resolved['fragment'] ?? '');
        return new LinkTarget(LinkService::TYPE_PAGE, 'page:' . $pageUid . ($fragment !== '' ? '#' . $fragment : ''), $resolved);
    }

    private function createRecordTarget(LinkCandidate $candidate, array $resolved): LinkTarget
    {
        $identifier = (string)($resolved['identifier'] ?? '');
        $uid = (int)($resolved['uid'] ?? 0);
        if ($identifier === '' || $uid <= 0) {
            return LinkTarget::invalid($candidate->link, 'Record link without identifier or uid');
        }
        $pageTsConfig = BackendUtility::getPagesTSconfig($candidate->pid);
        $table = (string)($pageTsConfig['TCEMAIN.']['linkHandler.'][$identifier . '.']['configuration.']['table'] ?? $identifier);
        $resolved['table'] = $table;
        return new LinkTarget(LinkService::TYPE_RECORD, 'record:' . $table . ':' . $uid, $resolved);
    }
}
```

- [ ] **Step 2: Command erweitern** – Konstruktor um `LinkTargetFactory $linkTargetFactory`
  ergänzen und die Ausgabeschleife ersetzen:

```php
            foreach ($this->linkExtractor->extract($table, $row, (int)$row['pid'], 0) as $candidate) {
                $target = $this->linkTargetFactory->create($candidate);
                $io->writeln(sprintf('%s  %s  →  %s', $candidate->field, $candidate->link, $target?->identifier ?? '(skipped)'));
            }
```

- [ ] **Step 3: Kaputte Syntax ergänzen.** Im Test-CE den RTE-Text um
  `<a href="t3://page">x</a>`, `<a href="t3://gibtsnicht?uid=1">y</a>`, `<a href="t3://record?uid=1">z</a>` erweitern.

- [ ] **Step 4: Smoke**

```bash
ddev exec bin/typo3 linkvalidator2:check --record=tt_content:<CE-uid>
```

Expected: `page:<A>`, `page:<B>`, …, `file:<uid>`, `url:https://typo3.org`, `mailto`/`#top` → `(skipped)`,
die drei kaputten → `invalid:t3://page`, `invalid:t3://gibtsnicht?uid=1`, `invalid:t3://record?uid=1`.
Kein Stacktrace.

Falls `t3://page` ohne uid **nicht** als invalid erscheint: `PageLinkHandler` liefert
`pageuid = 'current'` für leere Daten → das ist dann korrekt `(skipped)`; in diesem Fall
die Erwartung im Review-Focus #3 für `t3://page` auf „übersprungen“ anpassen und im Plan notieren.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Resolve link targets via LinkService"
```

---

### Task 5: Checker für interne Ziele

**Files:**
- Create: `Classes/Check/Checker/InvalidLinkChecker.php`, `PageChecker.php`,
  `RecordChecker.php`, `FileChecker.php`
- Modify: `Classes/Command/CheckCommand.php` (Ergebnis ausgeben)

**Interfaces:**
- Consumes: `LinkCheckerInterface`, `CheckResult`, `LinkTarget`, `LinkCandidate`, `CheckerRegistry`
- Produces: Fehlertypen (Strings, später Label-Keys):
  `link.invalid`, `page.missing`, `page.hidden`, `page.expired`, `page.noFrontend`,
  `record.missing`, `record.hidden`, `record.expired`, `record.unknownTable`,
  `file.missing`, `folder.missing`, `storage.offline` (unknown).

- [ ] **Step 1: `InvalidLinkChecker.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check\Checker;

use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class InvalidLinkChecker implements LinkCheckerInterface
{
    public function getSupportedTypes(): array
    {
        return [LinkTarget::TYPE_INVALID];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        return CheckResult::broken('link.invalid', ['reason' => (string)($target->resolved['reason'] ?? '')]);
    }
}
```

- [ ] **Step 2: `PageChecker.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class PageChecker implements LinkCheckerInterface
{
    private const DOKTYPES_WITHOUT_FRONTEND = [
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_PAGE];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $pageUid = (int)$target->resolved['pageuid'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $page = $queryBuilder
            ->select('uid', 'deleted', 'hidden', 'endtime', 'doktype')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($page === false || (int)$page['deleted'] === 1) {
            return CheckResult::broken('page.missing', ['page' => $pageUid]);
        }
        if ((int)$page['hidden'] === 1) {
            return CheckResult::broken('page.hidden', ['page' => $pageUid]);
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        if ((int)$page['endtime'] > 0 && (int)$page['endtime'] <= $now) {
            return CheckResult::broken('page.expired', ['page' => $pageUid, 'endtime' => (int)$page['endtime']]);
        }
        if (in_array((int)$page['doktype'], self::DOKTYPES_WITHOUT_FRONTEND, true)) {
            return CheckResult::broken('page.noFrontend', ['page' => $pageUid, 'doktype' => (int)$page['doktype']]);
        }
        return CheckResult::ok();
    }
}
```

- [ ] **Step 3: `RecordChecker.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class RecordChecker implements LinkCheckerInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private Context $context,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_RECORD];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $table = (string)$target->resolved['table'];
        $uid = (int)$target->resolved['uid'];
        if (!$this->tcaSchemaFactory->has($table)) {
            return CheckResult::broken('record.unknownTable', ['table' => $table]);
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $fields = ['uid'];
        $deletedField = $schema->hasCapability(TcaSchemaCapability::SoftDelete)
            ? (string)$schema->getCapability(TcaSchemaCapability::SoftDelete) : '';
        $hiddenField = $schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            ? (string)$schema->getCapability(TcaSchemaCapability::RestrictionDisabledField) : '';
        $endtimeField = $schema->hasCapability(TcaSchemaCapability::RestrictionEndTime)
            ? (string)$schema->getCapability(TcaSchemaCapability::RestrictionEndTime) : '';
        foreach ([$deletedField, $hiddenField, $endtimeField] as $field) {
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        $details = ['table' => $table, 'uid' => $uid];
        if ($record === false || ($deletedField !== '' && (int)$record[$deletedField] === 1)) {
            return CheckResult::broken('record.missing', $details);
        }
        if ($hiddenField !== '' && (int)$record[$hiddenField] === 1) {
            return CheckResult::broken('record.hidden', $details);
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        if ($endtimeField !== '' && (int)$record[$endtimeField] > 0 && (int)$record[$endtimeField] <= $now) {
            return CheckResult::broken('record.expired', $details);
        }
        return CheckResult::ok();
    }
}
```

- [ ] **Step 4: `FileChecker.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class FileChecker implements LinkCheckerInterface
{
    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_FILE, LinkService::TYPE_FOLDER];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        if ($target->type === LinkService::TYPE_FOLDER) {
            $folder = $target->resolved['folder'] ?? null;
            if (!$folder instanceof Folder) {
                return CheckResult::broken('folder.missing');
            }
            return $folder->getStorage()->isOnline() ? CheckResult::ok() : CheckResult::unknown('storage.offline');
        }
        $file = $target->resolved['file'] ?? null;
        if (!$file instanceof File) {
            return CheckResult::broken('file.missing');
        }
        if (!$file->getStorage()->isOnline()) {
            return CheckResult::unknown('storage.offline', ['file' => $file->getUid()]);
        }
        if ($file->isMissing()) {
            return CheckResult::broken('file.missing', ['file' => $file->getUid()]);
        }
        return CheckResult::ok();
    }
}
```

- [ ] **Step 5: Command erweitern** – Konstruktor um `CheckerRegistry $checkerRegistry` ergänzen,
  Schleife:

```php
            foreach ($this->linkExtractor->extract($table, $row, (int)$row['pid'], 0) as $candidate) {
                $target = $this->linkTargetFactory->create($candidate);
                $checker = $target !== null ? $this->checkerRegistry->getChecker($target->type) : null;
                if ($target === null || $checker === null) {
                    $io->writeln(sprintf('%s  %s  (skipped)', $candidate->field, $candidate->link));
                    continue;
                }
                $result = $checker->check($target, $candidate);
                $io->writeln(sprintf('%s  %s  %s %s', $candidate->field, $target->identifier, $result->status->value, $result->errorType));
            }
```

- [ ] **Step 6: Smoke**

```bash
ddev exec bin/typo3 cache:flush
ddev exec bin/typo3 linkvalidator2:check --record=tt_content:<CE-uid>
```

Expected: A → `ok`; B → `broken page.hidden`; C → `broken page.missing`; D → `broken page.noFrontend`;
999999 → `broken page.missing`; Datei (missing=1) → `broken file.missing`; `url:https://typo3.org` →
`(skipped)` (kein URL-Checker in Schritt 1); invalid-Links → `broken link.invalid`;
`header_link` → `broken page.hidden`.

- [ ] **Step 7: Commit**

```bash
git add -A && git commit -m "Add checkers for pages, records, files and invalid links"
```

---

### Task 6: Repositories

**Files:**
- Create: `Classes/Repository/TargetRepository.php`, `BrokenLinkRepository.php`, `RecordStatusRepository.php`

**Interfaces:**
- Consumes: `LinkTarget`, `CheckResult`, `LinkCandidate`
- Produces:
  - `TargetRepository::store(LinkTarget $target, CheckResult $result, int $now): int` (uid)
  - `BrokenLinkRepository::replaceForRecord(string $table, int $recordUid, list<array{candidate: LinkCandidate, targetUid: int, result: CheckResult}> $brokenLinks, int $now): void`
  - `BrokenLinkRepository::removeForRecord(string $table, int $recordUid): void`
  - `RecordStatusRepository::markChecked(string $table, int $recordUid, int $pid, int $language, int $brokenCount, int $now): void`
  - `RecordStatusRepository::remove(string $table, int $recordUid): void`

- [ ] **Step 1: `TargetRepository.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class TargetRepository
{
    private const TABLE = 'tx_linkvalidator2_target';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function store(LinkTarget $target, CheckResult $result, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing = $connection->select(['uid', 'failure_count', 'first_failed_at'], self::TABLE, ['identifier_hash' => $target->getHash()])->fetchAssociative();
        $data = [
            'link_type' => $target->type,
            'identifier' => $target->identifier,
            'identifier_hash' => $target->getHash(),
            'status' => $result->status->value,
            'error_type' => $result->errorType,
            'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
            'last_checked' => $now,
            'failure_count' => $result->isOk() ? 0 : (int)($existing['failure_count'] ?? 0) + 1,
            'first_failed_at' => $result->isOk() ? 0 : ((int)($existing['first_failed_at'] ?? 0) ?: $now),
        ];
        if ($existing === false) {
            $connection->insert(self::TABLE, $data);
            return (int)$connection->lastInsertId();
        }
        $connection->update(self::TABLE, $data, ['uid' => (int)$existing['uid']]);
        return (int)$existing['uid'];
    }
}
```

- [ ] **Step 2: `BrokenLinkRepository.php`** (`first_detected` bleibt für dasselbe Feld+Ziel erhalten)

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;

final readonly class BrokenLinkRepository
{
    private const TABLE = 'tx_linkvalidator2_broken_link';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<array{candidate: LinkCandidate, targetUid: int, result: CheckResult}> $brokenLinks
     */
    public function replaceForRecord(string $table, int $recordUid, array $brokenLinks, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $firstDetected = [];
        $existingRows = $connection->select(['field', 'target', 'first_detected'], self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid])->fetchAllAssociative();
        foreach ($existingRows as $row) {
            $firstDetected[$row['field'] . '|' . $row['target']] = (int)$row['first_detected'];
        }
        $connection->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
        foreach ($brokenLinks as ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result]) {
            $connection->insert(self::TABLE, [
                'target' => $targetUid,
                'table_name' => $table,
                'record_uid' => $recordUid,
                'field' => $candidate->field,
                'pid' => $candidate->pid,
                'language' => $candidate->language,
                'workspace' => 0,
                'link_string' => $candidate->link,
                'link_text' => $candidate->linkText,
                'status' => $result->status->value,
                'error_type' => $result->errorType,
                'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
                'first_detected' => $firstDetected[$candidate->field . '|' . $targetUid] ?? $now,
                'last_seen' => $now,
            ]);
        }
    }

    public function removeForRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }
}
```

- [ ] **Step 3: `RecordStatusRepository.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class RecordStatusRepository
{
    private const TABLE = 'tx_linkvalidator2_record_status';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function markChecked(string $table, int $recordUid, int $pid, int $language, int $brokenCount, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identifier = ['table_name' => $table, 'record_uid' => $recordUid, 'workspace' => 0];
        $data = ['pid' => $pid, 'language' => $language, 'last_checked' => $now, 'broken_count' => $brokenCount];
        if ($connection->count('uid', self::TABLE, $identifier) > 0) {
            $connection->update(self::TABLE, $data, $identifier);
            return;
        }
        $connection->insert(self::TABLE, $identifier + $data);
    }

    public function remove(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }
}
```

- [ ] **Step 4: Container-Check**

```bash
ddev exec bin/typo3 cache:flush && ddev exec bin/typo3 linkvalidator2:check --record=tt_content:<CE-uid>
```

Expected: unverändert zu Task 5 (Repositories sind noch unbenutzt, Container baut).

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Add repositories for targets, broken links and record status"
```

---

### Task 7: `RecordCheckService` – einen Datensatz komplett prüfen

**Files:**
- Create: `Classes/Service/CheckedLink.php`, `Classes/Service/RecordCheckService.php`
- Modify: `Classes/Command/CheckCommand.php` (`--record` nutzt den Service, schreibt in DB)

**Interfaces:**
- Consumes: alles aus Task 3–6
- Produces: `RecordCheckService::checkRecord(string $table, int $uid): list<CheckedLink>` –
  persistiert Ergebnis; leere Liste, wenn Datensatz fehlt/unsichtbar/ohne Links.
  `CheckedLink(LinkCandidate $candidate, LinkTarget $target, CheckResult $result)`.

- [ ] **Step 1: `CheckedLink.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Service;

use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class CheckedLink
{
    public function __construct(
        public LinkCandidate $candidate,
        public LinkTarget $target,
        public CheckResult $result,
    ) {}
}
```

- [ ] **Step 2: `RecordCheckService.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use GeorgRinger\Linkvalidator2\Check\CheckerRegistry;
use GeorgRinger\Linkvalidator2\Extraction\LinkExtractor;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;
use GeorgRinger\Linkvalidator2\Repository\TargetRepository;
use GeorgRinger\Linkvalidator2\Target\LinkTargetFactory;

final readonly class RecordCheckService
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private LinkExtractor $linkExtractor,
        private LinkTargetFactory $linkTargetFactory,
        private CheckerRegistry $checkerRegistry,
        private TargetRepository $targetRepository,
        private BrokenLinkRepository $brokenLinkRepository,
        private RecordStatusRepository $recordStatusRepository,
        private Context $context,
    ) {}

    /**
     * @return list<CheckedLink>
     */
    public function checkRecord(string $table, int $uid): array
    {
        $row = $this->tcaSchemaFactory->has($table) ? BackendUtility::getRecord($table, $uid) : null;
        if ($row === null) {
            $this->brokenLinkRepository->removeForRecord($table, $uid);
            $this->recordStatusRepository->remove($table, $uid);
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $pid = $this->getContainerPid($table, $schema, $row);
        $language = $this->getLanguage($schema, $row);

        if (!$this->isVisible($schema, $row, $now)) {
            $this->brokenLinkRepository->removeForRecord($table, $uid);
            $this->recordStatusRepository->markChecked($table, $uid, $pid, $language, 0, $now);
            return [];
        }

        $checkedLinks = [];
        $brokenLinks = [];
        $seen = [];
        foreach ($this->linkExtractor->extract($table, $row, $pid, $language) as $candidate) {
            $target = $this->linkTargetFactory->create($candidate);
            $checker = $target !== null ? $this->checkerRegistry->getChecker($target->type) : null;
            if ($target === null || $checker === null) {
                continue;
            }
            $key = $candidate->field . '|' . $target->identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result = $checker->check($target, $candidate);
            $targetUid = $this->targetRepository->store($target, $result, $now);
            $checkedLinks[] = new CheckedLink($candidate, $target, $result);
            if (!$result->isOk()) {
                $brokenLinks[] = ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result];
            }
        }
        $this->brokenLinkRepository->replaceForRecord($table, $uid, $brokenLinks, $now);
        $this->recordStatusRepository->markChecked($table, $uid, $pid, $language, count($brokenLinks), $now);
        return $checkedLinks;
    }

    /**
     * Pages are their own container; a translated page belongs to its default language page.
     */
    private function getContainerPid(string $table, TcaSchema $schema, array $row): int
    {
        if ($table !== 'pages') {
            return (int)$row['pid'];
        }
        if ($schema->hasCapability(TcaSchemaCapability::Language)) {
            $parentField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
            if ((int)($row[$parentField] ?? 0) > 0) {
                return (int)$row[$parentField];
            }
        }
        return (int)$row['uid'];
    }

    private function getLanguage(TcaSchema $schema, array $row): int
    {
        if (!$schema->hasCapability(TcaSchemaCapability::Language)) {
            return 0;
        }
        return (int)($row[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] ?? 0);
    }

    /**
     * Links in hidden or expired records are not visible to visitors and not reported.
     */
    private function isVisible(TcaSchema $schema, array $row, int $now): bool
    {
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            && (int)($row[(string)$schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)] ?? 0) === 1
        ) {
            return false;
        }
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionEndTime)) {
            $endtime = (int)($row[(string)$schema->getCapability(TcaSchemaCapability::RestrictionEndTime)] ?? 0);
            if ($endtime > 0 && $endtime <= $now) {
                return false;
            }
        }
        return true;
    }
}
```

- [ ] **Step 3: Command – `--record` über den Service.** Konstruktor auf
  `RecordCheckService $recordCheckService` umstellen (Extractor/Factory/Registry entfernen),
  Zweig ersetzen:

```php
            foreach ($this->recordCheckService->checkRecord($table, $uid) as $checkedLink) {
                $io->writeln(sprintf(
                    '%s  %s  %s %s',
                    $checkedLink->candidate->field,
                    $checkedLink->target->identifier,
                    $checkedLink->result->status->value,
                    $checkedLink->result->errorType
                ));
            }
            return Command::SUCCESS;
```

- [ ] **Step 4: Smoke inkl. Review-Focus #2 und #4**

```bash
ddev exec bin/typo3 cache:flush
ddev exec bin/typo3 linkvalidator2:check --record=tt_content:<CE-uid>
ddev mysql -e "SELECT field, link_string, error_type, first_detected FROM tx_linkvalidator2_broken_link WHERE table_name='tt_content' AND record_uid=<CE-uid>"
ddev mysql -e "SELECT * FROM tx_linkvalidator2_record_status"
```

Expected: broken_link-Zeilen für B, C, D, 999999, Datei, invalid ×3 (bzw. ×2, siehe Task 4),
header_link; record_status `broken_count` = Anzahl. Zweiter Lauf: `first_detected` unverändert.
Dann CE im Backend verstecken → erneut `--record` → broken_link leer, `broken_count = 0`.
Wieder einblenden. Übersetzung des CE anlegen (Sprache 1) → `--record=tt_content:<übersetzt>`
→ `language = 1`. Übersetzte Seite prüfen → `pid` = uid der Default-Seite.

- [ ] **Step 5: Commit**

```bash
git add -A && git commit -m "Check a record and persist broken links and status"
```

---

### Task 8: Queue und Budget-Lauf

**Erledigt 2026-10-11** (abweichend vom Entwurf unten: Keyset-Cursor statt erneuter Abfrage,
`--interval`, Lock, Waisen-Cleanup; Tests `CheckCommandQueueTest`).

**Files:**
- Create: `Classes/Service/RecordQueue.php`
- Modify: `Classes/Command/CheckCommand.php` (Optionen `--limit`, `--time-limit`)

**Interfaces:**
- Consumes: `CheckableFieldResolver::getCheckableTables()`, `RecordCheckService::checkRecord()`
- Produces: `RecordQueue::next(int $limit, int $checkedBefore): list<array{table: string, uid: int}>`
  – zuerst nie geprüfte Datensätze, dann die am längsten nicht geprüften mit
  `last_checked < $checkedBefore`.

- [ ] **Step 1: `RecordQueue.php`**

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use GeorgRinger\Linkvalidator2\Extraction\CheckableFieldResolver;

final readonly class RecordQueue
{
    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';

    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private CheckableFieldResolver $checkableFieldResolver,
    ) {}

    /**
     * @return list<array{table: string, uid: int}>
     */
    public function next(int $limit, int $checkedBefore): array
    {
        $items = $this->findNeverChecked($limit);
        if (count($items) < $limit) {
            $items = [...$items, ...$this->findLeastRecentlyChecked($limit - count($items), $checkedBefore)];
        }
        return $items;
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    private function findNeverChecked(int $limit): array
    {
        $items = [];
        foreach ($this->checkableFieldResolver->getCheckableTables() as $table) {
            $remaining = $limit - count($items);
            if ($remaining <= 0) {
                break;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder
                ->select('t.uid')
                ->from($table, 't')
                ->leftJoin(
                    't',
                    self::STATUS_TABLE,
                    's',
                    (string)$queryBuilder->expr()->and(
                        $queryBuilder->expr()->eq('s.table_name', $queryBuilder->createNamedParameter($table)),
                        $queryBuilder->expr()->eq('s.record_uid', $queryBuilder->quoteIdentifier('t.uid'))
                    )
                )
                ->where($queryBuilder->expr()->isNull('s.uid'))
                ->setMaxResults($remaining);
            if ($this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('t.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                $items[] = ['table' => $table, 'uid' => (int)$uid];
            }
        }
        return $items;
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    private function findLeastRecentlyChecked(int $limit, int $checkedBefore): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::STATUS_TABLE);
        $rows = $queryBuilder
            ->select('table_name', 'record_uid')
            ->from(self::STATUS_TABLE)
            ->where($queryBuilder->expr()->lt('last_checked', $queryBuilder->createNamedParameter($checkedBefore, Connection::PARAM_INT)))
            ->orderBy('last_checked', 'ASC')
            ->setMaxResults($limit)
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(
            static fn(array $row): array => ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid']],
            $rows
        );
    }
}
```

- [ ] **Step 2: Command – Queue-Lauf.** Konstruktor um `RecordQueue $recordQueue` und
  `Context $context` ergänzen; `configure()` ergänzen:

```php
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of records to check, 0 = no limit', '0')
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Stop after this many seconds, 0 = no limit', '0');
```

Den Zweig `$io->note('Queue processing follows in a later task.');` ersetzen durch:

```php
        $limit = (int)$input->getOption('limit');
        $timeLimit = (int)$input->getOption('time-limit');
        $runStartedAt = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $deadline = $timeLimit > 0 ? time() + $timeLimit : PHP_INT_MAX;
        $checked = 0;
        $broken = 0;
        while (time() < $deadline && ($limit === 0 || $checked < $limit)) {
            $batchSize = $limit === 0 ? 50 : min(50, $limit - $checked);
            $batch = $this->recordQueue->next($batchSize, $runStartedAt);
            if ($batch === []) {
                break;
            }
            foreach ($batch as ['table' => $table, 'uid' => $uid]) {
                foreach ($this->recordCheckService->checkRecord($table, $uid) as $checkedLink) {
                    $broken += $checkedLink->result->isOk() ? 0 : 1;
                }
                $checked++;
                if ($output->isVerbose()) {
                    $io->writeln(sprintf('%s:%d', $table, $uid));
                }
            }
        }
        $io->success(sprintf('Checked %d records, found %d broken links.', $checked, $broken));
        return Command::SUCCESS;
```

Hinweis zu `$runStartedAt`: Datensätze, die in diesem Lauf geprüft wurden, haben
`last_checked >= $runStartedAt` und kommen daher im selben Lauf nicht noch einmal – das
garantiert Terminierung (Review-Focus #5). `checkRecord()` schreibt für jeden existierenden
Datensatz eine Status-Zeile, sodass „nie geprüft“ für ihn danach nicht mehr greift.

- [ ] **Step 3: Smoke**

```bash
ddev exec bin/typo3 cache:flush
ddev mysql -e "TRUNCATE tx_linkvalidator2_record_status; TRUNCATE tx_linkvalidator2_broken_link; TRUNCATE tx_linkvalidator2_target"
ddev exec bin/typo3 linkvalidator2:check --limit=20 -v
ddev exec bin/typo3 linkvalidator2:check -v
ddev exec bin/typo3 linkvalidator2:check --limit=100000
ddev mysql -e "SELECT table_name, COUNT(*) FROM tx_linkvalidator2_record_status GROUP BY table_name"
```

Expected: erster Lauf genau 20 Datensätze; zweiter Lauf prüft alle übrigen einmal und endet;
dritter Lauf mit riesigem Limit prüft jeden Datensatz **genau einmal** (Anzahl = Summe der
Status-Zeilen) und endet. `--time-limit=2` endet nach ~2 s.

- [ ] **Step 4: Commit**

```bash
git add -A && git commit -m "Process records from the status queue within a budget"
```

---

### Task 8b: RTE-Markierung (erledigt außerhalb der Reihenfolge)

`Classes/EventListener/MarkBrokenRteLinkListener.php` auf `BrokenLinkAnalysisEvent`,
`before` den alten linkvalidator-Listenern; `LinkTargetFactory::createFromResolved()`,
`TargetRepository::findResult()`. Tests in Task 9 ergänzen: Seite hidden/expired/Sysfolder,
Record hidden, Datei fehlt, `t3://record?uid=1` → markiert; sichtbare Seite → nicht markiert.

---

### Task 8c: Level-Dropdown im Modul (erledigt, auf Wunsch, vorläufig)

`PageRecordFinder::findPageIds()` (PageRepository::getDescendantPageIdsRecursive mit
bypassEnableFieldsCheck), `BrokenLinkRepository::findByPids()`, moduleData `depth`,
CLI `--depth`. **Muss überdacht werden** – siehe Spec „Zwischenstand Modul: Level-Dropdown“.

---

### Task 8d: Bugfixes aus der Forge-Analyse (vor Task 9)

Aus der Analyse der offenen Forge-Issues (`Resources/Private/Issues/`, 2026-10-11).
Klein und unabhängig voneinander, je ein Commit; nach dem Fix die Issue-Datei nach
`done/` (bzw. Status anpassen), Index und README-Spalte *Forge* nachziehen.

- [x] **#97937 `&nbsp;` in Text-URLs** – `LinkExtractor::extractPlainUrls()`: `&nbsp;`/`&#160;`
      vor dem Parsen in Leerzeichen umsetzen. Smoke: HTML aus dem Issue → 6 URLs, keine
      endet auf `&nbsp`.
- [x] **#94086 eigene LinkHandler** – `LinkTargetFactory::createFromResolved()` `default`:
      Typ mit Checker (`CheckerRegistry`) → generisches `LinkTarget`; Typ eines registrierten
      Handlers ohne Checker → `null`; sonst `link.invalid`.
- [ ] **#106675/#101367 Rootline** – `PageChecker`: Vorfahren mit `extendToSubpages`
      (hidden, endtime) prüfen, Fehlertypen `page.hiddenByParent`/`page.expiredByParent`
      mit `parentPage`, Labels in `messages.xlf`. Smoke: neue Seiten unter 1287 im Seed.
- [x] **#89426 Abgleich beim Prüfen einer Seite** (gelöschte Seite selbst bleibt offen) – nach `findOnPage()` alle
      `broken_link`/`record_status`-Zeilen dieser `pid`, deren Datensatz nicht gefunden
      wurde, löschen. Smoke: Repro aus der Spec („Bekannter Bug“).

---

### Task 9: Test-Infrastruktur und Tests (aufgeschoben, laut Absprache)

**Stand 2026-10-11 – Infrastruktur anders gelöst als unten geplant:** eigenständig wie
EXT:news (`Build/Scripts/runTests.sh` nur unit/functional, `Build/phpunit/`, `.Build/` mit
`typo3/cms-core` dev-main + `typo3/testing-framework` dev-main). Keine Änderung an der
Root-`composer.json`, kein Wächter nötig – Steps 1–2 entfallen. Erste Tests:
`Unit/Check/CheckerRegistryTest`, `Unit/Configuration/ExtensionSettingsTest`,
`Functional/Extraction/LinkExtractorTest`, `Functional/Service/RecordCheckServiceTest`
(29 Tests). Weitere Tests unten bleiben offen.

Zusätzlich zu den Tests unten je ein Regressionstest pro Forge-Issue, das als gelöst gilt
(`Resources/Private/Issues/done/`, Abschnitt „Test“ der jeweiligen Datei) und für Task 8d.

Erst jetzt. Plan aus der Spec: PSR-4 für `GeorgRinger\Linkvalidator2\` und `…\Tests\` als
**lokale, nie committete** Änderung an der Root-`composer.json` von t3-main, Wächter-Eintrag
in `CLAUDE.local.md`, runTests mit explizitem Pfad.

**Files:**
- Modify (lokal, nie committen): `composer.json` (t3-main Root) – `autoload.psr-4` +
  `autoload-dev.psr-4`
- Modify: `CLAUDE.local.md` (t3-main) – Wächter
- Create: `Tests/Unit/Extraction/LinkExtractorTest.php`
- Create: `Tests/Functional/Service/RecordCheckServiceTest.php` + `Tests/Functional/Fixtures/*.csv`

- [ ] **Step 1: Autoload eintragen**

In t3-main `composer.json` unter `autoload.psr-4`:
`"GeorgRinger\\Linkvalidator2\\": "typo3conf/ext/linkvalidator2/Classes/"`, unter
`autoload-dev.psr-4`: `"GeorgRinger\\Linkvalidator2\\Tests\\": "typo3conf/ext/linkvalidator2/Tests/"`.

```bash
./Build/Scripts/runTests.sh -s composer -- dump-autoload
```

- [ ] **Step 2: Wächter in `CLAUDE.local.md`** (t3-main) ergänzen:

```markdown
## linkvalidator2: Autoload-Zeilen nie committen

Die Root-`composer.json` enthält lokal PSR-4-Einträge für
`GeorgRinger\Linkvalidator2\` (typo3conf/ext/linkvalidator2). Vor jedem Commit im
Core-Repo prüfen, dass `composer.json` diese Zeilen **nicht** im Commit hat.
```

- [ ] **Step 3: Unit-Test `LinkExtractorTest`** (deckt Review-Focus #1)

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Extraction;

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use GeorgRinger\Linkvalidator2\Extraction\CheckableField;
use GeorgRinger\Linkvalidator2\Extraction\CheckableFieldResolver;
use GeorgRinger\Linkvalidator2\Extraction\ExtractionMode;
use GeorgRinger\Linkvalidator2\Extraction\LinkExtractor;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class LinkExtractorTest extends UnitTestCase
{
    private function createSubject(CheckableField ...$fields): LinkExtractor
    {
        $resolver = $this->createMock(CheckableFieldResolver::class);
        $resolver->method('getFieldsForRecord')->willReturn($fields);
        $eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $eventDispatcher->method('dispatch')->willReturnArgument(0);
        return new LinkExtractor($resolver, new TypoLinkCodecService($eventDispatcher), new HtmlParser());
    }

    #[Test]
    public function linkUsedTwiceInOneFieldIsExtractedOnce(): void
    {
        $subject = $this->createSubject(new CheckableField('bodytext', ExtractionMode::Html));
        $html = '<p><a href="https://typo3.org">https://typo3.org</a> and <a href="https://typo3.org">again</a></p>';

        $candidates = $subject->extract('tt_content', ['uid' => 1, 'bodytext' => $html], 1, 0);

        self::assertCount(1, $candidates);
        self::assertSame('https://typo3.org', $candidates[0]->link);
        self::assertSame('https://typo3.org', $candidates[0]->linkText);
    }

    #[Test]
    public function typolinkFieldYieldsUrlPartOnly(): void
    {
        $subject = $this->createSubject(new CheckableField('header_link', ExtractionMode::Typolink));

        $candidates = $subject->extract('tt_content', ['uid' => 1, 'header_link' => 't3://page?uid=42 _blank - "Title"'], 1, 0);

        self::assertCount(1, $candidates);
        self::assertSame('t3://page?uid=42', $candidates[0]->link);
    }

    #[Test]
    public function shortcutPageYieldsPageLink(): void
    {
        $subject = $this->createSubject();

        $candidates = $subject->extract('pages', ['uid' => 5, 'doktype' => 4, 'shortcut_mode' => 0, 'shortcut' => '7'], 5, 0);

        self::assertCount(1, $candidates);
        self::assertSame('shortcut', $candidates[0]->field);
        self::assertSame('t3://page?uid=7', $candidates[0]->link);
    }
}
```

Hinweis: `CheckableFieldResolver` ist `final readonly` und daher nicht mockbar. Für den Test
`final` dort entfernen **oder** ein Interface `CheckableFieldResolverInterface` einführen –
Entscheidung beim Umsetzen treffen und hier nachtragen (Core-Tests vermeiden das Mocken
finaler Klassen).

- [ ] **Step 4: Unit-Test laufen lassen**

```bash
CI=true ./Build/Scripts/runTests.sh -s unit typo3conf/ext/linkvalidator2/Tests/Unit
```

Expected: 3 Tests grün. Falls runTests den Pfad außerhalb `typo3/sysext` nicht annimmt:
Ergebnis notieren, Alternative (temporäre phpunit-XML in `Build/phpunit/`, lokal) in die
Spec übernehmen.

- [ ] **Step 5: Functional-Test `RecordCheckServiceTest`** (deckt Review-Focus #2, #3, #4, #5)

Fixture `Tests/Functional/Fixtures/Records.csv`:

```
"pages"
,"uid","pid","title","doktype","hidden","deleted","sys_language_uid","l10n_parent","slug"
,1,0,"Root",1,0,0,0,0,"/"
,2,1,"Visible",1,0,0,0,0,"/visible"
,3,1,"Hidden",1,1,0,0,0,"/hidden"
,4,1,"Deleted",1,0,1,0,0,"/deleted"
,5,1,"Folder",254,0,0,0,0,"/folder"
,6,1,"Visible EN",1,0,0,1,2,"/visible-en"
"tt_content"
,"uid","pid","CType","header","bodytext","header_link","hidden","sys_language_uid"
,10,2,"text","links","<a href=""t3://page?uid=2"">ok</a> <a href=""t3://page?uid=3"">hidden</a> <a href=""t3://page?uid=4"">deleted</a> <a href=""t3://page?uid=5"">folder</a> <a href=""t3://gibtsnicht?uid=1"">bad</a>","t3://page?uid=3",0,0
,11,2,"text","hidden ce","<a href=""t3://page?uid=3"">hidden</a>","",1,0
,12,2,"text","translated","<a href=""t3://page?uid=4"">deleted</a>","",0,1
```

```php
<?php

declare(strict_types=1);

// GPL header

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Database\ConnectionPool;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Service\RecordQueue;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordCheckServiceTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3conf/ext/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/Records.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/BeUsers.csv');
        $this->setUpBackendUser(1);
    }

    private function brokenErrorTypes(string $table, int $uid): array
    {
        $rows = $this->get(ConnectionPool::class)->getConnectionForTable('tx_linkvalidator2_broken_link')
            ->select(['field', 'error_type'], 'tx_linkvalidator2_broken_link', ['table_name' => $table, 'record_uid' => $uid])
            ->fetchAllAssociative();
        $result = array_map(static fn(array $row): string => $row['field'] . ':' . $row['error_type'], $rows);
        sort($result);
        return $result;
    }

    #[Test]
    public function brokenInternalLinksAreStored(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        self::assertSame(
            ['bodytext:link.invalid', 'bodytext:page.hidden', 'bodytext:page.missing', 'bodytext:page.noFrontend', 'header_link:page.hidden'],
            $this->brokenErrorTypes('tt_content', 10)
        );
    }

    #[Test]
    public function hiddenSourceRecordReportsNothing(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 11);

        self::assertSame([], $this->brokenErrorTypes('tt_content', 11));
    }

    #[Test]
    public function brokenLinksVanishWhenSourceIsDeleted(): void
    {
        $service = $this->get(RecordCheckService::class);
        $service->checkRecord('tt_content', 10);
        $this->get(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => 1], ['uid' => 10]);

        $service->checkRecord('tt_content', 10);

        self::assertSame([], $this->brokenErrorTypes('tt_content', 10));
    }

    #[Test]
    public function languageOfTranslatedRecordIsStored(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 12);

        $language = $this->get(ConnectionPool::class)->getConnectionForTable('tx_linkvalidator2_record_status')
            ->select(['language'], 'tx_linkvalidator2_record_status', ['table_name' => 'tt_content', 'record_uid' => 12])
            ->fetchOne();
        self::assertSame(1, (int)$language);
    }

    #[Test]
    public function translatedPageBelongsToDefaultLanguagePage(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('pages', 6);

        $pid = $this->get(ConnectionPool::class)->getConnectionForTable('tx_linkvalidator2_record_status')
            ->select(['pid'], 'tx_linkvalidator2_record_status', ['table_name' => 'pages', 'record_uid' => 6])
            ->fetchOne();
        self::assertSame(2, (int)$pid);
    }

    #[Test]
    public function queueReturnsEachRecordOncePerRun(): void
    {
        $queue = $this->get(RecordQueue::class);
        $service = $this->get(RecordCheckService::class);
        $runStartedAt = time() + 1;
        $seen = [];
        while (($batch = $queue->next(2, $runStartedAt)) !== []) {
            foreach ($batch as ['table' => $table, 'uid' => $uid]) {
                $key = $table . ':' . $uid;
                self::assertArrayNotHasKey($key, $seen);
                $seen[$key] = true;
                $service->checkRecord($table, $uid);
            }
            if (count($seen) > 100) {
                self::fail('Queue does not terminate');
            }
        }
        self::assertNotEmpty($seen);
    }
}
```

Achtung `queueReturnsEachRecordOncePerRun`: `$runStartedAt = time() + 1` lässt im selben
Lauf geprüfte Datensätze (last_checked = jetzt) **nicht** zurückkommen, weil `next()` nur
`last_checked < $runStartedAt` liefert – das ist genau der Fall, der Endlosschleifen erzeugen
würde, wenn die Logik falsch wäre. Läuft der Test endlos statt zu scheitern, ist der
Abbruch nach 100 der Beweis.

`BeUsers.csv`: Admin-User uid 1 (Kopie aus
`typo3/sysext/core/Tests/Functional/Fixtures/be_users.csv` o. ä. – Pfad beim Umsetzen prüfen).

- [ ] **Step 6: Functional-Tests laufen lassen**

```bash
CI=true ./Build/Scripts/runTests.sh -s functional -d sqlite typo3conf/ext/linkvalidator2/Tests/Functional
```

Expected: alle grün. `testExtensionsToLoad` mit `typo3conf/ext/…` ist der Weg des
testing-frameworks für Nicht-Core-Extensions; falls das in t3-main nicht greift: Ergebnis
und Workaround in die Spec übernehmen.

- [ ] **Step 7: CGL und PHPStan auf die Extension**

```bash
./Build/Scripts/runTests.sh -s cgl -n -- typo3conf/ext/linkvalidator2
./Build/Scripts/runTests.sh -s phpstan -- typo3conf/ext/linkvalidator2/Classes typo3conf/ext/linkvalidator2/Tests
```

- [ ] **Step 8: Commit** (im Extension-Repo; Root-`composer.json` und `CLAUDE.local.md` **nicht**)

```bash
cd typo3conf/ext/linkvalidator2
git add -A && git commit -m "Add unit and functional tests for the engine"
```

---

## Nach Schritt 1

Die Spec `Notes/2026-10-08-linkvalidator-rewrite.md` um diese Punkte ergänzen:

- Softref-Parser werden nicht zum Extrahieren genutzt (verlieren den Link-String), nur zur
  Feldauswahl.
- ~~Plain-Text-URLs nicht extrahiert~~ – erledigt mit Schritt 2 (`ExtractionMode::PlainUrl`),
  Bug #97937 siehe Task 8d.
- Links in versteckten/abgelaufenen Quell-Datensätzen werden nicht gemeldet.
- `javascript:`/sonstige `unknown`-Links werden übersprungen statt als `link.invalid` gemeldet.
- Ergebnisse von Task 4 Step 4 (`t3://page` ohne uid) und Task 9 Step 4/6 (runTests-Pfade).
