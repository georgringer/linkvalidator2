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

use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the records of a page and its subpages for checking: one message per table with up to
 * $chunkSize records. Records that vanished from a page are cleaned up on the way. Queueing
 * a record twice is harmless, a check replaces the stored results and reuses cached external ones.
 */
final readonly class QueueFiller
{
    public function __construct(
        private PageRecordFinder $pageRecordFinder,
        private RecordCheckService $recordCheckService,
        private MessageBusInterface $messageBus,
    ) {}

    /**
     * @return array{pages: int, records: int, messages: int}
     */
    public function fill(int $pageUid, int $depth, int $chunkSize): array
    {
        $chunkSize = max(1, $chunkSize);
        $pages = $records = $messages = 0;
        $buffer = [];
        $this->recordCheckService->removeResultsOfMissingPages();
        foreach ($this->pageRecordFinder->iteratePageIdBatches($pageUid, $depth) as $pageIds) {
            foreach ($this->pageRecordFinder->findOnPages($pageIds) as $pid => $found) {
                $this->recordCheckService->removeStaleResults($pid, $found);
                foreach ($found as ['table' => $table, 'uid' => $uid]) {
                    $buffer[$table][] = $uid;
                    $records++;
                }
            }
            $pages += count($pageIds);
            // Full messages are sent while walking the tree, the rest at the end
            foreach ($buffer as $table => $uids) {
                while (count($uids) >= $chunkSize) {
                    $this->messageBus->dispatch(new CheckRecordsMessage($table, array_splice($uids, 0, $chunkSize)));
                    $messages++;
                }
                $buffer[$table] = $uids;
            }
        }
        foreach ($buffer as $table => $uids) {
            if ($uids !== []) {
                $this->messageBus->dispatch(new CheckRecordsMessage($table, $uids));
                $messages++;
            }
        }
        return ['pages' => $pages, 'records' => $records, 'messages' => $messages];
    }
}
