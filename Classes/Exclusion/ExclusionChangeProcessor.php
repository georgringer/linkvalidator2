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
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTargetFactory;

/**
 * Applies changed exclusions to the stored results of their subtree. A new exclusion removes
 * the results it matches right away, without checking anything: excluded links are not
 * stored. Where an exclusion may have been removed, links excluded so far were never checked:
 * those records become due for the queue.
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
            $this->recordStatusRepository->markDueByPids($this->getSubtree($pid));
        }
    }

    private function removeExcludedResults(int $pid): void
    {
        $subtree = $this->getSubtree($pid);
        $remove = [];
        foreach ($this->exclusionRepository->findOnPage($pid) as $exclusion) {
            $hint = $this->exclusionMatcher->getLinkStringHint($exclusion);
            foreach ($this->brokenLinkRepository->findForExclusion($subtree, $hint) as $row) {
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
        $records = [];
        foreach ($remove as $row) {
            $key = $row['table_name'] . ':' . $row['record_uid'];
            $records[$key] ??= ['table' => (string)$row['table_name'], 'uid' => (int)$row['record_uid'], 'broken' => 0, 'total' => 0];
            $records[$key]['total']++;
            $records[$key]['broken'] += in_array($row['status'], ['broken', 'unknown'], true) ? 1 : 0;
        }
        foreach ($records as ['table' => $table, 'uid' => $uid, 'broken' => $broken, 'total' => $total]) {
            $this->recordStatusRepository->markExcluded($table, $uid, $broken, $total);
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
