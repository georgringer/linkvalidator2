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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use GeorgRinger\Linkvalidator2\Report\Mail\BackendUrls;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportBuilder;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportItem;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportRequest;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class MailReportBuilderTest extends FunctionalTestCase
{
    use InsertBrokenLinkTrait;

    // Large enough that NOW minus a few days stays positive: first_detected is unsigned (MariaDB rejects negatives)
    private const NOW = 1_792_396_800;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/tt_content.csv');
    }

    #[Test]
    public function minAgeKeepsOnlyLinksBrokenForAtLeastThatLong(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - 2 * 3600);
        $this->insert(1, 'tt_content', 13, self::NOW - 600);

        $items = $this->builder()->first($this->request(minAge: '1 hour'), $this->urls(), self::NOW, 10);

        self::assertCount(1, $items);
        self::assertSame(self::NOW - 2 * 3600, $items[0]->firstDetected);
    }

    #[Test]
    public function maxAgeKeepsOnlyLinksBrokenForAtMostThatLong(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - 2 * 3600);
        $this->insert(1, 'tt_content', 13, self::NOW - 600);

        $items = $this->builder()->first($this->request(maxAge: '1 hour'), $this->urls(), self::NOW, 10);

        self::assertCount(1, $items);
        self::assertSame(self::NOW - 600, $items[0]->firstDetected);
    }

    #[Test]
    public function minAndMaxAgeFormARange(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - 5 * 86400);
        $this->insert(1, 'tt_content', 13, self::NOW - 3 * 86400);
        $this->insert(1, 'tt_content', 13, self::NOW - 3600);

        $builder = $this->builder();
        $request = $this->request(minAge: '1 day', maxAge: '4 days');

        self::assertSame(1, $builder->count($request, self::NOW));
    }

    #[Test]
    public function anEmptyRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->builder()->count($this->request(minAge: '7 days', maxAge: '1 day'), self::NOW);
    }

    #[Test]
    public function depthIncludesSubpages(): void
    {
        $this->insert(1, 'tt_content', 13, 100);
        $this->insert(2, 'pages', 2, 200);
        $builder = $this->builder();

        self::assertSame(1, $builder->count($this->request(depth: 0), self::NOW));
        self::assertSame(2, $builder->count($this->request(depth: 1), self::NOW));
    }

    #[Test]
    public function includeAndExcludeTablesApply(): void
    {
        $this->insert(1, 'tt_content', 13, 100);
        $this->insert(1, 'pages', 1, 100);
        $builder = $this->builder();

        self::assertSame(1, $builder->count($this->request(includeTables: ['pages']), self::NOW));
        self::assertSame(1, $builder->count($this->request(excludeTables: ['pages']), self::NOW));
    }

    #[Test]
    public function firstReturnsAtMostTheLimitOldestFirst(): void
    {
        $this->insert(1, 'tt_content', 13, 300);
        $this->insert(1, 'tt_content', 13, 100);
        $this->insert(1, 'tt_content', 13, 200);

        $items = $this->builder()->first($this->request(), $this->urls(), self::NOW, 2);

        self::assertSame([100, 200], array_map(static fn(MailReportItem $item): int => $item->firstDetected, $items));
    }

    #[Test]
    public function iterateReturnsEverythingAcrossPages(): void
    {
        foreach ([500, 400, 300, 200, 100] as $firstDetected) {
            $this->insert(1, 'tt_content', 13, $firstDetected);
        }

        $items = iterator_to_array($this->builder()->iterate($this->request(), $this->urls(), self::NOW, 2), false);

        self::assertSame([100, 200, 300, 400, 500], array_map(static fn(MailReportItem $item): int => $item->firstDetected, $items));
    }

    #[Test]
    public function anItemCarriesWhatTheMailShows(): void
    {
        $this->insert(2, 'tt_content', 13, 100, 'url.notFound', 'https://example.org/gone', 'Read more');

        $item = $this->builder()->first($this->request(pageUid: 2), $this->urls(), self::NOW, 1)[0];

        self::assertSame('Visible', $item->pageTitle);
        self::assertSame(2, $item->pageUid);
        self::assertSame('Only valid links', $item->recordTitle);
        self::assertSame('Read more', $item->linkText);
        self::assertSame('https://example.org/gone', $item->target);
        self::assertSame('broken', $item->status);
        self::assertSame('url.notFound', $item->errorType);
        self::assertSame('https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B13%5D=edit', $item->editUrl);
        self::assertSame('https://example.com/typo3/module/link-management/linkvalidator2?id=2', $item->moduleUrl);
    }

    #[Test]
    public function aDeletedRecordFallsBackToTableAndUid(): void
    {
        $this->insert(1, 'tt_content', 9999, 100);

        $item = $this->builder()->first($this->request(), $this->urls(), self::NOW, 1)[0];

        self::assertSame('tt_content:9999', $item->recordTitle);
    }

    private function builder(): MailReportBuilder
    {
        return new MailReportBuilder(
            $this->get(BrokenLinkRepository::class),
            $this->get(PageRecordFinder::class),
            $this->get(ErrorMessageFormatter::class),
        );
    }

    private function urls(): BackendUrls
    {
        return new BackendUrls(new Uri('https://example.com/typo3/module/link-management/linkvalidator2'), new Uri('https://example.com/typo3/record/edit'), new ServerRequest('https://example.com/'));
    }

    private function request(int $pageUid = 1, int $depth = 0, array $includeTables = [], array $excludeTables = [], ?string $minAge = null, ?string $maxAge = null): MailReportRequest
    {
        return new MailReportRequest($pageUid, $depth, new ReportFilter(), $includeTables, $excludeTables, $minAge, $maxAge);
    }
}
