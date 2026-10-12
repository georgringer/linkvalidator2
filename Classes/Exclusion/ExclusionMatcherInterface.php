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

namespace GeorgRinger\Linkvalidator2\Exclusion;

use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * Decides for one type of exclusion whether it applies to a link target. Matchers are
 * tagged automatically; a new type also needs an item and fields in the TCA of
 * tx_linkvalidator2_exclusion.
 */
interface ExclusionMatcherInterface
{
    /**
     * Value of the field "type" of the exclusion record
     */
    public function getType(): string;

    public function matches(Exclusion $exclusion, LinkTarget $target): bool;
}
