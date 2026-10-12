<?php

/**
 * Compares the records the LinkExtractor finds links in with the records the reference index
 * would hold rows for (freshly computed, without writing). Run:
 *
 *   ddev exec "TYPO3_PATH_ROOT=/var/www/html TYPO3_PATH_APP=/var/www/html php typo3conf/ext/linkvalidator2/Notes/refindex-coverage.php"
 *
 * miss  = links found, but no reference index row: such a record would never be queued
 * extra = no links found, but reference index rows (other relations): queued for nothing
 */

use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;
use GeorgRinger\Linkvalidator2\Extraction\CheckableFieldResolver;
use GeorgRinger\Linkvalidator2\Extraction\LinkExtractor;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\ReferenceIndex;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

$classLoader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE);
$container = Bootstrap::init($classLoader);
$_SERVER['HTTP_HOST'] = 't3-main.ddev.site';
$_SERVER['HTTPS'] = 'on';
$GLOBALS['BE_USER'] = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
$GLOBALS['BE_USER']->initializeUserSessionManager();
$GLOBALS['BE_USER']->setLogger(new \Psr\Log\NullLogger());
(fn() => $this->userSession = \TYPO3\CMS\Core\Session\UserSession::createNonFixated('lv2test'))->call($GLOBALS['BE_USER']);
$GLOBALS['BE_USER']->setBeUserByUid(1);
$GLOBALS['BE_USER']->fetchGroupData();
$GLOBALS['LANG'] = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Localization\LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

$schemaFactory = GeneralUtility::makeInstance(TcaSchemaFactory::class);
$resolver = new CheckableFieldResolver($schemaFactory, new ExtensionSettings(GeneralUtility::makeInstance(ExtensionConfiguration::class)));
$extractor = new LinkExtractor(
    $resolver,
    GeneralUtility::makeInstance(TypoLinkCodecService::class),
    GeneralUtility::makeInstance(HtmlParser::class),
    GeneralUtility::makeInstance(SoftReferenceParserFactory::class)
);
$referenceIndex = GeneralUtility::makeInstance(ReferenceIndex::class);
$connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);

$stats = [];
$samples = [];
foreach ($resolver->getCheckableTables() as $table) {
    $queryBuilder = $connectionPool->getQueryBuilderForTable($table);
    $queryBuilder->getRestrictions()->removeAll();
    $queryBuilder->select('uid')->from($table);
    $schema = $schemaFactory->get($table);
    if ($schema->hasCapability(TcaSchemaCapability::SoftDelete)) {
        $queryBuilder->where($queryBuilder->expr()->eq($schema->getCapability(TcaSchemaCapability::SoftDelete)->getFieldName(), 0));
    }
    if ($schema->isWorkspaceAware()) {
        $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', 0));
    }
    $stats[$table] = ['records' => 0, 'withLinks' => 0, 'withIndexRows' => 0, 'miss' => 0, 'extra' => 0];
    foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
        $row = BackendUtility::getRecord($table, (int)$uid);
        if ($row === null) {
            continue;
        }
        $candidates = $extractor->extract($table, $row, (int)($row['pid'] ?? 0), 0);
        $result = $referenceIndex->updateRefIndexTable($table, (int)$uid, true);
        $hasIndexRows = ($result['keptNodes'] + $result['addedNodes']) > 0;
        $stats[$table]['records']++;
        $stats[$table]['withLinks'] += $candidates !== [] ? 1 : 0;
        $stats[$table]['withIndexRows'] += $hasIndexRows ? 1 : 0;
        if ($candidates !== [] && !$hasIndexRows) {
            $stats[$table]['miss']++;
            $samples[$table][] = $uid . ' (' . implode(', ', array_unique(array_map(static fn($c) => $c->field, $candidates))) . ': ' . mb_substr($candidates[0]->link, 0, 40) . ')';
        }
        if ($candidates === [] && $hasIndexRows) {
            $stats[$table]['extra']++;
        }
    }
}

printf("%-45s %8s %9s %9s %6s %6s\n", 'table', 'records', 'withLinks', 'indexRows', 'miss', 'extra');
$total = array_fill_keys(['records', 'withLinks', 'withIndexRows', 'miss', 'extra'], 0);
foreach ($stats as $table => $s) {
    if ($s['records'] === 0) {
        continue;
    }
    printf("%-45s %8d %9d %9d %6d %6d\n", $table, ...array_values($s));
    foreach ($s as $key => $value) {
        $total[$key] += $value;
    }
}
printf("%-45s %8d %9d %9d %6d %6d\n", 'TOTAL', ...array_values($total));
foreach ($samples as $table => $list) {
    echo "\nmiss in $table: " . implode('; ', array_slice($list, 0, 10)) . (count($list) > 10 ? ' …' : '') . "\n";
}
