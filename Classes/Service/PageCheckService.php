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

use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkQuery;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;

/**
 * Checks all records of one page within an optional time budget. Repeated calls with the same
 * run start skip records already checked in this run, so a page is finished in several requests.
 */
final readonly class PageCheckService
{
    public function __construct(
        private PageRecordFinder $pageRecordFinder,
        private RecordCheckService $recordCheckService,
        private RecordStatusRepository $recordStatusRepository,
        private BrokenLinkRepository $brokenLinkRepository,
    ) {}

    /**
     * @param int $runStartedAt Timestamp the run started; results stored since are reused and records
     *                          checked since are skipped
     * @param float|null $timeBudget Seconds; the budget is checked between records, at least one record
     *                               is checked per call. Null checks the whole page.
     */
    public function checkPage(int $pageUid, int $runStartedAt, ?float $timeBudget = null, bool $cachedOnly = false, bool $ignoreStored = false): PageCheckResult
    {
        $start = microtime(true);
        $records = $this->pageRecordFinder->findOnPage($pageUid);
        $this->recordCheckService->removeStaleResults($pageUid, $records);
        $checkedInRun = $this->recordStatusRepository->findRecordsCheckedSince($pageUid, $runStartedAt);
        $checkedCount = 0;
        $done = true;
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            if (isset($checkedInRun[$table . ':' . $uid])) {
                continue;
            }
            if ($timeBudget !== null && $checkedCount > 0 && microtime(true) - $start >= $timeBudget) {
                $done = false;
                break;
            }
            $this->recordCheckService->checkRecord($table, $uid, $cachedOnly, $runStartedAt, ignoreStored: $ignoreStored);
            $checkedCount++;
        }
        return new PageCheckResult(
            $done,
            count($records),
            $checkedCount,
            $this->brokenLinkRepository->count(new BrokenLinkQuery([$pageUid], onlyBroken: true)),
            $this->brokenLinkRepository->count(new BrokenLinkQuery([$pageUid], onlyBroken: true, detectedSince: $runStartedAt)),
            $this->brokenLinkRepository->count(new BrokenLinkQuery([$pageUid], new ReportFilter(CheckStatus::Info->value))),
        );
    }
}
