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
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Linkvalidator2\Repository\BrokenLinkRepository;
use TYPO3\CMS\Linkvalidator2\Service\PageRecordFinder;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;

/**
 * @internal This class is a specific Backend controller implementation and is not part of the TYPO3's Core API.
 */
#[AsController]
final readonly class ReportController
{
    private const DEPTH_OPTIONS = [0, 1, 2, 3, 4, 999];

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private PageRecordFinder $pageRecordFinder,
        private RecordCheckService $recordCheckService,
        private BrokenLinkRepository $brokenLinkRepository,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $pageRecord = BackendUtility::readPageAccess($pageUid, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW)) ?: [];
        $moduleUrl = (string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report', ['id' => $pageUid]);

        $view = $this->moduleTemplateFactory->create($request);
        if ($pageRecord !== []) {
            $view->getDocHeaderComponent()->setPageBreadcrumb($pageRecord);
        }
        $view->setTitle($this->getLanguageService()->sL('linkvalidator2.module:title'));

        $depth = (int)$request->getAttribute('moduleData')->get('depth', 0);
        if (!in_array($depth, self::DEPTH_OPTIONS, true)) {
            $depth = 0;
        }
        $pageIds = $pageRecord !== [] ? $this->pageRecordFinder->findPageIds($pageUid, $depth) : [];

        if ($pageIds !== [] && ($request->getParsedBody()['checkPage'] ?? false)) {
            $recordCount = 0;
            foreach ($pageIds as $pageId) {
                foreach ($this->pageRecordFinder->findOnPage($pageId) as ['table' => $table, 'uid' => $uid]) {
                    $this->recordCheckService->checkRecord($table, $uid);
                    $recordCount++;
                }
            }
            $view->addFlashMessage(
                sprintf($this->getLanguageService()->sL('linkvalidator2.messages:report.checked'), $recordCount, count($pageIds)),
                '',
                ContextualFeedbackSeverity::OK
            );
        }

        $view->assignMultiple([
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ''),
            'moduleUrl' => $moduleUrl,
            'depth' => $depth,
            'depthOptions' => $this->getDepthOptions(),
            'brokenLinks' => $this->getBrokenLinks($pageIds, $moduleUrl),
        ]);
        return $view->renderResponse('Backend/Report');
    }

    /**
     * @return array<int, string>
     */
    private function getDepthOptions(): array
    {
        $options = [];
        foreach (self::DEPTH_OPTIONS as $depth) {
            $options[$depth] = $this->getLanguageService()->sL('linkvalidator2.messages:report.depth.' . $depth);
        }
        return $options;
    }

    /**
     * @param list<int> $pageIds
     * @return list<array<string, mixed>>
     */
    private function getBrokenLinks(array $pageIds, string $returnUrl): array
    {
        $result = [];
        $pageTitles = [];
        foreach ($this->brokenLinkRepository->findByPids($pageIds) as $row) {
            $table = (string)$row['table_name'];
            $uid = (int)$row['record_uid'];
            $pid = (int)$row['pid'];
            $record = BackendUtility::getRecord($table, $uid) ?? [];
            $pageTitles[$pid] ??= BackendUtility::getRecordTitle('pages', BackendUtility::getRecord('pages', $pid) ?? ['uid' => $pid]);
            $row['pageTitle'] = $pageTitles[$pid];
            $row['recordTitle'] = $record !== [] ? BackendUtility::getRecordTitle($table, $record) : $table . ':' . $uid;
            $row['editUrl'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$uid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            $result[] = $row;
        }
        return $result;
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
