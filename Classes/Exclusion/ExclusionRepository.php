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

namespace TYPO3\CMS\Linkvalidator2\Exclusion;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Finds the exclusions that apply to records on a page: the ones on the page itself,
 * on its ancestors and on the root level. Hidden, scheduled and expired exclusions do not
 * apply. Results are kept per page for the request; reset() after changing exclusions.
 */
final class ExclusionRepository
{
    public const TABLE = 'tx_linkvalidator2_exclusion';

    /** @var array<int, list<Exclusion>> */
    private array $cache = [];

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    /**
     * @return list<Exclusion>
     */
    public function findForPid(int $pid): array
    {
        return $this->cache[$pid] ??= $this->findByPids($this->getRootlinePids($pid));
    }

    public function reset(): void
    {
        $this->cache = [];
    }

    /**
     * @param list<int> $pids
     * @return list<Exclusion>
     */
    private function findByPids(array $pids): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)))
            ->executeQuery()
            ->fetchAllAssociative();
        return array_map(Exclusion::fromRow(...), $rows);
    }

    /**
     * @return list<int>
     */
    private function getRootlinePids(int $pid): array
    {
        $pids = [0];
        if ($pid > 0) {
            foreach (BackendUtility::BEgetRootLine($pid) as $page) {
                $pids[] = (int)$page['uid'];
            }
        }
        return array_values(array_unique($pids));
    }
}
