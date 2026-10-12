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
namespace GeorgRinger\Linkvalidator2\Message;

/**
 * Records that are due: a change made them stale (a saved record with links not checked yet,
 * a changed link target, a removed exclusion). They go to a transport of their own, which a
 * worker should empty before the regular one.
 */
final readonly class CheckDueRecordsMessage
{
    /**
     * @param list<int> $uids
     */
    public function __construct(
        public string $table,
        public array $uids,
    ) {}
}
