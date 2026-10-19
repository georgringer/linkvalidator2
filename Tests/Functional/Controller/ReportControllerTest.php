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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Controller;

use GeorgRinger\Linkvalidator2\Controller\ReportController;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The language of a result is shown as a flag, but only for sites with several languages
 */
final class ReportControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('multi', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de', 'fallbackType' => 'strict'],
            ],
        ]);
        $siteWriter->write('single', [
            'rootPageId' => 5,
            'base' => 'https://single.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ],
        ]);
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function everyLanguageOfASiteWithSeveralLanguagesGetsItsFlag(): void
    {
        $this->insertResult(2, 12, 0);
        $this->insertResult(2, 13, 1);

        $html = $this->render(2);

        self::assertStringContainsString('flags-us', $html);
        self::assertStringContainsString('flags-de', $html);
    }

    #[Test]
    public function allLanguagesGetTheirOwnIconAndTitle(): void
    {
        $this->insertResult(2, 12, -1);

        $html = $this->render(2);

        self::assertStringContainsString('flags-multiple', $html);
        self::assertStringContainsString('All languages', $html);
    }

    #[Test]
    public function aSiteWithASingleLanguageShowsNoFlag(): void
    {
        $this->insertResult(5, 12, 0);

        self::assertStringNotContainsString('flags-us', $this->render(5));
    }

    #[Test]
    public function aLanguageThatTheSiteDoesNotKnowShowsNoFlag(): void
    {
        $this->insertResult(2, 12, 7);

        $html = $this->render(2);

        self::assertStringNotContainsString('flags-us', $html);
        self::assertStringNotContainsString('flags-de', $html);
    }

    #[Test]
    public function ignoringOffersTheDomainOfAnExternalLink(): void
    {
        $this->insertResult(2, 12, 0);

        self::assertStringContainsString('Ignore the domain example.org on this page and its subpages', $this->render(2));
    }

    #[Test]
    public function theLanguageFilterOffersTheLanguagesOfTheResults(): void
    {
        $this->insertResult(2, 12, 0);
        $this->insertResult(2, 13, 1);

        $html = $this->render(2);

        self::assertStringContainsString('German', $html);
        self::assertStringContainsString('English', $html);
    }

    private function insertResult(int $pid, int $recordUid, int $language): void
    {
        $targets = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target');
        $targets->insert('tx_linkvalidator2_target', ['link_type' => 'url', 'identifier' => 'url:' . $recordUid, 'identifier_hash' => sha1((string)$recordUid . $language), 'status' => 'broken', 'error_type' => 'url.notFound']);
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link')->insert('tx_linkvalidator2_broken_link', [
            'target' => (int)$targets->lastInsertId(),
            'table_name' => 'tt_content',
            'record_uid' => $recordUid,
            'field' => 'bodytext',
            'pid' => $pid,
            'language' => $language,
            'link_string' => 'https://example.org/gone',
            'link_text' => 'text',
            'first_detected' => 100,
            'last_seen' => 100,
        ]);
    }

    private function render(int $pageUid): string
    {
        $module = $this->get(ModuleProvider::class)->getModule('linkvalidator2_report', $GLOBALS['BE_USER']);
        $request = (new ServerRequest('https://example.com/typo3/module/link-management/linkvalidator2?id=' . $pageUid))
            ->withQueryParams(['id' => $pageUid])
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on']))
            ->withAttribute('module', $module)
            ->withAttribute('moduleData', ModuleData::createFromModule($module, []))
            ->withAttribute('route', new Route('/module/link-management/linkvalidator2', ['_identifier' => 'linkvalidator2_report', 'packageName' => 'georgringer/linkvalidator2']));
        $GLOBALS['TYPO3_REQUEST'] = $request;
        return (string)$this->get(ReportController::class)($request)->getBody();
    }
}
