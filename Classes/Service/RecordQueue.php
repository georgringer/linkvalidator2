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
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Linkvalidator2\Extraction\CheckableFieldResolver;

/**
 * The record status table is the work queue: records never checked come first (no status row
 * yet), then records by their last check, due ones (0) first. Records checked in the
 * current run are not returned again, the position in the queue is kept by a cursor.
 */
final class RecordQueue
{
    private const STATUS_TABLE = 'tx_linkvalidator2_record_status';

    /** @var array{lastChecked: int, uid: int}|null Last returned status row */
    private ?array $cursor = null;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly TcaSchemaFactory $tcaSchemaFactory,
        private readonly CheckableFieldResolver $checkableFieldResolver,
    ) {}

    public function reset(): void
    {
        $this->cursor = null;
    }

    /**
     * @param int $checkedBefore Only records whose last check is older are returned
     * @return list<array{table: string, uid: int}>
     */
    public function next(int $limit, int $checkedBefore): array
    {
        $records = $this->findNeverChecked($limit);
        if (count($records) < $limit) {
            $records = [...$records, ...$this->findCheckedBefore($limit - count($records), $checkedBefore)];
        }
        return $records;
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    private function findNeverChecked(int $limit): array
    {
        $records = [];
        foreach ($this->checkableFieldResolver->getCheckableTables() as $table) {
            if (count($records) >= $limit) {
                break;
            }
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder
                ->select('r.uid')
                ->from($table, 'r')
                ->leftJoin('r', self::STATUS_TABLE, 's', (string)$queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('s.table_name', $queryBuilder->createNamedParameter($table)),
                    $queryBuilder->expr()->eq('s.record_uid', $queryBuilder->quoteIdentifier('r.uid')),
                    $queryBuilder->expr()->eq('s.workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT))
                ))
                ->where($queryBuilder->expr()->isNull('s.uid'))
                ->orderBy('r.uid')
                ->setMaxResults($limit - count($records));
            if ($this->tcaSchemaFactory->get($table)->isWorkspaceAware()) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('r.t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                $records[] = ['table' => $table, 'uid' => (int)$uid];
            }
        }
        return $records;
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    private function findCheckedBefore(int $limit, int $checkedBefore): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::STATUS_TABLE);
        $queryBuilder
            ->select('uid', 'table_name', 'record_uid', 'last_checked')
            ->from(self::STATUS_TABLE)
            ->where(
                $queryBuilder->expr()->eq('workspace', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('last_checked', $queryBuilder->createNamedParameter($checkedBefore, Connection::PARAM_INT))
            )
            ->orderBy('last_checked')
            ->addOrderBy('uid')
            ->setMaxResults($limit);
        if ($this->cursor !== null) {
            // Keyset: behind the last returned row. Rows checked meanwhile moved out of range,
            // rows marked due again (0) are before the cursor and wait for the next run.
            $lastChecked = $queryBuilder->createNamedParameter($this->cursor['lastChecked'], Connection::PARAM_INT);
            $queryBuilder->andWhere($queryBuilder->expr()->or(
                $queryBuilder->expr()->gt('last_checked', $lastChecked),
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->eq('last_checked', $lastChecked),
                    $queryBuilder->expr()->gt('uid', $queryBuilder->createNamedParameter($this->cursor['uid'], Connection::PARAM_INT))
                )
            ));
        }
        $records = [];
        foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
            $records[] = ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid']];
            $this->cursor = ['lastChecked' => (int)$row['last_checked'], 'uid' => (int)$row['uid']];
        }
        return $records;
    }
}
