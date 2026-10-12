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

/**
 * A matcher that can tell what every link it matches has to contain, so that stored results
 * are narrowed down by the database before the matcher decides. The hint only has to be right
 * in one direction: a link without it is never matched, a link with it may still not be.
 */
interface PrefilterableExclusionMatcherInterface extends ExclusionMatcherInterface
{
    /**
     * Text the link string of every matching link contains, compared without regard to case.
     * Null when there is none.
     */
    public function getLinkStringHint(Exclusion $exclusion): ?string;
}
