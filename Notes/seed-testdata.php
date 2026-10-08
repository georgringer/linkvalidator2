<?php

declare(strict_types=1);

// Throwaway seed script for linkvalidator2 test data below page 1287.
// Run: ddev exec php typo3conf/ext/linkvalidator2/Notes/seed-testdata.php

use TYPO3\CMS\Core\Authentication\CommandLineUserAuthentication;
use TYPO3\CMS\Core\Core\BootService;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

const ROOT = 1287;
const LANGUAGE = 5;

$classLoader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(4, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$failsafeContainer = Bootstrap::init($classLoader, true);
$bootService = $failsafeContainer->get(BootService::class);
$container = $bootService->getContainer();
$bootService->loadExtLocalconfDatabase();
Bootstrap::initializeBackendUser(CommandLineUserAuthentication::class);
$GLOBALS['BE_USER']->authenticate();
$GLOBALS['LANG'] = $container->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);

$existing = $container->get(ConnectionPool::class)->getConnectionForTable('pages')
    ->count('uid', 'pages', ['pid' => ROOT, 'deleted' => 0]);
if ($existing > 0) {
    fwrite(STDERR, "Page " . ROOT . " already has subpages, aborting.\n");
    exit(1);
}

function process(array $data, array $cmd = []): DataHandler
{
    $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
    $dataHandler->start($data, $cmd);
    $dataHandler->process_datamap();
    $dataHandler->process_cmdmap();
    if ($dataHandler->errorLog !== []) {
        fwrite(STDERR, implode("\n", $dataHandler->errorLog) . "\n");
        exit(1);
    }
    return $dataHandler;
}

$now = time();
$pages = [
    'visible' => ['title' => 'Ziel: sichtbar'],
    'hidden' => ['title' => 'Ziel: versteckt', 'hidden' => 1],
    'deleted' => ['title' => 'Ziel: gelöscht'],
    'expired' => ['title' => 'Ziel: abgelaufen', 'endtime' => $now - 86400],
    'future' => ['title' => 'Ziel: Start in Zukunft', 'starttime' => $now + 30 * 86400],
    'sysfolder' => ['title' => 'Ziel: Sysfolder', 'doktype' => 254],
    'spacer' => ['title' => 'Ziel: Spacer', 'doktype' => 199],
    'content' => ['title' => 'Testinhalte'],
    'linkPage' => ['title' => 'Seitentyp Link: extern', 'doktype' => 3, 'link' => 'https://typo3.org'],
    'linkPageBroken' => ['title' => 'Seitentyp Link: versteckte Seite', 'doktype' => 3],
    'shortcutOk' => ['title' => 'Shortcut: auf sichtbare Seite', 'doktype' => 4, 'shortcut_mode' => 0],
    'shortcutBroken' => ['title' => 'Shortcut: auf gelöschte Seite', 'doktype' => 4, 'shortcut_mode' => 0],
];
$data = [];
foreach (array_reverse($pages, true) as $key => $fields) {
    $data['pages']['NEW_' . $key] = $fields + ['pid' => ROOT, 'hidden' => 0];
}
$dataHandler = process($data);
$page = [];
foreach (array_keys($pages) as $key) {
    $page[$key] = (int)$dataHandler->substNEWwithIDs['NEW_' . $key];
}

process(['pages' => [
    $page['shortcutOk'] => ['shortcut' => $page['visible']],
    $page['shortcutBroken'] => ['shortcut' => $page['deleted']],
    $page['linkPageBroken'] => ['link' => 't3://page?uid=' . $page['hidden']],
]]);

$dataHandler = process(['tt_content' => [
    'NEW_anchorTarget' => ['pid' => $page['visible'], 'CType' => 'text', 'header' => 'Anker-Ziel', 'bodytext' => '<p>Ziel für Anker- und Record-Links</p>'],
    'NEW_hiddenTarget' => ['pid' => $page['visible'], 'CType' => 'text', 'header' => 'Verstecktes Ziel', 'hidden' => 1, 'bodytext' => '<p>Verstecktes Record-Ziel</p>'],
]]);
$anchorTarget = (int)$dataHandler->substNEWwithIDs['NEW_anchorTarget'];
$hiddenTarget = (int)$dataHandler->substNEWwithIDs['NEW_hiddenTarget'];

