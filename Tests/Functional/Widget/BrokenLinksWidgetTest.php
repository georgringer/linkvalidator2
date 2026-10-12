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
    public function brokenLinksAreCountedPerPage(): void
    {
        $counts = $this->get(BrokenLinkRepository::class)->countBrokenGroupedByPid([1, 2, 3]);

        // Records 10 and 14 are on page 1, a page without broken links is not listed
        self::assertSame([1], array_keys($counts));
        self::assertGreaterThan(7, $counts[1]);
    }

    #[Test]
    public function theWidgetListsThePagesWithTheNumberOfBrokenLinks(): void
    {
        $html = $this->render(['page' => 1, 'depth' => 1, 'limit' => 10, 'label' => '']);

        self::assertStringContainsString('8 broken links on 1 pages below &quot;Root&quot;', $html);
        self::assertStringContainsString('badge-danger', $html);
        self::assertStringContainsString('Open the report', $html);
    }

    #[Test]
    public function withoutAPageTheWidgetAsksForOne(): void
    {
        self::assertStringContainsString('Choose a page in the settings', $this->render(['page' => 0, 'depth' => 1, 'limit' => 10, 'label' => '']));
    }

    #[Test]
    public function aPageWithoutBrokenLinksSaysSo(): void
    {
        self::assertStringContainsString('No broken links below &quot;Visible&quot;', $this->render(['page' => 2, 'depth' => 0, 'limit' => 10, 'label' => '']));
    }

    #[Test]
    public function aUserWithoutModuleAccessSeesNoList(): void
    {
        $this->setUpBackendUser(2);

        self::assertStringContainsString('do not have access to the module', $this->render(['page' => 1, 'depth' => 1, 'limit' => 10, 'label' => '']));
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
