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
namespace GeorgRinger\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final readonly class RecordStatusRepository
{
    private const TABLE = 'tx_linkvalidator2_record_status';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param int $excludedCount Links not checked because of exclusions
     */
    public function markChecked(string $table, int $recordUid, int $pid, int $language, int $brokenCount, int $now, int $excludedCount = 0): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identifier = ['table_name' => $table, 'record_uid' => $recordUid, 'workspace' => 0];
        $data = ['pid' => $pid, 'language' => $language, 'last_checked' => $now, 'broken_count' => $brokenCount, 'excluded_count' => $excludedCount];
        if ($connection->count('uid', self::TABLE, $identifier) > 0) {
            $connection->update(self::TABLE, $data, $identifier);
            return;
        }
        $connection->insert(self::TABLE, $identifier + $data);
    }

    /**
     * Results of the record were removed because of an exclusion: they are no broken links any
     * more and count as excluded. Links that were fine have no result, they are counted by the
     * next check of the record.
     */
    public function markExcluded(string $table, int $recordUid, int $removedBroken, int $removedTotal): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $row = $connection->select(['broken_count', 'excluded_count'], self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid, 'workspace' => 0])->fetchAssociative();
        if ($row === false) {
            return;
        }
        $connection->update(self::TABLE, [
            'broken_count' => max(0, (int)$row['broken_count'] - $removedBroken),
            'excluded_count' => (int)$row['excluded_count'] + $removedTotal,
        ], ['table_name' => $table, 'record_uid' => $recordUid, 'workspace' => 0]);
    }

    public function remove(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }

    /**
     * @return array<string, true> keyed by "table:uid"
     */
    public function findRecordsCheckedSince(int $pid, int $since): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $rows = $queryBuilder
            ->select('table_name', 'record_uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('last_checked', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchAllAssociative();
        $result = [];
        foreach ($rows as $row) {
            $result[$row['table_name'] . ':' . $row['record_uid']] = true;
        }
        return $result;
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    public function findRecordsByPid(int $pid): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['table_name', 'record_uid'], self::TABLE, ['pid' => $pid])
            ->fetchAllAssociative();
        return array_map(static fn(array $row): array => ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid']], $rows);
    }

    /**
     * @return list<int>
     */
    public function findPids(): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return array_map(intval(...), $queryBuilder->select('pid')->from(self::TABLE)->groupBy('pid')->executeQuery()->fetchFirstColumn());
    }

    /**
     * Links of the records on these pages not checked because of exclusions
     *
     * @param list<int> $pids
     */
    public function countExcluded(array $pids): int
    {
        $count = 0;
        foreach (array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $count += (int)$queryBuilder
                ->selectLiteral('SUM(' . $queryBuilder->quoteIdentifier('excluded_count') . ')')
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchOne();
        }
        return $count;
    }

    /**
     * Time of the latest check of a record on these pages, 0 if none was checked yet
     *
     * @param list<int> $pids
     */
    public function findLastChecked(array $pids): int
    {
        $lastChecked = 0;
        foreach (array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $lastChecked = max($lastChecked, (int)$queryBuilder
                ->selectLiteral('MAX(' . $queryBuilder->quoteIdentifier('last_checked') . ')')
                ->from(self::TABLE)
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchOne());
        }
        return $lastChecked;
    }

    /**
     * The queue checks these records next
     *
     * @param list<array{table: string, uid: int}> $records
     */
    public function markDue(array $records): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            $connection->update(self::TABLE, ['last_checked' => 0], ['table_name' => $table, 'record_uid' => $uid]);
        }
    }

    /**
     * The queue checks these records next
     *
     * @param list<int>|null $pids null for all pages
     */
    public function markDueByPids(?array $pids): void
    {
        foreach ($pids === null ? [null] : array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->update(self::TABLE)->set('last_checked', 0);
            if ($chunk !== null) {
                $queryBuilder->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            }
            $queryBuilder->executeStatement();
        }
    }

    /**
     * @param list<int> $pids
     */
    public function removeByPids(array $pids): void
    {
        foreach (array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->delete(self::TABLE)
                ->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }
}
