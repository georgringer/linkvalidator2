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

namespace TYPO3\CMS\Linkvalidator2\Check;

use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;

interface LinkCheckerInterface
{
    /**
     * @return list<string> Link types as resolved by LinkService, e.g. "page", "file"
     */
    public function getSupportedTypes(): array;

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult;
}
