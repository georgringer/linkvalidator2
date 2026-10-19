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

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Turns the error type and details of a check result into the arguments of its label
 * ("linkvalidator2.messages:error.<type>") and into the finished message.
 */
final readonly class ErrorMessageFormatter
{
    public function __construct(
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * Arguments of the error labels: 1. HTTP status, code, date or parent page title, 2. a hint that a
     * notice comes from a parent page.
     *
     * @param array<int, string> $pageTitles titles of pages already looked up, extended by this call
     * @return array{0: string, 1: string}
     */
    public function getArguments(string $errorType, array $details, array &$pageTitles = []): array
    {
        $parentTitle = '';
        if (isset($details['parentPage'])) {
            $parentPage = (int)$details['parentPage'];
            $parentTitle = $pageTitles[$parentPage] ??= BackendUtility::getRecordTitle('pages', BackendUtility::getRecord('pages', $parentPage) ?? ['uid' => $parentPage]);
        }
        $first = match ($errorType) {
            'page.hiddenByParent', 'page.expiredByParent' => $parentTitle,
            'page.notYetVisible' => date('d.m.Y H:i', (int)($details['starttime'] ?? 0)),
            'page.visibleUntil' => date('d.m.Y H:i', (int)($details['endtime'] ?? 0)),
            default => (string)($details['httpStatus'] ?? $details['code'] ?? $details['seconds'] ?? ''),
        };
        $byParent = $parentTitle !== '' && in_array($errorType, ['page.accessRestricted', 'page.notYetVisible', 'page.visibleUntil'], true)
            ? sprintf($this->getLanguageService()->sL('linkvalidator2.messages:error.byParentPage'), $parentTitle)
            : '';
        return [$first, $byParent];
    }

    public function format(string $errorType, array $details): string
    {
        $label = $this->getLanguageService()->sL('linkvalidator2.messages:error.' . $errorType);
        if ($label === '') {
            return $errorType;
        }
        return vsprintf($label, $this->getArguments($errorType, $details));
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('default');
    }
}
