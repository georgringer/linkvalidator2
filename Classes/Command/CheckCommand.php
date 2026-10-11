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

namespace TYPO3\CMS\Linkvalidator2\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Linkvalidator2\Service\PageCheckService;
use TYPO3\CMS\Linkvalidator2\Service\PageRecordFinder;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;

#[AsCommand('linkvalidator2:check', 'Check links in records and store broken ones.')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly RecordCheckService $recordCheckService,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly PageCheckService $pageCheckService,
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
            $pageIds = $this->pageRecordFinder->findPageIds($pageUid, (int)$input->getOption('depth'));
            $runStartedAt = time();
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
        $io->note('Queue processing follows in a later task.');
        return Command::SUCCESS;
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
