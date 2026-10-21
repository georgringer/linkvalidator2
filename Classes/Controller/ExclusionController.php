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

namespace GeorgRinger\Linkvalidator2\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionPermissions;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionRepository;

/**
 * Lists the exclusions stored on the selected page and its subpages, and the ones of the
 * pages above that apply here as well. Creates exclusions from the report ("Ignore").
 *
 * @internal This class is a specific Backend controller implementation and is not part of the TYPO3's Core API.
 */
#[AsController]
final readonly class ExclusionController
{
    private const DEPTH_OPTIONS = [0, 1, 2, 3, 4, 999];
    private const ROUTE = 'linkvalidator2_report.exclusions';
    /** Types that can be created from a result in the report */
    private const IGNORE_TYPES = ['url', 'page'];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private ExclusionRepository $exclusionRepository,
        private ModuleProvider $moduleProvider,
        private ExclusionPermissions $exclusionPermissions,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $backendUser = $this->getBackendUser();
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $pageRecord = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) ?: [];
        $depth = (int)$request->getAttribute('moduleData')->get('exclusionDepth', 0);
        if (!in_array($depth, self::DEPTH_OPTIONS, true)) {
            $depth = 0;
        }

        $view = $this->moduleTemplateFactory->create($request);
        $view->setTitle($this->getLanguageService()->sL('linkvalidator2.messages:exclusions.title'));
        $view->makeDocHeaderModuleMenu(['id' => $pageUid]);
        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report', ['id' => $pageUid]))
                ->setTitle($this->getLanguageService()->sL('linkvalidator2.messages:exclusions.backToReport'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_RIGHT,
            1
        );
        if ($pageRecord !== []) {
            if ($pageUid > 0) {
                $view->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
        $view->getDocHeaderComponent()->setShortcutContext(
            'linkvalidator2_report.exclusions',
            sprintf($this->getLanguageService()->translate('shortcut.title', 'backend.messages'), $this->getLanguageService()->sL('linkvalidator2.messages:exclusions.title'), '', $pageRecord['title'], $pageUid),
            ['id' => $pageUid]
        );
            }
            $this->addDocHeaderButtons($view, $pageUid, $depth, $pageRecord, (string)$request->getAttribute('normalizedParams')->getRequestUri());
        }

        $view->assignMultiple([
            'pageUid' => $pageUid,
            'hasAccess' => $pageRecord !== [],
            'pageTitle' => (string)($pageRecord['title'] ?? ''),
            'depth' => $depth,
            'table' => ExclusionRepository::TABLE,
            'inherited' => $pageRecord !== [] && $pageUid > 0 ? $this->getInherited($pageUid) : [],
        ]);
        return $view->renderResponse('Backend/Exclusions');
    }

    /**
     * AJAX: creates an exclusion on the page selected in the report, through the DataHandler, so
     * the permissions of the user apply.
     * The DataHandler hook removes the results it excludes.
     */
    public function createAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->moduleProvider->accessGranted('linkvalidator2_report', $this->getBackendUser())) {
            return new JsonResponse(['error' => 'Access denied'], 403);
        }
        $body = (array)$request->getParsedBody();
        $pid = (int)($body['pid'] ?? 0);
        $type = (string)($body['type'] ?? '');
        $value = trim((string)($body['value'] ?? ''));
        if (!in_array($type, self::IGNORE_TYPES, true) || $value === '') {
            return new JsonResponse(['error' => 'Invalid exclusion'], 400);
        }
        $record = ['pid' => $pid, 'type' => $type];
        match ($type) {
            'url' => $record['value'] = explode('#', $value, 2)[0],
            'page' => $record['target_page'] = (int)$value,
        };
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start([ExclusionRepository::TABLE => ['NEW1' => $record]], []);
        $dataHandler->process_datamap();
        $uid = (int)($dataHandler->substNEWwithIDs['NEW1'] ?? 0);
        if ($uid === 0 || $dataHandler->errorLog !== []) {
            return new JsonResponse(['error' => implode(' ', $dataHandler->errorLog) ?: 'Access denied'], 403);
        }
        $title = $pid > 0 ? (string)(BackendUtility::getRecord('pages', $pid, 'title')['title'] ?? '') : $this->getLanguageService()->sL('linkvalidator2.messages:exclusions.rootLevel');
        return new JsonResponse(['uid' => $uid, 'page' => $title . ' [' . $pid . ']']);
    }

    /**
     * Exclusions of the pages above and of the root level
     *
     * @return list<array{uid: int, pid: int, title: string, type: string, pageTitle: string, editUrl: string}>
     */
    private function getInherited(int $pageUid): array
    {
        $result = [];
        foreach ($this->exclusionRepository->findForPid($pageUid) as $exclusion) {
            if ($exclusion->pid === $pageUid || !$this->exclusionPermissions->maySee($exclusion)) {
                continue;
            }
            $page = $exclusion->pid > 0 ? BackendUtility::getRecord('pages', $exclusion->pid, 'title') : null;
            $result[] = [
                'uid' => $exclusion->uid,
                'pid' => $exclusion->pid,
                'title' => BackendUtility::getRecordTitle(ExclusionRepository::TABLE, $exclusion->row),
                'type' => (string)BackendUtility::getProcessedValue(ExclusionRepository::TABLE, 'type', $exclusion->type),
                'pageTitle' => $page !== null ? (string)$page['title'] : '',
                'editUrl' => (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [ExclusionRepository::TABLE => [$exclusion->uid => 'edit']],
                    'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, ['id' => $pageUid]),
                ]),
            ];
        }
        return $result;
    }

    private function addDocHeaderButtons(ModuleTemplate $view, int $pageUid, int $depth, array $pageRecord, string $returnUrl): void
    {
        $languageService = $this->getLanguageService();
        $depthButton = $this->componentFactory->createDropDownButton()
            ->setLabel($languageService->sL('linkvalidator2.messages:report.depth'))
            ->setIcon($this->iconFactory->getIcon('actions-pagetree', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setShowActiveLabelText(true);
        foreach (self::DEPTH_OPTIONS as $option) {
            $depthButton->addItem(
                $this->componentFactory->createDropDownRadio()
                    ->setActive($option === $depth)
                    ->setLabel($languageService->sL('linkvalidator2.messages:report.depth.' . $option))
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute(self::ROUTE, ['id' => $pageUid, 'exclusionDepth' => $option]))
            );
        }
        $view->addButtonToButtonBar($depthButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        if ($this->exclusionPermissions->mayEditOnPage($pageUid, $pageRecord)) {
            $newButton = $this->componentFactory->createLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                    'edit' => [ExclusionRepository::TABLE => [$pageUid => 'new']],
                    'returnUrl' => $returnUrl,
                ]))
                ->setTitle($languageService->sL('linkvalidator2.messages:exclusions.new'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL));
            $view->addButtonToButtonBar($newButton, ButtonBar::BUTTON_POSITION_LEFT, 2);
        }
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
