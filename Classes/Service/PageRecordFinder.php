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
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Linkvalidator2\Extraction\CheckableFieldResolver;

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
     * @todo Deleted records are not returned, so their stored results are never touched again and stay
     *       in the report. Reconcile stored rows of the page with the records found here when a page
     *       is checked; DataHandler cleanup on delete/move follows in step 4.
     *
     * @return list<array{table: string, uid: int}>
     */
    public function findOnPage(int $pageUid): array
    {
        $items = [];
        foreach ($this->checkableFieldResolver->getCheckableTables() as $table) {
            $schema = $this->tcaSchemaFactory->get($table);
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $queryBuilder->select('uid')->from($table);
            if ($table === 'pages') {
                $constraints = [$queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT))];
                if ($schema->hasCapability(TcaSchemaCapability::Language)) {
                    $parentField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
                    $constraints[] = $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT));
                }
                $queryBuilder->where($queryBuilder->expr()->or(...$constraints));
            } else {
                $queryBuilder->where($queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)));
            }
            if ($schema->isWorkspaceAware()) {
                $queryBuilder->andWhere($queryBuilder->expr()->eq('t3ver_wsid', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)));
            }
            foreach ($queryBuilder->executeQuery()->fetchFirstColumn() as $uid) {
                $items[] = ['table' => $table, 'uid' => (int)$uid];
            }
        }
        return $items;
    }
}
