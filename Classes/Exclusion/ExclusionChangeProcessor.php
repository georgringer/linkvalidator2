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

namespace GeorgRinger\Linkvalidator2\Exclusion;

use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;

/**
 * Applies changed exclusions to the stored results of their subtree. Records with results are
 * checked again from the cache, so newly excluded links disappear right away. Where an
 * exclusion may have been removed, links excluded so far were never checked: those records
 * become due for the queue.
 *
 * @internal
 */
final readonly class ExclusionChangeProcessor
{
    public function __construct(
        private ExclusionRepository $exclusionRepository,
        private PageRecordFinder $pageRecordFinder,
        private BrokenLinkRepository $brokenLinkRepository,
        private RecordStatusRepository $recordStatusRepository,
        private RecordCheckService $recordCheckService,
    ) {}

    /**
     * @param list<int> $addedPids Pages with exclusions that apply now
     * @param list<int> $removedPids Pages with exclusions that may no longer apply
     */
    public function process(array $addedPids, array $removedPids): void
    {
        $this->exclusionRepository->reset();
        $records = [];
        foreach (array_unique([...$addedPids, ...$removedPids]) as $pid) {
            foreach ($this->brokenLinkRepository->findRecordsByPids($this->getSubtree($pid)) as $record) {
                $records[$record['table'] . ':' . $record['uid']] = $record;
            }
        }
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            $this->recordCheckService->checkRecord($table, $uid, true);
        }
        foreach (array_unique($removedPids) as $pid) {
            $this->recordStatusRepository->markDueByPids($this->getSubtree($pid));
        }
    }

    /**
     * @return list<int>|null null for all pages
     */
    private function getSubtree(int $pid): ?array
    {
        return $pid === 0 ? null : $this->pageRecordFinder->findPageIds($pid, 99);
    }
}
