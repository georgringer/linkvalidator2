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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Exclusion;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Results follow changes of exclusions in the backend: newly excluded links disappear
 * right away, records that may have lost an exclusion become due.
 */
final class ExclusionChangeTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const EXCLUSION_TABLE = 'tx_linkvalidator2_exclusion';

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');
        $this->writeSiteConfiguration();
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $recordCheckService = $this->get(RecordCheckService::class);
        $recordCheckService->checkRecord('tt_content', 10);
        $recordCheckService->checkRecord('tt_content', 15);
    }

    #[Test]
    public function newExclusionRemovesReportedLinksInItsSubtreeRightAway(): void
    {
        $this->process([self::EXCLUSION_TABLE => ['NEW1' => ['pid' => 2, 'type' => 'page', 'target_page' => 3]]]);

        self::assertContains('page:3', $this->getReportedTargets(10), 'Page 1 is outside of the subtree');
        self::assertSame(['page:11'], $this->getReportedTargets(15));
        self::assertGreaterThan(0, $this->getLastChecked(15));
    }

    #[Test]
    public function newExclusionAdjustsTheCountersOfTheRecordWithoutCheckingIt(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->update('tx_linkvalidator2_record_status', ['last_checked' => 12345], ['table_name' => 'tt_content', 'record_uid' => 15]);

        $this->process([self::EXCLUSION_TABLE => ['NEW1' => ['pid' => 2, 'type' => 'page', 'target_page' => 3]]]);

        $status = $this->getStatus(15);
        // The hidden page 3 was the only broken link, the notice for page 11 stays
        self::assertSame(0, (int)$status['broken_count']);
        self::assertSame(1, (int)$status['excluded_count']);
        self::assertSame(12345, (int)$status['last_checked'], 'The record is not checked again');
    }

    #[Test]
    public function exclusionWithoutMatchesLeavesTheResultsAlone(): void
    {
        $before = [$this->getReportedTargets(10), $this->getReportedTargets(15), $this->getStatus(10)];

        $this->process([self::EXCLUSION_TABLE => ['NEW1' => ['pid' => 1, 'type' => 'url', 'value' => 'no-match.example']]]);

        self::assertSame($before, [$this->getReportedTargets(10), $this->getReportedTargets(15), $this->getStatus(10)]);
    }

    #[Test]
    public function newUrlExclusionRemovesTheResultsOfItsDomainAndSubdomains(): void
    {
        $this->addExternalResult(10, 'https://example.org/page', 'urlA');
        $this->addExternalResult(10, 'http://blog.example.org/x#top', 'urlB');
        $this->addExternalResult(10, 'https://notexample.org/', 'urlC');
        $this->addExternalResult(10, 'https://example.com/', 'urlD');

        $this->process([self::EXCLUSION_TABLE => ['NEW1' => ['pid' => 1, 'type' => 'url', 'value' => 'Example.org']]]);

        $targets = $this->getReportedTargets(10);
        self::assertNotContains('url:https://example.org/page', $targets);
        self::assertNotContains('url:http://blog.example.org/x', $targets);
        self::assertContains('url:https://notexample.org/', $targets);
        self::assertContains('url:https://example.com/', $targets);
    }

    #[Test]
    public function deletedExclusionMakesRecordsOfItsSubtreeDue(): void
    {
        $uid = $this->addExclusion(2);

        $this->process([], [self::EXCLUSION_TABLE => [$uid => ['delete' => 1]]]);

        self::assertSame(0, $this->getLastChecked(15));
        self::assertGreaterThan(0, $this->getLastChecked(10), 'Page 1 is outside of the subtree');
        // Record 15 and page 2 itself, with priority
        self::assertGreaterThan(0, $this->getConnectionPool()->getConnectionForTable('sys_messenger_messages')
            ->count('*', 'sys_messenger_messages', ['queue_name' => 'linkvalidator2_priority']));
    }

    #[Test]
    public function hiddenExclusionMakesRecordsOfItsSubtreeDue(): void
    {
        $uid = $this->addExclusion(2);

        $this->process([self::EXCLUSION_TABLE => [$uid => ['hidden' => 1]]]);

        self::assertSame(0, $this->getLastChecked(15));
    }

    #[Test]
    public function movedExclusionAppliesToItsNewSubtreeAndLeavesTheOldOneDue(): void
    {
        $uid = $this->addExclusion(2);

        $this->process([], [self::EXCLUSION_TABLE => [$uid => ['move' => 6]]]);

        self::assertSame(0, $this->getLastChecked(15));

        $this->process([], [self::EXCLUSION_TABLE => [$uid => ['move' => 1]]]);

        self::assertNotContains('page:3', $this->getReportedTargets(10));
    }

    #[Test]
    public function exclusionOnTheRootLevelAppliesToAllRecords(): void
    {
        $this->process([self::EXCLUSION_TABLE => ['NEW1' => ['pid' => 0, 'type' => 'page', 'target_page' => 3]]]);

        self::assertNotContains('page:3', $this->getReportedTargets(10));
        self::assertSame(['page:11'], $this->getReportedTargets(15));
    }

    private function addExclusion(int $pid): int
    {
        $connection = $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE);
        $connection->insert(self::EXCLUSION_TABLE, ['pid' => $pid, 'type' => 'page', 'target_page' => 3]);
        return (int)$connection->lastInsertId();
    }

    private function process(array $data, array $commands = []): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler errors');
    }

    /**
     * @return array<string, mixed>
     */
    private function getStatus(int $contentUid): array
    {
        return $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->select(['broken_count', 'excluded_count', 'last_checked'], 'tx_linkvalidator2_record_status', ['table_name' => 'tt_content', 'record_uid' => $contentUid])
            ->fetchAssociative();
    }

    private function addExternalResult(int $contentUid, string $url, string $hash): void
    {
        $targets = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target');
        $targets->insert('tx_linkvalidator2_target', [
            'link_type' => 'url',
            'identifier' => 'url:' . explode('#', $url)[0],
            'identifier_hash' => sha1($hash),
            'status' => 'broken',
        ]);
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link')->insert('tx_linkvalidator2_broken_link', [
            'target' => (int)$targets->lastInsertId(),
            'table_name' => 'tt_content',
            'record_uid' => $contentUid,
            'field' => 'bodytext',
            'pid' => 1,
            'link_string' => $url,
            'status' => 'broken',
            'error_type' => 'url.notFound',
        ]);
    }

    private function getLastChecked(int $contentUid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->select(['last_checked'], 'tx_linkvalidator2_record_status', ['table_name' => 'tt_content', 'record_uid' => $contentUid])
            ->fetchOne();
    }

    /**
     * @return list<string>
     */
    private function getReportedTargets(int $contentUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_linkvalidator2_broken_link');
        return $queryBuilder
            ->select('t.identifier')
            ->from('tx_linkvalidator2_broken_link', 'b')
            ->join('b', 'tx_linkvalidator2_target', 't', 't.uid = b.target')
            ->where($queryBuilder->expr()->eq('b.record_uid', $contentUid), $queryBuilder->expr()->eq('b.table_name', $queryBuilder->quote('tt_content')))
            ->orderBy('b.uid')
            ->executeQuery()
            ->fetchFirstColumn();
    }
}
