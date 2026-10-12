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
namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report;

use GeorgRinger\Linkvalidator2\Report\LanguageIconProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class LanguageIconProviderTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
        $siteWriter = $this->get(SiteWriter::class);
        $siteWriter->write('multi', [
            'rootPageId' => 1,
            'base' => 'https://example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
                ['languageId' => 1, 'title' => 'German', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'flag' => 'de', 'fallbackType' => 'strict'],
            ],
        ]);
        $siteWriter->write('single', [
            'rootPageId' => 5,
            'base' => 'https://single.example.com/',
            'languages' => [
                ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'flag' => 'us'],
            ],
        ]);
    }

    #[Test]
    public function everyLanguageOfASiteWithSeveralLanguagesGetsItsFlag(): void
    {
        $subject = $this->get(LanguageIconProvider::class);

        $english = $subject->describe(2, 0);
        $german = $subject->describe(2, 1);

        self::assertSame('English', $english['title']);
        self::assertStringContainsString('flags-us', $english['icon']);
        self::assertSame('German', $german['title']);
        self::assertStringContainsString('flags-de', $german['icon']);
        self::assertStringContainsString('title="German"', $german['icon']);
    }

    #[Test]
    public function allLanguagesGetTheirOwnIcon(): void
    {
        $result = $this->get(LanguageIconProvider::class)->describe(2, -1);

        self::assertSame('All languages', $result['title']);
        self::assertStringContainsString('flags-multiple', $result['icon']);
    }

    #[Test]
    public function aSiteWithASingleLanguageShowsNoFlag(): void
    {
        self::assertNull($this->get(LanguageIconProvider::class)->describe(5, 0));
    }

    #[Test]
    public function aLanguageThatTheSiteDoesNotKnowShowsNoFlag(): void
    {
        self::assertNull($this->get(LanguageIconProvider::class)->describe(2, 7));
    }

    #[Test]
    public function theLanguageIsTheOneOfTheSiteOfThePageOfTheRecord(): void
    {
        $subject = $this->get(LanguageIconProvider::class);

        // Page 5 belongs to the other site, wherever the report was opened
        self::assertNull($subject->describe(5, 0));
        self::assertNotNull($subject->describe(2, 0));
    }
}
