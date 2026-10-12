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
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use GeorgRinger\Linkvalidator2\Report\ReportPermissions;

final readonly class BrokenLinkRepository
{
    private const TABLE = 'tx_linkvalidator2_broken_link';
    /** Notices (status "info") are stored as well, but are no broken links */
    private const BROKEN_STATUSES = ['broken', 'unknown'];

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
        int $offset = 0,
        ?ReportPermissions $permissions = null,
    ): array {
        if ($pids === []) {
            return [];
        }
        $queryBuilder = $this->createFilteredQueryBuilder($pids, $filter, $permissions)->select('b.*', 't.link_type', 't.identifier');
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
    public function countByPids(array $pids, ReportFilter $filter = new ReportFilter(), ?ReportPermissions $permissions = null): int
    {
        if ($pids === []) {
            return 0;
        }
        return (int)$this->createFilteredQueryBuilder($pids, $filter, $permissions)
            ->count('b.uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * Number of broken links (not notices) per type of link
     *
     * @param list<int> $pids
     * @return array<string, int> By link type, only types with broken links
     */
    public function countBrokenGroupedByLinkType(array $pids, ?ReportPermissions $permissions = null): array
    {
        $counts = [];
        foreach (array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->createQueryBuilderForPids($chunk, $permissions);
            $rows = $queryBuilder
                ->select('t.link_type')
                ->addSelectLiteral('COUNT(b.uid) AS amount')
                ->andWhere($queryBuilder->expr()->in('b.status', $queryBuilder->createNamedParameter(self::BROKEN_STATUSES, Connection::PARAM_STR_ARRAY)))
                ->groupBy('t.link_type')
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $type = (string)$row['link_type'];
                $counts[$type] = ($counts[$type] ?? 0) + (int)$row['amount'];
            }
        }
        return $counts;
    }

    /**
     * Values present among the broken links of the given pages with their number of results, to offer
     * only filter options with results. Not narrowed by the other filters.
     *
     * @param list<int> $pids
     * @return array{status: array<string, int>, linkType: array<string, int>, table: array<string, int>, language: array<int, int>}
     */
    public function findFilterValues(array $pids, ?ReportPermissions $permissions = null): array
    {
        $values = ['status' => [], 'linkType' => [], 'table' => [], 'language' => []];
        if ($pids === []) {
            return $values;
        }
        $columns = ['status' => 'b.status', 'linkType' => 't.link_type', 'table' => 'b.table_name', 'language' => 'b.language'];
        foreach ($columns as $key => $column) {
            $rows = $this->createQueryBuilderForPids($pids, $permissions)
                ->select($column . ' AS value')
                ->addSelectLiteral('COUNT(b.uid) AS amount')
                ->groupBy($column)
                ->orderBy($column)
                ->executeQuery()
                ->fetchAllAssociative();
            foreach ($rows as $row) {
                $values[$key][$key === 'language' ? (int)$row['value'] : (string)$row['value']] = (int)$row['amount'];
            }
        }
        return $values;
    }

    /**
     * @param list<int> $pids
     */
    private function createFilteredQueryBuilder(array $pids, ReportFilter $filter, ?ReportPermissions $permissions): QueryBuilder
    {
        $queryBuilder = $this->createQueryBuilderForPids($pids, $permissions);
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
    /**
     * @param list<int> $pids
     */
    private function createQueryBuilderForPids(array $pids, ?ReportPermissions $permissions = null): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $queryBuilder
            ->from(self::TABLE, 'b')
            ->leftJoin('b', TargetRepository::TABLE, 't', $queryBuilder->expr()->eq('t.uid', $queryBuilder->quoteIdentifier('b.target')))
            ->where(
                $queryBuilder->expr()->in('b.pid', $queryBuilder->createNamedParameter($pids, Connection::PARAM_INT_ARRAY)),
                $queryBuilder->expr()->eq('b.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        if ($permissions !== null) {
            $this->applyPermissions($queryBuilder, $permissions);
        }
        return $queryBuilder;
    }

    /**
     * Only rows the user may fix. The page permission clauses refer to the table "pages", which is
     * joined under its own name.
     */
    private function applyPermissions(QueryBuilder $queryBuilder, ReportPermissions $permissions): void
    {
        if ($permissions->tables === []) {
            $queryBuilder->andWhere('1 = 0');
            return;
        }
        $expr = $queryBuilder->expr();
        $queryBuilder->join('b', 'pages', 'pages', $expr->eq('pages.uid', $queryBuilder->quoteIdentifier('b.pid')));
        $pagesTable = $queryBuilder->createNamedParameter('pages');
        $queryBuilder->andWhere($expr->or(
            $expr->and($expr->eq('b.table_name', $pagesTable), $permissions->pageEditClause),
            $expr->and($expr->neq('b.table_name', $pagesTable), $permissions->contentEditClause),
        ));
        if ($permissions->languages !== null) {
            // -1 ("all languages") is editable with any language permission, like in the core
            $queryBuilder->andWhere($expr->in(
                'b.language',
                $queryBuilder->createNamedParameter([...$permissions->languages, -1], Connection::PARAM_INT_ARRAY)
            ));
        }
        $tableConstraints = [];
        foreach ($permissions->tables as $table => ['fields' => $fields, 'authMode' => $authMode]) {
            $constraints = [
                $expr->eq('b.table_name', $queryBuilder->createNamedParameter($table)),
                $expr->in('b.field', $queryBuilder->createNamedParameter($fields, Connection::PARAM_STR_ARRAY)),
            ];
            foreach ($authMode as $field => $values) {
                $records = $this->connectionPool->getQueryBuilderForTable($table);
                $records->getRestrictions()->removeAll();
                $records->select('uid')->from($table)->where(
                    $records->expr()->in($field, $queryBuilder->createNamedParameter($values, Connection::PARAM_STR_ARRAY))
                );
                $constraints[] = $expr->in('b.record_uid', $records->getSQL());
            }
            $tableConstraints[] = $expr->and(...$constraints);
        }
        $queryBuilder->andWhere($expr->or(...$tableConstraints));
    }

    /**
     * Stored results below the pages whose link string contains the hint, for an exclusion to
     * decide on. Without a hint all results of the pages.
     *
     * @param list<int>|null $pids null for all pages
     * @return list<array<string, mixed>>
     */
    public function findForExclusion(?array $pids, ?string $hint): array
    {
        $rows = [];
        foreach ($pids === null ? [null] : array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->select('uid', 'table_name', 'record_uid', 'field', 'pid', 'language', 'link_string', 'link_text', 'status')->from(self::TABLE);
            if ($chunk !== null) {
                $queryBuilder->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            }
            if ($hint !== null) {
                $queryBuilder->andWhere($queryBuilder->expr()->comparison(
                    'LOWER(' . $queryBuilder->quoteIdentifier('link_string') . ')',
                    'LIKE',
                    $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards(mb_strtolower($hint)) . '%')
                ));
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * @param list<int> $uids
     */
    public function removeByUids(array $uids): void
    {
        foreach (array_chunk($uids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->delete(self::TABLE)
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeStatement();
        }
    }

    /**
     * A result the user may see in the report, found by what identifies it. The uid of a row is
     * not used: checking a record replaces its results, and the report may be older than that.
     *
     * @return array<string, mixed>|null The row with the identifier of its target
     */
    public function findOneVisible(string $table, int $recordUid, string $field, int $targetUid, ?ReportPermissions $permissions): ?array
    {
        $identity = ['table_name' => $table, 'record_uid' => $recordUid, 'field' => $field, 'target' => $targetUid, 'workspace' => 0];
        $pid = $this->connectionPool->getConnectionForTable(self::TABLE)->select(['pid'], self::TABLE, $identity)->fetchOne();
        if ($pid === false) {
            return null;
        }
        $queryBuilder = $this->createQueryBuilderForPids([(int)$pid], $permissions);
        $queryBuilder->select('b.*', 't.identifier');
        foreach ($identity as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.' . $column, $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)));
        }
        $row = $queryBuilder->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * The result of a target changed: every stored result for it takes the new status. A target
     * that is fine now has no results any more.
     *
     * @return array<string, array{table: string, uid: int, brokenDelta: int}> Records whose number of broken links changed, by "table:uid"
     */
    public function applyResultToTarget(int $targetUid, CheckResult $result): array
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $rows = $connection->select(['uid', 'table_name', 'record_uid', 'status'], self::TABLE, ['target' => $targetUid])->fetchAllAssociative();
        $newCounts = in_array($result->status->value, self::BROKEN_STATUSES, true) ? 1 : 0;
        $records = [];
        foreach ($rows as $row) {
            $delta = ($result->isOk() ? 0 : $newCounts) - (in_array($row['status'], self::BROKEN_STATUSES, true) ? 1 : 0);
            if ($delta !== 0) {
                $key = $row['table_name'] . ':' . $row['record_uid'];
                $records[$key] ??= ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid'], 'brokenDelta' => 0];
                $records[$key]['brokenDelta'] += $delta;
            }
        }
        if ($result->isOk()) {
            $connection->delete(self::TABLE, ['target' => $targetUid]);
        } else {
            $connection->update(self::TABLE, [
                'status' => $result->status->value,
                'error_type' => $result->errorType,
                'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
            ], ['target' => $targetUid]);
        }
        return $records;
    }

    public function removeForRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }

    public function countBrokenByPid(int $pid, ?ReportPermissions $permissions = null): int
    {
        $queryBuilder = $this->createQueryBuilderForPids([$pid], $permissions);
        return (int)$queryBuilder
            ->count('b.uid')
            ->andWhere($queryBuilder->expr()->in('b.status', $queryBuilder->createNamedParameter(self::BROKEN_STATUSES, Connection::PARAM_STR_ARRAY)))
            ->executeQuery()
            ->fetchOne();
    }

    public function countDetectedSince(int $pid, int $since, ?ReportPermissions $permissions = null): int
    {
        $queryBuilder = $this->createQueryBuilderForPids([$pid], $permissions);
        return (int)$queryBuilder
            ->count('b.uid')
            ->andWhere(
                $queryBuilder->expr()->gte('b.first_detected', $queryBuilder->createNamedParameter($since, Connection::PARAM_INT)),
                $queryBuilder->expr()->in('b.status', $queryBuilder->createNamedParameter(self::BROKEN_STATUSES, Connection::PARAM_STR_ARRAY))
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

    /**
     * @param list<int>|null $pids null for all pages
     * @return list<array{table: string, uid: int}>
     */
    public function findRecordsByPids(?array $pids): array
    {
        $records = [];
        foreach ($pids === null ? [null] : array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->select('table_name', 'record_uid')->from(self::TABLE)->groupBy('table_name', 'record_uid');
            if ($chunk !== null) {
                $queryBuilder->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $records[] = ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid']];
            }
        }
        return $records;
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
