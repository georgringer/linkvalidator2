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
use GeorgRinger\Linkvalidator2\Service\DueQueue;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTargetFactory;

/**
 * Applies changed exclusions to the stored results of their subtree. A new exclusion removes
 * the results it matches right away, without checking anything: excluded links are not
 * stored. Where an exclusion may have been removed, links excluded so far were never checked:
 * those records become due and are queued with priority.
 *
 * @internal
 */
final readonly class ExclusionChangeProcessor
{
    public function __construct(
        private ExclusionRepository $exclusionRepository,
        private ExclusionMatcher $exclusionMatcher,
        private PageRecordFinder $pageRecordFinder,
        private BrokenLinkRepository $brokenLinkRepository,
        private RecordStatusRepository $recordStatusRepository,
        private LinkTargetFactory $linkTargetFactory,
        private DueQueue $dueQueue,
    ) {}

    /**
     * @param list<int> $addedPids Pages with exclusions that apply now
     * @param list<int> $removedPids Pages with exclusions that may no longer apply
     */
    public function process(array $addedPids, array $removedPids): void
    {
        $this->exclusionRepository->reset();
        foreach (array_unique($addedPids) as $pid) {
            $this->removeExcludedResults($pid);
        }
        foreach (array_unique($removedPids) as $pid) {
            $this->dueQueue->queue($this->recordStatusRepository->findRecordsByPids($this->getSubtree($pid)));
        }
    }

    private function removeExcludedResults(int $pid): void
    {
        $subtree = $this->getSubtree($pid);
        $remove = [];
        foreach ($this->exclusionRepository->findOnPage($pid) as $exclusion) {
            foreach ($this->brokenLinkRepository->findForExclusion($subtree) as $row) {
                if (isset($remove[(int)$row['uid']])) {
                    continue;
                }
                $target = $this->linkTargetFactory->create(new LinkCandidate(
                    (string)$row['table_name'],
                    (int)$row['record_uid'],
                    (int)$row['pid'],
                    (int)$row['language'],
                    (string)$row['field'],
                    (string)$row['link_string'],
                    (string)$row['link_text'],
                ));
                if ($target !== null && $this->exclusionMatcher->isExcluded([$exclusion], $target)) {
                    $remove[(int)$row['uid']] = $row;
                }
            }
        }
        if ($remove === []) {
            return;
        }
        $this->brokenLinkRepository->removeByUids(array_keys($remove));
        $removedByRecord = [];
        foreach ($remove as $row) {
            $key = $row['table_name'] . ':' . $row['record_uid'];
            $removedByRecord[$key] ??= ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid'], 'count' => 0];
            $removedByRecord[$key]['count']++;
        }
        foreach ($removedByRecord as ['table' => $table, 'uid' => $uid, 'count' => $count]) {
            $this->recordStatusRepository->markExcluded($table, $uid, $count);
        }
    }

    /**
     * @return list<int>|null null for all pages
     */
    private function getSubtree(int $pid): ?array
    {
        if ($pid === 0) {
            return null;
        }
        return array_merge(...iterator_to_array($this->pageRecordFinder->iteratePageIdBatches($pid, 99, 1000), false));
    }
}
