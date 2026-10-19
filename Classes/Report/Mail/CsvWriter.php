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

use TYPO3\CMS\Core\Utility\CsvUtility;

/**
 * A result of the mail report as an Excel friendly CSV: UTF-8 with BOM, separated by semicolons. Text that a
 * spreadsheet would run as a formula gets a leading quote.
 */
final readonly class CsvWriter
{
    private const HEADER = [
        'Page', 'Page id', 'Table', 'Uid', 'Field', 'Language', 'Record', 'Link text', 'Target', 'Link type',
        'Status', 'Error type', 'Error', 'Broken since', 'Last seen', 'Edit link', 'Module link',
    ];

    /**
     * @param iterable<MailReportItem> $items
     */
    public function write(iterable $items): string
    {
        $csv = "\xEF\xBB\xBF" . $this->row(self::HEADER);
        foreach ($items as $item) {
            $csv .= $this->row([
                $item->pageTitle,
                $item->pageUid,
                $item->table,
                $item->uid,
                $item->field,
                $item->language,
                $item->recordTitle,
                $item->linkText,
                $item->target,
                $item->linkType,
                $item->status,
                $item->errorType,
                $item->errorMessage,
                gmdate('Y-m-d H:i:s', $item->firstDetected),
                gmdate('Y-m-d H:i:s', $item->lastSeen),
                $item->editUrl,
                $item->moduleUrl,
            ]);
        }
        return $csv;
    }

    private function row(array $cells): string
    {
        return CsvUtility::csvValues($cells, ';', '"', CsvUtility::TYPE_PREFIX_CONTROLS) . "\n";
    }
}
