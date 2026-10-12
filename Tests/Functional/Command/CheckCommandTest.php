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
use GeorgRinger\Linkvalidator2\Command\CheckCommand;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CheckCommandTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';
    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
    }

    #[Test]
    public function checkingAPageStoresBrokenLinksOfItsRecords(): void
    {
        $this->checkPage(1);

        self::assertSame(7, $this->countRows(self::BROKEN_LINK_TABLE, 'tt_content', 10));
        self::assertSame(1, $this->countRows(self::BROKEN_LINK_TABLE, 'tt_content', 14));
    }

    /**
     * Forge #89426
     */
    #[Test]
    public function resultsOfADeletedRecordDisappearWhenItsPageIsCheckedAgain(): void
    {
        $this->checkPage(1);
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->update('tt_content', ['deleted' => 1], ['uid' => 10]);

        $this->checkPage(1);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE, 'tt_content', 10));
        self::assertSame(0, $this->countRows(self::STATUS_TABLE, 'tt_content', 10));
        self::assertSame(1, $this->countRows(self::BROKEN_LINK_TABLE, 'tt_content', 14), 'Other records keep their results');
    }

    /**
     * Forge #89426
     */
    #[Test]
    public function resultsOfAMovedRecordDisappearFromItsFormerPage(): void
    {
        $this->checkPage(1);
        $this->getConnectionPool()->getConnectionForTable('tt_content')
            ->update('tt_content', ['pid' => 2], ['uid' => 10]);

        $this->checkPage(1);

        self::assertSame(0, $this->countRows(self::BROKEN_LINK_TABLE, 'tt_content', 10));
        self::assertSame(0, $this->countRows(self::STATUS_TABLE, 'tt_content', 10));
    }

    #[Test]
    public function resultsOfADeletedPageTranslationDisappearWhenThePageIsCheckedAgain(): void
    {
        $this->checkPage(2);
        self::assertSame(1, $this->countRows(self::STATUS_TABLE, 'pages', 10));
        $this->getConnectionPool()->getConnectionForTable('pages')
            ->update('pages', ['deleted' => 1], ['uid' => 10]);

        $this->checkPage(2);

        self::assertSame(0, $this->countRows(self::STATUS_TABLE, 'pages', 10));
    }

    private function checkPage(int $pageUid): void
    {
        $tester = new CommandTester($this->get(CheckCommand::class));
        $tester->execute(['--page' => (string)$pageUid, '--no-external' => true]);
        $tester->assertCommandIsSuccessful();
    }

    private function countRows(string $table, string $recordTable, int $recordUid): int
    {
        return $this->getConnectionPool()->getConnectionForTable($table)
            ->count('*', $table, ['table_name' => $recordTable, 'record_uid' => $recordUid]);
    }
}
