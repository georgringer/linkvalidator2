<?php

declare(strict_types=1);

// Throwaway seed script: a large page tree below page 1287 for load tests
// (module, pagination, queue). Rows are inserted with plain SQL for speed.
//
// Run:    ddev exec php typo3conf/ext/linkvalidator2/Notes/seed-bigtree.php [fanout] [levels] [contentPerPage]
//         defaults 10 3 4 → 1110 pages, 4440 content elements (+ translations)
// Remove: ddev exec php typo3conf/ext/linkvalidator2/Notes/seed-bigtree.php --delete

use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\BootService;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

const PARENT = 1287;
const ROOT_TITLE = 'Großer Baum';
const LANGUAGE = 5;
// Targets from seed-testdata.php
const PAGE_DELETED = 1297;
const PAGE_HIDDEN = 1298;
const PAGE_EXPIRED = 1296;
const PAGE_SYSFOLDER = 1294;
const CONTENT_HIDDEN = 467;

$classLoader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(4, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$failsafeContainer = Bootstrap::init($classLoader, true);
$bootService = $failsafeContainer->get(BootService::class);
$container = $bootService->getContainer();
$bootService->loadExtLocalconfDatabase();
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
$GLOBALS['BE_USER']->authenticate();
$GLOBALS['LANG'] = $container->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

$connectionPool = $container->get(ConnectionPool::class);
$pagesConnection = $connectionPool->getConnectionForTable('pages');
$contentConnection = $connectionPool->getConnectionForTable('tt_content');

$rootUid = (int)$pagesConnection->executeQuery(
    'SELECT uid FROM pages WHERE pid = ? AND title = ? AND deleted = 0 ORDER BY uid LIMIT 1',
    [PARENT, ROOT_TITLE]
)->fetchOne();

if (($argv[1] ?? '') === '--delete') {
    if ($rootUid === 0) {
        echo "Nothing to delete.\n";
        exit(0);
    }
    // collect recursively (translations share the pid of their default page)
    $pageIds = [$rootUid];
    $queue = [$rootUid];
    while ($queue !== []) {
        $children = array_map('intval', $pagesConnection->executeQuery(
            'SELECT uid FROM pages WHERE pid IN (?)',
            [$queue],
            [Connection::PARAM_INT_ARRAY]
        )->fetchFirstColumn());
        $pageIds = [...$pageIds, ...$children];
        $queue = $children;
    }
    $translations = array_map('intval', $pagesConnection->executeQuery(
        'SELECT uid FROM pages WHERE l10n_parent IN (?)',
        [$pageIds],
        [Connection::PARAM_INT_ARRAY]
    )->fetchFirstColumn());
    $pageIds = array_values(array_unique([...$pageIds, ...$translations]));
    foreach (array_chunk($pageIds, 1000) as $chunk) {
        $contentConnection->executeStatement('DELETE FROM tt_content WHERE pid IN (?)', [$chunk], [Connection::PARAM_INT_ARRAY]);
        foreach (['sys_category', 'tx_styleguide_elements_basic', 'tx_styleguide_elements_rte'] as $table) {
            $connectionPool->getConnectionForTable($table)
                ->executeStatement('DELETE FROM ' . $table . ' WHERE pid IN (?)', [$chunk], [Connection::PARAM_INT_ARRAY]);
        }
        $pagesConnection->executeStatement('DELETE FROM pages WHERE uid IN (?)', [$chunk], [Connection::PARAM_INT_ARRAY]);
        foreach (['tx_linkvalidator2_broken_link', 'tx_linkvalidator2_record_status'] as $table) {
            $connectionPool->getConnectionForTable($table)->executeStatement(
                'DELETE FROM ' . $table . ' WHERE pid IN (?)',
                [$chunk],
                [Connection::PARAM_INT_ARRAY]
            );
        }
    }
    echo 'Deleted ' . count($pageIds) . " pages with their content and linkvalidator2 results.\n";
    exit(0);
}

if ($rootUid > 0) {
    fwrite(STDERR, '"' . ROOT_TITLE . "\" already exists (uid $rootUid). Run with --delete first.\n");
    exit(1);
}

$fanout = max(1, (int)($argv[1] ?? 10));
$levels = max(1, (int)($argv[2] ?? 3));
$contentPerPage = max(0, (int)($argv[3] ?? 4));

$dataHandler = GeneralUtility::makeInstance(DataHandler::class);
$dataHandler->start(['pages' => ['NEW1' => ['pid' => PARENT, 'title' => ROOT_TITLE, 'hidden' => 0]]], []);
$dataHandler->process_datamap();
if ($dataHandler->errorLog !== []) {
    fwrite(STDERR, implode("\n", $dataHandler->errorLog) . "\n");
    exit(1);
}
$rootUid = (int)$dataHandler->substNEWwithIDs['NEW1'];
$rootSlug = (string)$pagesConnection->select(['slug'], 'pages', ['uid' => $rootUid])->fetchOne();

$fileUid = (int)$connectionPool->getConnectionForTable('sys_file')
    ->select(['uid'], 'sys_file', ['missing' => 0], [], ['uid' => 'ASC'], 1)->fetchOne();
$now = time();

// Record link handler with an identifier that differs from the table, so the checker has to
// map it via Page TSconfig. tt_content links keep using the identifier as table name (fallback).
$pagesConnection->update('pages', ['TSconfig' => implode("\n", [
    'TCEMAIN.linkHandler.category {',
    '    handler = TYPO3\\CMS\\Backend\\LinkHandler\\RecordLinkHandler',
    '    label = Category',
    '    configuration.table = sys_category',
    '}',
])], ['uid' => $rootUid]);
$pagesConnection->insert('pages', [
    'pid' => $rootUid, 'title' => 'Kategorien', 'slug' => $rootSlug . '/kategorien', 'doktype' => 254,
    'sorting' => 99999, 'crdate' => $now, 'tstamp' => $now,
    'perms_userid' => 1, 'perms_groupid' => 0, 'perms_user' => 31, 'perms_group' => 27, 'perms_everybody' => 0,
]);
$categoryFolder = (int)$pagesConnection->lastInsertId();
$categoryConnection = $connectionPool->getConnectionForTable('sys_category');
$categories = ['valid' => [], 'hidden' => 0, 'expired' => 0, 'deleted' => 0];
for ($i = 1; $i <= 10; $i++) {
    $categoryConnection->insert('sys_category', ['pid' => $categoryFolder, 'title' => 'Kategorie ' . $i, 'crdate' => $now, 'tstamp' => $now]);
    $categories['valid'][] = (int)$categoryConnection->lastInsertId();
}
foreach (['hidden' => ['hidden' => 1], 'expired' => ['endtime' => $now - 86400], 'deleted' => ['deleted' => 1]] as $state => $fields) {
    $categoryConnection->insert('sys_category', ['pid' => $categoryFolder, 'title' => 'Kategorie ' . $state, 'crdate' => $now, 'tstamp' => $now, ...$fields]);
    $categories[$state] = (int)$categoryConnection->lastInsertId();
}

$pageDefaults = [
    'doktype' => 1,
    'crdate' => $now,
    'tstamp' => $now,
    'perms_userid' => 1,
    'perms_groupid' => 0,
    'perms_user' => 31,
    'perms_group' => 27,
    'perms_everybody' => 0,
];

/**
 * Links for one source, $seed makes them different but reproducible. "broken" holds one link,
 * rotating through all kinds of broken targets.
 *
 * @param list<int> $validPages
 * @param array{valid: list<int>, hidden: int, expired: int, deleted: int} $categories
 * @return array{valid: list<array{0: string, 1: string}>, broken: array{0: string, 1: string}}
 */
function linkPool(int $seed, array $validPages, int $fileUid, array $categories): array
{
    $broken = [
        ['t3://page?uid=' . PAGE_DELETED, 'Gelöschte Seite'],
        ['t3://page?uid=' . PAGE_HIDDEN, 'Versteckte Seite'],
        ['t3://page?uid=' . PAGE_EXPIRED, 'Abgelaufene Seite'],
        ['t3://page?uid=' . PAGE_SYSFOLDER, 'Sysfolder'],
        ['t3://page?uid=' . (900000 + $seed), 'Nie existierende Seite'],
        ['t3://file?uid=999999', 'Fehlende Datei'],
        ['t3://record?identifier=tt_content&uid=' . CONTENT_HIDDEN, 'Verstecktes Element'],
        ['t3://record?identifier=category&uid=' . $categories['hidden'], 'Versteckte Kategorie'],
        ['t3://record?identifier=category&uid=' . $categories['expired'], 'Abgelaufene Kategorie'],
        ['t3://record?identifier=category&uid=' . $categories['deleted'], 'Gelöschte Kategorie'],
        ['t3://record?identifier=category&uid=999999', 'Nie existierende Kategorie'],
    ];
    return [
        'valid' => [
            ['t3://page?uid=' . $validPages[$seed % count($validPages)], 'Gültige Seite'],
            ['t3://page?uid=' . $validPages[($seed * 7) % count($validPages)], 'Andere gültige Seite'],
            ['https://typo3.org/', 'TYPO3'],
            ['t3://file?uid=' . $fileUid, 'Datei'],
            ['t3://record?identifier=category&uid=' . $categories['valid'][$seed % count($categories['valid'])], 'Gültige Kategorie'],
        ],
        'broken' => $broken[$seed % count($broken)],
    ];
}

/**
 * 5 valid links, one broken link, every 3rd element an external link (404 on example.org).
 *
 * @param list<int> $validPages
 * @param array{valid: list<int>, hidden: int, expired: int, deleted: int} $categories
 */
function bodytext(int $seed, array $validPages, int $fileUid, array $categories): string
{
    $pool = linkPool($seed, $validPages, $fileUid, $categories);
    $links = [...$pool['valid'], $pool['broken']];
    if ($seed % 3 === 0) {
        $links[] = ['https://example.org/seite-' . $seed, 'Extern ' . $seed];
    }
    $html = '<p>Element ' . $seed . '.</p>';
    foreach ($links as [$href, $text]) {
        $html .= '<p><a href="' . $href . '">' . $text . '</a></p>';
    }
    return $html;
}

$pagesConnection->beginTransaction();
$allPages = [];
$parentOf = [];
$currentLevel = [['uid' => $rootUid, 'slug' => $rootSlug]];
for ($level = 1; $level <= $levels; $level++) {
    $nextLevel = [];
    foreach ($currentLevel as $parent) {
        for ($i = 1; $i <= $fanout; $i++) {
            $title = $parent['uid'] === $rootUid ? 'Bereich ' . $i : 'Seite ' . $level . '.' . $i . ' (unter ' . $parent['uid'] . ')';
            $slug = $parent['slug'] . '/l' . $level . '-' . $i;
            $pagesConnection->insert('pages', [...$pageDefaults, 'pid' => $parent['uid'], 'title' => $title, 'slug' => $slug, 'sorting' => $i * 256]);
            $uid = (int)$pagesConnection->lastInsertId();
            $nextLevel[] = ['uid' => $uid, 'slug' => $slug];
            $allPages[] = $uid;
            $parentOf[$uid] = $parent['uid'];
        }
    }
    $currentLevel = $nextLevel;
}

$translatedPages = 0;
$contentElements = 0;
$rows = [];
$columns = ['pid', 'CType', 'header', 'header_link', 'bodytext', 'colPos', 'sorting', 'sys_language_uid', 'l18n_parent', 'crdate', 'tstamp'];
foreach ($allPages as $index => $pageUid) {
    $translate = $index % 10 === 0;
    if ($translate) {
        $pagesConnection->insert('pages', [
            ...$pageDefaults,
            'pid' => $parentOf[$pageUid],
            'title' => '[DE] Seite ' . $pageUid,
            'slug' => '/de-' . $pageUid,
            'sys_language_uid' => LANGUAGE,
            'l10n_parent' => $pageUid,
            'l10n_source' => $pageUid,
        ]);
        $translatedPages++;
    }
    for ($c = 1; $c <= $contentPerPage; $c++) {
        $seed = $pageUid * 10 + $c;
        $headerLink = match (true) {
            $c !== 1 => '',
            $index % 10 === 0 => 't3://page?uid=' . PAGE_DELETED,
            $index % 10 === 5 => 't3://record?identifier=category&uid=' . $categories['hidden'],
            $index % 10 === 3 => 't3://record?identifier=category&uid=' . $categories['valid'][0],
            default => 't3://page?uid=' . $allPages[($index + 1) % count($allPages)],
        };
        $rows[] = [$pageUid, 'text', 'Element ' . $c, $headerLink, bodytext($seed, $allPages, $fileUid, $categories), 0, $c * 256, 0, 0, $now, $now];
        $contentElements++;
    }
    if (count($rows) >= 500) {
        $contentConnection->bulkInsert('tt_content', $rows, $columns);
        $rows = [];
    }
}
if ($rows !== []) {
    $contentConnection->bulkInsert('tt_content', $rows, $columns);
}

// Translations of the content on translated pages
$translatedContent = $contentConnection->executeStatement(
    'INSERT INTO tt_content (pid, CType, header, header_link, bodytext, colPos, sorting, sys_language_uid, l18n_parent, l10n_source, crdate, tstamp)
     SELECT c.pid, c.CType, CONCAT(\'[DE] \', c.header), c.header_link, c.bodytext, c.colPos, c.sorting, ?, c.uid, c.uid, c.crdate, c.tstamp
     FROM tt_content c JOIN pages p ON p.l10n_parent = c.pid AND p.sys_language_uid = ?
     WHERE c.sys_language_uid = 0 AND c.pid > ? AND c.crdate = ?',
    [LANGUAGE, LANGUAGE, $rootUid, $now]
);
/**
 * Empty values for NOT NULL columns without default (styleguide has some, e.g. uuid_1).
 *
 * @return array<string, string|int>
 */
function requiredColumnDefaults(Connection $connection, string $table): array
{
    $defaults = [];
    $columns = $connection->executeQuery(
        'SELECT COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = \'NO\'
         AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE \'%auto_increment%\'',
        [$table]
    )->fetchAllAssociative();
    foreach ($columns as $column) {
        $defaults[$column['COLUMN_NAME']] = in_array($column['DATA_TYPE'], ['int', 'smallint', 'tinyint', 'bigint', 'mediumint', 'double', 'float', 'decimal'], true) ? 0 : '';
    }
    return $defaults;
}

// EXT:styleguide records as further sources: link fields (type=link) and rich text fields.
// Every 10th record is hidden, its links must not be reported.
$styleguideCounts = ['tx_styleguide_elements_basic' => 0, 'tx_styleguide_elements_rte' => 0];
if ($pagesConnection->createSchemaManager()->tablesExist(['tx_styleguide_elements_basic', 'tx_styleguide_elements_rte'])) {
    $pagesConnection->insert('pages', [
        ...$pageDefaults, 'pid' => $rootUid, 'title' => 'Styleguide-Datensätze', 'slug' => $rootSlug . '/styleguide',
        'doktype' => 254, 'sorting' => 99998,
    ]);
    $styleguideFolder = (int)$pagesConnection->lastInsertId();
    $basicConnection = $connectionPool->getConnectionForTable('tx_styleguide_elements_basic');
    for ($i = 1; $i <= 50; $i++) {
        $seed = 700000 + $i;
        $pool = linkPool($seed, $allPages, $fileUid, $categories);
        $basicConnection->insert('tx_styleguide_elements_basic', [
            ...requiredColumnDefaults($basicConnection, 'tx_styleguide_elements_basic'),
            'pid' => $styleguideFolder,
            'hidden' => $i % 10 === 0 ? 1 : 0,
            'link_1' => $pool['valid'][0][0],
            'link_2' => $pool['valid'][4][0],
            'link_3' => $pool['valid'][2][0],
            'link_4' => $pool['broken'][0],
            'link_5' => 'mailto:test@example.org',
            'link_6' => $i % 3 === 0 ? 'https://example.org/styleguide-' . $i : '',
            'crdate' => $now,
            'tstamp' => $now,
        ]);
        $styleguideCounts['tx_styleguide_elements_basic']++;
    }
    $rteConnection = $connectionPool->getConnectionForTable('tx_styleguide_elements_rte');
    for ($i = 1; $i <= 30; $i++) {
        $seed = 800000 + $i;
        $pool = linkPool($seed + 1, $allPages, $fileUid, $categories);
        $rteConnection->insert('tx_styleguide_elements_rte', [
            ...requiredColumnDefaults($rteConnection, 'tx_styleguide_elements_rte'),
            'pid' => $styleguideFolder,
            'hidden' => $i % 10 === 0 ? 1 : 0,
            'rte_1' => bodytext($seed, $allPages, $fileUid, $categories),
            'rte_11' => '<p>Zweites Feld: <a href="' . $pool['broken'][0] . '">' . $pool['broken'][1] . '</a></p>',
            'crdate' => $now,
            'tstamp' => $now,
        ]);
        $styleguideCounts['tx_styleguide_elements_rte']++;
    }
}
$pagesConnection->commit();

printf(
    "Root \"%s\" uid %d: %d pages (%d levels × %d), %d translated pages, %d content elements, %d translated content elements.\n",
    ROOT_TITLE,
    $rootUid,
    count($allPages),
    $levels,
    $fanout,
    $translatedPages,
    $contentElements,
    $translatedContent
);
printf(
    "Styleguide: %d tx_styleguide_elements_basic, %d tx_styleguide_elements_rte (every 10th hidden).\n",
    $styleguideCounts['tx_styleguide_elements_basic'],
    $styleguideCounts['tx_styleguide_elements_rte']
);
