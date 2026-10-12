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

use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Html\Event\BrokenLinkAnalysisEvent;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Broken links are marked when a rich text field is opened in the editor
 */
final class MarkBrokenRteLinkListenerTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEMAIN.linkHandler.cat.configuration.table = sys_category'],
            ['uid' => 1]
        );
        $this->getConnectionPool()->getConnectionForTable('sys_category')->insert('sys_category', ['uid' => 1, 'pid' => 1, 'title' => 'Category']);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function linkToHiddenPageIsMarked(): void
    {
        self::assertTrue($this->analyze('t3://page?uid=3')->isBrokenLink());
    }

    /**
     * The table of an identifier mapped in Page TSconfig is unknown without the record
     */
    #[Test]
    public function recordLinkWithMappedIdentifierIsNotMarkedWithoutRecordContext(): void
    {
        self::assertFalse($this->analyze('t3://record?identifier=cat&uid=1')->isBrokenLink());
    }

    #[Test]
    public function recordLinkWithMappedIdentifierIsCheckedForTheEditedRecord(): void
    {
        $this->editRecord('tt_content', 10);

        self::assertFalse($this->analyze('t3://record?identifier=cat&uid=1')->isBrokenLink());
        self::assertTrue($this->analyze('t3://record?identifier=cat&uid=999')->isBrokenLink());
    }

    #[Test]
    public function recordLinkWithMappedIdentifierIsCheckedForANewRecord(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(['edit' => ['tt_content' => [1 => 'new']]]);

        self::assertTrue($this->analyze('t3://record?identifier=cat&uid=999')->isBrokenLink());
    }

    private function editRecord(string $table, int $uid): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->createRequest(['edit' => [$table => [$uid => 'edit']]]);
    }

    private function createRequest(array $queryParams): ServerRequest
    {
        return (new ServerRequest('https://typo3-testing.local/typo3/record/edit'))
            ->withQueryParams($queryParams)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function analyze(string $link): BrokenLinkAnalysisEvent
    {
        $resolved = $this->get(LinkService::class)->resolve($link);
        return $this->get(EventDispatcherInterface::class)->dispatch(new BrokenLinkAnalysisEvent($resolved['type'], $resolved));
    }
}
