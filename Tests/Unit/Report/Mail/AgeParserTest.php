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

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\Mail\AgeParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class AgeParserTest extends UnitTestCase
{
    private const NOW = 1_800_000_000;

    public static function validAgesProvider(): iterable
    {
        yield 'days' => ['7 days', self::NOW - 7 * 86400];
        yield 'one day' => ['1 day', self::NOW - 86400];
        yield 'hours' => ['12 hours', self::NOW - 12 * 3600];
    }

    #[Test]
    #[DataProvider('validAgesProvider')]
    public function theAgeIsSubtractedFromNow(string $age, int $expected): void
    {
        self::assertSame($expected, (new AgeParser())->toTimestamp($age, self::NOW));
    }

    public static function invalidAgesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['soon'];
        yield 'zero' => ['0 days'];
    }

    #[Test]
    #[DataProvider('invalidAgesProvider')]
    public function anUnusableAgeIsRejected(string $age): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AgeParser())->toTimestamp($age, self::NOW);
    }
}
