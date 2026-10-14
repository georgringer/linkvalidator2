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
namespace GeorgRinger\Linkvalidator2\Repository;

use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportPermissions;

/**
 * Which stored results of {@see BrokenLinkRepository} are looked at: whether they are listed,
 * counted or grouped.
 */
final readonly class BrokenLinkQuery
{
    /**
     * @param list<int> $pids
     * @param ReportPermissions|null $permissions Only what the user may fix, null for everything
     * @param bool $onlyBroken Leaves out notices, which are stored but no broken links
     * @param int|null $detectedSince Only links first detected at or after this timestamp
     */
    public function __construct(
        public array $pids,
        public ReportFilter $filter = new ReportFilter(),
        public ?ReportPermissions $permissions = null,
        public bool $onlyBroken = false,
        public ?int $detectedSince = null,
    ) {}
}
