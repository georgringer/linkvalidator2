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
namespace GeorgRinger\Linkvalidator2\Widget;

use GeorgRinger\Linkvalidator2\Report\ReportPermissionsFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Dashboard widget: the pages below a selected page with the most broken links. Optional, it
 * is only registered if EXT:dashboard is installed (Configuration/Services.php). Page, levels
 * and the number of pages shown are settings of every instance of the widget. Only what the
 * user may fix is counted, like in the report.
 *
 * @internal
 */
final readonly class BrokenLinksWidget implements WidgetRendererInterface
{
    private const MODULE = 'linkvalidator2_report';

    public function __construct(
        private WidgetConfigurationInterface $configuration,
        private BackendViewFactory $backendViewFactory,
        private PageRecordFinder $pageRecordFinder,
        private BrokenLinkRepository $brokenLinkRepository,
        private ReportPermissionsFactory $reportPermissionsFactory,
        private ModuleProvider $moduleProvider,
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @return SettingDefinition[]
     */
    public function getSettingsDefinitions(): array
    {
        return [
            new SettingDefinition(
                key: 'label',
                type: 'string',
                default: '',
                label: 'linkvalidator2.widget:setting.label.label',
                description: 'linkvalidator2.widget:setting.label.description',
            ),
            new SettingDefinition(
                key: 'page',
                type: 'page',
                default: 0,
                label: 'linkvalidator2.widget:setting.page.label',
                description: 'linkvalidator2.widget:setting.page.description',
            ),
            new SettingDefinition(
                key: 'depth',
                type: 'int',
                default: 1,
                label: 'linkvalidator2.widget:setting.depth.label',
                description: 'linkvalidator2.widget:setting.depth.description',
                enum: [
                    0 => 'linkvalidator2.widget:setting.depth.0',
                    1 => 'linkvalidator2.widget:setting.depth.1',
                    2 => 'linkvalidator2.widget:setting.depth.2',
                    3 => 'linkvalidator2.widget:setting.depth.3',
                    4 => 'linkvalidator2.widget:setting.depth.4',
                    99 => 'linkvalidator2.widget:setting.depth.99',
                ],
            ),
            new SettingDefinition(
                key: 'limit',
                type: 'int',
                default: 10,
                label: 'linkvalidator2.widget:setting.limit.label',
                description: 'linkvalidator2.widget:setting.limit.description',
                options: ['min' => 1, 'max' => 50],
            ),
        ];
    }

    public function renderWidget(WidgetContext $context): WidgetResult
    {
        $backendUser = $this->getBackendUser();
        $pageUid = (int)$context->settings->get('page');
        $view = $this->backendViewFactory->create($context->request, ['typo3/cms-dashboard', 'georgringer/linkvalidator2']);
        $view->assign('configuration', $this->configuration);
        $pageRecord = $pageUid > 0
            ? BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW))
            : false;
        $state = match (true) {
            !$this->moduleProvider->accessGranted(self::MODULE, $backendUser) => 'noModule',
            $pageUid <= 0 => 'noPage',
            $pageRecord === false => 'noAccess',
            default => 'list',
        };
        $view->assign('state', $state);
        $label = (string)$context->settings->get('label');
        if ($state === 'list') {
            $view->assignMultiple($this->getList($pageUid, (array)$pageRecord, max(0, (int)$context->settings->get('depth')), max(1, (int)$context->settings->get('limit'))));
        }
        return new WidgetResult(
            content: $view->render('Widget/BrokenLinks'),
            label: $label !== '' ? $label : null,
            refreshable: true,
        );
    }

    /**
     * @param array<string, mixed> $pageRecord
     * @return array<string, mixed>
     */
    private function getList(int $pageUid, array $pageRecord, int $depth, int $limit): array
    {
        $pids = [];
        foreach ($this->pageRecordFinder->iteratePageIdBatches($pageUid, $depth, 1000) as $batch) {
            array_push($pids, ...$batch);
        }
        $counts = $this->brokenLinkRepository->countBrokenGroupedByPid($pids, $this->reportPermissionsFactory->createForUser($this->getBackendUser()));
        // Most broken links first, then the page uid for a stable order
        uksort($counts, static fn(int $a, int $b): int => $counts[$b] <=> $counts[$a] ?: $a <=> $b);
        $rows = [];
        foreach (array_slice($counts, 0, $limit, true) as $pid => $amount) {
            $page = BackendUtility::getRecord('pages', $pid) ?? ['uid' => $pid];
            $rows[] = [
                'uid' => $pid,
                'title' => BackendUtility::getRecordTitle('pages', $page),
                'record' => $page,
                'amount' => $amount,
                'url' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pid]),
            ];
        }
        return [
            'pageTitle' => BackendUtility::getRecordTitle('pages', $pageRecord),
            'rows' => $rows,
            'total' => array_sum($counts),
            'pagesWithBrokenLinks' => count($counts),
            'moreCount' => max(0, count($counts) - $limit),
            'reportUrl' => (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid, 'depth' => $depth]),
        ];
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
