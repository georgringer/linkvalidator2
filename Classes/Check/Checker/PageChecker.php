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
namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\FrontendPageResolver;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * Whether a visitor gets the page is decided by the frontend's page resolution. The page and its
 * rootline are only looked at to name the reason, and to tell restrictions an editor should know
 * about (access, start and stop time) from broken links.
 */
final readonly class PageChecker implements LinkCheckerInterface
{
    private const DOKTYPES_WITHOUT_FRONTEND = [
        PageRepository::DOKTYPE_SPACER,
        PageRepository::DOKTYPE_SYSFOLDER,
    ];
    private const DOKTYPES_REDIRECTING = [
        PageRepository::DOKTYPE_LINK,
        PageRepository::DOKTYPE_SHORTCUT,
    ];
    /** fe_group values that do not restrict anonymous visitors: none, "hide at login" */
    private const NO_ACCESS_RESTRICTION = ['', '0', '-1'];

    public function __construct(
        private ConnectionPool $connectionPool,
        private Context $context,
        private FrontendPageResolver $frontendPageResolver,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_PAGE];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $pageUid = (int)$target->resolved['pageuid'];
        $page = $this->getPage($pageUid);
        if ($page === null) {
            return CheckResult::broken('page.missing', ['page' => $pageUid]);
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $failureCode = $this->frontendPageResolver->getFailureCode($pageUid);
        if ($failureCode === null) {
            return $this->getRestrictionOfReachablePage($page, $now) ?? CheckResult::ok();
        }
        if ($failureCode === FrontendPageResolver::FAILURE_NO_SITE) {
            return CheckResult::broken('page.noSite', ['page' => $pageUid]);
        }
        return $this->getReasonForPage($page, $now, $pageUid, null)
            ?? $this->getReasonFromRootline($page, $now)
            ?? (in_array((int)$page['doktype'], self::DOKTYPES_REDIRECTING, true)
                ? CheckResult::broken('page.targetNotReachable', ['page' => $pageUid])
                : CheckResult::broken('page.notReachable', ['page' => $pageUid, 'code' => $failureCode]));
    }

    /**
     * Why the frontend does not deliver this page, judged by the page itself, or by a parent
     * whose restrictions extend to its subpages ($parentPage set).
     */
    private function getReasonForPage(array $page, int $now, int $targetPage, ?int $parentPage): ?CheckResult
    {
        $details = ['page' => $targetPage] + ($parentPage !== null ? ['parentPage' => $parentPage] : []);
        $byParent = $parentPage !== null ? 'ByParent' : '';
        if ((int)$page['hidden'] === 1) {
            return CheckResult::broken('page.hidden' . $byParent, $details);
        }
        if ((int)$page['endtime'] > 0 && (int)$page['endtime'] <= $now) {
            return CheckResult::broken('page.expired' . $byParent, $details + ['endtime' => (int)$page['endtime']]);
        }
        if ($parentPage === null && in_array((int)$page['doktype'], self::DOKTYPES_WITHOUT_FRONTEND, true)) {
            return CheckResult::broken('page.noFrontend', $details + ['doktype' => (int)$page['doktype']]);
        }
        if ((int)$page['starttime'] > $now) {
            return CheckResult::info('page.notYetVisible', $details + ['starttime' => (int)$page['starttime']]);
        }
        if (!in_array((string)$page['fe_group'], self::NO_ACCESS_RESTRICTION, true)) {
            return CheckResult::info('page.accessRestricted', $details + ['fe_group' => (string)$page['fe_group']]);
        }
        return null;
    }

    private function getReasonFromRootline(array $page, int $now): ?CheckResult
    {
        foreach ($this->getParentPages($page) as $parent) {
            if ((int)$parent['extendToSubpages'] !== 1) {
                continue;
            }
            $reason = $this->getReasonForPage($parent, $now, (int)$page['uid'], (int)$parent['uid']);
            if ($reason !== null) {
                return $reason;
            }
        }
        return null;
    }

    /**
     * A page the frontend delivers may still disappear at its stop time, or at the one of a
     * parent extending it to subpages.
     */
    private function getRestrictionOfReachablePage(array $page, int $now): ?CheckResult
    {
        $candidates = [[$page, null]];
        foreach ($this->getParentPages($page) as $parent) {
            if ((int)$parent['extendToSubpages'] === 1) {
                $candidates[] = [$parent, (int)$parent['uid']];
            }
        }
        foreach ($candidates as [$candidate, $parentPage]) {
            if ((int)$candidate['endtime'] > $now) {
                return CheckResult::info(
                    'page.visibleUntil',
                    ['page' => (int)$page['uid'], 'endtime' => (int)$candidate['endtime']] + ($parentPage !== null ? ['parentPage' => $parentPage] : [])
                );
            }
        }
        return null;
    }

    /**
     * @return list<array<string, mixed>> parent pages, closest first
     */
    private function getParentPages(array $page): array
    {
        $parents = [];
        $seen = [(int)$page['uid'] => true];
        $pid = (int)$page['pid'];
        while ($pid > 0 && !isset($seen[$pid])) {
            $seen[$pid] = true;
            $parent = $this->getPage($pid);
            if ($parent === null) {
                break;
            }
            $parents[] = $parent;
            $pid = (int)$parent['pid'];
        }
        return $parents;
    }

    private function getPage(int $uid): ?array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $page = $queryBuilder
            ->select('uid', 'pid', 'deleted', 'hidden', 'starttime', 'endtime', 'fe_group', 'extendToSubpages', 'doktype')
            ->from('pages')
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();
        return $page === false || (int)$page['deleted'] === 1 ? null : $page;
    }
}
