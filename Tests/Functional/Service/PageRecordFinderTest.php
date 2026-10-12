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
