<?php
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Frontend\Page\PageInformationCreationFailedException;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

$classLoader = require '/var/www/html/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_CLI);
$container = Bootstrap::init($classLoader);
$pool = GeneralUtility::makeInstance(ConnectionPool::class);
$conn = $pool->getConnectionForTable('pages');

// Temporary pages below 1287
$now = time();
$tmp = [
    'hiddenExtend' => ['title' => 'SPIKE versteckt + extendToSubpages', 'hidden' => 1, 'extendToSubpages' => 1],
    'expiredExtend' => ['title' => 'SPIKE abgelaufen + extendToSubpages', 'endtime' => $now - 3600, 'extendToSubpages' => 1],
    'hiddenNoExtend' => ['title' => 'SPIKE versteckt ohne extend', 'hidden' => 1],
    'feGroup' => ['title' => 'SPIKE fe_group -2', 'fe_group' => '-2'],
    'feGroupExtend' => ['title' => 'SPIKE fe_group -2 + extend', 'fe_group' => '-2', 'extendToSubpages' => 1],
];
$uids = [];
foreach ($tmp as $key => $values) {
    $conn->insert('pages', $values + ['pid' => 1287, 'doktype' => 1, 'slug' => '/spike-' . strtolower($key), 'tstamp' => $now, 'crdate' => $now]);
    $uids[$key] = (int)$conn->lastInsertId();
    $conn->insert('pages', ['pid' => $uids[$key], 'title' => 'SPIKE child of ' . $key, 'doktype' => 1, 'slug' => '/spike-' . strtolower($key) . '/child', 'tstamp' => $now, 'crdate' => $now]);
    $uids[$key . 'Child'] = (int)$conn->lastInsertId();
}
// A site variant where French (6) falls back to default
$site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByIdentifier('main');
$config = $site->getConfiguration();
foreach ($config['languages'] as &$language) {
    if ((int)$language['languageId'] === 6) {
        $language['fallbackType'] = 'fallback';
        $language['fallbacks'] = '0';
    }
}
unset($language);
$fallbackSite = new Site('main', 1, $config);

$cases = [
    'visible 1299' => [1299, 0],
    'hidden 1298' => [1298, 0],
    'deleted 1297' => [1297, 0],
    'expired 1296' => [1296, 0],
    'future 1295' => [1295, 0],
    'sysfolder 1294' => [1294, 0],
    'spacer 1293' => [1293, 0],
    'shortcut->visible 1289' => [1289, 0],
    'shortcut->deleted 1288' => [1288, 0],
    'link->hidden 1290' => [1290, 0],
    'missing 999999' => [999999, 0],
    'child of hidden+extend' => [$uids['hiddenExtendChild'], 0],
    'child of expired+extend' => [$uids['expiredExtendChild'], 0],
    'child of hidden w/o extend' => [$uids['hiddenNoExtendChild'], 0],
    'fe_group -2' => [$uids['feGroup'], 0],
    'child of fe_group+extend' => [$uids['feGroupExtendChild'], 0],
    'translated 1292 de(5)' => [1292, 5],
    'untranslated 1299 de(5, strict)' => [1299, 5],
    'untranslated 1299 fr(6, fallback)' => [1299, 6, $fallbackSite],
];

// Private service: borrow it from the frontend middleware (spike only)
$middleware = $container->get(\TYPO3\CMS\Frontend\Middleware\PrepareTypoScriptFrontendRendering::class);
$factory = (new \ReflectionProperty($middleware, 'pageInformationFactory'))->getValue($middleware);
$context = GeneralUtility::makeInstance(Context::class);

$run = function (string $label) use ($cases, $factory, $site, $context): void {
    echo "\n== $label (backend.user isLoggedIn: " . var_export($context->getPropertyFromAspect('backend.user', 'isLoggedIn', false), true) . ")\n";
    foreach ($cases as $name => $case) {
        [$pageId, $languageId] = $case;
        $useSite = $case[2] ?? $site;
        $languageBefore = $context->getPropertyFromAspect('language', 'id', 0);
        $request = (new ServerRequest((string)$useSite->getBase(), 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $useSite)
            ->withAttribute('language', $useSite->getLanguageById($languageId))
            ->withAttribute('routing', new PageArguments($pageId, '0', []))
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 't3-main.ddev.site', 'HTTPS' => 'on']));
        $t = microtime(true);
        try {
            $info = $factory->create($request);
            $result = 'OK id=' . $info->getId() . ' contentFromPid=' . $info->getContentFromPid();
        } catch (PageInformationCreationFailedException $e) {
            $result = 'FAIL code=' . $e->getCode() . ' status=' . $e->getResponse()->getStatusCode();
        } catch (\Throwable $e) {
            $result = 'ERROR ' . get_class($e) . ' ' . $e->getCode() . ' ' . substr($e->getMessage(), 0, 80);
        }
        $ms = round((microtime(true) - $t) * 1000, 1);
        $languageAfter = $context->getPropertyFromAspect('language', 'id', 0);
        printf("%-36s %-50s %6sms%s\n", $name, $result, $ms, $languageAfter !== $languageBefore ? "  [context language $languageBefore -> $languageAfter]" : '');
    }
};

try {
    $run('CLI, no backend user');
    // Simulate the module's AJAX request: a backend user is logged in
    $beUser = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
    $beUser->user = ['uid' => 1, 'admin' => 1, 'username' => 'admin', 'workspace_id' => 0];
    $context->setAspect('backend.user', new UserAspect($beUser));
    $run('backend user logged in');
    // Isolated: anonymous for the duration of the check
    $context->setAspect('backend.user', new UserAspect(null));
    $context->setAspect('visibility', new VisibilityAspect());
    $run('backend user aspect reset to anonymous');
} finally {
    foreach ($uids as $uid) {
        $conn->delete('pages', ['uid' => $uid]);
    }
    echo "\ncleaned up " . count($uids) . " pages\n";
}
