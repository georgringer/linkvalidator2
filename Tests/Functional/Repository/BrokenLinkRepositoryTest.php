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
namespace GeorgRinger\Linkvalidator2\Tests\Functional\Repository;

use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkGroup;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkQuery;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BrokenLinkRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    #[Test]
    public function aReportOverMoreThanOneHundredThousandPagesDoesNotExceedTheLimitOfPlaceholders(): void
    {
        $this->insertRows([['broken', 5, 100], ['broken', 90000, 100]]);
        $repository = $this->get(BrokenLinkRepository::class);
        $pids = range(1, 100000);

        $query = new BrokenLinkQuery($pids);
        self::assertSame(2, $repository->count($query));
        self::assertCount(2, $repository->find($query, new ReportOrdering()));
        self::assertSame(['broken' => 2], $repository->countGroupedBy($query, BrokenLinkGroup::Status));
    }

    #[Test]
    public function onlyBrokenLeavesOutNotices(): void
    {
        $this->insertRows([['broken', 1, 100], ['unknown', 1, 100], ['info', 1, 100]]);
        $repository = $this->get(BrokenLinkRepository::class);

        self::assertSame(3, $repository->count(new BrokenLinkQuery([1])));
        self::assertSame(2, $repository->count(new BrokenLinkQuery([1], onlyBroken: true)));
        self::assertSame(1, $repository->count(new BrokenLinkQuery([1], new ReportFilter('info'))));
    }

    #[Test]
    public function detectedSinceCountsOnlyBrokenLinksFirstDetectedFromThen(): void
    {
        $this->insertRows([['broken', 1, 100], ['broken', 1, 200], ['info', 1, 200], ['broken', 2, 200]]);
        $repository = $this->get(BrokenLinkRepository::class);

        self::assertSame(1, $repository->count(new BrokenLinkQuery([1], onlyBroken: true, detectedSince: 150)));
        self::assertSame(2, $repository->count(new BrokenLinkQuery([1, 2], onlyBroken: true, detectedSince: 150)));
    }

    #[Test]
    public function groupedCountsReuseTheConditionsOfTheQuery(): void
    {
        $this->insertRows([['broken', 1, 100], ['broken', 1, 100], ['info', 1, 100], ['broken', 2, 100]]);
        $repository = $this->get(BrokenLinkRepository::class);

        self::assertSame(['broken' => 2, 'info' => 1], $repository->countGroupedBy(new BrokenLinkQuery([1]), BrokenLinkGroup::Status));
        self::assertSame(['tt_content' => 2], $repository->countGroupedBy(new BrokenLinkQuery([1], onlyBroken: true), BrokenLinkGroup::Table));
        self::assertSame([0 => 4], $repository->countGroupedBy(new BrokenLinkQuery([1, 2]), BrokenLinkGroup::Language));
    }

    /**
     * @param list<array{0: string, 1: int, 2: int}> $rows Status, pid, first_detected
     */
    private function insertRows(array $rows): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link');
        $targets = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target');
        foreach ($rows as $index => [$status, $pid, $firstDetected]) {
            $targets->insert('tx_linkvalidator2_target', ['link_type' => 'url', 'identifier' => 'url:' . $index, 'identifier_hash' => sha1((string)$index), 'status' => $status]);
            $connection->insert('tx_linkvalidator2_broken_link', [
                'target' => (int)$targets->lastInsertId(),
                'table_name' => 'tt_content',
                'record_uid' => $index + 1,
                'field' => 'bodytext',
                'pid' => $pid,
                'first_detected' => $firstDetected,
            ]);
        }
    }
}
