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

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\Mail\CsvWriter;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportItem;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CsvWriterTest extends UnitTestCase
{
    private const HEADER = 'Page;"Page id";Table;Uid;Field;Language;Record;"Link text";Target;"Link type";Status;"Error type";Error;"Broken since";"Last seen";"Edit link";"Module link"';

    #[Test]
    public function outputStartsWithTheUtf8Bom(): void
    {
        self::assertStringStartsWith("\xEF\xBB\xBF", (new CsvWriter())->write([]));
    }

    #[Test]
    public function anEmptyListYieldsOnlyTheHeader(): void
    {
        self::assertSame(self::HEADER . "\n", $this->withoutBom((new CsvWriter())->write([])));
    }

    #[Test]
    public function headerAndOneRowAreWritten(): void
    {
        $csv = $this->withoutBom((new CsvWriter())->write([$this->item()]));

        self::assertSame(
            self::HEADER . "\n"
            . 'Home;1;tt_content;12;bodytext;0;Intro;Read;https://example.org/gone;url;broken;url.notFound;"Not found (404)";"2026-10-19 08:00:00";"2026-10-19 09:30:00";https://example.org/typo3/edit;https://example.org/typo3/module' . "\n",
            $csv
        );
    }

    #[Test]
    public function fieldsWithSemicolonQuoteAndNewlineAreQuoted(): void
    {
        $csv = $this->withoutBom((new CsvWriter())->write([$this->item(linkText: "a;b \"c\"\nd")]));

        self::assertStringContainsString("\"a;b \"\"c\"\"\nd\"", $csv);
    }

    #[Test]
    public function valuesStartingWithFormulaCharactersAreNeutralised(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@x'] as $value) {
            $csv = $this->withoutBom((new CsvWriter())->write([$this->item(linkText: $value, target: $value)]));
            self::assertStringContainsString(";'" . $value . ";'" . $value . ';', $csv, $value);
        }
    }

    private function withoutBom(string $csv): string
    {
        return substr($csv, 3);
    }

    private function item(string $linkText = 'Read', string $target = 'https://example.org/gone'): MailReportItem
    {
        return new MailReportItem(
            pageTitle: 'Home',
            pageUid: 1,
            table: 'tt_content',
            uid: 12,
            field: 'bodytext',
            language: 0,
            recordTitle: 'Intro',
            linkText: $linkText,
            target: $target,
            linkType: 'url',
            status: 'broken',
            errorType: 'url.notFound',
            errorMessage: 'Not found (404)',
            firstDetected: 1_792_396_800,
            lastSeen: 1_792_402_200,
            editUrl: 'https://example.org/typo3/edit',
            moduleUrl: 'https://example.org/typo3/module',
        );
    }
}
