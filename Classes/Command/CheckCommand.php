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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Linkvalidator2\Check\CheckerRegistry;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkExtractor;
use TYPO3\CMS\Linkvalidator2\Target\LinkTargetFactory;

#[AsCommand('linkvalidator2:check', 'Check links in records and store broken ones.')]
final class CheckCommand extends Command
{
    public function __construct(
        private readonly LinkExtractor $linkExtractor,
        private readonly LinkTargetFactory $linkTargetFactory,
        private readonly CheckerRegistry $checkerRegistry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('record', 'r', InputOption::VALUE_REQUIRED, 'Check a single record, format "table:uid"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $record = (string)$input->getOption('record');
        if ($record !== '') {
            [$table, $uid] = $this->parseRecordOption($record);
            if ($table === '') {
                $io->error('Option --record expects "table:uid", e.g. "tt_content:12".');
                return Command::INVALID;
            }
            $row = BackendUtility::getRecord($table, $uid);
            if ($row === null) {
                $io->error(sprintf('Record %s:%d not found.', $table, $uid));
                return Command::FAILURE;
            }
            foreach ($this->linkExtractor->extract($table, $row, (int)$row['pid'], 0) as $candidate) {
                $target = $this->linkTargetFactory->create($candidate);
                $checker = $target !== null ? $this->checkerRegistry->getChecker($target->type) : null;
                if ($target === null || $checker === null) {
                    $io->writeln(sprintf('%s  %s  (skipped)', $candidate->field, $candidate->link));
                    continue;
                }
                $result = $checker->check($target, $candidate);
                $io->writeln(sprintf('%s  %s  %s %s', $candidate->field, $target->identifier, $result->status->value, $result->errorType));
            }
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
