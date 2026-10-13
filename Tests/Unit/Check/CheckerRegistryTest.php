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

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use GeorgRinger\Linkvalidator2\Check\CacheableLinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Check\CheckerRegistry;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CheckerRegistryTest extends UnitTestCase
{
    #[Test]
    public function checkerIsUsedForEachOfItsTypes(): void
    {
        $subject = new CheckerRegistry([$this->createChecker(['file', 'folder'], CheckResult::broken('file.missing'))]);

        self::assertTrue($subject->hasChecker('file'));
        self::assertTrue($subject->hasChecker('folder'));
        self::assertSame('file.missing', $subject->check($this->createTarget('folder'), $this->createSource())?->errorType);
    }

    #[Test]
    public function unknownTypeHasNoChecker(): void
    {
        $subject = new CheckerRegistry([$this->createChecker(['page'], CheckResult::ok())]);

        self::assertFalse($subject->hasChecker('email'));
        self::assertNull($subject->check($this->createTarget('email'), $this->createSource()));
    }

    #[Test]
    public function checkersAreAskedInOrderUntilOneKnowsTheResult(): void
    {
        $subject = new CheckerRegistry([
            $this->createChecker(['url'], CheckResult::unknown('url.httpStatus')),
            $this->createChecker(['url'], CheckResult::ok()),
            $this->createChecker(['url'], CheckResult::broken('never.asked')),
        ]);

        self::assertTrue($subject->check($this->createTarget('url'), $this->createSource())?->isOk());
    }

    #[Test]
    public function brokenResultEndsTheChain(): void
    {
        $subject = new CheckerRegistry([
            $this->createChecker(['url'], CheckResult::broken('url.notFound')),
            $this->createChecker(['url'], CheckResult::ok()),
        ]);

        self::assertSame(CheckStatus::Broken, $subject->check($this->createTarget('url'), $this->createSource())?->status);
    }

    #[Test]
    public function skippingCheckerIsPassedOver(): void
    {
        $subject = new CheckerRegistry([
            $this->createChecker(['url'], CheckResult::skip()),
            $this->createChecker(['url'], CheckResult::ok()),
        ]);

        self::assertTrue($subject->check($this->createTarget('url'), $this->createSource())?->isOk());
    }

    #[Test]
    public function lastUnknownResultIsKeptWhenNobodyDecides(): void
    {
        $subject = new CheckerRegistry([
            $this->createChecker(['url'], CheckResult::unknown('url.httpStatus')),
            $this->createChecker(['url'], CheckResult::skip()),
        ]);

        self::assertSame('url.httpStatus', $subject->check($this->createTarget('url'), $this->createSource())?->errorType);
    }

    #[Test]
    public function resultIsNullWhenAllCheckersSkip(): void
    {
        $subject = new CheckerRegistry([$this->createChecker(['url'], CheckResult::skip())]);

        self::assertNull($subject->check($this->createTarget('url'), $this->createSource()));
        self::assertTrue($subject->hasChecker('url'));
    }

    #[Test]
    public function cacheLifetimeComesFromTheCacheableCheckerOfTheType(): void
    {
        $subject = new CheckerRegistry([
            $this->createChecker(['url'], CheckResult::skip()),
            $this->createCacheableChecker(['url'], 3600),
            $this->createChecker(['page'], CheckResult::ok()),
        ]);

        self::assertSame(3600, $subject->getCacheLifetime('url'));
        self::assertNull($subject->getCacheLifetime('page'));
        self::assertNull($subject->getCacheLifetime('email'));
    }

    private function createTarget(string $type): LinkTarget
    {
        return new LinkTarget($type, $type . ':1');
    }

    private function createSource(): LinkCandidate
    {
        return new LinkCandidate('tt_content', 1, 1, 0, 'bodytext', '', '');
    }

    /**
     * @param list<string> $types
     */
    private function createChecker(array $types, CheckResult $result): LinkCheckerInterface
    {
        return new readonly class ($types, $result) implements LinkCheckerInterface {
            public function __construct(private array $types, private CheckResult $result) {}

            public function getSupportedTypes(): array
            {
                return $this->types;
            }

            public function check(LinkTarget $target, LinkCandidate $source): CheckResult
            {
                return $this->result;
            }
        };
    }

    /**
     * @param list<string> $types
     */
    private function createCacheableChecker(array $types, int $lifetime): CacheableLinkCheckerInterface
    {
        return new readonly class ($types, $lifetime) implements CacheableLinkCheckerInterface {
            public function __construct(private array $types, private int $lifetime) {}

            public function getSupportedTypes(): array
            {
                return $this->types;
            }

            public function getCacheLifetime(): int
            {
                return $this->lifetime;
            }

            public function check(LinkTarget $target, LinkCandidate $source): CheckResult
            {
                return CheckResult::ok();
            }
        };
    }
}
