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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Repository\TargetRepository;
use GeorgRinger\Linkvalidator2\Service\PageCheckService;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use GeorgRinger\Linkvalidator2\Tests\Functional\BrokenLinkResultsTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageCheckServiceTest extends FunctionalTestCase
{
    use BrokenLinkResultsTrait;

    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => [
            'linkvalidator2' => [
                'externalCacheLifetime' => '0',
            ],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
    }

    #[Test]
    public function pageIsCheckedCompletelyWithoutTimeBudget(): void
    {
        $result = $this->get(PageCheckService::class)->checkPage(1, $this->now());

        self::assertTrue($result->done);
        // pages:1 and tt_content 10–14
        self::assertSame(6, $result->recordCount);
        self::assertSame(6, $result->checkedCount);
        // 7 in tt_content:10, 1 in the translation tt_content:14
        self::assertSame(8, $result->brokenLinkCount);
    }

    #[Test]
    public function exceededTimeBudgetIsContinuedByTheNextCall(): void
    {
        $subject = $this->get(PageCheckService::class);
        $runStartedAt = $this->now();

        $calls = 0;
        do {
            $result = $subject->checkPage(1, $runStartedAt, 0.0);
            $calls++;
            // Each call makes progress by at least one record
            self::assertSame(1, $result->checkedCount);
        } while (!$result->done && $calls < 10);

        self::assertTrue($result->done);
        self::assertSame(6, $calls);
        self::assertSame(8, $result->brokenLinkCount);
    }

    #[Test]
    public function recordsCheckedBeforeTheRunAreCheckedAgain(): void
    {
        $subject = $this->get(PageCheckService::class);
        $subject->checkPage(1, $this->now());

        $result = $subject->checkPage(1, $this->now() + 1);

        self::assertSame(6, $result->checkedCount);
    }

    #[Test]
    public function brokenLinksFoundForTheFirstTimeAreCountedAsNew(): void
    {
        $subject = $this->get(PageCheckService::class);
        self::assertSame(8, $subject->checkPage(1, $this->now())->newBrokenLinkCount);
        // Pretend the first run happened a day ago
        $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->executeStatement('UPDATE ' . self::BROKEN_LINK_TABLE . ' SET first_detected = first_detected - 86400');
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->executeStatement('UPDATE tx_linkvalidator2_record_status SET last_checked = last_checked - 86400');
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['bodytext' => '<p><a href="t3://page?uid=2">ok</a> <a href="t3://page?uid=4">deleted</a></p>'],
            ['uid' => 13]
        );

        $result = $subject->checkPage(1, $this->now());

        self::assertSame(9, $result->brokenLinkCount);
        self::assertSame(1, $result->newBrokenLinkCount);
    }

    #[Test]
    public function noticesAreCountedSeparately(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');

        $result = $this->get(PageCheckService::class)->checkPage(2, $this->now());

        self::assertSame(1, $result->brokenLinkCount);
        self::assertSame(1, $result->newBrokenLinkCount);
        self::assertSame(1, $result->noticeCount);
    }

    #[Test]
    public function staleResultsOfThePageAreRemoved(): void
    {
        $subject = $this->get(PageCheckService::class);
        $subject->checkPage(1, $this->now());
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => 1], ['uid' => 10]);

        $result = $subject->checkPage(1, $this->now() + 1);

        self::assertSame(1, $result->brokenLinkCount);
    }

    /**
     * Even without a cache lifetime, a URL is requested once per run, not once per page.
     */
    #[Test]
    public function externalResultStoredDuringTheRunIsReused(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_external.csv');
        $runStartedAt = $this->now();
        $url = 'https://lv2-test.invalid/gone';
        $this->get(TargetRepository::class)->store(
            new LinkTarget(LinkService::TYPE_URL, 'url:' . $url, ['type' => LinkService::TYPE_URL, 'url' => $url]),
            CheckResult::broken('url.notFound', ['httpStatus' => 404]),
            $runStartedAt
        );

        $this->get(PageCheckService::class)->checkPage(2, $runStartedAt);

        $errorType = $this->selectBrokenLinks(['t.error_type'], ['table_name' => 'tt_content', 'record_uid' => 20])[0]['error_type'] ?? null;
        // Requesting the URL would have reported the .invalid host as unknown
        self::assertSame('url.notFound', $errorType);
    }

    /**
     * The clock the checks store their times with
     */
    private function now(): int
    {
        return (int)$this->get(Context::class)->getPropertyFromAspect('date', 'timestamp');
    }
}
