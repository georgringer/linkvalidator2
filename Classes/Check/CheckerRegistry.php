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

namespace GeorgRinger\Linkvalidator2\Check;

use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * Checkers of a type are asked in the order they are registered, which is the order of their
 * "priority" in the service tag (highest first). The chain ends with the first result that
 * is not skipped and not unknown.
 */
final class CheckerRegistry
{
    /** @var array<string, list<LinkCheckerInterface>> */
    private array $checkersByType = [];

    /**
     * @param iterable<LinkCheckerInterface> $checkers
     */
    public function __construct(iterable $checkers)
    {
        foreach ($checkers as $checker) {
            foreach ($checker->getSupportedTypes() as $type) {
                $this->checkersByType[$type][] = $checker;
            }
        }
    }

    public function hasChecker(string $type): bool
    {
        return isset($this->checkersByType[$type]);
    }

    /**
     * @return CheckResult|null Null if every checker skipped the target
     */
    public function check(LinkTarget $target, LinkCandidate $source): ?CheckResult
    {
        $result = null;
        foreach ($this->checkersByType[$target->type] ?? [] as $checker) {
            $current = $checker->check($target, $source);
            if ($current->status === CheckStatus::Skipped) {
                continue;
            }
            $result = $current;
            if ($current->status !== CheckStatus::Unknown) {
                break;
            }
        }
        return $result;
    }
}
