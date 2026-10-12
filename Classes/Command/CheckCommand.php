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

use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Context\Context;

/**
 * Checks records right away in this process, without the queue: for scripts, debugging and
 * installations without a worker. Large page trees are better off with linkvalidator2:fillqueue
 * and messenger:consume, which can use several workers.
 */
#[AsCommand('linkvalidator2:check', 'Check links in records and store broken ones.')]
final class CheckCommand extends Command
{
    /** Records between two progress lines, besides the line after every batch of pages */
    private const PROGRESS_RECORDS = 500;

    public function __construct(
        private readonly RecordCheckService $recordCheckService,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Check a single record, format "table:uid"')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Check all records of a page')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Levels of subpages to include with --page', '0')
            ->addOption('no-external', null, InputOption::VALUE_NONE, 'Do not request external URLs, use stored results only');
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
            return $this->checkPages($pageUid, max(0, (int)$input->getOption('depth')), $cachedOnly, $io);
        }
        $io->error('Nothing to check: pass --page=<uid> (with --depth) or --record=<table:uid>. To check in the background use linkvalidator2:fillqueue and messenger:consume.');
        return Command::INVALID;
    }

    private function checkPages(int $pageUid, int $depth, bool $cachedOnly, SymfonyStyle $io): int
    {
        // Same clock as the check times stored by the checks
        $runStartedAt = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $pages = $records = $broken = 0;
        foreach ($this->pageRecordFinder->iteratePageIdBatches($pageUid, $depth) as $pageIds) {
            foreach ($this->pageRecordFinder->findOnPages($pageIds) as $pid => $found) {
                $this->recordCheckService->removeStaleResults($pid, $found);
                foreach ($found as ['table' => $table, 'uid' => $uid]) {
                    foreach ($this->recordCheckService->checkRecord($table, $uid, $cachedOnly, $runStartedAt) as $checkedLink) {
                        $broken += in_array($checkedLink->result->status, [CheckStatus::Broken, CheckStatus::Unknown], true) ? 1 : 0;
                    }
                    if (++$records % self::PROGRESS_RECORDS === 0) {
                        $this->writeProgress($io, $pages, $records, $broken);
                    }
                }
                $pages++;
            }
            $this->writeProgress($io, $pages, $records, $broken);
        }
        $io->success(sprintf('Checked %d records on %d pages, found %d broken links.', $records, $pages, $broken));
        return Command::SUCCESS;
    }

    private function writeProgress(SymfonyStyle $io, int $pages, int $records, int $broken): void
    {
        $io->writeln(sprintf('%d pages, %d records, %d broken links so far', $pages, $records, $broken));
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
