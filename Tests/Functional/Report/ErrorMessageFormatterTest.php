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

use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ErrorMessageFormatterTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
    }

    public static function messagesDataProvider(): \Generator
    {
        yield 'frontend failure code' => ['page.notReachable', ['page' => 1, 'code' => 1607585445], 'Linked page is not delivered by the frontend (code 1607585445)'];
        yield 'http status' => ['url.httpStatus', ['httpStatus' => 418], 'Server responded with HTTP 418'];
        yield 'timeout' => ['url.timeout', ['seconds' => 10], 'Server did not respond within 10 seconds'];
        yield 'start time' => ['page.notYetVisible', ['starttime' => 2145916800], 'Linked page is not online yet, it is published on ' . date('d.m.Y H:i', 2145916800) . ' (start time)'];
        yield 'without placeholder' => ['page.hidden', ['page' => 1], 'Linked page is hidden'];
    }

    #[DataProvider('messagesDataProvider')]
    #[Test]
    public function messageIsFormattedWithItsDetails(string $errorType, array $details, string $expected): void
    {
        $message = $this->get(ErrorMessageFormatter::class)->format($errorType, $details);

        self::assertStringStartsWith($expected, $message);
        self::assertStringNotContainsString('%', $message);
    }
}
