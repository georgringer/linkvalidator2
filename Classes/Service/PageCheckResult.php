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
namespace GeorgRinger\Linkvalidator2\Service;

final readonly class PageCheckResult
{
    public function __construct(
        /** False when the time budget ran out; checking the page again with the same run start continues */
        public bool $done,
        public int $recordCount,
        /** Records of the page checked in this run so far, by this call or before, at most $recordCount */
        public int $processedCount,
        /** Records checked by this call */
        public int $checkedCount,
        /** Broken links stored for the page after this call */
        public int $brokenLinkCount,
        /** Of these, the ones first detected in this run */
        public int $newBrokenLinkCount,
        /** Notices stored for the page: reachable targets with a restriction, not counted as broken */
        public int $noticeCount,
    ) {}
}
