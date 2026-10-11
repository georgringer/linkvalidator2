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
use TYPO3\CMS\Backend\Routing\PreviewUriBuilder;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\Components\ComponentFactory;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionRepository;
use TYPO3\CMS\Linkvalidator2\Report\ReportFilter;
use TYPO3\CMS\Linkvalidator2\Report\ReportOrdering;
use TYPO3\CMS\Linkvalidator2\Report\ReportPermissions;
use TYPO3\CMS\Linkvalidator2\Report\ReportPermissionsFactory;
use TYPO3\CMS\Linkvalidator2\Repository\BrokenLinkRepository;
use TYPO3\CMS\Linkvalidator2\Service\PageRecordFinder;

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
        private SiteFinder $siteFinder,
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
        $view->addButtonToButtonBar(
            $this->componentFactory->createLinkButton()
                ->setHref((string)$this->uriBuilder->buildUriFromRoute('linkvalidator2_report.exclusions', ['id' => $pageUid]))
                ->setTitle($this->getLanguageService()->sL('linkvalidator2.messages:exclusions.show'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('actions-ban', IconSize::SMALL)),
            ButtonBar::BUTTON_POSITION_RIGHT,
            1
        );

        $depth = (int)$request->getAttribute('moduleData')->get('depth', 0);
        if (!in_array($depth, self::DEPTH_OPTIONS, true)) {
            $depth = 0;
        }
        $pageIds = $pageRecord !== [] ? $this->pageRecordFinder->findPageIds($pageUid, $depth) : [];
        // Only what the user may fix; null for admins
        $permissions = $this->reportPermissionsFactory->createForUser($this->getBackendUser());
        $filter = ReportFilter::fromModuleData($request->getAttribute('moduleData'));
        $ordering = ReportOrdering::fromModuleData($request->getAttribute('moduleData'));
        $pagination = $this->getPagination(
            $this->brokenLinkRepository->countByPids($pageIds, $filter, $permissions),
            (int)($request->getQueryParams()['page'] ?? 1)
        );

        if ($pageRecord !== []) {
            $this->addDocHeaderButtons($view, $pageUid, $depth);
            $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/check-links.js');
            $this->pageRenderer->loadJavaScriptModule('@typo3/linkvalidator2/ignore-link.js');
        }

        $view->assignMultiple([
            'pageUid' => $pageUid,
            'pageTitle' => (string)($pageRecord['title'] ?? ''),
            'moduleUrl' => $moduleUrl,
            'depth' => $depth,
            'depthOptions' => $this->getDepthOptions(),
            'filter' => $filter,
            'filterOptions' => $this->getFilterOptions($pageIds, $pageUid, $filter, $permissions),
            'resetFilterUrl' => (string)$this->uriBuilder->buildUriFromRoute(
                'linkvalidator2_report',
                ['id' => $pageUid, ...array_fill_keys(ReportFilter::PROPERTIES, '')]
            ),
            'pagination' => $pagination,
            'columns' => $this->getColumns($pageUid, $ordering),
            'brokenLinks' => $this->getBrokenLinks($pageIds, $filter, $ordering, $pagination, $moduleUrl, $permissions),
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
     * Arguments of the error labels: 1. HTTP status, code, date or parent page title, 2. a hint that a
     * notice comes from a parent page.
     *
     * @param array<int, string> $pageTitles
     * @return array{0: string, 1: string}
     */
    private function getMessageArguments(string $errorType, array $details, array &$pageTitles): array
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
    private function addDocHeaderButtons(ModuleTemplate $view, int $pageUid, int $depth): void
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
    private function getBrokenLinks(array $pageIds, ReportFilter $filter, ReportOrdering $ordering, array $pagination, string $returnUrl, ?ReportPermissions $permissions): array
    {
        $result = [];
        $mayIgnore = $this->getBackendUser()->check('tables_modify', ExclusionRepository::TABLE);
        $pageTitles = [];
        $rows = $this->brokenLinkRepository->findByPids($pageIds, $filter, $ordering, self::ITEMS_PER_PAGE, $pagination['startRecord'] - 1, $permissions);
        foreach ($rows as $row) {
            $table = (string)$row['table_name'];
            $uid = (int)$row['record_uid'];
            $pid = (int)$row['pid'];
            $record = BackendUtility::getRecord($table, $uid) ?? [];
            $pageTitles[$pid] ??= BackendUtility::getRecordTitle('pages', BackendUtility::getRecord('pages', $pid) ?? ['uid' => $pid]);
            $row['pageTitle'] = $pageTitles[$pid];
            $row['details'] = (array)json_decode((string)$row['error_details'], true);
            $row['messageArguments'] = $this->getMessageArguments((string)$row['error_type'], $row['details'], $pageTitles);
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
                $options[] = ['type' => 'url', 'value' => $host, 'label' => sprintf($languageService->sL('linkvalidator2.messages:report.ignore.domain'), $host)];
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
     *
     * @return list<array{label: string, url: string, active: bool, descending: bool}>
     */
    private function getColumns(int $pageUid, ReportOrdering $ordering): array
    {
        $columns = [];
        foreach (array_keys(ReportOrdering::FIELDS) as $field) {
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
    private function getFilterOptions(array $pageIds, int $pageUid, ReportFilter $filter, ?ReportPermissions $permissions): array
    {
        $languageService = $this->getLanguageService();
        $values = $this->brokenLinkRepository->findFilterValues($pageIds, $permissions);
        $labels = [];
        foreach ($values['status'] as $status) {
            $labels['status'][$status] = $languageService->sL('linkvalidator2.messages:status.' . $status) ?: $status;
        }
        foreach ($values['linkType'] as $linkType) {
            $labels['linkType'][$linkType] = $languageService->sL('linkvalidator2.messages:linkType.' . $linkType) ?: $linkType;
        }
        foreach ($values['table'] as $table) {
            $title = $this->tcaSchemaFactory->has($table) ? $languageService->sL($this->tcaSchemaFactory->get($table)->getTitle()) : '';
            $labels['table'][$table] = $title !== '' ? $title : $table;
        }
        $languageTitles = $this->getLanguageTitles($pageUid);
        foreach ($values['language'] as $language) {
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
                $options[$property][] = ['value' => (string)$value, 'label' => $label, 'selected' => (string)$value === $selectedValue];
            }
        }
        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function getLanguageTitles(int $pageUid): array
    {
        $titles = [-1 => $this->getLanguageService()->sL('linkvalidator2.messages:language.all')];
        try {
            foreach ($this->siteFinder->getSiteByPageId($pageUid)->getAllLanguages() as $siteLanguage) {
                $titles[$siteLanguage->getLanguageId()] = $siteLanguage->getTitle();
            }
        } catch (SiteNotFoundException) {
        }
        return $titles;
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
