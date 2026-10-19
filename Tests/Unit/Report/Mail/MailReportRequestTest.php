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

use GeorgRinger\Linkvalidator2\Report\Mail\MailReportRequest;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class MailReportRequestTest extends UnitTestCase
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
    public function minAgeSetsTheUpperBoundOfTheFirstDetectionTime(string $age, int $expected): void
    {
        self::assertSame([null, $expected], $this->request(minAge: $age)->getDetectedRange(self::NOW));
    }

    #[Test]
    #[DataProvider('validAgesProvider')]
    public function maxAgeSetsTheLowerBoundOfTheFirstDetectionTime(string $age, int $expected): void
    {
        self::assertSame([$expected, null], $this->request(maxAge: $age)->getDetectedRange(self::NOW));
    }

    #[Test]
    public function withoutAgesThereAreNoBounds(): void
    {
        self::assertSame([null, null], $this->request()->getDetectedRange(self::NOW));
    }

    #[Test]
    public function minAndMaxAgeFormARange(): void
    {
        self::assertSame(
            [self::NOW - 7 * 86400, self::NOW - 86400],
            $this->request(minAge: '1 day', maxAge: '7 days')->getDetectedRange(self::NOW)
        );
    }

    #[Test]
    public function equalAgesAreAValidRange(): void
    {
        self::assertSame(
            [self::NOW - 86400, self::NOW - 86400],
            $this->request(minAge: '1 day', maxAge: '1 day')->getDetectedRange(self::NOW)
        );
    }

    #[Test]
    public function anEmptyRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->request(minAge: '7 days', maxAge: '1 day')->getDetectedRange(self::NOW);
    }

    public static function invalidAgesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'words' => ['soon'];
        yield 'zero' => ['0 days'];
    }

    #[Test]
    #[DataProvider('invalidAgesProvider')]
    public function anUnusableMinAgeIsRejected(string $age): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->request(minAge: $age)->getDetectedRange(self::NOW);
    }

    #[Test]
    #[DataProvider('invalidAgesProvider')]
    public function anUnusableMaxAgeIsRejected(string $age): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->request(maxAge: $age)->getDetectedRange(self::NOW);
    }

    private function request(?string $minAge = null, ?string $maxAge = null): MailReportRequest
    {
        return new MailReportRequest(1, 0, new ReportFilter(), [], [], $minAge, $maxAge);
    }
}
