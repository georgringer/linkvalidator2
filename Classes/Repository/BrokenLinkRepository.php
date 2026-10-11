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
namespace TYPO3\CMS\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Report\ReportFilter;
use TYPO3\CMS\Linkvalidator2\Report\ReportOrdering;

final readonly class BrokenLinkRepository
{
    private const TABLE = 'tx_linkvalidator2_broken_link';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<array{candidate: LinkCandidate, targetUid: int, result: CheckResult}> $brokenLinks
     */
    public function replaceForRecord(string $table, int $recordUid, array $brokenLinks, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $firstDetected = [];
        $existingRows = $connection->select(['field', 'target', 'first_detected'], self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid])->fetchAllAssociative();
        foreach ($existingRows as $row) {
            $firstDetected[$row['field'] . '|' . $row['target']] = (int)$row['first_detected'];
        }
        $connection->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
        foreach ($brokenLinks as ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result]) {
            $connection->insert(self::TABLE, [
                'target' => $targetUid,
                'table_name' => $table,
                'record_uid' => $recordUid,
                'field' => $candidate->field,
                'pid' => $candidate->pid,
                'language' => $candidate->language,
                'workspace' => 0,
                'link_string' => $candidate->link,
                'link_text' => $candidate->linkText,
                'status' => $result->status->value,
                'error_type' => $result->errorType,
                'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
                'first_detected' => $firstDetected[$candidate->field . '|' . $targetUid] ?? $now,
                'last_seen' => $now,
            ]);
        }
    }

    /**
     * @param list<int> $pids
     * @return list<array<string, mixed>> rows of the broken link table plus "link_type" of the target
     */
    public function findByPids(
        array $pids,
        ReportFilter $filter = new ReportFilter(),
        ReportOrdering $ordering = new ReportOrdering(),
        int $limit = 0,
        int $offset = 0
    ): array {
        if ($pids === []) {
            return [];
        }
        $queryBuilder = $this->createFilteredQueryBuilder($pids, $filter)->select('b.*', 't.link_type');
        foreach ($ordering->getColumns() as $column) {
            $queryBuilder->addOrderBy('b.' . $column, $ordering->getDirection());
        }
        // Stable order within equal values, so pagination does not skip or repeat rows
        foreach (['pid', 'table_name', 'record_uid', 'field', 'uid'] as $column) {
            $queryBuilder->addOrderBy('b.' . $column);
        }
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit)->setFirstResult($offset);
        }
        return $queryBuilder->executeQuery()->fetchAllAssociative();
    }

    /**
     * @param list<int> $pids
     */
    public function countByPids(array $pids, ReportFilter $filter = new ReportFilter()): int
    {
        if ($pids === []) {
            return 0;
        }
        return (int)$this->createFilteredQueryBuilder($pids, $filter)
            ->count('b.uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Values present among the broken links of the given pages, to offer only filter options with results.
     *
     * @param list<int> $pids
     * @return array{status: list<string>, linkType: list<string>, table: list<string>, language: list<int>}
     */
    public function findFilterValues(array $pids): array
    {
        $values = ['status' => [], 'linkType' => [], 'table' => [], 'language' => []];
        if ($pids === []) {
            return $values;
        }
        $rows = $this->createQueryBuilderForPids($pids)
            ->select('b.status', 't.link_type', 'b.table_name', 'b.language')
            ->distinct()
            ->executeQuery()
            ->fetchAllAssociative();
        foreach ($rows as $row) {
            $values['status'][] = (string)$row['status'];
            $values['linkType'][] = (string)$row['link_type'];
            $values['table'][] = (string)$row['table_name'];
            $values['language'][] = (int)$row['language'];
        }
        foreach ($values as $key => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $values[$key] = $list;
        }
        return $values;
    }

    /**
     * @param list<int> $pids
     */
    private function createFilteredQueryBuilder(array $pids, ReportFilter $filter): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilderForPids($pids);
        if ($filter->status !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.status', $queryBuilder->createNamedParameter($filter->status)));
        }
        if ($filter->linkType !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('t.link_type', $queryBuilder->createNamedParameter($filter->linkType)));
        }
        if ($filter->table !== '') {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.table_name', $queryBuilder->createNamedParameter($filter->table)));
        }
        if ($filter->language !== null) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.language', $queryBuilder->createNamedParameter($filter->language, Connection::PARAM_INT)));
        }
        if ($filter->search !== '') {
            $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%');
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->like('b.link_string', $like),
                $queryBuilder->expr()->like('b.link_text', $like),
            ));
        }
        return $queryBuilder;
    }

    /**
     * @param list<int> $pids
     */
    private function createQueryBuilderForPids(array $pids): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return $queryBuilder
            ->from(self::TABLE, 'b')
            ->leftJoin('b', TargetRepository::TABLE, 't', $queryBuilder->expr()->eq('t.uid', $queryBuilder->quoteIdentifier('b.target')))
            ->where(
                $queryBuilder->expr()->in('b.pid', $queryBuilder->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('b.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
    }

    public function removeForRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }

    public function countDetectedSince(int $pid, int $since): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        return (int)$queryBuilder
            ->count('uid')
            ->from(self::TABLE)
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->gte('first_detected', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT))
            )
            ->executeQuery()
            ->fetchOne();
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
}
