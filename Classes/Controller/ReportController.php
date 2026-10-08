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

namespace TYPO3\CMS\Linkvalidator2\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * @internal This class is a specific Backend controller implementation and is not part of the TYPO3's Core API.
 */
#[AsController]
final readonly class ReportController
{
    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $pageRecord = BackendUtility::readPageAccess($pageUid, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW)) ?: [];

        $view = $this->moduleTemplateFactory->create($request);
        if ($pageRecord !== []) {
            $view->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
        }
        $view->setTitle($this->getLanguageService()->sL('linkvalidator2.module:title'));
        $view->assignMultiple([
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ''),
        ]);
        return $view->renderResponse('Backend/Report');
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
