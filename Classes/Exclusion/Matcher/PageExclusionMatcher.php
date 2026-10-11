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

namespace TYPO3\CMS\Linkvalidator2\Exclusion\Matcher;

use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Linkvalidator2\Exclusion\Exclusion;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionMatcherInterface;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;

/**
 * Links to one page, e.g. a login page restricted on purpose
 */
final readonly class PageExclusionMatcher implements ExclusionMatcherInterface
{
    public function getType(): string
    {
        return 'page';
    }

    public function matches(Exclusion $exclusion, LinkTarget $target): bool
    {
        $pageUid = (int)($exclusion->row['target_page'] ?? 0);
        return $target->type === LinkService::TYPE_PAGE
            && $pageUid > 0
            && (int)($target->resolved['pageuid'] ?? 0) === $pageUid;
    }
}
