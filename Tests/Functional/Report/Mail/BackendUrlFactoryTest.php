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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\Mail\BackendUrlFactory;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Site\SiteFinder;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class BackendUrlFactoryTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
    }

    #[Test]
    public function moduleUrlIsAbsoluteOnTheSiteHost(): void
    {
        $urls = $this->createFactory()->create(2, null);

        self::assertSame('https://example.com/typo3/module/link-management/linkvalidator2?id=2', $urls->module(2));
    }

    #[Test]
    public function theRequestCarriesTheUrlOfTheSite(): void
    {
        $request = $this->createFactory()->create(2, null)->getRequest();

        self::assertSame('https://example.com/', $request->getAttribute('normalizedParams')->getSiteUrl());
    }

    #[Test]
    public function editUrlTargetsTheRecordEditor(): void
    {
        $urls = $this->createFactory()->create(2, null);

        self::assertSame(
            'https://example.com/typo3/record/edit?edit%5Btt_content%5D%5B12%5D=edit',
            $urls->edit('tt_content', 12)
        );
    }

    #[Test]
    public function anAbsoluteBackendEntryPointWins(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'] = 'https://admin.example.org/typo3';

        $urls = $this->createFactory()->create(2, null);

        self::assertSame('https://admin.example.org/typo3/module/link-management/linkvalidator2?id=2', $urls->module(2));
    }

    #[Test]
    public function theHostKeepsItsFullName(): void
    {
        $urls = $this->createFactory()->create(99, 'https://t3-main.ddev.site/');

        self::assertSame('https://t3-main.ddev.site/typo3/module/link-management/linkvalidator2?id=99', $urls->module(99));
    }

    #[Test]
    public function theHostDoesNotDependOnTheScriptThatIsRunning(): void
    {
        $currentScript = Environment::getCurrentScript();
        Environment::initialize(
            Environment::getContext(),
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getPublicPath() . '/vendor/bin/typo3',
            Environment::isWindows() ? 'WINDOWS' : 'UNIX'
        );
        try {
            $url = $this->createFactory()->create(99, 'https://t3-main.ddev.site/')->module(99);
        } finally {
            Environment::initialize(
                Environment::getContext(),
                Environment::isCli(),
                Environment::isComposerMode(),
                Environment::getProjectPath(),
                Environment::getPublicPath(),
                Environment::getVarPath(),
                Environment::getConfigPath(),
                $currentScript,
                Environment::isWindows() ? 'WINDOWS' : 'UNIX'
            );
        }

        self::assertSame('https://t3-main.ddev.site/typo3/module/link-management/linkvalidator2?id=99', $url);
    }

    #[Test]
    public function aPageWithoutSiteAndWithoutBaseUrlThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        // Page 99 does not exist, so no site covers it
        $this->createFactory()->create(99, null);
    }

    #[Test]
    public function baseUrlIsUsedWhenThereIsNoSite(): void
    {
        $urls = $this->createFactory()->create(99, 'https://cli.example.net/');

        self::assertSame('https://cli.example.net/typo3/module/link-management/linkvalidator2?id=99', $urls->module(99));
    }

    private function createFactory(): BackendUrlFactory
    {
        return new BackendUrlFactory(
            $this->get(SiteFinder::class),
            $this->get(BackendEntryPointResolver::class),
            $this->get(Router::class),
        );
    }
}
