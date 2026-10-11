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

namespace TYPO3\CMS\Linkvalidator2\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Finds the records that reference a record, from the reference index: the records whose
 * links may point to it. Only live records; the index has to be up to date.
 */
final readonly class ReferencingRecordFinder
{
    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<int> $uids
     * @return list<array{table: string, uid: int}>
     */
    public function find(string $table, array $uids): array
    {
        $records = [];
        foreach (array_chunk($uids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('sys_refindex');
            $rows = $queryBuilder
                ->select('tablename', 'recuid')
                ->from('sys_refindex')
                ->where(
                    $queryBuilder->expr()->eq('ref_table', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->in('ref_uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                )
                ->groupBy('tablename', 'recuid')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $records[] = ['table' => (string)$row['tablename'], 'uid' => (int)$row['recuid']];
            }
        }
        return $records;
    }
}
