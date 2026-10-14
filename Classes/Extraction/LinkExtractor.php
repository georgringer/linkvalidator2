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
namespace GeorgRinger\Linkvalidator2\Extraction;

use TYPO3\CMS\Core\DataHandling\SoftReference\SoftReferenceParserFactory;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;

final readonly class LinkExtractor
{
    public function __construct(
        private CheckableFieldResolver $checkableFieldResolver,
        private TypoLinkCodecService $typoLinkCodecService,
        private HtmlParser $htmlParser,
        private SoftReferenceParserFactory $softReferenceParserFactory,
    ) {}

    /**
     * @return list<LinkCandidate>
     */
    public function extract(string $table, array $row, int $pid, int $language): array
    {
        $candidates = [];
        foreach ($this->checkableFieldResolver->getFieldsForRecord($table, $row) as $field) {
            $value = (string)($row[$field->name] ?? '');
            if ($value === '') {
                continue;
            }
            foreach ($field->modes as $mode) {
                $links = match ($mode) {
                    ExtractionMode::Typolink => $this->extractFromTypolink($value),
                    ExtractionMode::Html => $this->extractFromHtml($value),
                    ExtractionMode::PlainUrl => $this->extractPlainUrls($value),
                };
                foreach ($links as $link => $linkText) {
                    $candidates[] = new LinkCandidate($table, (int)$row['uid'], $pid, $language, $field->name, (string)$link, $linkText);
                }
            }
        }
        $shortcut = $this->extractShortcut($table, $row, $pid, $language);
        if ($shortcut !== null) {
            $candidates[] = $shortcut;
        }
        return $candidates;
    }

    /**
     * @return array<string, string> link => link text
     */
    private function extractFromTypolink(string $value): array
    {
        $link = trim((string)($this->typoLinkCodecService->decode($value)['url'] ?? ''));
        return $link === '' ? [] : [$link => ''];
    }

    /**
     * Keyed by link, so a link used twice in the same field is reported once. The key of an empty
     * href is an empty string.
     *
     * @return array<string, string> link => link text
     */
    private function extractFromHtml(string $html): array
    {
        $links = [];
        $blocks = $this->htmlParser->splitIntoBlock('a', $html);
        for ($i = 1, $count = count($blocks); $i < $count; $i += 2) {
            $attributes = $this->htmlParser->get_tag_attributes($this->htmlParser->getFirstTag($blocks[$i]), true)[0];
            // An anchor without href attribute is no link. An empty one is: the RTE turns links to
            // missing files and folders into href="", which visitors cannot follow.
            if (!isset($attributes['href'])) {
                continue;
            }
            $href = trim((string)$attributes['href']);
            if (isset($links[$href])) {
                continue;
            }
            $links[$href] = trim(strip_tags($this->htmlParser->removeFirstAndLastTag($blocks[$i])));
        }
        return $links;
    }

    /**
     * The parser skips URLs directly after a quote or "=", so href attributes are not found twice.
     * A URL used as link text is, but deduplicated with its link when both are the same target.
     *
     * @return array<string, string> link => link text
     */
    private function extractPlainUrls(string $value): array
    {
        $links = [];
        // The parser takes a non-breaking space for part of the URL
        $value = preg_replace('/&nbsp;|&#160;|&#x0*a0;|\x{00A0}/iu', ' ', $value) ?? $value;
        $result = $this->softReferenceParserFactory->getSoftReferenceParser('url')->parse('', '', 0, $value);
        foreach ($result->getMatchedElements() as $element) {
            // Sentence punctuation directly after a URL is matched by the parser as well
            $url = rtrim(htmlspecialchars_decode((string)($element['matchString'] ?? '')), '.,;:!?');
            if ($url !== '') {
                $links[$url] = $url;
            }
        }
        return $links;
    }

    /**
     * A shortcut to a fixed page links to that page. In the other modes (first or random subpage,
     * parent page, or no page selected) the shortcut page links to itself: the frontend's page
     * resolution of it decides whether the shortcut leads anywhere (Forge #92542).
     */
    private function extractShortcut(string $table, array $row, int $pid, int $language): ?LinkCandidate
    {
        if ($table !== 'pages' || (int)($row['doktype'] ?? 0) !== PageRepository::DOKTYPE_SHORTCUT) {
            return null;
        }
        $targetUid = (int)preg_replace('/^pages_/', '', (string)($row['shortcut'] ?? ''));
        if ((int)($row['shortcut_mode'] ?? 0) === PageRepository::SHORTCUT_MODE_NONE && $targetUid > 0) {
            return new LinkCandidate($table, (int)$row['uid'], $pid, $language, 'shortcut', 't3://page?uid=' . $targetUid, '');
        }
        return new LinkCandidate($table, (int)$row['uid'], $pid, $language, 'shortcut_mode', 't3://page?uid=' . $pid, '');
    }
}
