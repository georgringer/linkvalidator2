<?php
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
$classLoader = require dirname(__DIR__, 4) . '/vendor/autoload.php';
SystemEnvironmentBuilder::run(0, SystemEnvironmentBuilder::REQUESTTYPE_BE);
$container = Bootstrap::init($classLoader);
$_SERVER['HTTP_HOST'] = 't3-main.ddev.site';
$_SERVER['HTTPS'] = 'on';
$GLOBALS["BE_USER"] = new \TYPO3\CMS\Core\Authentication\BackendUserAuthentication();
$GLOBALS["BE_USER"]->initializeUserSessionManager();
$GLOBALS["BE_USER"]->setLogger(new \Psr\Log\NullLogger());
(fn() => $this->userSession = \TYPO3\CMS\Core\Session\UserSession::createNonFixated("lv2test"))->call($GLOBALS["BE_USER"]);
$GLOBALS['BE_USER']->setBeUserByUid(1);
$GLOBALS['BE_USER']->fetchGroupData();
$GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
parse_str($argv[1] ?? '', $data);
$module = $container->get(\TYPO3\CMS\Backend\Module\ModuleRegistry::class)->getModule('linkvalidator2_report');
$moduleData = ModuleData::createFromModule($module, array_intersect_key($data, $module->getDefaultModuleData()));
// Second argument "post": send the data as POST body (e.g. checkPage=1)
$isPost = ($argv[2] ?? '') === 'post';
$request = (new ServerRequest('https://t3-main.ddev.site/typo3/module/link-management/linkvalidator2', $isPost ? 'POST' : 'GET'))
    ->withQueryParams($isPost ? ['id' => $data['id'] ?? 0] : $data)
    ->withParsedBody($isPost ? $data : null)
    ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
    ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($_SERVER))
    ->withAttribute('module', $module)
    ->withAttribute('moduleData', $moduleData)
    ->withAttribute('route', new Route('/module/link-management/linkvalidator2', ['_identifier' => 'linkvalidator2_report', 'packageName' => 'typo3/cms-linkvalidator2', 'module' => $module]));
$GLOBALS['TYPO3_REQUEST'] = $request;
$controller = GeneralUtility::makeInstance(\TYPO3\CMS\Linkvalidator2\Controller\ReportController::class);
$html = (string)$controller($request)->getBody();
$start = strpos($html, '<h1');
echo strip_tags(preg_replace(['/<option([^>]*)selected/', '/<\/(tr|option|li|h1|label)>/'], ['<option$1>[*]', "$0\n"], substr($html, $start)));
