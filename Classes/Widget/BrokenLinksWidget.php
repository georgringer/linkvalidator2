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
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Settings\SettingDefinition;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfigurationInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\CMS\Dashboard\Widgets\WidgetRendererInterface;
use TYPO3\CMS\Dashboard\Widgets\WidgetResult;

/**
 * Dashboard widget: the broken links below a selected page by type of link (external URLs,
 * pages, files …). Optional, it is only registered if EXT:dashboard is installed
 * (Configuration/Services.php). Page and levels are settings of every instance of the widget.
 * Only what the user may fix is counted, like in the report. The button to the report is only
 * there for users who have the module.
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
            $pageUid <= 0 => 'noPage',
            $pageRecord === false => 'noAccess',
            default => 'list',
        };
        $view->assign('state', $state);
        if ($state === 'list') {
            $view->assignMultiple($this->getList($pageUid, (array)$pageRecord, max(0, (int)$context->settings->get('depth'))));
        }
        $label = (string)$context->settings->get('label');
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
    private function getList(int $pageUid, array $pageRecord, int $depth): array
    {
        $backendUser = $this->getBackendUser();
        $canOpenReport = $this->moduleProvider->accessGranted(self::MODULE, $backendUser);
        $pids = [];
        foreach ($this->pageRecordFinder->iteratePageIdBatches($pageUid, $depth, 1000) as $batch) {
            array_push($pids, ...$batch);
        }
        $counts = $this->brokenLinkRepository->countBrokenGroupedByLinkType($pids, $this->reportPermissionsFactory->createForUser($backendUser));
        // Most broken links first, then the type for a stable order
        uksort($counts, static fn(string $a, string $b): int => $counts[$b] <=> $counts[$a] ?: strcmp($a, $b));
        $languageService = $this->getLanguageService();
        $rows = [];
        foreach ($counts as $type => $amount) {
            $rows[] = [
                'type' => $type,
                'label' => $languageService->sL('linkvalidator2.messages:linkType.' . $type) ?: $type,
                'amount' => $amount,
                'url' => $canOpenReport ? (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid, 'depth' => $depth, 'linkType' => $type]) : '',
            ];
        }
        return [
            'pageTitle' => BackendUtility::getRecordTitle('pages', $pageRecord),
            'rows' => $rows,
            'total' => array_sum($counts),
            'reportUrl' => $canOpenReport ? (string)$this->uriBuilder->buildUriFromRoute(self::MODULE, ['id' => $pageUid, 'depth' => $depth]) : '',
        ];
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
