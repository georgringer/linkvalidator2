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

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Report;

use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ReportOrderingTest extends UnitTestCase
{
    #[Test]
    public function groupingByPageSortsByPageFirst(): void
    {
        self::assertSame(['b.pid', 'b.first_detected'], (new ReportOrdering('since', false, true))->getColumns());
    }

    #[Test]
    public function groupingByPageDoesNotRepeatThePageColumn(): void
    {
        self::assertSame(['b.pid'], (new ReportOrdering('page', false, true))->getColumns());
    }

    #[Test]
    public function withoutGroupingOnlyTheChosenColumnIsUsed(): void
    {
        self::assertSame(['b.first_detected'], (new ReportOrdering('since'))->getColumns());
    }
}
