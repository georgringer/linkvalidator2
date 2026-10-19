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

namespace GeorgRinger\Linkvalidator2\EventListener;

use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;
use GeorgRinger\Linkvalidator2\Report\ReportPermissionsFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkQuery;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use TYPO3\CMS\Backend\Controller\Event\ModifyPageLayoutContentEvent;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Points to the broken links of a page above the page module, as far as the user may fix them. The
 * extension configuration "pageModuleCallout" switches it off for everyone, Page TSconfig
 * mod.linkvalidator2.pageModuleCallout = 0|1 decides for a page tree. Results exist for the live
 * workspace only.
 */
final readonly class PageModuleCalloutListener
{
    public function __construct(
        private ExtensionSettings $extensionSettings,
        private ReportPermissionsFactory $reportPermissionsFactory,
        private BrokenLinkRepository $brokenLinkRepository,
        private UriBuilder $uriBuilder,
        private IconFactory $iconFactory,
    ) {}

    #[AsEventListener('linkvalidator2/page-module-callout')]
    public function __invoke(ModifyPageLayoutContentEvent $event): void
    {
        $pageUid = (int)($event->getRequest()->getQueryParams()['id'] ?? 0);
        $user = $this->getBackendUser();
        if ($pageUid <= 0 || (int)$user->workspace !== 0 || !$this->isEnabled($pageUid)) {
            return;
        }
        $count = $this->brokenLinkRepository->count(new BrokenLinkQuery([$pageUid], permissions: $this->reportPermissionsFactory->createForUser($user), onlyBroken: true));
        if ($count === 0) {
            return;
        }
        $languageService = $this->getLanguageService();
        $title = (string)$languageService->label('linkvalidator2.messages:callout.title', ['count' => $count]);
        $event->addHeaderContent(
            '<div class="callout callout-warning">'
                . '<div class="callout-icon"><span class="icon-emphasized">' . $this->iconFactory->getIcon('module-linkvalidator', IconSize::SMALL)->render() . '</span></div>'
                . '<div class="callout-content">'
                    . '<div class="callout-title">' . htmlspecialchars($title) . '</div>'
                    . '<div class="callout-body"><a href="' . htmlspecialchars((string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report', ['id' => $pageUid])) . '">'
                        . htmlspecialchars((string)$languageService->label('linkvalidator2.messages:callout.open')) . '</a></div>'
                . '</div>'
            . '</div>'
        );
    }

    private function isEnabled(int $pageUid): bool
    {
        $setting = BackendUtility::getPagesTSconfig($pageUid)['mod.']['linkvalidator2.']['pageModuleCallout'] ?? null;
        return $setting === null || $setting === '' ? $this->extensionSettings->isPageModuleCalloutEnabled() : (bool)(int)$setting;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'];
    }
}
