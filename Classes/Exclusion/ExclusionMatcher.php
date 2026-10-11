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

namespace TYPO3\CMS\Linkvalidator2\Exclusion;

use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;

final readonly class ExclusionMatcher
{
    /** @var array<string, ExclusionMatcherInterface> */
    private array $matchers;

    /**
     * @param iterable<ExclusionMatcherInterface> $matchers
     */
    public function __construct(iterable $matchers)
    {
        $byType = [];
        foreach ($matchers as $matcher) {
            $byType[$matcher->getType()] = $matcher;
        }
        $this->matchers = $byType;
    }

    /**
     * @return list<string>
     */
    public function getTypes(): array
    {
        return array_keys($this->matchers);
    }

    /**
     * Exclusions of a type without matcher never apply.
     *
     * @param list<Exclusion> $exclusions
     */
    public function isExcluded(array $exclusions, LinkTarget $target): bool
    {
        foreach ($exclusions as $exclusion) {
            if (($this->matchers[$exclusion->type] ?? null)?->matches($exclusion, $target)) {
                return true;
            }
        }
        return false;
    }
}
