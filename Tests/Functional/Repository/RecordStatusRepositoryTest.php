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

namespace TYPO3\CMS\Linkvalidator2\Tests\Functional\Repository;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Linkvalidator2\Repository\RecordStatusRepository;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class RecordStatusRepositoryTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    #[Test]
    public function lastCheckOfPagesNeverCheckedIsZero(): void
    {
        self::assertSame(0, $this->get(RecordStatusRepository::class)->findLastChecked([1, 2]));
    }

    #[Test]
    public function lastCheckIsTheLatestOfTheRecordsOnThePages(): void
    {
        $subject = $this->get(RecordStatusRepository::class);
        $subject->markChecked('tt_content', 1, 1, 0, 0, 1000);
        $subject->markChecked('tt_content', 2, 2, 0, 0, 3000);
        $subject->markChecked('tt_content', 3, 3, 0, 0, 5000);
        // Due records do not count as checked
        $subject->markChecked('tt_content', 4, 2, 0, 0, 0);

        self::assertSame(3000, $subject->findLastChecked([1, 2]));
        self::assertSame(0, $subject->findLastChecked([4]));
    }
}
