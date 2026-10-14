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

namespace GeorgRinger\Linkvalidator2\Tests\Functional;

use TYPO3\CMS\Core\Database\Connection;

/**
 * The status and the error of a result are stored with its target, so the stored results are read
 * with the target joined.
 */
trait BrokenLinkResultsTrait
{
    /**
     * @param list<string> $columns "b.<column>" of the broken link, "t.<column>" of its target
     * @param array<string, int|string> $where columns of the broken link
     * @return list<array<string, mixed>>
     */
    private function selectBrokenLinks(array $columns, array $where, string $orderBy = 'b.uid'): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable('tx_linkvalidator2_broken_link');
        $queryBuilder
            ->select(...$columns)
            ->from('tx_linkvalidator2_broken_link', 'b')
            ->leftJoin('b', 'tx_linkvalidator2_target', 't', 't.uid = b.target')
            ->orderBy($orderBy);
        foreach ($where as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.' . $column, $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)));
        }
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @return list<string>
     */
    private function selectErrorTypes(array $where, bool $onlyBroken = false): array
    {
        $errorTypes = [];
        foreach ($this->selectBrokenLinks(['t.error_type', 't.status'], $where) as $row) {
            if (!$onlyBroken || in_array($row['status'], ['broken', 'unknown'], true)) {
                $errorTypes[] = $row['error_type'];
            }
        }
        sort($errorTypes);
        return $errorTypes;
    }

    private function countBrokenResults(string $table, int $uid): int
    {
        return count($this->selectErrorTypes(['table_name' => $table, 'record_uid' => $uid], true));
    }
}
