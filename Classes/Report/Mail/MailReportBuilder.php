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

namespace GeorgRinger\Linkvalidator2\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkQuery;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Reads the broken links of a mail report with the query of the module. The oldest come first.
 */
final class MailReportBuilder
{
    private const PAGE_SIZE = 1000;

    /** @var array<int, string> */
    private array $pageTitles = [];

    public function __construct(
        private readonly BrokenLinkRepository $brokenLinkRepository,
        private readonly PageRecordFinder $pageRecordFinder,
        private readonly ErrorMessageFormatter $errorMessageFormatter,
    ) {}

    public function count(MailReportRequest $request, int $now): int
    {
        return $this->brokenLinkRepository->count($this->createQuery($request, $now));
    }

    /**
     * @return list<MailReportItem>
     */
    public function first(MailReportRequest $request, BackendUrls $urls, int $now, int $limit): array
    {
        $rows = $this->brokenLinkRepository->find($this->createQuery($request, $now), $this->createOrdering(), $limit);
        return array_map(fn(array $row): MailReportItem => $this->createItem($row, $urls), $rows);
    }

    /**
     * @return \Generator<int, MailReportItem>
     */
    public function iterate(MailReportRequest $request, BackendUrls $urls, int $now, int $pageSize = self::PAGE_SIZE): \Generator
    {
        $query = $this->createQuery($request, $now);
        $ordering = $this->createOrdering();
        for ($offset = 0; ; $offset += $pageSize) {
            $rows = $this->brokenLinkRepository->find($query, $ordering, $pageSize, $offset);
            foreach ($rows as $row) {
                yield $this->createItem($row, $urls);
            }
            if (count($rows) < $pageSize) {
                return;
            }
        }
    }

    private function createQuery(MailReportRequest $request, int $now): BrokenLinkQuery
    {
        [$detectedSince, $detectedBefore] = $request->getDetectedRange($now);
        return new BrokenLinkQuery(
            $this->pageRecordFinder->findPageIds($request->pageUid, $request->depth),
            $request->filter,
            null,
            false,
            $detectedSince,
            $detectedBefore,
            $request->includeTables,
            $request->excludeTables,
        );
    }

    private function createOrdering(): ReportOrdering
    {
        return new ReportOrdering('since');
    }

    private function createItem(array $row, BackendUrls $urls): MailReportItem
    {
        $table = (string)$row['table_name'];
        $uid = (int)$row['record_uid'];
        $pid = (int)$row['pid'];
        $record = BackendUtility::getRecord($table, $uid);
        return new MailReportItem(
            pageTitle: $this->getPageTitle($pid),
            pageUid: $pid,
            table: $table,
            uid: $uid,
            field: (string)$row['field'],
            language: (int)$row['language'],
            recordTitle: $record !== null ? BackendUtility::getRecordTitle($table, $record) : $table . ':' . $uid,
            linkText: (string)$row['link_text'],
            target: (string)$row['link_string'],
            linkType: (string)$row['link_type'],
            status: (string)$row['status'],
            errorType: (string)$row['error_type'],
            errorMessage: $this->errorMessageFormatter->format((string)$row['error_type'], (array)json_decode((string)$row['error_details'], true)),
            firstDetected: (int)$row['first_detected'],
            lastSeen: (int)$row['last_seen'],
            editUrl: $urls->edit($table, $uid),
            moduleUrl: $urls->module($pid),
        );
    }

    private function getPageTitle(int $pid): string
    {
        return $this->pageTitles[$pid] ??= BackendUtility::getRecordTitle('pages', BackendUtility::getRecord('pages', $pid) ?? ['uid' => $pid]);
    }
}
