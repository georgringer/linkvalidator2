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

use GeorgRinger\Linkvalidator2\Report\Mail\BackendUrlFactory;
use GeorgRinger\Linkvalidator2\Report\Mail\CsvWriter;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportBuilder;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportOptions;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportRequest;
use GeorgRinger\Linkvalidator2\Report\Mail\ReportContent;
use GeorgRinger\Linkvalidator2\Report\Mail\ReportMailFactory;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsCommand('linkvalidator2:report', 'Mail the broken links of a page and its subpages.')]
final class ReportCommand extends Command
{
    private const STATUS_ALL = 'all';
    private const STATUSES = ['broken', 'unknown', 'info'];

    public function __construct(
        private readonly MailReportBuilder $mailReportBuilder,
        private readonly BackendUrlFactory $backendUrlFactory,
        private readonly ReportMailFactory $reportMailFactory,
        private readonly CsvWriter $csvWriter,
        private readonly MailerInterface $mailer,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly Context $context,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('page', InputArgument::REQUIRED, 'Page to start at')
            ->addOption('to', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Recipient, repeat or separate by comma')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'Sender, the system sender if empty')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject, "Broken links: <page title>" if empty')
            ->addOption('depth', 'd', InputOption::VALUE_REQUIRED, 'Levels of subpages to include', '0')
            ->addOption('status', null, InputOption::VALUE_REQUIRED, 'Only links with this status: broken, unknown, info or all', 'broken')
            ->addOption('link-type', null, InputOption::VALUE_REQUIRED, 'Only links of this link type, for example url or page')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'Only links in records of this language id')
            ->addOption('search', null, InputOption::VALUE_REQUIRED, 'Only links containing this text in the target or the link text')
            ->addOption('include-tables', null, InputOption::VALUE_REQUIRED, 'Only results of these tables, separated by comma')
            ->addOption('exclude-tables', null, InputOption::VALUE_REQUIRED, 'No results of these tables, separated by comma; applied after --include-tables')
            ->addOption('min-age', null, InputOption::VALUE_REQUIRED, 'Only links broken for at least this long, for example "7 days"')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'Only links broken for at most this long, for example "1 day"')
            ->addOption('content', null, InputOption::VALUE_REQUIRED, 'What the mail contains: inline, attach (CSV) or both', ReportContent::Inline->value)
            ->addOption('inline-limit', null, InputOption::VALUE_REQUIRED, 'Most links listed in the text of the mail', '50')
            ->addOption('send-empty', null, InputOption::VALUE_NONE, 'Send the mail also if there are no links')
            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Name of the Fluid email template', 'BrokenLinksReport')
            ->addOption('mail-language', null, InputOption::VALUE_REQUIRED, 'Language key of the texts in the mail', 'default')
            ->addOption('base-url', null, InputOption::VALUE_REQUIRED, 'Base URL of the backend host if the page belongs to no site');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pageUid = (int)$input->getArgument('page');
        $pageRecord = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid) : null;
        if ($pageRecord === null) {
            $io->error(sprintf('Page "%s" does not exist.', (string)$input->getArgument('page')));
            return Command::FAILURE;
        }
        $recipients = $this->splitList($input->getOption('to'));
        if ($recipients === []) {
            $io->error('Give at least one recipient with --to.');
            return Command::FAILURE;
        }
        foreach ([...$recipients, ...$this->splitList((string)$input->getOption('from'))] as $address) {
            if (!GeneralUtility::validEmail($address)) {
                $io->error(sprintf('"%s" is not a valid email address.', $address));
                return Command::FAILURE;
            }
        }
        $content = ReportContent::tryFrom((string)$input->getOption('content'));
        if ($content === null) {
            $io->error('--content must be inline, attach or both.');
            return Command::FAILURE;
        }
        $status = (string)$input->getOption('status');
        if ($status !== self::STATUS_ALL && !in_array($status, self::STATUSES, true)) {
            $io->error('--status must be one of ' . implode(', ', [...self::STATUSES, self::STATUS_ALL]) . '.');
            return Command::FAILURE;
        }
        $language = (string)$input->getOption('language');
        $request = new MailReportRequest(
            $pageUid,
            max(0, (int)$input->getOption('depth')),
            new ReportFilter(
                $status === self::STATUS_ALL ? '' : $status,
                (string)$input->getOption('link-type'),
                '',
                $language !== '' && is_numeric($language) ? (int)$language : null,
                trim((string)$input->getOption('search')),
            ),
            $this->splitList((string)$input->getOption('include-tables')),
            $this->splitList((string)$input->getOption('exclude-tables')),
            $this->emptyToNull($input->getOption('min-age')),
            $this->emptyToNull($input->getOption('max-age')),
        );
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        try {
            $urls = $this->backendUrlFactory->create($pageUid, $this->emptyToNull($input->getOption('base-url')));
            $total = $this->mailReportBuilder->count($request, $now);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }
        if ($total === 0 && !$input->getOption('send-empty')) {
            $io->success('No broken links found, no mail sent.');
            return Command::SUCCESS;
        }

        $mailLanguage = (string)$input->getOption('mail-language');
        $GLOBALS['LANG'] = $this->languageServiceFactory->create($mailLanguage);
        $inlineLimit = max(0, (int)$input->getOption('inline-limit'));
        $inline = $content->includesInline() && $inlineLimit > 0 && $total > 0 ? $this->mailReportBuilder->first($request, $urls, $now, $inlineLimit) : [];
        $csv = $content->includesCsv() && $total > 0 ? $this->csvWriter->write($this->mailReportBuilder->iterate($request, $urls, $now)) : null;
        $email = $this->reportMailFactory->create(
            new MailReportOptions(
                $recipients,
                $this->emptyToNull($input->getOption('from')),
                $this->emptyToNull($input->getOption('subject')),
                $content,
                $inlineLimit,
                (string)$input->getOption('template'),
                $mailLanguage,
            ),
            BackendUtility::getRecordTitle('pages', $pageRecord),
            $total,
            $inline,
            $csv,
            $urls->module($pageUid),
            $now,
            $urls->getRequest(),
        );
        try {
            $this->mailer->send($email);
        } catch (\Throwable $e) {
            $io->error('The mail could not be sent: ' . $e->getMessage());
            return Command::FAILURE;
        }
        $io->success(sprintf('Mailed %d broken links to %s.', $total, implode(', ', $recipients)));
        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function splitList(mixed $value): array
    {
        $parts = [];
        foreach ((array)$value as $item) {
            array_push($parts, ...GeneralUtility::trimExplode(',', (string)$item, true));
        }
        return $parts;
    }

    private function emptyToNull(mixed $value): ?string
    {
        return $value === null || trim((string)$value) === '' ? null : trim((string)$value);
    }
}
