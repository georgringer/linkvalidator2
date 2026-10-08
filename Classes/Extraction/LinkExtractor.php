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
namespace TYPO3\CMS\Linkvalidator2\Extraction;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\LinkHandling\TypoLinkCodecService;

final readonly class LinkExtractor
{
    public function __construct(
        private CheckableFieldResolver $checkableFieldResolver,
        private TypoLinkCodecService $typoLinkCodecService,
        private HtmlParser $htmlParser,
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
            $links = match ($field->mode) {
                ExtractionMode::Typolink => $this->extractFromTypolink($value),
                ExtractionMode::Html => $this->extractFromHtml($value),
            };
            foreach ($links as $link => $linkText) {
                $candidates[] = new LinkCandidate($table, (int)$row['uid'], $pid, $language, $field->name, (string)$link, $linkText);
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
     * Keyed by link, so a link used twice in the same field is reported once.
     *
     * @return array<string, string> link => link text
     */
    private function extractFromHtml(string $html): array
    {
        $links = [];
        $blocks = $this->htmlParser->splitIntoBlock('a', $html);
        for ($i = 1, $count = count($blocks); $i < $count; $i += 2) {
            $attributes = $this->htmlParser->get_tag_attributes($this->htmlParser->getFirstTag($blocks[$i]), true)[0];
            $href = trim((string)($attributes['href'] ?? ''));
            if ($href === '' || isset($links[$href])) {
                continue;
            }
            $links[$href] = trim(strip_tags($this->htmlParser->removeFirstAndLastTag($blocks[$i])));
        }
        return $links;
    }

    private function extractShortcut(string $table, array $row, int $pid, int $language): ?LinkCandidate
    {
        if ($table !== 'pages'
            || (int)($row['doktype'] ?? 0) !== PageRepository::DOKTYPE_SHORTCUT
            || (int)($row['shortcut_mode'] ?? 0) !== PageRepository::SHORTCUT_MODE_NONE
        ) {
            return null;
        }
        $targetUid = (int)preg_replace('/^pages_/', '', (string)($row['shortcut'] ?? ''));
        if ($targetUid <= 0) {
            return null;
        }
        return new LinkCandidate($table, (int)$row['uid'], $pid, $language, 'shortcut', 't3://page?uid=' . $targetUid, '');
    }
}
