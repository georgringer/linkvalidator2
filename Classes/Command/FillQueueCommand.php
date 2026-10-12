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

use GeorgRinger\Linkvalidator2\Service\QueueFiller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Backend\Utility\BackendUtility;

#[AsCommand('linkvalidator2:fillqueue', 'Queue the records of a page tree for checking, to be processed by messenger:consume.')]
final class FillQueueCommand extends Command
{
    public function __construct(
        private readonly QueueFiller $queueFiller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'Page to start at; its subpages are included')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Levels of subpages to include', '999')
            ->addOption('chunk-size', 'c', InputOption::VALUE_REQUIRED, 'Records per message; a message is acknowledged only when all its records are checked, so keep it small when many external URLs time out', '25');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageUid = (int)$input->getArgument('page');
        if ($pageUid <= 0 || BackendUtility::getRecord('pages', $pageUid, 'uid') === null) {
            $io->error(sprintf('Page "%s" does not exist.', (string)$input->getArgument('page')));
            return Command::FAILURE;
        }
        $result = $this->queueFiller->fill($pageUid, max(0, (int)$input->getOption('depth')), (int)$input->getOption('chunk-size'));
        $io->success(sprintf('Queued %d records of %d pages in %d messages.', $result['records'], $result['pages'], $result['messages']));
        return Command::SUCCESS;
    }
}
