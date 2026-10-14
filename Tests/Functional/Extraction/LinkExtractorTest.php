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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Extraction;

use PHPUnit\Framework\Attributes\Test;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Extraction\LinkExtractor;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LinkExtractorTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    #[Test]
    public function linkFieldAndRichTextLinksAreExtracted(): void
    {
        $row = $this->contentRow([
            'header_link' => 't3://page?uid=2 _blank',
            'bodytext' => '<p><a href="t3://page?uid=3">first</a> and <a href="https://example.org/">second</a></p>',
        ]);

        self::assertSame(
            [
                ['header_link', 't3://page?uid=2', ''],
                ['bodytext', 't3://page?uid=3', 'first'],
                ['bodytext', 'https://example.org/', 'second'],
            ],
            $this->extract('tt_content', $row)
        );
    }

    #[Test]
    public function sameLinkTwiceInOneFieldIsExtractedOnce(): void
    {
        $row = $this->contentRow([
            'bodytext' => '<p><a href="t3://page?uid=3">first</a> <a href="t3://page?uid=3">again</a></p>',
        ]);

        self::assertSame([['bodytext', 't3://page?uid=3', 'first']], $this->extract('tt_content', $row));
    }

    #[Test]
    public function emptyHrefIsExtractedOncePerFieldAnchorsWithoutHrefAreNot(): void
    {
        $row = $this->contentRow([
            'bodytext' => '<p><a href="">gone</a> <a href=" ">again</a> <a name="top">anchor</a> <a href="t3://page?uid=3">page</a></p>',
        ]);

        self::assertSame([['bodytext', '', 'gone'], ['bodytext', 't3://page?uid=3', 'page']], $this->extract('tt_content', $row));
    }

    #[Test]
    public function urlUsedAsLinkTextIsFoundAsLinkAndAsPlainUrl(): void
    {
        // Both share field and target, RecordCheckService checks and reports them once
        $row = $this->contentRow([
            'bodytext' => '<p><a href="https://example.org">https://example.org</a></p>',
        ]);

        self::assertSame(
            [
                ['bodytext', 'https://example.org', 'https://example.org'],
                ['bodytext', 'https://example.org', 'https://example.org'],
            ],
            $this->extract('tt_content', $row)
        );
    }

    #[Test]
    public function plainTextUrlsAreExtractedWithoutTrailingPunctuation(): void
    {
        $row = $this->contentRow([
            'bodytext' => '<p>See https://example.org/page. Or https://example.com/?a=1&amp;b=2, then stop.</p>',
        ]);

        self::assertSame(
            [
                ['bodytext', 'https://example.org/page', 'https://example.org/page'],
                ['bodytext', 'https://example.com/?a=1&b=2', 'https://example.com/?a=1&b=2'],
            ],
            $this->extract('tt_content', $row)
        );
    }

    /**
     * Forge #97937
     */
    #[Test]
    public function plainTextUrlEndsAtNonBreakingSpace(): void
    {
        $row = $this->contentRow([
            'bodytext' => '<p>lorem ipsum https://weber.digital&nbsp; &nbsp; dolor https://example.org&#160;sit https://example.com' . "\u{00A0}" . 'amet https://example.net&#xA0;</p>'
                . '<p>Linear Icons Free (<a class="external" href="https://linearicons.com">https://linearicons.com</a>) (CC BY-SA 4.0) by Perxis (<a class="external" href="https://perxis.com">https://perxis.com</a>)</p>',
        ]);

        self::assertSame(
            [
                'https://linearicons.com',
                'https://perxis.com',
                'https://weber.digital',
                'https://example.org',
                'https://example.com',
                'https://example.net',
            ],
            array_values(array_unique(array_column($this->extract('tt_content', $row), 1)))
        );
    }

    #[Test]
    public function fieldsNotShownForTheRecordTypeAreIgnored(): void
    {
        $row = $this->pageRow(['doktype' => 1, 'link' => 't3://page?uid=3']);

        self::assertSame([], $this->extract('pages', $row));
    }

    #[Test]
    public function linkOfLinkPageIsExtracted(): void
    {
        $row = $this->pageRow(['doktype' => 3, 'link' => 't3://page?uid=3']);

        self::assertSame([['link', 't3://page?uid=3', '']], $this->extract('pages', $row));
    }

    #[Test]
    public function shortcutTargetIsExtracted(): void
    {
        $row = $this->pageRow(['doktype' => 4, 'shortcut' => '5', 'shortcut_mode' => 0]);

        self::assertSame([['shortcut', 't3://page?uid=5', '']], $this->extract('pages', $row));
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}> field, link, link text
     */
    private function extract(string $table, array $row): array
    {
        return array_map(
            static fn(LinkCandidate $candidate): array => [$candidate->field, $candidate->link, $candidate->linkText],
            $this->get(LinkExtractor::class)->extract($table, $row, 1, 0)
        );
    }

    private function contentRow(array $values): array
    {
        return $values + ['uid' => 1, 'pid' => 1, 'CType' => 'text', 'header_link' => '', 'bodytext' => '', 'sys_language_uid' => 0, 'l18n_parent' => 0];
    }

    private function pageRow(array $values): array
    {
        return $values + ['uid' => 1, 'pid' => 0, 'shortcut' => '', 'shortcut_mode' => 0, 'link' => '', 'sys_language_uid' => 0, 'l10n_parent' => 0];
    }
}