$link = static fn(string $href, string $text): string => '<a href="' . htmlspecialchars($href) . '">' . htmlspecialchars($text) . '</a>';
$allInOne = '<p>Interne Seiten: '
    . $link('t3://page?uid=' . $page['visible'], 'sichtbar') . ', '
    . $link('t3://page?uid=' . $page['hidden'], 'versteckt') . ', '
    . $link('t3://page?uid=' . $page['deleted'], 'gelöscht') . ', '
    . $link('t3://page?uid=' . $page['expired'], 'abgelaufen') . ', '
    . $link('t3://page?uid=' . $page['future'], 'Zukunft') . ', '
    . $link('t3://page?uid=' . $page['sysfolder'], 'Sysfolder') . ', '
    . $link('t3://page?uid=' . $page['spacer'], 'Spacer') . ', '
    . $link('t3://page?uid=999999', 'gibt es nicht') . ', '
    . $link('t3://page?uid=' . $page['visible'] . '#c' . $anchorTarget, 'Anker ok') . ', '
    . $link('t3://page?uid=' . $page['visible'] . '#c999999', 'Anker kaputt') . '</p>'
    . '<p>Datensätze: '
    . $link('t3://record?identifier=tt_content&uid=' . $anchorTarget, 'Record ok') . ', '
    . $link('t3://record?identifier=tt_content&uid=' . $hiddenTarget, 'Record versteckt') . ', '
    . $link('t3://record?identifier=tt_content&uid=999999', 'Record gibt es nicht') . '</p>'
    . '<p>Dateien: '
    . $link('t3://file?uid=87', 'Datei ok') . ', '
    . $link('t3://file?uid=99999999', 'Datei gibt es nicht') . ', '
    . $link('t3://folder?storage=1&identifier=/gibtsnicht/', 'Ordner gibt es nicht') . '</p>'
    . '<p>Extern: '
    . $link('https://typo3.org', 'https://typo3.org') . ', '
    . $link('https://typo3.org', 'nochmal typo3.org') . ', '
    . $link('https://example.invalid/', 'Domain gibt es nicht') . '</p>'
    . '<p>Übersprungen: '
    . $link('mailto:test@example.org', 'Mail') . ', '
    . $link('tel:+431234', 'Telefon') . ', '
    . $link('#top', 'Anker auf Seite') . '</p>'
    . '<p>Kaputte Syntax: '
    . $link('t3://page', 'Seite ohne uid') . ', '
    . $link('t3://gibtsnicht?uid=1', 'unbekannter Handler') . ', '
    . $link('t3://record?uid=1', 'Record ohne identifier') . '</p>';
$okOnly = '<p>' . $link('t3://page?uid=' . $page['visible'], 'sichtbar') . ' '
    . $link('t3://page?uid=' . $page['future'], 'Zukunft') . ' '
    . $link('t3://file?uid=87', 'Datei') . '</p>';
$brokenOnly = '<p>' . $link('t3://page?uid=' . $page['deleted'], 'gelöscht') . '</p>';

$dataHandler = process(['tt_content' => [
    'NEW_hiddenSource' => ['pid' => $page['content'], 'CType' => 'text', 'header' => 'Versteckt mit kaputtem Link (nichts melden)', 'hidden' => 1, 'bodytext' => $brokenOnly],
    'NEW_expiredSource' => ['pid' => $page['content'], 'CType' => 'text', 'header' => 'Abgelaufen mit kaputtem Link (nichts melden)', 'endtime' => $now - 86400, 'bodytext' => $brokenOnly],
    'NEW_okOnly' => ['pid' => $page['content'], 'CType' => 'text', 'header' => 'Nur gültige Links', 'bodytext' => $okOnly],
    'NEW_allInOne' => ['pid' => $page['content'], 'CType' => 'text', 'header' => 'Alle Fälle', 'header_link' => 't3://page?uid=' . $page['hidden'], 'bodytext' => $allInOne],
]]);
$allInOneUid = (int)$dataHandler->substNEWwithIDs['NEW_allInOne'];

$dataHandler = process([], [
    'pages' => [
        $page['deleted'] => ['delete' => 1],
        $page['content'] => ['localize' => LANGUAGE],
    ],
]);
$translatedPageUid = (int)($dataHandler->copyMappingArray_merged['pages'][$page['content']] ?? 0);
$dataHandler = process([], ['tt_content' => [$allInOneUid => ['localize' => LANGUAGE]]]);
$translatedUid = (int)($dataHandler->copyMappingArray_merged['tt_content'][$allInOneUid] ?? 0);

// Bypass DataHandler: the RTE transformation empties links to missing files/folders,
// and localized records are created hidden.
$connectionPool = $container->get(ConnectionPool::class);
$contentConnection = $connectionPool->getConnectionForTable('tt_content');
foreach ([$allInOneUid, $translatedUid] as $uid) {
    $bodytext = (string)$contentConnection->select(['bodytext'], 'tt_content', ['uid' => $uid])->fetchOne();
    $bodytext = str_replace(
        ['<a href="">Datei gibt es nicht</a>', '<a href="">Ordner gibt es nicht</a>'],
        ['<a href="t3://file?uid=99999999">Datei gibt es nicht</a>', '<a href="t3://folder?storage=1&amp;identifier=/gibtsnicht/">Ordner gibt es nicht</a>'],
        $bodytext
    );
    $contentConnection->update('tt_content', ['bodytext' => $bodytext], ['uid' => $uid]);
}
$contentConnection->update('tt_content', ['hidden' => 0], ['uid' => $translatedUid]);
$connectionPool->getConnectionForTable('pages')->update('pages', ['hidden' => 0], ['uid' => $translatedPageUid]);

echo "Pages:\n";
foreach ($page as $key => $uid) {
    echo sprintf("  %-15s %d\n", $key, $uid);
}
echo sprintf("  %-15s %d\n", 'content(' . LANGUAGE . ')', $translatedPageUid);
echo "Content:\n";
echo sprintf("  %-15s %d\n  %-15s %d\n  %-15s %d\n", 'allInOne', $allInOneUid, 'translated(' . LANGUAGE . ')', $translatedUid, 'anchorTarget', $anchorTarget);
echo sprintf("  %-15s %d\n", 'hiddenTarget', $hiddenTarget);
