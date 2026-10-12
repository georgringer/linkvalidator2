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

namespace GeorgRinger\Linkvalidator2\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Service\PageCheckService;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Service\RecordQueue;

#[AsCommand('linkvalidator2:check', 'Check links in records and store broken ones.')]
final class CheckCommand extends Command
{
    private const QUEUE_BATCH_SIZE = 100;

    public function __construct(
        private readonly RecordCheckService $recordCheckService,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly PageCheckService $pageCheckService,
        private readonly Context $context,
        private readonly RecordQueue $recordQueue,
        private readonly LockFactory $lockFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Check a single record, format "table:uid"')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Check all records of a page')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Levels of subpages to include with --page', '0')
            ->addOption('no-external', null, InputOption::VALUE_NONE, 'Do not request external URLs, use stored results only')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Queue: maximum number of records to check, 0 for no limit', '0')
            ->addOption('time-limit', 't', InputOption::VALUE_REQUIRED, 'Queue: stop after this many seconds, 0 for no limit', '0')
            ->addOption('interval', 'i', InputOption::VALUE_REQUIRED, 'Queue: check records again after this many hours; due records are always checked', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $cachedOnly = (bool)$input->getOption('no-external');
        $record = (string)$input->getOption('record');
        if ($record !== '') {
            [$table, $uid] = $this->parseRecordOption($record);
            if ($table === '') {
                $io->error('Option --record expects "table:uid", e.g. "tt_content:12".');
                return Command::INVALID;
            }
            foreach ($this->recordCheckService->checkRecord($table, $uid, $cachedOnly) as $checkedLink) {
                $io->writeln(sprintf(
                    '%s  %s  %s %s',
                    $checkedLink->candidate->field,
                    $checkedLink->target->identifier,
                    $checkedLink->result->status->value,
                    $checkedLink->result->errorType
                ));
            }
            return Command::SUCCESS;
        }
        $pageUid = (int)$input->getOption('page');
        if ($pageUid > 0) {
            $pageIds = $this->pageRecordFinder->findPageIds($pageUid, (int)$input->getOption('depth'));
            // Same clock as the check times stored by the checks
            $runStartedAt = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
            $recordCount = 0;
            $broken = 0;
            foreach ($pageIds as $pageId) {
                $result = $this->pageCheckService->checkPage($pageId, $runStartedAt, null, $cachedOnly);
                $recordCount += $result->recordCount;
                $broken += $result->brokenLinkCount;
                if ($output->isVerbose()) {
                    $io->writeln(sprintf('pages:%d  %d records, %d broken links', $pageId, $result->recordCount, $result->brokenLinkCount));
                }
            }
            $io->success(sprintf('Checked %d records on %d pages, found %d broken links.', $recordCount, count($pageIds), $broken));
            return Command::SUCCESS;
        }
        return $this->processQueue($input, $io, $cachedOnly);
    }

    /**
     * Without --record/--page: records never checked, due and checked longer ago than the
     * interval, within the limits. Meant for the scheduler ("Execute console commands").
     */
    private function processQueue(InputInterface $input, SymfonyStyle $io, bool $cachedOnly): int
    {
        $limit = max(0, (int)$input->getOption('limit'));
        $timeLimit = max(0, (int)$input->getOption('time-limit'));
        $locker = $this->lockFactory->createLocker(
            'linkvalidator2-queue',
            LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK
        );
        try {
            if (!$locker->acquire(LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK)) {
                $io->note('Another run is processing the queue.');
                return Command::SUCCESS;
            }
        } catch (LockAcquireWouldBlockException) {
            $io->note('Another run is processing the queue.');
            return Command::SUCCESS;
        }
        try {
            $start = microtime(true);
            // Same clock as the check times stored by the checks
            $runStartedAt = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
            $checkedBefore = min($runStartedAt, $runStartedAt - (int)round((float)$input->getOption('interval') * 3600));
            $this->recordCheckService->removeResultsOfMissingPages();
            $this->recordQueue->reset();
            $checked = [];
            $brokenLinks = 0;
            while (true) {
                $batchSize = $limit > 0 ? min(self::QUEUE_BATCH_SIZE, $limit - count($checked)) : self::QUEUE_BATCH_SIZE;
                $batch = $batchSize > 0 ? $this->recordQueue->next($batchSize, $checkedBefore) : [];
                $batch = array_filter($batch, static fn(array $record): bool => !isset($checked[$record['table'] . ':' . $record['uid']]));
                if ($batch === []) {
                    break;
                }
                foreach ($batch as ['table' => $table, 'uid' => $uid]) {
                    if ($timeLimit > 0 && microtime(true) - $start >= $timeLimit) {
                        break 2;
                    }
                    foreach ($this->recordCheckService->checkRecord($table, $uid, $cachedOnly, $runStartedAt) as $checkedLink) {
                        $brokenLinks += in_array($checkedLink->result->status, [CheckStatus::Broken, CheckStatus::Unknown], true) ? 1 : 0;
                    }
                    $checked[$table . ':' . $uid] = true;
                    if ($io->isVerbose()) {
                        $io->writeln(sprintf('%s:%d', $table, $uid));
                    }
                }
            }
            $io->success(sprintf('Checked %d records, found %d broken links.', count($checked), $brokenLinks));
            return Command::SUCCESS;
        } finally {
            $locker->release();
        }
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function parseRecordOption(string $value): array
    {
        if (!preg_match('/^([a-z0-9_]+):(\d+)$/i', $value, $matches)) {
            return ['', 0];
        }
        return [$matches[1], (int)$matches[2]];
    }
}
