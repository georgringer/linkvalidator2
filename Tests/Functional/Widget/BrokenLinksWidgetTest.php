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
namespace GeorgRinger\Linkvalidator2\Tests\Functional\Widget;

use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Routing\Route;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Settings\Settings;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Dashboard\WidgetRegistry;
use TYPO3\CMS\Dashboard\Widgets\WidgetConfiguration;
use TYPO3\CMS\Dashboard\Widgets\WidgetContext;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The widget is optional: it exists only with EXT:dashboard.
 */
final class BrokenLinksWidgetTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    protected array $coreExtensionsToLoad = ['dashboard'];
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->writeSiteConfiguration();
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $recordCheckService = $this->get(RecordCheckService::class);
        foreach ([10, 13, 14] as $uid) {
            $recordCheckService->checkRecord('tt_content', $uid, true);
        }
    }

    #[Test]
    public function theWidgetIsRegisteredWithTheDashboard(): void
    {
        self::assertArrayHasKey('linkvalidator2BrokenLinks', $this->get(WidgetRegistry::class)->getAllWidgets());
    }

    #[Test]
    public function brokenLinksAreCountedPerTypeOfLink(): void
    {
        $counts = $this->get(BrokenLinkRepository::class)->countBrokenGroupedByLinkType([1, 2, 3]);

        // Records 10 and 14 link to pages, one link of record 10 is not resolvable
        self::assertArrayHasKey('page', $counts);
        self::assertArrayHasKey('invalid', $counts);
        self::assertSame(8, array_sum($counts));
    }

    #[Test]
    public function aPageWithoutBrokenLinksIsNotCounted(): void
    {
        self::assertSame([], $this->get(BrokenLinkRepository::class)->countBrokenGroupedByLinkType([2]));
    }

    #[Test]
    public function theWidgetListsTheTypesOfLinkWithTheirNumberAndLinksToTheReport(): void
    {
        $html = $this->render(['page' => 1, 'depth' => 1, 'label' => '']);

        self::assertStringContainsString('8 broken links below &quot;Root&quot;', $html);
        self::assertStringContainsString('>Page</a>', $html);
        self::assertStringContainsString('badge-danger', $html);
        self::assertStringContainsString('Open the report', $html);
        self::assertStringContainsString('linkType=page', $html);
    }

    #[Test]
    public function withoutAPageTheWidgetAsksForOne(): void
    {
        $html = $this->render(['page' => 0, 'depth' => 1, 'label' => '']);

        self::assertStringContainsString('Choose a page in the settings', $html);
        self::assertStringNotContainsString('Open the report', $html);
    }

    #[Test]
    public function aPageWithoutBrokenLinksSaysSo(): void
    {
        self::assertStringContainsString('No broken links below &quot;Visible&quot;', $this->render(['page' => 2, 'depth' => 0, 'label' => '']));
    }

    #[Test]
    public function aUserWithoutTheModuleSeesTheNumbersButNoButtonAndNoLinks(): void
    {
        // The page is the mount of the editor, who may edit its content but has no access to the module
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => Permission::CONTENT_EDIT | Permission::PAGE_SHOW], ['uid' => 1]);
        $this->getConnectionPool()->getConnectionForTable('be_users')->update('be_users', ['db_mountpoints' => '1'], ['uid' => 2]);
        $this->setUpBackendUser(2);

        $html = $this->render(['page' => 1, 'depth' => 1, 'label' => '']);

        // Only what the editor may fix is counted, which is less than the 8 of the admin
        self::assertMatchesRegularExpression('/[1-7] broken links below &quot;Root&quot;/', $html);
        self::assertStringContainsString('badge-danger', $html);
        self::assertStringNotContainsString('Open the report', $html);
        self::assertStringNotContainsString('linkType=', $html);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function render(array $settings): string
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/dashboard'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', new Route('/dashboard', ['packageName' => 'typo3/cms-dashboard']));
        $configuration = new WidgetConfiguration(
            identifier: 'linkvalidator2BrokenLinks',
            serviceName: 'dashboard.widget.linkvalidator2.brokenLinks',
            groupNames: ['general'],
            title: 'Broken links',
            description: '',
            iconIdentifier: 'content-widget-list',
            height: 'medium',
            width: 'medium',
        );
        $widget = $this->get('dashboard.widget.linkvalidator2.brokenLinks');
        return $widget->renderWidget(new WidgetContext('test', [], $configuration, new Settings($settings), $request))->content;
    }
}
