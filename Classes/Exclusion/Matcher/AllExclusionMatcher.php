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

namespace GeorgRinger\Linkvalidator2\Exclusion\Matcher;

use GeorgRinger\Linkvalidator2\Exclusion\Exclusion;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcherInterface;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * Turns off checking for the page and its subpages, e.g. an archive
 */
final readonly class AllExclusionMatcher implements ExclusionMatcherInterface
{
    public function getType(): string
    {
        return 'all';
    }

    public function matches(Exclusion $exclusion, LinkTarget $target): bool
    {
        return true;
    }
}
