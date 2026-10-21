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
use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\MathUtility;
use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionPermissions;
use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use GeorgRinger\Linkvalidator2\Report\ReportPermissions;
use GeorgRinger\Linkvalidator2\Report\ReportPermissionsFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkGroup;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkQuery;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;

/**
 * @internal This class is a specific Backend controller implementation and is not part of the TYPO3's Core API.
 */
#[AsController]
final readonly class ReportController
{
    private const DEPTH_OPTIONS = [0, 1, 2, 3, 4, 999];
    private const ITEMS_PER_PAGE = 50;

    public function __construct(
        private ModuleTemplateFactory $moduleTemplateFactory,
        private UriBuilder $uriBuilder,
        private PageRecordFinder $pageRecordFinder,
        private PageRenderer $pageRenderer,
        private ComponentFactory $componentFactory,
        private IconFactory $iconFactory,
        private LinkService $linkService,
        private ReportPermissionsFactory $reportPermissionsFactory,
        private BrokenLinkRepository $brokenLinkRepository,
        private TcaSchemaFactory $tcaSchemaFactory,
        private RecordStatusRepository $recordStatusRepository,
        private ErrorMessageFormatter $errorMessageFormatter,
        private ExtensionSettings $extensionSettings,
        private TranslationConfigurationProvider $translationConfigurationProvider,
        private ExclusionPermissions $exclusionPermissions,
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
        $view->makeDocHeaderModuleMenu(['id' => $pageUid]);
        $mayManageExclusions = $this->exclusionPermissions->mayEditOnPage($pageUid, $pageRecord);
        if ($mayManageExclusions) {
            $view->addButtonToButtonBar(
                $this->componentFactory->createLinkButton()
                    ->setHref((string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report.exclusions', ['id' => $pageUid]))
                    ->setTitle($this->getLanguageService()->sL('linkvalidator2.messages:exclusions.show'))
                    ->setShowLabelText(true)
                    ->setIcon($this->iconFactory->getIcon('actions-ban', IconSize::SMALL)),
                ButtonBar::BUTTON_POSITION_RIGHT,
                1
            );
        }

        $depth = (int)$request->getAttribute('moduleData')->get('depth', 0);
        if (!in_array($depth, self::DEPTH_OPTIONS, true)) {
            $depth = 0;
        }
        $pageIds = $pageRecord !== [] ? $this->pageRecordFinder->findPageIds($pageUid, $depth) : [];
        // Only what the user may fix; null for admins
        $permissions = $this->reportPermissionsFactory->createForUser($this->getBackendUser());
        $filter = ReportFilter::fromModuleData($request->getAttribute('moduleData'));
        $ordering = ReportOrdering::fromModuleData($request->getAttribute('moduleData'));
        $query = new BrokenLinkQuery($pageIds, $filter, $permissions);
        $pagination = $this->getPagination(
            $this->brokenLinkRepository->count($query),
            (int)($request->getQueryParams()['page'] ?? 1)
        );

        // The root level is readable for admins, but there is no page to check
        if ($pageUid > 0 && $pageRecord !== []) {
            $this->addDocHeaderButtons($view, $pageUid, $depth, $this->extensionSettings->isManualCheckAllowed());
            $this->pageRenderer->loadJavaScriptModule('@typo3/backend/context-menu.js');
            $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/problem-details.js');
            if ($this->extensionSettings->isManualCheckAllowed()) {
                $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/check-links.js');
                $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/recheck-link.js');
            }
            $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/ignore-link.js');
        }

        $view->assignMultiple([
            'manualCheckAllowed' => $this->extensionSettings->isManualCheckAllowed(),
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ''),
            'moduleUrl' => $moduleUrl,
            'depth' => $depth,
            'depthOptions' => $this->getDepthOptions(),
            'filter' => $filter,
            'filterOptions' => $this->getFilterOptions($query, $pageUid),
            'resetFilterUrl' => (string)$this->uriBuilder->buildUriFromRoute(
                'linkvalidator2_report',
                ['id' => $pageUid, ...array_fill_keys(ReportFilter::PROPERTIES, '')]
            ),
            'pagination' => $pagination,
            'columns' => $this->getColumns($pageUid, $ordering),
            'brokenLinks' => $this->getBrokenLinks($query, $ordering, $pagination, $moduleUrl, $mayManageExclusions),
            'excludedCount' => $this->recordStatusRepository->countExcluded($pageIds),
            'mayManageExclusions' => $mayManageExclusions,
            'exclusionsUrl' => (string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report.exclusions', ['id' => $pageUid]),
            // Tells an empty report of pages never checked from one without broken links
            'lastChecked' => $pagination['total'] === 0 && !$filter->isActive() ? $this->recordStatusRepository->findLastChecked($pageIds) : 0,
        ]);
        return $view->renderResponse('Backend/Report');
    }

    /**
     * Where the link target can be looked at: external URLs themselves, pages in the frontend preview
     * (hidden pages included for backend users), existing files by their public URL.
     */
    private function getTargetUrl(string $link, int $language): ?string
    {
        try {
            $resolved = $this->linkService->resolve($link);
        } catch (\Throwable) {
            return null;
        }
        $type = (string)($resolved['type'] ?? '');
        if ($type === LinkService::TYPE_URL) {
            $url = (string)($resolved['url'] ?? '');
            return in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true) ? $url : null;
        }
        if ($type === LinkService::TYPE_PAGE && MathUtility::canBeInterpretedAsInteger($resolved['pageuid'] ?? null)) {
            $pageUid = (int)$resolved['pageuid'];
            if (BackendUtility::getRecord('pages', $pageUid) === null) {
                return null;
            }
            $uri = PreviewUriBuilder::create($pageUid)
                ->withLanguage(max(0, $language))
                ->withSection((string)($resolved['fragment'] ?? ''))
                ->buildUri();
            return $uri !== null ? (string)$uri : null;
        }
        if ($type === LinkService::TYPE_FILE && ($resolved['file'] ?? null) instanceof File && !$resolved['file']->isMissing()) {
            return $resolved['file']->getPublicUrl();
        }
        return null;
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
     * "Check links" with its own levels: choosing them only sets the scope of the next check
     * (no reload), the report is filtered by the levels of the filter form. Preselected are
     * the levels of the report.
     */
    private function addDocHeaderButtons(ModuleTemplate $view, int $pageUid, int $depth, bool $manualCheckAllowed): void
    {
        if (!$manualCheckAllowed) {
            return;
        }
        $languageService = $this->getLanguageService();
        $depthButton = $this->componentFactory->createDropDownButton()
            ->setLabel($languageService->sL('linkvalidator2.messages:report.depth'))
            ->setIcon($this->iconFactory->getIcon('actions-pagetree', IconSize::SMALL))
            ->setShowLabelText(true)
            ->setShowActiveLabelText(true);
        foreach (self::DEPTH_OPTIONS as $option) {
            $depthButton->addItem(
                $this->componentFactory->createDropDownRadio()
                    ->setTag('button')
                    ->setActive($option === $depth)
                    ->setLabel($languageService->sL('linkvalidator2.messages:report.depth.' . $option))
                    ->setAttributes(['data-linkvalidator2-check-depth' => (string)$option])
            );
        }
        $view->addButtonToButtonBar($depthButton, ButtonBar::BUTTON_POSITION_LEFT, 1);

        $checkButton = $this->componentFactory->createGenericButton()
            ->setTag('button')
            ->setLabel($languageService->sL('linkvalidator2.messages:report.checkPage'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-play', IconSize::SMALL))
            ->setAttributes([
                'type' => 'button',
                // An empty attribute value is not rendered
                'data-linkvalidator2-check' => '1',
                'data-page-id' => (string)$pageUid,
                'data-depth' => (string)$depth,
            ]);
        $view->addButtonToButtonBar($checkButton, ButtonBar::BUTTON_POSITION_LEFT, 1);
    }

    /**
     * @todo Fetches record and page title per row (at most one page of results). Load in bulk.
     *
     * @param list<int> $pageIds
     * @param array{startRecord: int} $pagination
     * @return list<array<string, mixed>>
     */
    private function getBrokenLinks(BrokenLinkQuery $query, ReportOrdering $ordering, array $pagination, string $returnUrl, bool $mayIgnore): array
    {
        $result = [];
        $pageTitles = [];
        $pageRecords = [];
        $rows = $this->brokenLinkRepository->find($query, $ordering, self::ITEMS_PER_PAGE, $pagination['startRecord'] - 1);
        foreach ($rows as $row) {
            $table = (string)$row['table_name'];
            $uid = (int)$row['record_uid'];
            $pid = (int)$row['pid'];
            $record = BackendUtility::getRecord($table, $uid) ?? [];
            $pageRecords[$pid] ??= BackendUtility::getRecord('pages', $pid) ?? ['uid' => $pid];
            $pageTitles[$pid] ??= BackendUtility::getRecordTitle('pages', $pageRecords[$pid]);
            $row['pageRecord'] = $pageRecords[$pid];
            $row['record'] = $record;
            $row['pageTitle'] = $pageTitles[$pid];
            $language = $this->getLanguageInformation($pid, (int)$row['language']);
            $row['languageFlag'] = $language['flagIcon'] ?? '';
            $row['languageTitle'] = $language['title'] ?? '';
            $row['details'] = (array)json_decode((string)$row['error_details'], true);
            $row['detailsJson'] = json_encode($row['details'] + ['target' => (string)$row['link_string']], JSON_THROW_ON_ERROR);
            $row['messageArguments'] = $this->errorMessageFormatter->getArguments((string)$row['error_type'], $row['details'], $pageTitles);
            $row['targetUrl'] = $this->getTargetUrl((string)$row['link_string'], (int)$row['language']);
            $row['statusClass'] = match ((string)$row['status']) {
                'info' => 'badge-info',
                'unknown' => 'badge-warning',
                default => 'badge-danger',
            };
            $row['recordTitle'] = $record !== [] ? BackendUtility::getRecordTitle($table, $record) : $table . ':' . $uid;
            $row['ignoreOptions'] = $mayIgnore ? $this->getIgnoreOptions($row) : [];
            $row['editUrl'] = (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
                'edit' => [$table => [$uid => 'edit']],
                'returnUrl' => $returnUrl,
            ]);
            $result[] = $row;
        }
        return $result;
    }

    /**
     * Exclusions that can be created from a result, stored on the page selected in the report
     *
     * @return list<array{type: string, value: string, label: string}>
     */
    private function getIgnoreOptions(array $row): array
    {
        $languageService = $this->getLanguageService();
        $linkType = (string)$row['link_type'];
        if ($linkType === LinkService::TYPE_URL) {
            $url = explode('#', (string)$row['link_string'], 2)[0];
            $options = [['type' => 'url', 'value' => $url, 'label' => $languageService->sL('linkvalidator2.messages:report.ignore.url')]];
            $host = (string)parse_url($url, PHP_URL_HOST);
            if ($host !== '') {
                $options[] = ['type' => 'url', 'value' => $host, 'label' => (string)$languageService->label('linkvalidator2.messages:report.ignore.domain', ['domain' => $host])];
            }
            return $options;
        }
        if ($linkType === LinkService::TYPE_PAGE) {
            $pageUid = (int)explode('#', substr((string)$row['identifier'], 5), 2)[0];
            if ($pageUid > 0) {
                return [['type' => 'page', 'value' => (string)$pageUid, 'label' => $languageService->sL('linkvalidator2.messages:report.ignore.page')]];
            }
        }
        return [];
    }

    /**
     * Table header: clicking a column sorts by it, clicking the sorted column reverses the direction.
     * The link column (target and link text) is not offered for sorting, ReportOrdering still supports it.
     *
     * @return list<array{label: string, url: ?string, active: bool, descending: bool}>
     */
    private function getColumns(int $pageUid, ReportOrdering $ordering): array
    {
        $columns = [];
        foreach (['page', 'record', 'link', 'problem', 'since'] as $field) {
            if ($field === 'link') {
                $columns[] = [
                    'label' => $this->getLanguageService()->sL('linkvalidator2.messages:report.column.link'),
                    'url' => null,
                    'active' => false,
                    'descending' => false,
                ];
                continue;
            }
            $active = $ordering->field === $field;
            $columns[] = [
                'label' => $this->getLanguageService()->sL('linkvalidator2.messages:report.column.' . $field),
                'url' => (string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report', [
                    'id' => $pageUid,
                    'orderField' => $field,
                    'orderDirection' => $active && !$ordering->descending ? 'desc' : 'asc',
                ]),
                'active' => $active,
                'descending' => $ordering->descending,
            ];
        }
        return $columns;
    }

    /**
     * @return array{total: int, current: int, numberOfPages: int, startRecord: int, endRecord: int, previousPage: int, nextPage: int}
     */
    private function getPagination(int $total, int $page): array
    {
        $numberOfPages = max(1, (int)ceil($total / self::ITEMS_PER_PAGE));
        $current = min(max(1, $page), $numberOfPages);
        $startRecord = ($current - 1) * self::ITEMS_PER_PAGE + 1;
        return [
            'total' => $total,
            'current' => $current,
            'numberOfPages' => $numberOfPages,
            'startRecord' => $startRecord,
            'endRecord' => min($startRecord + self::ITEMS_PER_PAGE - 1, $total),
            'previousPage' => $current > 1 ? $current - 1 : 0,
            'nextPage' => $current < $numberOfPages ? $current + 1 : 0,
        ];
    }

    /**
     * Only values that occur among the broken links of the selected pages are offered.
     *
     * @param list<int> $pageIds
     * @return array<string, list<array{value: string, label: string, selected: bool}>>
     */
    private function getFilterOptions(BrokenLinkQuery $query, int $pageUid): array
    {
        $languageService = $this->getLanguageService();
        $filter = $query->filter;
        // Offered are the values present, not narrowed by the other filters
        $unfiltered = new BrokenLinkQuery($query->pids, permissions: $query->permissions);
        $values = [
            'status' => $this->brokenLinkRepository->countGroupedBy($unfiltered, BrokenLinkGroup::Status),
            'linkType' => $this->brokenLinkRepository->countGroupedBy($unfiltered, BrokenLinkGroup::LinkType),
            'table' => $this->brokenLinkRepository->countGroupedBy($unfiltered, BrokenLinkGroup::Table),
            'language' => $this->brokenLinkRepository->countGroupedBy($unfiltered, BrokenLinkGroup::Language),
        ];
        $labels = [];
        foreach (array_keys($values['status']) as $status) {
            $labels['status'][$status] = $languageService->sL('linkvalidator2.messages:status.' . $status) ?: $status;
        }
        foreach (array_keys($values['linkType']) as $linkType) {
            $labels['linkType'][$linkType] = $languageService->sL('linkvalidator2.messages:linkType.' . $linkType) ?: $linkType;
        }
        foreach (array_keys($values['table']) as $table) {
            $title = $this->tcaSchemaFactory->has($table) ? $languageService->sL($this->tcaSchemaFactory->get($table)->getTitle()) : '';
            $labels['table'][$table] = $title !== '' ? $title : $table;
        }
        $languageTitles = $this->getLanguageTitles($pageUid);
        foreach (array_keys($values['language']) as $language) {
            $labels['language'][$language] = $languageTitles[$language] ?? ('#' . $language);
        }
        $selected = [
            'status' => $filter->status,
            'linkType' => $filter->linkType,
            'table' => $filter->table,
            'language' => $filter->language === null ? '' : (string)$filter->language,
        ];
        $options = [];
        foreach ($selected as $property => $selectedValue) {
            $options[$property] = [];
            foreach ($labels[$property] ?? [] as $value => $label) {
                $options[$property][] = ['value' => (string)$value, 'label' => $label, 'count' => $values[$property][$value] ?? 0, 'selected' => (string)$value === $selectedValue];
            }
        }
        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function getLanguageTitles(int $pageUid): array
    {
        return array_map(static fn(array $language): string => (string)$language['title'], $this->translationConfigurationProvider->getSystemLanguages($pageUid));
    }

    /**
     * Title and flag of the language of a result, for sites with several languages only
     *
     * @return array{title: string, flagIcon: string}|null
     */
    private function getLanguageInformation(int $pid, int $language): ?array
    {
        $languages = $this->translationConfigurationProvider->getSystemLanguages($pid);
        return count(array_diff_key($languages, [-1 => true])) > 1 ? ($languages[$language] ?? null) : null;
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
