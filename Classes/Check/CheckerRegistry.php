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

final class CheckerRegistry
{
    /** @var array<string, LinkCheckerInterface> */
    private array $checkersByType = [];

    /**
     * @param iterable<LinkCheckerInterface> $checkers
     */
    public function __construct(iterable $checkers)
    {
        foreach ($checkers as $checker) {
            foreach ($checker->getSupportedTypes() as $type) {
                $this->checkersByType[$type] = $checker;
            }
        }
    }

    public function getChecker(string $type): ?LinkCheckerInterface
    {
        return $this->checkersByType[$type] ?? null;
    }
}
