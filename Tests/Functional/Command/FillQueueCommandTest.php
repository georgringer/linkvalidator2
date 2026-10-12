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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Command;

use GeorgRinger\Linkvalidator2\Command\FillQueueCommand;
use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;
use GeorgRinger\Linkvalidator2\MessageHandler\CheckRecordsHandler;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The queue is the messenger transport: the command only queues what a page tree holds.
 */
final class FillQueueCommandTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';
    private const MESSAGE_TABLE = 'sys_messenger_messages';
    /** Live pages 1–3, 5–11 and content 10–14 of the fixtures */
    private const RECORD_COUNT = 15;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function allRecordsOfTheTreeAreQueuedAndNothingIsCheckedYet(): void
    {
        $output = $this->fill('1');

        self::assertSame(self::RECORD_COUNT, $this->countQueuedUids());
        self::assertSame(0, $this->countStatusRows());
        // The translated page 10 belongs to page 2 and is not walked as a page of its own
        self::assertStringContainsString('Queued 15 records of 9 pages', $output);
    }

    #[Test]
    public function recordsAreGroupedByTable(): void
    {
        $this->fill('1');

        $tables = array_map(static fn(CheckRecordsMessage $message): string => $message->table, $this->getQueuedMessages());
        sort($tables);
        self::assertSame(['pages', 'tt_content'], $tables);
    }

    #[Test]
    public function chunkSizeLimitsTheRecordsPerMessage(): void
    {
        $this->fill('1', ['--chunk-size' => '2']);

        $messages = $this->getQueuedMessages();
        self::assertCount(8, $messages);
        foreach ($messages as $message) {
            self::assertLessThanOrEqual(2, count($message->uids));
        }
        self::assertSame(self::RECORD_COUNT, $this->countQueuedUids());
    }

    #[Test]
    public function depthZeroQueuesOnlyThePageItselfAndItsContent(): void
    {
        $this->fill('1', ['--depth' => '0']);

        // Page 1 and its five content elements
        self::assertSame(6, $this->countQueuedUids());
    }

    #[Test]
    public function aSubtreeCanBeQueuedAlone(): void
    {
        $this->fill('6');

        // Folder 6 and the restricted page 11 below it
        self::assertSame(2, $this->countQueuedUids());
    }

    #[Test]
    public function queuedRecordsAreCheckedByTheHandler(): void
    {
        $this->fill('1');

        foreach ($this->getQueuedMessages() as $message) {
            $this->get(CheckRecordsHandler::class)($message);
        }

        self::assertSame(self::RECORD_COUNT, $this->countStatusRows());
    }

    #[Test]
    public function runtimeCachesAreFlushedBeforeEveryMessage(): void
    {
        // A worker closes the database connection after each message: prepared statements kept in
        // the runtime cache (e.g. by BackendUtility::BEgetRootLine()) would fail with "server has gone away"
        $runtimeCache = $this->get(CacheManager::class)->getCache('runtime');
        $runtimeCache->set('linkvalidator2-test', 'stale');

        $this->get(CheckRecordsHandler::class)(new CheckRecordsMessage('tt_content', [10]));

        self::assertFalse($runtimeCache->has('linkvalidator2-test'));
    }

    #[Test]
    public function resultsOfRecordsThatVanishedFromAPageAreRemoved(): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)->insert(self::STATUS_TABLE, [
            'table_name' => 'tt_content',
            'record_uid' => 999,
            'pid' => 2,
            'last_checked' => 1,
        ]);

        $this->fill('1');

        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->count('*', self::STATUS_TABLE, ['record_uid' => 999]));
    }

    #[Test]
    public function aPageWithTwentyThousandRecordsIsSplitIntoMessagesWithoutLosingAnyRecord(): void
    {
        $this->insertContentElements(2, 20000);

        $this->fill('2', ['--depth' => '0', '--chunk-size' => '25']);

        $uids = [];
        foreach ($this->getQueuedMessages() as $message) {
            self::assertLessThanOrEqual(25, count($message->uids));
            if ($message->table === 'tt_content') {
                array_push($uids, ...$message->uids);
            }
        }
        // The 20,000 new elements on top of the content element of the fixtures, each of them once
        self::assertCount(20000, array_unique($uids));
        self::assertGreaterThanOrEqual(800, count(array_filter($this->getQueuedMessages(), static fn(CheckRecordsMessage $message): bool => $message->table === 'tt_content')));
    }

    #[Test]
    public function missingPageFails(): void
    {
        $tester = new CommandTester($this->get(FillQueueCommand::class));

        $tester->execute(['page' => '4711']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame(0, $this->countQueuedUids());
    }

    private function insertContentElements(int $pid, int $count): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tt_content');
        for ($start = 1; $start <= $count; $start += 1000) {
            $rows = [];
            for ($i = $start; $i < $start + 1000 && $i <= $count; $i++) {
                $rows[] = [$pid, 'text', 'Bulk ' . $i];
            }
            $connection->bulkInsert('tt_content', $rows, ['pid', 'CType', 'header']);
        }
    }

    private function fill(string $page, array $options = []): string
    {
        $tester = new CommandTester($this->get(FillQueueCommand::class));
        $tester->execute(['page' => $page] + $options);
        $tester->assertCommandIsSuccessful();
        return $tester->getDisplay();
    }

    /**
     * @return list<CheckRecordsMessage>
     */
    private function getQueuedMessages(): array
    {
        $serializer = $this->get(PhpSerializer::class);
        $rows = $this->getConnectionPool()->getConnectionForTable(self::MESSAGE_TABLE)
            ->select(['body', 'headers'], self::MESSAGE_TABLE)
            ->fetchAllAssociative();
        return array_map(
            static fn(array $row): CheckRecordsMessage => $serializer->decode([
                'body' => $row['body'],
                'headers' => json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR),
            ])->getMessage(),
            $rows
        );
    }

    private function countQueuedUids(): int
    {
        return array_sum(array_map(static fn(CheckRecordsMessage $message): int => count($message->uids), $this->getQueuedMessages()));
    }

    private function countStatusRows(): int
    {
        return $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)->count('*', self::STATUS_TABLE, []);
    }
}
