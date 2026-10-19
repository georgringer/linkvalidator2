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

/**
 * A result of the mail report as an Excel friendly CSV: UTF-8 with BOM, separated by semicolons.
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
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, "\xEF\xBB\xBF");
        $this->putRow($stream, self::HEADER);
        foreach ($items as $item) {
            $this->putRow($stream, [
                $this->text($item->pageTitle),
                $item->pageUid,
                $item->table,
                $item->uid,
                $item->field,
                $item->language,
                $this->text($item->recordTitle),
                $this->text($item->linkText),
                $this->text($item->target),
                $item->linkType,
                $item->status,
                $item->errorType,
                $this->text($item->errorMessage),
                gmdate('Y-m-d H:i:s', $item->firstDetected),
                gmdate('Y-m-d H:i:s', $item->lastSeen),
                $item->editUrl,
                $item->moduleUrl,
            ]);
        }
        rewind($stream);
        $csv = (string)stream_get_contents($stream);
        fclose($stream);
        return $csv;
    }

    /**
     * @param resource $stream
     */
    private function putRow($stream, array $row): void
    {
        fputcsv($stream, $row, ';', '"', '');
    }

    /**
     * A spreadsheet would run text starting with one of these characters as a formula.
     */
    private function text(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'" . $value : $value;
    }
}
