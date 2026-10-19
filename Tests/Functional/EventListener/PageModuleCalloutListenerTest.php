<?php

declare(strict_types=1);

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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\EventListener;

use GeorgRinger\Linkvalidator2\EventListener\PageModuleCalloutListener;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleRegistry;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The page module points to the broken links of the page, unless that is switched off
 */
final class PageModuleCalloutListenerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function theNumberOfBrokenLinksOfThePageIsShownWithALinkToTheReport(): void
    {
        $this->insertResult(2, 12, 'broken');
        $this->insertResult(2, 13, 'broken');

        $content = $this->callout(2);

        self::assertStringContainsString('2 broken links on this page', $content);
        self::assertStringContainsString('linkvalidator2', $content);
        self::assertStringContainsString('id=2', $content);
    }

    #[Test]
    public function oneBrokenLinkIsInTheSingular(): void
    {
        $this->insertResult(2, 12, 'broken');

        self::assertStringContainsString('1 broken link on this page', $this->callout(2));
    }

    #[Test]
    public function nothingIsShownWithoutBrokenLinks(): void
    {
        $this->insertResult(2, 12, 'info');

        self::assertSame('', $this->callout(2));
    }

    #[Test]
    public function linksOfOtherPagesAreNotCounted(): void
    {
        $this->insertResult(3, 12, 'broken');

        self::assertSame('', $this->callout(2));
    }

    #[Test]
    public function theGlobalSettingSwitchesTheCalloutOff(): void
    {
        $this->insertResult(2, 12, 'broken');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['linkvalidator2']['pageModuleCallout'] = '0';

        self::assertSame('', $this->callout(2));
    }

    #[Test]
    public function pageTsConfigSwitchesTheCalloutOffForAPage(): void
    {
        $this->insertResult(2, 12, 'broken');
        $this->setPageTsConfig(2, 'mod.linkvalidator2.pageModuleCallout = 0');

        self::assertSame('', $this->callout(2));
    }

    #[Test]
    public function pageTsConfigSwitchesTheCalloutOnAgainstTheGlobalSetting(): void
    {
        $this->insertResult(2, 12, 'broken');
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['linkvalidator2']['pageModuleCallout'] = '0';
        $this->setPageTsConfig(2, 'mod.linkvalidator2.pageModuleCallout = 1');

        self::assertStringContainsString('1 broken link on this page', $this->callout(2));
    }

    #[Test]
    public function aUserWhoMayNotFixTheLinksSeesNothing(): void
    {
        $this->insertResult(2, 12, 'broken');
        $editor = $this->setUpBackendUser(2);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($editor);

        self::assertSame('', $this->callout(2));
    }

    #[Test]
    public function nothingIsShownInAWorkspace(): void
    {
        $this->insertResult(2, 12, 'broken');
        $GLOBALS['BE_USER']->workspace = 1;

        self::assertSame('', $this->callout(2));
    }

    private function setPageTsConfig(int $pageUid, string $tsConfig): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['TSconfig' => $tsConfig], ['uid' => $pageUid]);
    }

    private function insertResult(int $pid, int $recordUid, string $status): void
    {
        $targets = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target');
        $targets->insert('tx_linkvalidator2_target', ['link_type' => 'url', 'identifier' => 'url:' . $recordUid . $status . $pid, 'identifier_hash' => sha1($recordUid . $status . $pid), 'status' => $status, 'error_type' => 'url.notFound']);
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link')->insert('tx_linkvalidator2_broken_link', [
            'target' => (int)$targets->lastInsertId(),
            'table_name' => 'tt_content',
            'record_uid' => $recordUid,
            'field' => 'bodytext',
            'pid' => $pid,
            'link_string' => 'https://example.org/gone',
            'link_text' => '<script>alert(1)</script>',
            'first_detected' => 100,
            'last_seen' => 100,
        ]);
    }

    private function callout(int $pageUid): string
    {
        $module = $this->get(ModuleRegistry::class)->getModule('web_layout');
        $request = (new ServerRequest('https://example.com/typo3/module/web/layout?id=' . $pageUid))
            ->withQueryParams(['id' => $pageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']))
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', ModuleData::createFromModule($module, []))
            ->withAttribute('route', new Route('/module/web/layout', ['_identifier' => 'web_layout', 'packageName' => 'typo3/cms-backend']));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        $event = new ModifyPageLayoutContentEvent($request, $this->get(ModuleTemplateFactory::class)->create($request));
        $this->get(PageModuleCalloutListener::class)($event);
        return $event->getHeaderContent();
    }
}
