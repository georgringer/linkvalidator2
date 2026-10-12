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
namespace GeorgRinger\Linkvalidator2\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use GeorgRinger\Linkvalidator2\Extraction\CheckableFieldResolver;

/**
 * Finds the live records that belong to one page: the page itself,
 * its translations and all records stored on it.
 */
final readonly class PageRecordFinder
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private CheckableFieldResolver $checkableFieldResolver,
        private PageRepository $pageRepository,
    ) {}

    /**
     * The page itself plus its subpages down to $depth levels, including hidden pages
     * and folders, which may hold records with links as well.
     *
     * @return list<int>
     */
    public function findPageIds(int $pageUid, int $depth): array
    {
        if ($depth <= 0) {
            return [$pageUid];
        }
        return [$pageUid, ...$this->pageRepository->getDescendantPageIdsRecursive($pageUid, $depth, 0, [], true)];
    }

    /**
     * The page itself plus its subpages down to $depth levels, level by level and in batches, so
     * that a large tree is never held in memory as a whole: one query per level and batch of
     * parents. Hidden pages and folders are included, translations of pages are not.
     *
     * @return \Generator<int, list<int>>
     */
    public function iteratePageIdBatches(int $pageUid, int $depth, int $batchSize = 200): \Generator
    {
        $seen = [$pageUid => true];
        $level = [$pageUid];
        for ($i = 0; $level !== []; $i++) {
            foreach (array_chunk($level, $batchSize) as $batch) {
                yield $batch;
            }
            if ($i >= $depth) {
                return;
            }
            $next = [];
            foreach (array_chunk($level, 1000) as $parents) {
                $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
                $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
                $queryBuilder->select('uid')->from('pages')->where(
                    $queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($parents, Connection::PARAM_INT_ARRAY)),
                    $queryBuilder->expr()->eq('sys_language_uid', 0),
                    $queryBuilder->expr()->eq('t3ver_wsid', 0)
                );
                foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                    if (!isset($seen[(int)$uid])) {
                        $seen[(int)$uid] = true;
                        $next[] = (int)$uid;
                    }
                }
            }
            $level = $next;
        }
    }

    /**
     * @return list<array{table: string, uid: int}>
     */
    public function findOnPage(int $pageUid): array
    {
        return $this->findOnPages([$pageUid])[$pageUid];
    }

    /**
     * Same as findOnPage() for several pages with one query per table.
     *
     * @param list<int> $pageUids
     * @return array<int, list<array{table: string, uid: int}>> Keyed by page, every page is present
     */
    public function findOnPages(array $pageUids): array
    {
        $items = array_fill_keys($pageUids, []);
        if ($pageUids === []) {
            return $items;
        }
        foreach ($this->checkableFieldResolver->getCheckableTables() as $table) {
            $schema = $this->tcaSchemaFactory->get($table);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $pids = $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY);
            $parentField = null;
            if ($table === 'pages') {
                $constraints = [$queryBuilder->expr()->in('uid', $pids)];
                $queryBuilder->select('uid', 'pid');
                if ($schema->hasCapability(TcaSchemaCapability::Language)) {
                    $parentField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
                    $constraints[] = $queryBuilder->expr()->in($parentField, $pids);
                    $queryBuilder->addSelect($parentField);
                }
                $queryBuilder->where($queryBuilder->expr()->or(...$constraints));
            } else {
                $queryBuilder->select('uid', 'pid')->where($queryBuilder->expr()->in('pid', $pids));
            }
            $queryBuilder->from($table)->orderBy('uid');
            if ($schema->isWorkspaceAware()) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            foreach ($queryBuilder->executeQuery()->fetchAllAssociative() as $row) {
                $uid = (int)$row['uid'];
                // A page and its translations belong to the page, other records to the page they are stored on
                $pageUid = $parentField !== null && (int)$row[$parentField] > 0 ? (int)$row[$parentField] : ($table === 'pages' ? $uid : (int)$row['pid']);
                if (isset($items[$pageUid])) {
                    $items[$pageUid][] = ['table' => $table, 'uid' => $uid];
                }
            }
        }
        return $items;
    }
}
