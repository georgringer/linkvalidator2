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
namespace GeorgRinger\Linkvalidator2\Hook;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionChangeProcessor;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionRepository;
use GeorgRinger\Linkvalidator2\Extraction\CheckableFieldResolver;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Service\ReferencingRecordFinder;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;

/**
 * Keeps the results up to date with changes made in the backend: records are checked again
 * once the DataHandler is done (internal targets immediately, external ones from the cache,
 * records with external links not checked yet become due), deleted records and pages lose
 * their results. Only the live workspace is checked; publishing counts as a change.
 * Changed exclusions are applied to the results of their subtree. When a record changes, the
 * records referencing it (reference index) become due, as their links may point to it: e.g.
 * a page that got hidden or deleted. They are not checked right away: the hook runs before
 * the DataHandler clears the caches, so the page resolution would still see the old state.
 *
 * Never lets checking break saving: failures are logged.
 *
 * @internal
 */
final class DataHandlerHook
{
    private const COPYING_COMMANDS = ['copy', 'localize', 'copyToLanguage'];

    /** @var array<string, array{table: string, uid: int}> */
    private array $records = [];

    /** @var array<string, true>|null */
    private ?array $checkableTables = null;

    /** @var array<string, array<int, int>> Changed records, by table, whose referencing records become due */
    private array $targets = [];

    /** @var array<int, true> Pages with exclusions that apply now */
    private array $addedExclusionPids = [];

    /** @var array<int, true> Pages with exclusions that may no longer apply */
    private array $removedExclusionPids = [];

    public function __construct(
        private readonly RecordCheckService $recordCheckService,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly CheckableFieldResolver $checkableFieldResolver,
        private readonly ExclusionChangeProcessor $exclusionChangeProcessor,
        private readonly ReferencingRecordFinder $referencingRecordFinder,
        private readonly RecordStatusRepository $recordStatusRepository,
        private readonly LoggerInterface $logger,
    ) {}

    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if (!$this->isLiveWorkspace($dataHandler)) {
            return;
        }
        $uid = MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        if ($table === ExclusionRepository::TABLE) {
            // Any change may narrow an exclusion
            $this->rememberExclusion($uid, $status === 'new' ? 'added' : 'changed');
            return;
        }
        $this->remember($table, $uid);
        if ($status !== 'new') {
            $this->rememberTarget($table, $uid);
        }
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->checkRemembered();
    }

    public function processCmdmap_preProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        if ($table === ExclusionRepository::TABLE && in_array($command, ['delete', 'move'], true)) {
            $this->rememberExclusion((int)$id, 'removed');
        }
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        $uid = (int)$id;
        if ($table === ExclusionRepository::TABLE) {
            match ($command) {
                'move', 'undelete' => $this->rememberExclusion($uid, 'added'),
                'copy' => $this->rememberExclusion((int)($dataHandler->copyMappingArray_merged[$table][$uid] ?? 0), 'added'),
                default => null,
            };
            return;
        }
        if ($command === 'version') {
            $this->remember($table, $uid);
            return;
        }
        if (!$this->isLiveWorkspace($dataHandler)) {
            return;
        }
        if (in_array($command, ['delete', 'undelete', 'move'], true)) {
            $this->rememberTarget($table, $uid);
        }
        match (true) {
            $command === 'delete' => $this->removeResultsOfDeleted($table, $uid),
            $command === 'move', $command === 'undelete' => $this->remember($table, $uid),
            in_array($command, self::COPYING_COMMANDS, true) => $this->remember($table, (int)($dataHandler->copyMappingArray_merged[$table][$uid] ?? 0)),
            default => null,
        };
    }

    public function processCmdmap_afterFinish(DataHandler $dataHandler): void
    {
        $this->checkRemembered();
    }

    private function remember(string $table, int $uid): void
    {
        if ($uid > 0 && isset($this->getCheckableTables()[$table])) {
            $this->records[$table . ':' . $uid] = ['table' => $table, 'uid' => $uid];
        }
    }

    /**
     * @param 'added'|'removed'|'changed' $change
     */
    private function rememberExclusion(int $uid, string $change): void
    {
        $row = $uid > 0 ? BackendUtility::getRecord(ExclusionRepository::TABLE, $uid, 'pid', '', false) : null;
        if ($row === null) {
            return;
        }
        $pid = (int)$row['pid'];
        if ($change !== 'removed') {
            $this->addedExclusionPids[$pid] = true;
        }
        if ($change !== 'added') {
            $this->removedExclusionPids[$pid] = true;
        }
    }

    private function rememberTarget(string $table, int $uid): void
    {
        if ($uid > 0) {
            $this->targets[$table][$uid] = $uid;
        }
    }

    private function checkRemembered(): void
    {
        $this->processRememberedExclusions();
        $records = $this->records;
        $this->records = [];
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            try {
                $this->recordCheckService->checkRecord($table, $uid, true);
            } catch (\Throwable $e) {
                $this->logger->warning('Checking links of {table}:{uid} after saving failed: {message}', ['table' => $table, 'uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]);
            }
        }
        // After the checks above, which mark records as checked
        $this->markReferencingRecordsDue();
    }

    private function markReferencingRecordsDue(): void
    {
        $targets = $this->targets;
        $this->targets = [];
        try {
            foreach ($targets as $table => $uids) {
                $records = array_filter(
                    $this->referencingRecordFinder->find($table, array_values($uids)),
                    fn(array $record): bool => isset($this->getCheckableTables()[$record['table']])
                );
                $this->recordStatusRepository->markDue(array_values($records));
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Marking records referencing changed records as due failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function processRememberedExclusions(): void
    {
        $added = array_keys($this->addedExclusionPids);
        $removed = array_keys($this->removedExclusionPids);
        $this->addedExclusionPids = [];
        $this->removedExclusionPids = [];
        if ($added === [] && $removed === []) {
            return;
        }
        try {
            $this->exclusionChangeProcessor->process($added, $removed);
        } catch (\Throwable $e) {
            $this->logger->warning('Applying changed link check exclusions failed: {message}', ['message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    /**
     * Translations and records on deleted pages are deleted along without a command of their own,
     * so the page of the record is reconciled, and results of pages that are gone are removed.
     */
    private function removeResultsOfDeleted(string $table, int $uid): void
    {
        try {
            if ($table === 'pages') {
                $this->recordCheckService->removeResultsOfMissingPages();
                return;
            }
            $pid = (int)(BackendUtility::getRecord($table, $uid, 'pid', '', false)['pid'] ?? 0);
            $this->recordCheckService->removeStaleResults($pid, $this->pageRecordFinder->findOnPage($pid));
        } catch (\Throwable $e) {
            $this->logger->warning('Removing link results of deleted {table}:{uid} failed: {message}', ['table' => $table, 'uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]);
        }
    }

    private function isLiveWorkspace(DataHandler $dataHandler): bool
    {
        return (int)($dataHandler->BE_USER->workspace ?? 0) === 0;
    }

    /**
     * @return array<string, true>
     */
    private function getCheckableTables(): array
    {
        return $this->checkableTables ??= array_fill_keys($this->checkableFieldResolver->getCheckableTables(), true);
    }
}
