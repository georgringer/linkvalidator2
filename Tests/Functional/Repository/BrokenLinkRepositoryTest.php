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

use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BrokenLinkRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    #[Test]
    public function aReportOverMoreThanOneHundredThousandPagesDoesNotExceedTheLimitOfPlaceholders(): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link');
        foreach ([5, 90000] as $pid) {
            $connection->insert('tx_linkvalidator2_broken_link', ['table_name' => 'tt_content', 'record_uid' => $pid, 'field' => 'bodytext', 'pid' => $pid, 'status' => 'broken']);
        }
        $repository = $this->get(BrokenLinkRepository::class);
        $pids = range(1, 100000);

        self::assertSame(2, $repository->countByPids($pids));
        self::assertCount(2, $repository->findByPids($pids));
        self::assertSame(['broken' => 2], $repository->findFilterValues($pids)['status']);
    }
}
