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

namespace TYPO3\CMS\Linkvalidator2\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Linkvalidator2\Report\ReportFilter;
use TYPO3\CMS\Linkvalidator2\Repository\BrokenLinkRepository;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;
use TYPO3\CMS\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordCheckServiceTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';
    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';

    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
    }

    #[Test]
    public function brokenLinksOfARecordAreStoredOncePerFieldAndTarget(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        $this->assertCSVDataSet(__DIR__ . '/../Fixtures/expected_all_cases.csv');
    }

    #[Test]
    public function validLinksAreCheckedButNotStored(): void
    {
        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('tt_content', 13);

        self::assertCount(2, $checkedLinks);
        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
        self::assertSame(0, $this->getStatus('tt_content', 13)['broken_count']);
    }

    #[Test]
    public function restrictedTargetIsStoredAsNoticeButNotCountedAsBroken(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $rows = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['status', 'error_type'], self::BROKEN_LINK_TABLE, ['record_uid' => 15], [], ['status' => 'ASC'])
            ->fetchAllAssociative();
        self::assertSame(
            [['status' => 'broken', 'error_type' => 'page.hidden'], ['status' => 'info', 'error_type' => 'page.accessRestricted']],
            $rows
        );
        self::assertSame(1, $this->getStatus('tt_content', 15)['broken_count']);
    }

    #[Test]
    public function reportListsNoticesUnlessFilteredByStatus(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);
        $repository = $this->get(BrokenLinkRepository::class);

        self::assertSame(2, $repository->countByPids([2]));
        self::assertSame(1, $repository->countByPids([2], new ReportFilter('info')));
        self::assertSame(1, $repository->countByPids([2], new ReportFilter('broken')));
        self::assertSame(1, $repository->countBrokenByPid(2));
    }

    #[Test]
    public function linksToEmailAddressesAreSkipped(): void
    {
        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        foreach ($checkedLinks as $checkedLink) {
            self::assertStringStartsNotWith('mailto:', $checkedLink->candidate->link);
        }
    }

    #[Test]
    public function hiddenRecordIsNotReported(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 11);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
        self::assertSame(0, $this->getStatus('tt_content', 11)['broken_count']);
    }

    #[Test]
    public function expiredRecordIsNotReported(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 12);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
    }

    #[Test]
    public function resultsDisappearWhenTheRecordIsHiddenAndCheckedAgain(): void
    {
        $subject = $this->get(RecordCheckService::class);
        $subject->checkRecord('tt_content', 10);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['hidden' => 1], ['uid' => 10]);

        $subject->checkRecord('tt_content', 10);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
        self::assertSame(0, $this->getStatus('tt_content', 10)['broken_count']);
    }

    #[Test]
    public function resultsDisappearWhenTheRecordIsDeletedAndCheckedAgain(): void
    {
        $subject = $this->get(RecordCheckService::class);
        $subject->checkRecord('tt_content', 10);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['deleted' => 1], ['uid' => 10]);

        $subject->checkRecord('tt_content', 10);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
        self::assertSame(0, $this->countRows(self::STATUS_TABLE));
    }

    #[Test]
    public function firstDetectionIsKeptWhenCheckedAgain(): void
    {
        $subject = $this->get(RecordCheckService::class);
        $subject->checkRecord('tt_content', 10);
        $connection = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE);
        $connection->update(self::BROKEN_LINK_TABLE, ['first_detected' => 1000], ['record_uid' => 10]);

        $subject->checkRecord('tt_content', 10);

        $firstDetected = $connection->select(['first_detected'], self::BROKEN_LINK_TABLE)->fetchFirstColumn();
        self::assertCount(7, $firstDetected);
        self::assertSame([1000], array_values(array_unique(array_map(intval(...), $firstDetected))));
    }

    #[Test]
    public function languageOfTranslatedRecordIsStored(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 14);

        self::assertSame(['pid' => 1, 'language' => 1, 'broken_count' => 1], $this->getStatus('tt_content', 14));
    }

    #[Test]
    public function linkInPageFieldIsStoredForThePageItself(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('pages', 9);

        $row = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['field', 'pid', 'error_type'], self::BROKEN_LINK_TABLE, ['table_name' => 'pages', 'record_uid' => 9])
            ->fetchAssociative();
        self::assertSame(['field' => 'link', 'pid' => 9, 'error_type' => 'page.hidden'], $this->normalize($row));
    }

    #[Test]
    public function translatedPageBelongsToItsDefaultLanguagePage(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('pages', 10);

        self::assertSame(['pid' => 2, 'language' => 1, 'broken_count' => 0], $this->getStatus('pages', 10));
    }

    #[Test]
    public function shortcutToDeletedPageIsBroken(): void
    {
        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('pages', 7);

        self::assertCount(1, $checkedLinks);
        self::assertSame('shortcut', $checkedLinks[0]->candidate->field);
        self::assertSame('page.missing', $checkedLinks[0]->result->errorType);
    }

    #[Test]
    public function shortcutToVisiblePageIsOk(): void
    {
        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('pages', 8);

        self::assertCount(1, $checkedLinks);
        self::assertTrue($checkedLinks[0]->result->isOk());
    }

    #[Test]
    public function linkToTheCurrentPageInContentIsValid(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/current_page.csv');

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 17);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE));
        self::assertSame(0, $this->getStatus('tt_content', 17)['broken_count']);
    }

    #[Test]
    public function linkPageLinkingToItselfIsBroken(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/current_page.csv');

        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('pages', 12);

        self::assertCount(1, $checkedLinks);
        self::assertSame('link', $checkedLinks[0]->candidate->field);
        self::assertSame('page.linksToItself', $checkedLinks[0]->result->errorType);
        self::assertSame(1, $this->getStatus('pages', 12)['broken_count']);
    }

    public static function shortcutModeDataProvider(): \Generator
    {
        yield 'first subpage without subpages' => [20, 'page.targetNotReachable'];
        yield 'first subpage with only a hidden subpage' => [21, 'page.targetNotReachable'];
        yield 'first subpage with a visible subpage' => [23, null];
        yield 'parent page not reachable' => [25, 'page.targetNotReachable'];
        yield 'parent page reachable' => [26, null];
        yield 'fixed target missing, no subpage' => [27, 'page.targetNotReachable'];
    }

    /**
     * Forge #92542: the frontend's page resolution decides where the shortcut leads to
     */
    #[DataProvider('shortcutModeDataProvider')]
    #[Test]
    public function shortcutIsCheckedForEveryMode(int $pageUid, ?string $expectedErrorType): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_shortcut_modes.csv');

        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('pages', $pageUid);

        self::assertCount(1, $checkedLinks);
        self::assertSame('shortcut_mode', $checkedLinks[0]->candidate->field);
        self::assertSame($expectedErrorType, $checkedLinks[0]->result->isOk() ? null : $checkedLinks[0]->result->errorType);
    }

    private function countRows(string $table): int
    {
        return $this->getConnectionPool()->getConnectionForTable($table)->count('*', $table, []);
    }

    /**
     * @return array{pid: int, language: int, broken_count: int}
     */
    private function getStatus(string $table, int $uid): array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->select(['pid', 'language', 'broken_count'], self::STATUS_TABLE, ['table_name' => $table, 'record_uid' => $uid])
            ->fetchAssociative();
        self::assertIsArray($row, 'No status row for ' . $table . ':' . $uid);
        return $this->normalize($row);
    }

    /**
     * Integer columns come back as strings from some database drivers.
     */
    private function normalize(array|false $row): array
    {
        self::assertIsArray($row);
        return array_map(static fn(mixed $value): mixed => is_numeric($value) ? (int)$value : $value, $row);
    }
}
