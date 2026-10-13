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
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportPermissionsFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\LinkRechecker;
use GeorgRinger\Linkvalidator2\Service\PageCheckService;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;

/**
 * AJAX endpoints for checking a page tree from the backend module in batches: the browser
 * fetches the pages of the scope, then checks one page per request until it is done.
 *
 * @internal This class is a specific Backend controller implementation and is not part of the TYPO3's Core API.
 */
#[AsController]
final readonly class CheckController
{
    private const MODULE = 'linkvalidator2_report';
    private const DEPTH_OPTIONS = [0, 1, 2, 3, 4, 999];
    /** Seconds per request; checking a page continues with the next request when exceeded */
    private const TIME_BUDGET = 5.0;

    public function __construct(
        private ModuleProvider $moduleProvider,
        private PageRecordFinder $pageRecordFinder,
        private PageCheckService $pageCheckService,
        private Context $context,
        private ReportPermissionsFactory $reportPermissionsFactory,
        private BrokenLinkRepository $brokenLinkRepository,
        private ExtensionSettings $extensionSettings,
        private LinkRechecker $linkRechecker,
    ) {}

    public function startAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasModuleAccess()) {
            return $this->error('Access denied', 403);
        }
        $pageUid = (int)($request->getQueryParams()['id'] ?? 0);
        $depth = (int)($request->getQueryParams()['depth'] ?? 0);
        if (!in_array($depth, self::DEPTH_OPTIONS, true)) {
            return $this->error('Invalid depth', 400);
        }
        if (!$this->hasPageAccess($pageUid)) {
            return $this->error('Page not found', 404);
        }
        return new JsonResponse([
            'pageIds' => $this->pageRecordFinder->findPageIds($pageUid, $depth),
            // Same clock as the check times stored by the checks
            'runStartedAt' => (int)$this->context->getPropertyFromAspect('date', 'timestamp'),
        ]);
    }

    public function pageAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasModuleAccess()) {
            return $this->error('Access denied', 403);
        }
        $body = (array)$request->getParsedBody();
        $pageId = (int)($body['pageId'] ?? 0);
        $runStartedAt = (int)($body['runStartedAt'] ?? 0);
        if ($runStartedAt <= 0) {
            return $this->error('Missing run start', 400);
        }
        if (!$this->hasPageAccess($pageId)) {
            return $this->error('Page not found', 404);
        }
        $result = $this->pageCheckService->checkPage($pageId, $runStartedAt, self::TIME_BUDGET, ignoreStored: filter_var($body['ignoreStored'] ?? false, FILTER_VALIDATE_BOOLEAN));
        // All records are checked, but counted is only what the user sees in the report
        $permissions = $this->reportPermissionsFactory->createForUser($this->getBackendUser());
        return new JsonResponse([
            'done' => $result->done,
            'recordCount' => $result->recordCount,
            'checkedCount' => $result->checkedCount,
            'brokenLinkCount' => $permissions === null ? $result->brokenLinkCount : $this->brokenLinkRepository->countBrokenByPid($pageId, $permissions),
            'newBrokenLinkCount' => $permissions === null ? $result->newBrokenLinkCount : $this->brokenLinkRepository->countDetectedSince($pageId, $runStartedAt, $permissions),
            'noticeCount' => $permissions === null ? $result->noticeCount : $this->brokenLinkRepository->countByPids([$pageId], new ReportFilter(CheckStatus::Info->value), $permissions),
        ]);
    }

    /**
     * AJAX: checks the link of one result in the report again, with a new request for an external
     * URL.
     */
    public function recheckAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->hasModuleAccess()) {
            return $this->error('Access denied', 403);
        }
        $body = (array)$request->getParsedBody();
        $permissions = $this->reportPermissionsFactory->createForUser($this->getBackendUser());
        $row = $this->brokenLinkRepository->findOneVisible(
            (string)($body['table'] ?? ''),
            (int)($body['record'] ?? 0),
            (string)($body['field'] ?? ''),
            (int)($body['target'] ?? 0),
            $permissions
        );
        if ($row === null || !$this->hasPageAccess((int)$row['pid'])) {
            return $this->error('Result not found', 404);
        }
        $status = $this->linkRechecker->recheck($row);
        return new JsonResponse(['status' => $status]);
    }

    private function hasModuleAccess(): bool
    {
        return $this->extensionSettings->isManualCheckAllowed()
            && $this->moduleProvider->accessGranted(self::MODULE, $this->getBackendUser());
    }

    private function hasPageAccess(int $pageUid): bool
    {
        return $pageUid > 0
            && BackendUtility::readPageAccess($pageUid, $this->getBackendUser()->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    private function error(string $message, int $status): ResponseInterface
    {
        return new JsonResponse(['error' => $message], $status);
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
