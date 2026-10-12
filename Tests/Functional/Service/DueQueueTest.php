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

use GeorgRinger\Linkvalidator2\Message\CheckDueRecordsMessage;
use GeorgRinger\Linkvalidator2\MessageHandler\CheckDueRecordsHandler;
use GeorgRinger\Linkvalidator2\Service\DueQueue;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Records that became due are queued on a transport of their own, which a worker takes first.
 */
final class DueQueueTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';
    private const MESSAGE_TABLE = 'sys_messenger_messages';
    private const PRIORITY_QUEUE = 'linkvalidator2_priority';

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function recordsAreMarkedDueAndQueuedWithPriority(): void
    {
        $this->insertStatus(10, 1700000000);

        $this->get(DueQueue::class)->queue([['table' => 'tt_content', 'uid' => 10]]);

        self::assertSame(0, $this->getLastChecked(10));
        $messages = $this->getQueuedMessages();
        self::assertCount(1, $messages);
        self::assertSame(['tt_content', [10]], [$messages[0]->table, $messages[0]->uids]);
    }

    #[Test]
    public function messagesAreSplitIntoChunksPerTable(): void
    {
        $records = array_map(static fn(int $uid): array => ['table' => 'tt_content', 'uid' => $uid], range(1, 30));
        $records[] = ['table' => 'pages', 'uid' => 2];

        $this->get(DueQueue::class)->queue($records);

        $sizes = array_map(static fn(CheckDueRecordsMessage $message): string => $message->table . ':' . count($message->uids), $this->getQueuedMessages());
        sort($sizes);
        self::assertSame(['pages:1', 'tt_content:25', 'tt_content:5'], $sizes);
    }

    #[Test]
    public function aRecordQueuedTwiceIsQueuedOncePerCall(): void
    {
        $this->get(DueQueue::class)->queue([['table' => 'tt_content', 'uid' => 10], ['table' => 'tt_content', 'uid' => 10]]);

        self::assertSame([10], $this->getQueuedMessages()[0]->uids);
    }

    #[Test]
    public function nothingIsQueuedForNoRecords(): void
    {
        $this->get(DueQueue::class)->queue([]);

        self::assertSame([], $this->getQueuedMessages());
    }

    #[Test]
    public function theHandlerChecksTheQueuedRecords(): void
    {
        $this->get(DueQueue::class)->queue([['table' => 'tt_content', 'uid' => 10], ['table' => 'tt_content', 'uid' => 13]]);

        foreach ($this->getQueuedMessages() as $message) {
            $this->get(CheckDueRecordsHandler::class)($message);
        }

        self::assertGreaterThan(0, $this->getLastChecked(10));
        self::assertGreaterThan(0, $this->getLastChecked(13));
    }

    private function insertStatus(int $uid, int $lastChecked): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)->insert(self::STATUS_TABLE, [
            'table_name' => 'tt_content',
            'record_uid' => $uid,
            'pid' => 1,
            'last_checked' => $lastChecked,
        ]);
    }

    private function getLastChecked(int $uid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->select(['last_checked'], self::STATUS_TABLE, ['table_name' => 'tt_content', 'record_uid' => $uid])
            ->fetchOne();
    }

    /**
     * @return list<CheckDueRecordsMessage>
     */
    private function getQueuedMessages(): array
    {
        $serializer = $this->get(PhpSerializer::class);
        $rows = $this->getConnectionPool()->getConnectionForTable(self::MESSAGE_TABLE)
            ->select(['body', 'headers'], self::MESSAGE_TABLE, ['queue_name' => self::PRIORITY_QUEUE])
            ->fetchAllAssociative();
        return array_map(
            static fn(array $row): CheckDueRecordsMessage => $serializer->decode([
                'body' => $row['body'],
                'headers' => json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR),
            ])->getMessage(),
            $rows
        );
    }
}
