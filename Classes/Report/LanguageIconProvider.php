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
namespace GeorgRinger\Linkvalidator2\Report;

use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The flag of the language of a result for the report. The language is the one of the site the
 * page of the record belongs to, which is not necessarily the one of the page selected in the
 * report. Only sites with more than one language get flags: with a single language they would
 * show the same flag in every row.
 */
final class LanguageIconProvider
{
    /** @var array<string, array<int, array{flag: string, title: string}>> Languages by site, empty for a site that needs no flags */
    private array $languagesBySite = [];

    /** @var array<int, string> Site identifier by page, empty if there is no site */
    private array $siteOfPage = [];

    public function __construct(
        private readonly SiteFinder $siteFinder,
        private readonly IconFactory $iconFactory,
    ) {}

    /**
     * @return array{icon: string, title: string}|null Null if the site of the page has a single language
     */
    public function describe(int $pageUid, int $language): ?array
    {
        $languages = $this->getLanguages($pageUid);
        if ($languages === []) {
            return null;
        }
        $description = $language === -1
            ? ['flag' => 'flags-multiple', 'title' => $this->getLanguageService()->sL('linkvalidator2.messages:language.all')]
            : ($languages[$language] ?? null);
        if ($description === null) {
            return null;
        }
        return [
            'icon' => $this->iconFactory->getIcon($description['flag'], IconSize::SMALL)->setTitle($description['title'])->render(),
            'title' => $description['title'],
        ];
    }

    /**
     * @return array<int, array{flag: string, title: string}>
     */
    private function getLanguages(int $pageUid): array
    {
        if (!isset($this->siteOfPage[$pageUid])) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageUid);
                $this->siteOfPage[$pageUid] = $site->getIdentifier();
                $this->languagesBySite[$site->getIdentifier()] ??= $this->readLanguages($site);
            } catch (SiteNotFoundException) {
                $this->siteOfPage[$pageUid] = '';
            }
        }
        return $this->languagesBySite[$this->siteOfPage[$pageUid]] ?? [];
    }

    /**
     * @return array<int, array{flag: string, title: string}>
     */
    private function readLanguages(Site $site): array
    {
        $languages = $site->getAllLanguages();
        if (count($languages) < 2) {
            return [];
        }
        $result = [];
        foreach ($languages as $siteLanguage) {
            $result[$siteLanguage->getLanguageId()] = ['flag' => $siteLanguage->getFlagIdentifier(), 'title' => $siteLanguage->getTitle()];
        }
        return $result;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
