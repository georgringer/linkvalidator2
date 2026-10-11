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

namespace TYPO3\CMS\Linkvalidator2\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Linkvalidator2\Configuration\ExtensionSettings;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExtensionSettingsTest extends UnitTestCase
{
    public static function excludedFieldsDataProvider(): \Generator
    {
        yield 'listed field' => ['tt_content.header_link, pages.url', 'tt_content', 'header_link', true];
        yield 'other field of the table' => ['tt_content.header_link', 'tt_content', 'bodytext', false];
        yield 'whole table' => ['tx_news_domain_model_news.*', 'tx_news_domain_model_news', 'bodytext', true];
        yield 'nothing configured' => ['', 'tt_content', 'bodytext', false];
    }

    #[DataProvider('excludedFieldsDataProvider')]
    #[Test]
    public function isFieldExcludedMatchesFieldsAndTables(string $configuration, string $table, string $field, bool $expected): void
    {
        self::assertSame($expected, $this->createSubject(['excludedFields' => $configuration])->isFieldExcluded($table, $field));
    }

    #[Test]
    public function defaultsApplyWithoutConfiguration(): void
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willThrowException(new ExtensionConfigurationExtensionNotConfiguredException('', 1760180000));
        $subject = new ExtensionSettings($extensionConfiguration);

        self::assertSame(168 * 3600, $subject->getExternalCacheLifetime());
        self::assertSame(10, $subject->getExternalTimeout());
        self::assertSame('TYPO3 LinkValidator', $subject->getExternalUserAgent());
        self::assertFalse($subject->isFieldExcluded('tt_content', 'bodytext'));
    }

    #[Test]
    public function invalidValuesAreClamped(): void
    {
        $subject = $this->createSubject(['externalCacheLifetime' => '-5', 'externalTimeout' => '0', 'externalUserAgent' => '  ']);

        self::assertSame(0, $subject->getExternalCacheLifetime());
        self::assertSame(1, $subject->getExternalTimeout());
        self::assertSame('TYPO3 LinkValidator', $subject->getExternalUserAgent());
    }

    private function createSubject(array $configuration): ExtensionSettings
    {
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn(string $extension, string $path): mixed => $configuration[$path] ?? null
        );
        return new ExtensionSettings($extensionConfiguration);
    }
}
