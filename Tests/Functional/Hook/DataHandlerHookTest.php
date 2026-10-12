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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Hook;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Results follow changes made in the backend right away (step 4)
 */
final class DataHandlerHookTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';
    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';

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
    }

    #[Test]
    public function newRecordIsCheckedWhenSaved(): void
    {
        $dataHandler = $this->process(['tt_content' => ['NEW1' => [
            'pid' => 2,
            'CType' => 'text',
            'header' => 'New',
            'bodytext' => '<p><a href="t3://page?uid=3">hidden</a></p>',
        ]]]);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];

        self::assertSame(['page.hidden'], $this->getErrorTypes($uid));
    }

    #[Test]
    public function fixedLinkDisappearsWhenSaved(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $this->process(['tt_content' => [15 => ['bodytext' => '<p><a href="t3://page?uid=2">visible</a></p>']]]);

        self::assertSame([], $this->getErrorTypes(15));
        self::assertSame(0, $this->getStatus(15)['broken_count']);
    }

    /**
     * Forge #105911
     */
    #[Test]
    public function resultsDisappearWhenTheRecordIsHidden(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $this->process(['tt_content' => [15 => ['hidden' => 1]]]);

        self::assertSame([], $this->getErrorTypes(15));
    }

    #[Test]
    public function resultsDisappearWhenTheRecordIsDeleted(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $this->process([], ['tt_content' => [15 => ['delete' => 1]]]);

        self::assertSame([], $this->getErrorTypes(15));
        self::assertNull($this->getStatus(15));
    }

    /**
     * Forge #89426
     */
    #[Test]
    public function resultsOfRecordsDisappearWhenTheirPageIsDeleted(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $this->process([], ['pages' => [2 => ['delete' => 1]]]);

        self::assertSame([], $this->getErrorTypes(15));
        self::assertNull($this->getStatus(15));
    }

    #[Test]
    public function resultsMoveWithTheRecord(): void
    {
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        $this->process([], ['tt_content' => [15 => ['move' => 1]]]);

        $pids = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['pid'], self::BROKEN_LINK_TABLE, ['record_uid' => 15])
            ->fetchFirstColumn();
        self::assertSame([1, 1], array_map(intval(...), $pids));
        self::assertSame(1, $this->getStatus(15)['pid']);
    }

    #[Test]
    public function externalUrlIsNotRequestedButMarkedAsDue(): void
    {
        $dataHandler = $this->process(['tt_content' => ['NEW1' => [
            'pid' => 2,
            'CType' => 'text',
            'header' => 'External',
            'header_link' => 'https://lv2-test.invalid/page',
        ]]]);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];

        // Requesting the URL would have reported the .invalid host as broken
        self::assertSame([], $this->getErrorTypes($uid));
        self::assertSame(0, $this->getStatus($uid)['last_checked']);
    }

    /**
     * The target changes, not the record with the link: records referencing it become due
     */
    #[Test]
    public function recordsLinkingToAChangedPageBecomeDue(): void
    {
        $uid = $this->createContentLinkingTo(2);
        $otherUid = $this->createContentLinkingTo(1);

        $this->process(['pages' => [2 => ['hidden' => 1]]]);

        self::assertSame(0, $this->getStatus($uid)['last_checked']);
        self::assertGreaterThan(0, $this->getStatus($otherUid)['last_checked'], 'Not linking to the page');
    }

    #[Test]
    public function recordsLinkingToADeletedPageBecomeDue(): void
    {
        $uid = $this->createContentLinkingTo(2);

        $this->process([], ['pages' => [2 => ['delete' => 1]]]);

        self::assertSame(0, $this->getStatus($uid)['last_checked']);
    }

    #[Test]
    public function dueRecordReportsTheHiddenTargetWhenChecked(): void
    {
        $uid = $this->createContentLinkingTo(2);
        $this->process(['pages' => [2 => ['hidden' => 1]]]);
        // The queue checks in a request of its own
        $this->get(CacheManager::class)->getCache('runtime')->flush();

        $this->get(RecordCheckService::class)->checkRecord('tt_content', $uid);

        self::assertSame(['page.hidden'], $this->getErrorTypes($uid));
    }

    private function createContentLinkingTo(int $pageUid): int
    {
        $dataHandler = $this->process(['tt_content' => ['NEW1' => [
            'pid' => 1,
            'CType' => 'text',
            'header' => 'Link',
            'bodytext' => '<p><a href="t3://page?uid=' . $pageUid . '">target</a></p>',
        ]]]);
        $uid = (int)$dataHandler->substNEWwithIDs['NEW1'];
        self::assertSame([], $this->getErrorTypes($uid), 'Target is reachable at first');
        return $uid;
    }

    private function process(array $data, array $commands = []): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($data, $commands);
        $dataHandler->process_datamap();
        $dataHandler->process_cmdmap();
        self::assertSame([], $dataHandler->errorLog, 'DataHandler errors');
        return $dataHandler;
    }

    /**
     * @return list<string>
     */
    private function getErrorTypes(int $uid): array
    {
        $errorTypes = $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->select(['error_type'], self::BROKEN_LINK_TABLE, ['table_name' => 'tt_content', 'record_uid' => $uid, 'status' => 'broken'])
            ->fetchFirstColumn();
        sort($errorTypes);
        return $errorTypes;
    }

    /**
     * @return array{pid: int, broken_count: int, last_checked: int}|null
     */
    private function getStatus(int $uid): ?array
    {
        $row = $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->select(['pid', 'broken_count', 'last_checked'], self::STATUS_TABLE, ['table_name' => 'tt_content', 'record_uid' => $uid])
            ->fetchAssociative();
        return $row === false ? null : array_map(intval(...), $row);
    }
}
