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
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
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
     * @param list<array{candidate: LinkCandidate, targetUid: int}> $brokenLinks
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
        foreach ($brokenLinks as ['candidate' => $candidate, 'targetUid' => $targetUid]) {
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
                'first_detected' => $firstDetected[$candidate->field . '|' . $targetUid] ?? $now,
                'last_seen' => $now,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>> rows of the broken link table plus "link_type", "identifier", "status", "error_type" and "error_details" of the target
     */
    public function find(BrokenLinkQuery $query, ReportOrdering $ordering = new ReportOrdering(), int $limit = 0, int $offset = 0): array
    {
        if ($query->pids === []) {
            return [];
        }
        $queryBuilder = $this->createQueryBuilder($query)->select('b.*', 't.link_type', 't.identifier', 't.status', 't.error_type', 't.error_details');
        foreach ($ordering->getColumns() as $column) {
            $queryBuilder->addOrderBy($column, $ordering->getDirection());
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

    public function count(BrokenLinkQuery $query): int
    {
        if ($query->pids === []) {
            return 0;
        }
        return (int)$this->createQueryBuilder($query)
            ->count('b.uid')
            ->executeQuery()
            ->fetchOne();
    }

    /**
     * @return array<string|int, int> Number of results by value, only values with results. The language is an integer.
     */
    public function countGroupedBy(BrokenLinkQuery $query, BrokenLinkGroup $group): array
    {
        if ($query->pids === []) {
            return [];
        }
        $column = $group->getColumn();
        $rows = $this->createQueryBuilder($query)
            ->select($column . ' AS value')
            ->addSelectLiteral('COUNT(b.uid) AS amount')
            ->groupBy($column)
            ->orderBy($column)
            ->executeQuery()
            ->fetchAllAssociative();
        $counts = [];
        foreach ($rows as $row) {
            $counts[$group === BrokenLinkGroup::Language ? (int)$row['value'] : (string)$row['value']] = (int)$row['amount'];
        }
        return $counts;
    }

    private function createQueryBuilder(BrokenLinkQuery $query): QueryBuilder
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $expr = $queryBuilder->expr();
        $queryBuilder
            ->from(self::TABLE, 'b')
            ->leftJoin('b', TargetRepository::TABLE, 't', $expr->eq('t.uid', $queryBuilder->quoteIdentifier('b.target')))
            ->where(
                // The integers are part of the SQL, not placeholders: a database allows about 65,000 of them in
                // a statement, and a report over a large page tree has more pages than that
                $expr->in('b.pid', array_map(intval(...), $query->pids)),
                $expr->eq('b.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
            );
        if ($query->permissions !== null) {
            $this->applyPermissions($queryBuilder, $query->permissions);
        }
        if ($query->onlyBroken) {
            $queryBuilder->andWhere($expr->in('t.status', $queryBuilder->createNamedParameter(self::BROKEN_STATUSES, Connection::PARAM_STR_ARRAY)));
        }
        if ($query->detectedSince !== null) {
            $queryBuilder->andWhere($expr->gte('b.first_detected', $queryBuilder->createNamedParameter($query->detectedSince, Connection::PARAM_INT)));
        }
        if ($query->detectedBefore !== null) {
            $queryBuilder->andWhere($expr->lte('b.first_detected', $queryBuilder->createNamedParameter($query->detectedBefore, Connection::PARAM_INT)));
        }
        if ($query->includeTables !== []) {
            $queryBuilder->andWhere($expr->in('b.table_name', $queryBuilder->createNamedParameter($query->includeTables, Connection::PARAM_STR_ARRAY)));
        }
        if ($query->excludeTables !== []) {
            $queryBuilder->andWhere($expr->notIn('b.table_name', $queryBuilder->createNamedParameter($query->excludeTables, Connection::PARAM_STR_ARRAY)));
        }
        $filter = $query->filter;
        if ($filter->status !== '') {
            $queryBuilder->andWhere($expr->eq('t.status', $queryBuilder->createNamedParameter($filter->status)));
        }
        if ($filter->linkType !== '') {
            $queryBuilder->andWhere($expr->eq('t.link_type', $queryBuilder->createNamedParameter($filter->linkType)));
        }
        if ($filter->table !== '') {
            $queryBuilder->andWhere($expr->eq('b.table_name', $queryBuilder->createNamedParameter($filter->table)));
        }
        if ($filter->language !== null) {
            $queryBuilder->andWhere($expr->eq('b.language', $queryBuilder->createNamedParameter($filter->language, Connection::PARAM_INT)));
        }
        if ($filter->search !== '') {
            $like = $queryBuilder->createNamedParameter('%' . $queryBuilder->escapeLikeWildcards($filter->search) . '%');
            $queryBuilder->andWhere($expr->or(
                $expr->like('b.link_string', $like),
                $expr->like('b.link_text', $like),
            ));
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
     * Stored results below the pages, for an exclusion to decide on.
     *
     * @param list<int>|null $pids null for all pages
     * @return list<array<string, mixed>>
     */
    public function findForExclusion(?array $pids): array
    {
        $rows = [];
        foreach ($pids === null ? [null] : array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
            $queryBuilder->select('uid', 'table_name', 'record_uid', 'field', 'pid', 'language', 'link_string', 'link_text')->from(self::TABLE);
            if ($chunk !== null) {
                $queryBuilder->where($queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)));
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
        $queryBuilder = $this->createQueryBuilder(new BrokenLinkQuery([(int)$pid], permissions: $permissions));
        $queryBuilder->select('b.*', 't.identifier', 't.status', 't.error_type', 't.error_details');
        foreach ($identity as $column => $value) {
            $queryBuilder->andWhere($queryBuilder->expr()->eq('b.' . $column, $queryBuilder->createNamedParameter($value, is_int($value) ? Connection::PARAM_INT : Connection::PARAM_STR)));
        }
        $row = $queryBuilder->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }

    /**
     * A target that is fine now has no results any more. The status of the others is read from the
     * target, so there is nothing to update.
     */
    public function removeByTarget(int $targetUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(self::TABLE, ['target' => $targetUid]);
    }

    public function removeForRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
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
