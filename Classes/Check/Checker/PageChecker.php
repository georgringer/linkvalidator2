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
namespace TYPO3\CMS\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Check\LinkCheckerInterface;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;

final readonly class PageChecker implements LinkCheckerInterface
{
    private const DOKTYPES_WITHOUT_FRONTEND = [
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_PAGE];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $pageUid = (int)$target->resolved['pageuid'];
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $page = $queryBuilder
            ->select('uid', 'deleted', 'hidden', 'endtime', 'doktype')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($pageUid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        if ($page === false || (int)$page['deleted'] === 1) {
            return CheckResult::broken('page.missing', ['page' => $pageUid]);
        }
        if ((int)$page['hidden'] === 1) {
            return CheckResult::broken('page.hidden', ['page' => $pageUid]);
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        if ((int)$page['endtime'] > 0 && (int)$page['endtime'] <= $now) {
            return CheckResult::broken('page.expired', ['page' => $pageUid, 'endtime' => (int)$page['endtime']]);
        }
        if (in_array((int)$page['doktype'], self::DOKTYPES_WITHOUT_FRONTEND, true)) {
            return CheckResult::broken('page.noFrontend', ['page' => $pageUid, 'doktype' => (int)$page['doktype']]);
        }
        return CheckResult::ok();
    }
}
