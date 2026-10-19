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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\Mail\MailReportItem;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportOptions;
use GeorgRinger\Linkvalidator2\Report\Mail\ReportContent;
use GeorgRinger\Linkvalidator2\Report\Mail\ReportMailFactory;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\TemplatedEmailFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportMailFactoryTest extends FunctionalTestCase
{
    private const NOW = 1_792_396_800;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    #[Test]
    public function inlineModeListsTheItemsAndAttachesNothing(): void
    {
        $email = $this->create(ReportContent::Inline, items: [$this->item('https://example.org/gone')], total: 1, csv: null);

        self::assertStringContainsString('https://example.org/gone', (string)$email->getHtmlBody());
        self::assertStringContainsString('https://example.org/gone', (string)$email->getTextBody());
        self::assertCount(0, $email->getAttachments());
    }

    #[Test]
    public function attachModeHasASummaryAndTheCsvButNoRows(): void
    {
        $email = $this->create(ReportContent::Attach, items: [], total: 3, csv: "csv-content");

        self::assertStringContainsString('3 broken links', (string)$email->getHtmlBody());
        self::assertStringNotContainsString('<table', (string)$email->getHtmlBody());
        self::assertCount(1, $email->getAttachments());
    }

    #[Test]
    public function bothModeHasRowsAndTheCsv(): void
    {
        $email = $this->create(ReportContent::Both, items: [$this->item('https://example.org/gone')], total: 1, csv: 'csv-content');

        self::assertStringContainsString('https://example.org/gone', (string)$email->getHtmlBody());
        self::assertCount(1, $email->getAttachments());
    }

    #[Test]
    public function theCsvAttachmentIsNamedAndTyped(): void
    {
        $attachment = $this->create(ReportContent::Attach, items: [], total: 1, csv: 'csv-content')->getAttachments()[0];

        self::assertSame('broken-links-2026-10-19.csv', $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename'));
        self::assertSame('text/csv', $attachment->getMediaType() . '/' . $attachment->getMediaSubtype());
        self::assertSame('csv-content', $attachment->getBody());
    }

    #[Test]
    public function noCsvIsAttachedWhenThereIsNone(): void
    {
        $email = $this->create(ReportContent::Both, items: [], total: 0, csv: null);

        self::assertCount(0, $email->getAttachments());
    }

    #[Test]
    public function moreThanTheLimitShowsHowManyAreLeftWithTheModuleLink(): void
    {
        $email = $this->create(ReportContent::Inline, items: [$this->item('https://example.org/gone')], total: 8, csv: null);

        self::assertStringContainsString('7 more', (string)$email->getTextBody());
        self::assertStringContainsString('https://example.com/typo3/module', (string)$email->getTextBody());
    }

    #[Test]
    public function theDefaultSubjectNamesThePage(): void
    {
        $email = $this->create(ReportContent::Inline, items: [], total: 1, csv: null);

        self::assertSame('Broken links: Home', $email->getSubject());
    }

    #[Test]
    public function aCustomSubjectWins(): void
    {
        $email = $this->create(ReportContent::Inline, items: [], total: 1, csv: null, subject: 'Weekly check');

        self::assertSame('Weekly check', $email->getSubject());
    }

    #[Test]
    public function fromOverridesTheSystemSenderAndToIsSet(): void
    {
        $email = $this->create(ReportContent::Inline, items: [], total: 1, csv: null, from: 'robot@example.org');

        self::assertEquals([new Address('robot@example.org')], $email->getFrom());
        self::assertEquals([new Address('editor@example.org'), new Address('lead@example.org')], $email->getTo());
    }

    #[Test]
    public function htmlInLinkTextAndTargetIsEscaped(): void
    {
        $item = $this->item('https://example.org/?a=<script>alert(1)</script>', '<b>bold</b>');
        $email = $this->create(ReportContent::Inline, items: [$item], total: 1, csv: null);

        $html = (string)$email->getHtmlBody();
        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringNotContainsString('<b>bold</b>', $html);
        self::assertStringContainsString('&lt;b&gt;bold&lt;/b&gt;', $html);
    }

    #[Test]
    public function theTextVersionIsNotHtmlEscaped(): void
    {
        $email = $this->create(ReportContent::Inline, items: [$this->item('https://example.org/?a=1&b=2')], total: 1, csv: null, pageTitle: 'Home "A" & B');

        $text = (string)$email->getTextBody();
        self::assertStringContainsString('"Home "A" & B"', $text);
        self::assertStringContainsString('https://example.org/?a=1&b=2', $text);
        self::assertStringNotContainsString('&amp;', $text);
        self::assertStringNotContainsString('&quot;', $text);
    }

    /**
     * @param list<MailReportItem> $items
     */
    private function create(ReportContent $content, array $items, int $total, ?string $csv, ?string $subject = null, ?string $from = null, string $pageTitle = 'Home'): \TYPO3\CMS\Core\Mail\FluidEmail
    {
        $factory = new ReportMailFactory($this->get(TemplatedEmailFactory::class), $this->get(LanguageServiceFactory::class));
        $options = new MailReportOptions(['editor@example.org', 'lead@example.org'], $from, $subject, $content, 50, 'BrokenLinksReport', 'default');
        return $factory->create($options, $pageTitle, $total, $items, $csv, 'https://example.com/typo3/module/link-management/linkvalidator2?id=1', self::NOW);
    }

    private function item(string $target, string $linkText = 'Read more'): MailReportItem
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
            firstDetected: self::NOW - 86400,
            lastSeen: self::NOW,
            editUrl: 'https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit',
            moduleUrl: 'https://example.com/typo3/module/link-management/linkvalidator2?id=1',
        );
    }
}
