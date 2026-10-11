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
namespace TYPO3\CMS\Linkvalidator2\Hook;

use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Linkvalidator2\Extraction\CheckableFieldResolver;
use TYPO3\CMS\Linkvalidator2\Service\PageRecordFinder;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;

/**
 * Keeps the results up to date with changes made in the backend: records are checked again
 * once the DataHandler is done (internal targets immediately, external ones from the cache,
 * records with external links not checked yet become due), deleted records and pages lose
 * their results. Only the live workspace is checked; publishing counts as a change.
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

    public function __construct(
        private readonly RecordCheckService $recordCheckService,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly CheckableFieldResolver $checkableFieldResolver,
        private readonly LoggerInterface $logger,
    ) {}

    public function processDatamap_afterDatabaseOperations(string $status, string $table, int|string $id, array $fieldArray, DataHandler $dataHandler): void
    {
        if (!$this->isLiveWorkspace($dataHandler)) {
            return;
        }
        $uid = MathUtility::canBeInterpretedAsInteger($id) ? (int)$id : (int)($dataHandler->substNEWwithIDs[$id] ?? 0);
        $this->remember($table, $uid);
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $this->checkRemembered();
    }

    public function processCmdmap_postProcess(string $command, string $table, int|string $id, mixed $value, DataHandler $dataHandler): void
    {
        $uid = (int)$id;
        if ($command === 'version') {
            $this->remember($table, $uid);
            return;
        }
        if (!$this->isLiveWorkspace($dataHandler)) {
            return;
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

    private function checkRemembered(): void
    {
        $records = $this->records;
        $this->records = [];
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            try {
                $this->recordCheckService->checkRecord($table, $uid, true);
            } catch (\Throwable $e) {
                $this->logger->warning('Checking links of {table}:{uid} after saving failed: {message}', ['table' => $table, 'uid' => $uid, 'message' => $e->getMessage(), 'exception' => $e]);
            }
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
