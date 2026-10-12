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

use GeorgRinger\Linkvalidator2\Controller\CheckController;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;
use TYPO3Tests\TestLinkHandler\TestCacheableLinkChecker;
use TYPO3Tests\TestLinkHandler\TestLinkChecker;

/**
 * "Check this link again" in the report
 */
final class RecheckTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';

    protected array $testExtensionsToLoad = [
        'georgringer/linkvalidator2',
        __DIR__ . '/../Fixtures/Extensions/test_link_handler',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_cached_link.csv');
        TestCacheableLinkChecker::$checks = 0;
        TestCacheableLinkChecker::$working = false;
        TestLinkChecker::$repaired = false;
        $this->setUpBackendUser(1);
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 31);
    }

    protected function tearDown(): void
    {
        // Static state of the test checkers must not reach other tests
        TestCacheableLinkChecker::$working = false;
        TestLinkChecker::$repaired = false;
        parent::tearDown();
    }

    #[Test]
    public function aStoredResultIsReusedWithoutRecheck(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 31);

        self::assertSame(1, TestCacheableLinkChecker::$checks);
    }

    #[Test]
    public function recheckChecksTheTargetAgainDespiteTheStoredResult(): void
    {
        $response = $this->recheck($this->getResultUid(31));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['status' => 'broken'], $this->decode($response));
        self::assertSame(2, TestCacheableLinkChecker::$checks);
    }

    #[Test]
    public function theResultOfAnExternalTargetIsAppliedToAllRecordsThatLinkToIt(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 32);
        TestCacheableLinkChecker::$working = true;

        $response = $this->recheck($this->getResultUid(31));

        self::assertSame(['status' => 'ok'], $this->decode($response));
        self::assertSame(0, $this->countResults(31));
        self::assertSame(0, $this->countResults(32), 'Same target');
        self::assertSame(0, $this->getBrokenCount(32));
        self::assertSame(0, $this->countPriorityMessages(), 'Nothing is left for the queue');
    }

    #[Test]
    public function aTargetThatIsStillBrokenKeepsTheResultsOfOtherRecords(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 32);

        $this->recheck($this->getResultUid(31));

        self::assertSame(1, $this->countResults(32));
        self::assertSame(1, $this->getBrokenCount(32));
    }

    #[Test]
    public function theResultOfAnInternalTargetIsAppliedToAllRecordsThatLinkToIt(): void
    {
        $recordCheckService = $this->get(RecordCheckService::class);
        $recordCheckService->checkRecord('tt_content', 33);
        $recordCheckService->checkRecord('tt_content', 34);
        TestLinkChecker::$repaired = true;

        $this->recheck($this->getResultUid(33));

        self::assertSame(0, $this->countResults(33));
        self::assertSame(0, $this->countResults(34), 'Same target');
        self::assertSame(0, $this->getBrokenCount(34));
        self::assertSame(0, $this->countPriorityMessages());
    }

    #[Test]
    public function everyTypeOfLinkCanBeChecked(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_custom_link_handler.csv');
        $recordCheckService = $this->get(RecordCheckService::class);
        // Page links, files, folders, an unknown handler, a checker of an extension, one with cached results
        foreach ([10, 30, 33, 35] as $uid) {
            $recordCheckService->checkRecord('tt_content', $uid, true);
        }
        $connection = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE);
        $results = $connection->select(['record_uid', 'field', 'target'], self::BROKEN_LINK_TABLE)->fetchAllAssociative();
        self::assertGreaterThan(8, count($results));
        foreach ($results as $result) {
            // Checking a record replaces its results, the uid of a row is only valid until then
            $uid = $connection->select(['uid'], self::BROKEN_LINK_TABLE, $result)->fetchOne();
            if ($uid === false) {
                continue;
            }
            $response = $this->recheck((int)$uid);
            self::assertSame(200, $response->getStatusCode(), 'Result of record ' . $result['record_uid'] . ', field ' . $result['field']);
            self::assertContains($this->decode($response)['status'], ['broken', 'unknown', 'info', 'ok']);
        }
        $types = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target')
            ->select(['link_type'], 'tx_linkvalidator2_target')
            ->fetchFirstColumn();
        foreach (['page', 'file', 'folder', 'invalid', 'checked'] as $type) {
            self::assertContains($type, $types, 'Type ' . $type . ' was part of the test');
        }
    }

    #[Test]
    public function aLinkThatIsGoneFromTheRecordIsReportedAsGoneAndItsResultRemoved(): void
    {
        $uid = $this->getResultUid(31);
        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['bodytext' => '<p>No link</p>'], ['uid' => 31]);

        $response = $this->recheck($uid);

        self::assertSame(['status' => 'gone'], $this->decode($response));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)->count('*', self::BROKEN_LINK_TABLE, ['record_uid' => 31]));
    }

    #[Test]
    public function aResultIsFoundAlthoughTheRecordWasCheckedAgainSinceTheReportWasLoaded(): void
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['uid', 'table_name', 'record_uid', 'field', 'target'], self::BROKEN_LINK_TABLE, ['record_uid' => 31])
            ->fetchAssociative();
        // A worker or an editor checks the record: its results are replaced and get new uids
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 31);

        $response = $this->recheckByKey($row);

        self::assertNotSame($row['uid'], $this->getResultUid(31));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function anUnknownResultIsNotFound(): void
    {
        self::assertSame(404, $this->recheck(4711)->getStatusCode());
    }

    #[Test]
    public function recheckIsDeniedWithoutModuleAccess(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(403, $this->recheck($this->getResultUid(31))->getStatusCode());
    }

    #[Test]
    public function recheckIsDeniedWhenManualChecksAreDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['linkvalidator2']['allowManualCheck'] = '0';

        self::assertSame(403, $this->recheck($this->getResultUid(31))->getStatusCode());
        self::assertSame(1, TestCacheableLinkChecker::$checks);
    }

    /**
     * @param int $uid Uid of the result; the request carries what identifies it, as the report does
     */
    private function recheck(int $uid): ResponseInterface
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['table_name', 'record_uid', 'field', 'target'], self::BROKEN_LINK_TABLE, ['uid' => $uid])
            ->fetchAssociative();
        return $this->recheckByKey($row === false ? ['table_name' => 'tt_content', 'record_uid' => 0, 'field' => 'bodytext', 'target' => 0] : $row);
    }

    /**
     * @param array{table_name: string, record_uid: int, field: string, target: int} $row
     */
    private function recheckByKey(array $row): ResponseInterface
    {
        return $this->get(CheckController::class)->recheckAction(
            (new ServerRequest('https://typo3-testing.local/', 'POST'))->withParsedBody([
                'table' => $row['table_name'],
                'record' => $row['record_uid'],
                'field' => $row['field'],
                'target' => $row['target'],
            ])
        );
    }

    private function countResults(int $contentUid): int
    {
        return $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->count('*', self::BROKEN_LINK_TABLE, ['table_name' => 'tt_content', 'record_uid' => $contentUid]);
    }

    private function getBrokenCount(int $contentUid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->select(['broken_count'], 'tx_linkvalidator2_record_status', ['table_name' => 'tt_content', 'record_uid' => $contentUid])
            ->fetchOne();
    }

    private function countPriorityMessages(): int
    {
        return $this->getConnectionPool()->getConnectionForTable('sys_messenger_messages')
            ->count('*', 'sys_messenger_messages', ['queue_name' => 'linkvalidator2_priority']);
    }

    private function getResultUid(int $contentUid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['uid'], self::BROKEN_LINK_TABLE, ['table_name' => 'tt_content', 'record_uid' => $contentUid])
            ->fetchOne();
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
