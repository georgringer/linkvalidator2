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

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Context\Context;
use GeorgRinger\Linkvalidator2\Command\CheckCommand;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Background checking without --record/--page: the record status table is the queue.
 */
final class CheckCommandQueueTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';
    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';
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
    public function limitRestrictsTheNumberOfCheckedRecords(): void
    {
        $output = $this->runQueue(['--limit' => '3']);

        self::assertSame(3, $this->countStatusRows());
        self::assertStringContainsString('Checked 3 records', $output);
    }

    #[Test]
    public function runChecksEveryRecordOnceAndEnds(): void
    {
        $this->runQueue();

        self::assertSame(self::RECORD_COUNT, $this->countStatusRows());
        self::assertSame(7, $this->countBrokenLinks('tt_content', 10));
    }

    #[Test]
    public function recordsCheckedWithinTheIntervalAreSkipped(): void
    {
        $this->runQueue();

        $output = $this->runQueue();

        self::assertStringContainsString('Checked 0 records', $output);
    }

    #[Test]
    public function dueRecordsAreCheckedWithinTheInterval(): void
    {
        $this->runQueue();
        $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->update(self::STATUS_TABLE, ['last_checked' => 0], ['table_name' => 'tt_content', 'record_uid' => 10]);

        $output = $this->runQueue();

        self::assertStringContainsString('Checked 1 records', $output);
        self::assertGreaterThan(0, $this->getLastChecked('tt_content', 10));
    }

    #[Test]
    public function recordsCheckedLongerAgoThanTheIntervalAreCheckedOldestFirst(): void
    {
        $this->runQueue();
        $now = (int)$this->get(Context::class)->getPropertyFromAspect('date', 'timestamp');
        $connection = $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE);
        $connection->update(self::STATUS_TABLE, ['last_checked' => $now - 3 * 86400], ['table_name' => 'tt_content', 'record_uid' => 13]);
        $connection->update(self::STATUS_TABLE, ['last_checked' => $now - 2 * 86400], ['table_name' => 'tt_content', 'record_uid' => 12]);

        $output = $this->runQueue(['--limit' => '1']);

        self::assertStringContainsString('Checked 1 records', $output);
        self::assertGreaterThanOrEqual($now, $this->getLastChecked('tt_content', 13));
        self::assertSame($now - 2 * 86400, $this->getLastChecked('tt_content', 12));
    }

    #[Test]
    public function resultsOfPagesThatNoLongerExistAreRemoved(): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)->insert(self::STATUS_TABLE, [
            'table_name' => 'tt_content',
            'record_uid' => 999,
            'pid' => 4711,
            'last_checked' => 1,
        ]);

        $this->runQueue(['--limit' => '1']);

        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->count('*', self::STATUS_TABLE, ['record_uid' => 999]));
    }

    private function runQueue(array $options = []): string
    {
        $tester = new CommandTester($this->get(CheckCommand::class));
        $tester->execute($options + ['--no-external' => true]);
        $tester->assertCommandIsSuccessful();
        return $tester->getDisplay();
    }

    private function countStatusRows(): int
    {
        return $this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)->count('*', self::STATUS_TABLE, []);
    }

    private function countBrokenLinks(string $table, int $uid): int
    {
        return $this->getConnectionPool()->getConnectionForTable(self::BROKEN_LINK_TABLE)
            ->count('*', self::BROKEN_LINK_TABLE, ['table_name' => $table, 'record_uid' => $uid]);
    }

    private function getLastChecked(string $table, int $uid): int
    {
        return (int)$this->getConnectionPool()->getConnectionForTable(self::STATUS_TABLE)
            ->select(['last_checked'], self::STATUS_TABLE, ['table_name' => $table, 'record_uid' => $uid])
            ->fetchOne();
    }
}
