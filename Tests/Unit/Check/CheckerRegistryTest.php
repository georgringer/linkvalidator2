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

namespace TYPO3\CMS\Linkvalidator2\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Linkvalidator2\Check\CheckerRegistry;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Check\LinkCheckerInterface;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CheckerRegistryTest extends UnitTestCase
{
    #[Test]
    public function checkerIsFoundForEachOfItsTypes(): void
    {
        $fileChecker = $this->createChecker(['file', 'folder']);
        $subject = new CheckerRegistry([$this->createChecker(['page']), $fileChecker]);

        self::assertSame($fileChecker, $subject->getChecker('file'));
        self::assertSame($fileChecker, $subject->getChecker('folder'));
    }

    #[Test]
    public function unknownTypeHasNoChecker(): void
    {
        self::assertNull((new CheckerRegistry([$this->createChecker(['page'])]))->getChecker('email'));
    }

    #[Test]
    public function laterCheckerReplacesEarlierOneForTheSameType(): void
    {
        $replacement = $this->createChecker(['page']);
        $subject = new CheckerRegistry([$this->createChecker(['page']), $replacement]);

        self::assertSame($replacement, $subject->getChecker('page'));
    }

    /**
     * @param list<string> $types
     */
    private function createChecker(array $types): LinkCheckerInterface
    {
        return new readonly class ($types) implements LinkCheckerInterface {
            public function __construct(private array $types) {}

            public function getSupportedTypes(): array
            {
                return $this->types;
            }

            public function check(LinkTarget $target, LinkCandidate $source): CheckResult
            {
                return CheckResult::ok();
            }
        };
    }
}
