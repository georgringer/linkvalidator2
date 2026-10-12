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

use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageRecordFinderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
    }

    #[Test]
    public function pageIdsAreIteratedLevelByLevel(): void
    {
        $batches = iterator_to_array($this->get(PageRecordFinder::class)->iteratePageIdBatches(1, 999), false);

        // Deleted pages and translations of pages are not walked, hidden pages and folders are
        self::assertSame([[1], [2, 3, 5, 6, 7, 8, 9], [11]], array_map($this->sorted(...), $batches));
    }

    #[Test]
    public function depthLimitsTheLevels(): void
    {
        $finder = $this->get(PageRecordFinder::class);

        self::assertSame([[1]], iterator_to_array($finder->iteratePageIdBatches(1, 0), false));
        self::assertSame([[1], [2, 3, 5, 6, 7, 8, 9]], array_map($this->sorted(...), iterator_to_array($finder->iteratePageIdBatches(1, 1), false)));
    }

    #[Test]
    public function batchSizeSplitsALevel(): void
    {
        $batches = iterator_to_array($this->get(PageRecordFinder::class)->iteratePageIdBatches(1, 1, 3), false);

        self::assertSame([1, 3, 3, 1], array_map(count(...), $batches));
    }

    #[Test]
    public function aLevelWithMoreThanOneThousandPagesIsWalkedCompletely(): void
    {
        // 2,500 subpages of page 2, one subpage each: the parents are asked for in chunks of 1,000
        $connection = $this->getConnectionPool()->getConnectionForTable('pages');
        for ($start = 1; $start <= 2500; $start += 500) {
            $rows = [];
            for ($i = $start; $i < $start + 500; $i++) {
                $rows[] = [2, 'Child ' . $i, 1];
            }
            $connection->bulkInsert('pages', $rows, ['pid', 'title', 'doktype']);
        }
        $children = $connection->executeQuery('SELECT uid FROM pages WHERE title LIKE ?', ['Child %'])->fetchFirstColumn();
        foreach (array_chunk($children, 500) as $chunk) {
            $rows = array_map(static fn($uid): array => [(int)$uid, 'Grandchild of ' . $uid, 1], $chunk);
            $connection->bulkInsert('pages', $rows, ['pid', 'title', 'doktype']);
        }
        $finder = $this->get(PageRecordFinder::class);

        $batches = iterator_to_array($finder->iteratePageIdBatches(2, 2), false);
        $pageIds = array_merge(...$batches);

        // Page 2, 2,500 children and 2,500 grandchildren, each of them once, in batches of at most 200
        self::assertCount(5001, $pageIds);
        self::assertCount(5001, array_unique($pageIds));
        self::assertLessThanOrEqual(200, max(array_map(count(...), $batches)));
        self::assertSame($pageIds, $finder->findPageIds(2, 2));
    }

    #[Test]
    public function recordsOfSeveralPagesAreFoundWithTheirPage(): void
    {
        $found = $this->get(PageRecordFinder::class)->findOnPages([1, 2, 11]);

        self::assertSame([1, 2, 11], array_keys($found));
        self::assertContains(['table' => 'tt_content', 'uid' => 10], $found[1]);
        self::assertContains(['table' => 'pages', 'uid' => 1], $found[1]);
        // The translation of page 2 is found on page 2
        self::assertContains(['table' => 'pages', 'uid' => 10], $found[2]);
        self::assertSame([['table' => 'pages', 'uid' => 11]], $found[11]);
    }

    /**
     * @param list<int> $pageIds
     * @return list<int>
     */
    private function sorted(array $pageIds): array
    {
        sort($pageIds);
        return $pageIds;
    }
}
